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

import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', 'src')
const OUTPUT = join(ROOT, 'i18n', 'scopes.json')

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

export function render(scopes) {
  return `${JSON.stringify(scopes, null, 2)}\n`
}

export function currentFile() {
  return existsSync(OUTPUT) ? readFileSync(OUTPUT, 'utf8') : ''
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const text = render(buildScopes())
  if (process.argv.includes('--check')) {
    if (text !== currentFile()) {
      console.error('src/i18n/scopes.json is out of date — run `yarn i18n:scopes`.')
      process.exit(1)
    }
  } else {
    writeFileSync(OUTPUT, text)
    console.log('Wrote src/i18n/scopes.json')
  }
}
