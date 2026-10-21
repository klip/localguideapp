// Works out which UI text each route needs, so the first paint fetches only
// that (see src/i18n/index.ts). Writes src/i18n/scopes.json:
//
//   { "shell": [...], "routes": { "<route name>": [...] } }
//
// Each entry is a key prefix at widget level ("discover.deck",
// "common.app_header"). `shell` is what App.vue renders around every page;
// a route's list is what its page and everything it imports use, minus the
// shell. Found by following static `@/...` and relative imports from App.vue
// and from each page in router/index.ts, and collecting every quoted string
// that starts like a key — so a dynamic key (`common.time_ago.${unit}`)
// counts as long as its page and widget are literal.
//
//   node scripts/i18n-scopes.mjs          # rewrite scopes.json
//   node scripts/i18n-scopes.mjs --check  # exit 1 if it's out of date
//
// src/__tests__/i18nScopes.spec.ts runs the check too.
//
// It also writes src/i18n/layout.json, the CMS content editor's map of the
// app (docs/auth-cms-plan.md, phase 7): for each page its own blocks in the
// order its template uses them, the shared components it shows, the header
// and footer, and the blocks no page shows (country names, API errors…).

import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', 'src')
const OUTPUT = join(ROOT, 'i18n', 'scopes.json')
const LAYOUT_OUTPUT = join(ROOT, 'i18n', 'layout.json')

/** The shell's blocks as the app shows them: the header, the footer, and what can appear over any page. */
const HEADER = ['common.app_header', 'common.language_switcher']
const FOOTER = ['common.app_footer', 'common.cookie_notice']

/** Page namespaces and `common` — anything else that looks like `a.b.` (a file name, a URL) isn't a key. */
function namespaces() {
  const en = JSON.parse(readFileSync(join(ROOT, 'i18n', 'en.json'), 'utf8'))
  return new Set(Object.keys(en).map((key) => key.split('.')[0]))
}

/** `@/x` or `./x` (relative to `from`) → a .ts/.vue file under src, or null (packages, JSON, styles). */
function resolveImport(spec, from = ROOT) {
  const base = spec.startsWith('@/') ? join(ROOT, spec.slice(2)) : resolve(dirname(from), spec)
  for (const candidate of [base, `${base}.ts`, `${base}.vue`, join(base, 'index.ts')]) {
    if (/\.(ts|vue)$/.test(candidate) && existsSync(candidate)) return candidate
  }
  return null
}

/** Every widget-level prefix used by `entry` and the modules it imports statically. */
function collect(entry, known, seen = new Set(), found = new Set()) {
  if (seen.has(entry)) return found
  seen.add(entry)
  const source = readFileSync(entry, 'utf8')
  for (const match of source.matchAll(/['"`]([a-z_]+)\.([a-z_]+)\./g)) {
    if (known.has(match[1])) found.add(`${match[1]}.${match[2]}`)
  }
  // Static imports only: router/index.ts pulls pages in with `import()`,
  // which must not drag every page into every other page's list.
  for (const match of source.matchAll(/(?:^|\n)\s*import\s[^'"]*?from\s+['"]((?:@\/|\.{1,2}\/)[^'"]+)['"]/g)) {
    const file = resolveImport(match[1], entry)
    if (file) collect(file, known, seen, found)
  }
  return found
}

export function buildScopes() {
  const known = namespaces()
  const router = readFileSync(join(ROOT, 'router', 'index.ts'), 'utf8')
  const shell = collect(join(ROOT, 'App.vue'), known)

  const routes = {}
  for (const match of router.matchAll(/name:\s*'([^']+)',\s*component:\s*\(\)\s*=>\s*import\('(@\/pages\/[^']+)'\)/g)) {
    const page = resolveImport(match[2])
    if (!page) throw new Error(`Can't find ${match[2]} for route ${match[1]}`)
    routes[match[1]] = [...collect(page, known)].filter((scope) => !shell.has(scope)).sort()
  }

  return { shell: [...shell].sort(), routes }
}

/** Widget prefixes in `source`, in the order they first appear. */
function inOrder(source, known) {
  const seen = []
  for (const match of source.matchAll(/['"`]([a-z_]+)\.([a-z_]+)\./g)) {
    const prefix = `${match[1]}.${match[2]}`
    if (known.has(match[1]) && !seen.includes(prefix)) seen.push(prefix)
  }
  return seen
}

/** "guide-discover" → "Guide discover". */
function title(route) {
  const text = route.replace(/-/g, ' ')
  return text.charAt(0).toUpperCase() + text.slice(1)
}

/**
 * The CMS's map: `{ shell: { header, footer, always }, pages: [{ route, path,
 * title, main, shared }], unplaced }`. A page's `main` is its own blocks
 * (`page.*`), template order first; `shared` the common components it uses.
 */
export function buildLayout() {
  const known = namespaces()
  const scopes = buildScopes()
  const router = readFileSync(join(ROOT, 'router', 'index.ts'), 'utf8')
  const shell = scopes.shell

  const pages = []
  for (const match of router.matchAll(/path:\s*'([^']+)',\s*name:\s*'([^']+)',\s*component:\s*\(\)\s*=>\s*import\('(@\/pages\/[^']+)'\)/g)) {
    const [, path, route, spec] = match
    const source = readFileSync(resolveImport(spec), 'utf8')
    const template = source.match(/<template>([\s\S]*)<\/template>/)?.[1] ?? ''
    const all = scopes.routes[route] ?? []
    const own = all.filter((prefix) => !prefix.startsWith('common.'))
    const ordered = [...inOrder(template, known), ...inOrder(source, known)].filter(
      (prefix, i, list) => own.includes(prefix) && list.indexOf(prefix) === i,
    )
    pages.push({
      route,
      path,
      title: title(route),
      main: [...ordered, ...own.filter((prefix) => !ordered.includes(prefix))],
      shared: all.filter((prefix) => prefix.startsWith('common.')),
    })
  }

  const placed = new Set([...shell, ...pages.flatMap((page) => [...page.main, ...page.shared])])
  const en = JSON.parse(readFileSync(join(ROOT, 'i18n', 'en.json'), 'utf8'))
  const unplaced = [...new Set(Object.keys(en).map((key) => key.split('.').slice(0, 2).join('.')))]
    .filter((prefix) => !placed.has(prefix))
    .sort()

  return {
    shell: {
      header: HEADER.filter((prefix) => shell.includes(prefix)),
      footer: FOOTER.filter((prefix) => shell.includes(prefix)),
      always: shell.filter((prefix) => !HEADER.includes(prefix) && !FOOTER.includes(prefix)),
    },
    pages,
    unplaced,
  }
}

export function currentLayoutFile() {
  return existsSync(LAYOUT_OUTPUT) ? readFileSync(LAYOUT_OUTPUT, 'utf8') : ''
}

export function render(scopes) {
  return `${JSON.stringify(scopes, null, 2)}\n`
}

export function currentFile() {
  return existsSync(OUTPUT) ? readFileSync(OUTPUT, 'utf8') : ''
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const text = render(buildScopes())
  const layout = render(buildLayout())
  if (process.argv.includes('--check')) {
    if (text !== currentFile() || layout !== currentLayoutFile()) {
      console.error('src/i18n/scopes.json or layout.json is out of date — run `yarn i18n:scopes`.')
      process.exit(1)
    }
  } else {
    writeFileSync(OUTPUT, text)
    writeFileSync(LAYOUT_OUTPUT, layout)
    console.log('Wrote src/i18n/scopes.json and src/i18n/layout.json')
  }
}
