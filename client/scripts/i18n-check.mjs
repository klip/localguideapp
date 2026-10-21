// Checks the code against the page map's text blocks (src/router/appMap.json,
// pulled from the database by api/bin/pull-app-map.php): every text block a
// page's code uses should be listed for that page (or the shell), so the
// page's text is loaded before it renders — and every block of text should be
// on some page, so the content editor can show where it is. The blocks are
// developer-owned (the CMS manages routes, not what pages contain): this
// prints the SQL to put in a migration.
//
//   node scripts/i18n-check.mjs     # yarn i18n:check — exit 1 on a gap
//
// Blocks are found by following static `@/...` and relative imports from
// App.vue (the shell) and from each page component, collecting every quoted
// string that starts like a key — so a dynamic key (`common.time_ago.${unit}`)
// counts as long as its page and widget are literal.
// src/__tests__/i18nScopes.spec.ts runs the same check.

import { existsSync, readFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', 'src')

function readJson(path) {
  return JSON.parse(readFileSync(join(ROOT, path), 'utf8'))
}

/** Page namespaces and `common` — anything else that looks like `a.b.` (a file name, a URL) isn't a key. */
function namespaces(en) {
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
  // Static imports only: pages are lazy (`import()`) and mustn't pull each other in.
  for (const match of source.matchAll(
    /(?:^|\n)\s*import\s[^'"]*?from\s+['"]((?:@\/|\.{1,2}\/)[^'"]+)['"]/g,
  )) {
    const file = resolveImport(match[1], entry)
    if (file) collect(file, known, seen, found)
  }
  return found
}

/**
 * `{ missing: [{ route, prefix }], unplaced: [prefix], unknownPages: [name] }`
 * — blocks the code uses that the map doesn't list for that route (`_shell`
 * for App.vue), text no route lists at all, and routes naming a page
 * component that isn't in src/router/pages.ts.
 */
export function checkMap(map = readJson('router/appMap.json'), en = readJson('i18n/en.json')) {
  const known = namespaces(en)
  const shellMap = new Set(map.blocks._shell ?? [])
  const missing = []

  const shellCode = collect(join(ROOT, 'App.vue'), known)
  for (const prefix of [...shellCode].sort()) {
    if (!shellMap.has(prefix)) missing.push({ route: '_shell', prefix })
  }

  const pages = readFileSync(join(ROOT, 'router', 'pages.ts'), 'utf8')
  const unknownPages = []
  for (const route of map.routes) {
    if (!route.component) continue
    if (!new RegExp(`\\b${route.component}:`).test(pages)) {
      unknownPages.push(route.name)
      continue
    }
    const listed = new Set([...shellMap, ...(map.blocks[route.name] ?? [])])
    const used = collect(join(ROOT, 'pages', `${route.component}.vue`), known)
    for (const prefix of [...used].sort()) {
      if (!shellCode.has(prefix) && !listed.has(prefix)) missing.push({ route: route.name, prefix })
    }
  }

  const placed = new Set(Object.values(map.blocks).flat())
  const unplaced = [...new Set(Object.keys(en).map((key) => key.split('.').slice(0, 2).join('.')))]
    .filter((prefix) => !placed.has(prefix))
    .sort()

  return { missing, unplaced, unknownPages }
}

/** SQL that puts `missing` blocks on their routes (`main` for a page's own, `shared` for common, `always` in the shell). */
export function suggestSql(missing) {
  return missing.map(({ route, prefix }) => {
    const area = route === '_shell' ? 'always' : prefix.startsWith('common.') ? 'shared' : 'main'
    return (
      'INSERT IGNORE INTO `app_route_blocks` (`route_id`, `prefix`, `area`, `position`) ' +
      `SELECT id, '${prefix}', '${area}', 99 FROM app_routes WHERE name = '${route}';`
    )
  })
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const { missing, unplaced, unknownPages } = checkMap()
  for (const name of unknownPages)
    console.error(`Route ${name} names a page component src/router/pages.ts doesn't have.`)
  if (missing.length) {
    console.error(
      "Blocks the code uses that the page map doesn't list — add to a migration and apply:",
    )
    for (const line of suggestSql(missing)) console.error(`  ${line}`)
  }
  if (unplaced.length) console.error(`Text on no page: ${unplaced.join(', ')}`)
  if (missing.length || unplaced.length || unknownPages.length) {
    console.error(
      'Then pull the map: docker exec lemp-rg-api php /var/www/localguideapp/api/bin/pull-app-map.php',
    )
    process.exit(1)
  }
  console.log(`The page map covers the code.`)
}
