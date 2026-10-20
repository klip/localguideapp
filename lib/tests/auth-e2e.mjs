// End-to-end check of token sign-in (docs/auth-cms-plan.md, phase 1) against
// the running services — the dev server (Vite, proxying /auth/*) and the
// gateway must be up:
//   docker exec -w /var/www/localguideapp lemp-node node lib/tests/auth-e2e.mjs
// Uses a seeded demo account (docs/production-memo.md); changes nothing but
// its refresh tokens. Takes ~35 s (it waits out the reuse grace window).
const APP = 'http://localhost:5173' // Vite, which proxies /auth/* to the auth service
const WS = 'ws://ws:8090' // the gateway directly (no Origin = non-browser client)
const EMAIL = 'seed.guest.01@example.test'
const PASSWORD = 'aA123456'

let failures = 0
const check = (name, ok, extra = '') => {
  console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${extra ? ' — ' + extra : ''}`)
  if (!ok) failures++
}

function cookiesFrom(res) {
  const jar = {}
  for (const line of res.headers.getSetCookie()) {
    const [pair] = line.split(';')
    const i = pair.indexOf('=')
    jar[pair.slice(0, i)] = { value: pair.slice(i + 1), raw: line }
  }
  return jar
}

async function post(action, body = {}, { cookie = '', origin = APP, type = 'application/json' } = {}) {
  const res = await fetch(`${APP}/auth/${action}`, {
    method: 'POST',
    headers: { 'Content-Type': type, Origin: origin, ...(cookie ? { Cookie: cookie } : {}) },
    body: JSON.stringify(body),
  })
  let json = null
  try {
    json = await res.json()
  } catch {}
  return { status: res.status, body: json, cookies: cookiesFrom(res) }
}

function socket() {
  const ws = new WebSocket(WS)
  let next = 1
  const waiting = new Map()
  const pushes = []
  ws.onmessage = (e) => {
    const m = JSON.parse(e.data)
    if (m.type === 'response') waiting.get(m.id)?.(m)
    else pushes.push(m)
  }
  const open = new Promise((r) => (ws.onopen = r))
  return {
    open,
    pushes,
    request: (payload) =>
      new Promise((resolve) => {
        const id = next++
        waiting.set(id, resolve)
        ws.send(JSON.stringify({ type: 'request', id, ...payload }))
      }),
    send: (m) => ws.send(JSON.stringify(m)),
    close: () => ws.close(),
  }
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

// 1. Sign in over HTTP.
const login = await post('login', { email: EMAIL, password: PASSWORD })
const refresh1 = login.cookies['__Host-rg_refresh']
const device = login.cookies['__Host-rg_device']
check('login 200 with an access token', login.status === 200 && typeof login.body?.accessToken === 'string', String(login.status))
check('login body has no session hash or refresh token', !('sessionHash' in (login.body ?? {})) && !JSON.stringify(login.body).includes(refresh1?.value ?? '@@'))
check('refresh cookie is HttpOnly; Secure; SameSite=Strict; Path=/', !!refresh1 && /HttpOnly/i.test(refresh1.raw) && /Secure/i.test(refresh1.raw) && /SameSite=Strict/i.test(refresh1.raw) && /path=\//i.test(refresh1.raw))
check('device cookie set', !!device && /HttpOnly/i.test(device.raw))
const token = login.body.accessToken
const userId = login.body.id

// 2. The gateway verifies the token itself.
const s = socket()
await s.open
let r = await s.request({ action: 'myProfile', accessToken: token })
check('WS request with the token → 200, my profile', r.status === 200 && Number(r.body.id) === userId, String(r.status))
const [h, p, sig] = token.split('.')
const forgedPayload = Buffer.from(JSON.stringify({ ...JSON.parse(Buffer.from(p, 'base64url')), sub: '1' })).toString('base64url')
r = await s.request({ action: 'myProfile', accessToken: `${h}.${forgedPayload}.${sig}` })
check('a forged token → 401', r.status === 401, String(r.status))
r = await s.request({ action: 'myProfile', accessToken: token, actingRole: 'guide' })
check('a role the token lacks → 403', r.status === 403, String(r.status))
r = await s.request({ action: 'forgetTrustedDevices', accessToken: token })
check('an auth-service action identified by the token → 200', r.status === 200, String(r.status))

// 3. Pushes: bind the socket by token.
s.send({ type: 'auth', accessToken: token })
await sleep(500)
check('socket binds to the user', s.pushes.some((m) => m.type === 'auth' && m.userId === userId))

// 4. Refresh rotates the cookie.
const cookie1 = `__Host-rg_refresh=${refresh1.value}; __Host-rg_device=${device.value}`
const ref = await post('refresh', {}, { cookie: cookie1 })
const refresh2 = ref.cookies['__Host-rg_refresh']
check('refresh 200 with a new token and the user', ref.status === 200 && ref.body.accessToken && ref.body.accessToken !== token && ref.body.user?.id === userId, String(ref.status))
check('refresh rotates the cookie', !!refresh2 && refresh2.value !== refresh1.value)

// 5. CSRF guards.
check('a foreign Origin → 403', (await post('refresh', {}, { cookie: cookie1, origin: 'https://evil.example' })).status === 403)
check('a non-JSON body → 415', (await post('refresh', {}, { cookie: cookie1, type: 'text/plain' })).status === 415)

// 6. Reuse of a rotated token after the grace window revokes the family.
await sleep(31_000) // past the 30 s grace window
const reuse = await post('refresh', {}, { cookie: cookie1 })
check('reusing the old refresh token → 401, cookie cleared', reuse.status === 401 && /__Host-rg_refresh=;|__Host-rg_refresh=deleted|Max-Age=0|expires=Thu, 01 Jan 1970/i.test(reuse.cookies['__Host-rg_refresh']?.raw ?? ''), String(reuse.status))
const cookie2 = `__Host-rg_refresh=${refresh2.value}; __Host-rg_device=${device.value}`
check('…and the family is revoked: the newer token fails too', (await post('refresh', {}, { cookie: cookie2 })).status === 401)

// 7. Logout ends the sign-in at once — its access token too, not in 15 minutes.
const login2 = await post('login', { email: EMAIL, password: PASSWORD })
const cookie3 = `__Host-rg_refresh=${login2.cookies['__Host-rg_refresh'].value}`
check('the new sign-in works', (await s.request({ action: 'myProfile', accessToken: login2.body.accessToken })).status === 200)
check('logout 200', (await post('logout', {}, { cookie: cookie3 })).status === 200)
check('after logout, refresh → 401', (await post('refresh', {}, { cookie: cookie3 })).status === 401)
await sleep(300)
r = await s.request({ action: 'myProfile', accessToken: login2.body.accessToken })
check('after logout, its access token → 401 right away', r.status === 401, String(r.status))

// 8. Sign out everywhere: one device ends the other's sign-in and unbinds its socket.
const phone = await post('login', { email: EMAIL, password: PASSWORD })
const laptop = await post('login', { email: EMAIL, password: PASSWORD })
const phoneSocket = socket()
await phoneSocket.open
phoneSocket.send({ type: 'auth', accessToken: phone.body.accessToken })
await sleep(300)
const laptopCookie = `__Host-rg_refresh=${laptop.cookies['__Host-rg_refresh'].value}`
check('sign out everywhere 200', (await post('logoutEverywhere', {}, { cookie: laptopCookie })).status === 200)
await sleep(300)
r = await phoneSocket.request({ action: 'myProfile', accessToken: phone.body.accessToken })
check("the other device's access token → 401", r.status === 401, String(r.status))
check("the other device's socket was unbound", phoneSocket.pushes.some((m) => m.type === 'auth' && m.userId === null))
check("the other device's refresh → 401", (await post('refresh', {}, { cookie: `__Host-rg_refresh=${phone.cookies['__Host-rg_refresh'].value}` })).status === 401)
check('…and this one\'s too', (await post('refresh', {}, { cookie: laptopCookie })).status === 401)
phoneSocket.close()
const fresh = await post('login', { email: EMAIL, password: PASSWORD })
r = await s.request({ action: 'myProfile', accessToken: fresh.body.accessToken })
check('signing straight back in works', r.status === 200, String(r.status))
await post('logout', {}, { cookie: `__Host-rg_refresh=${fresh.cookies['__Host-rg_refresh'].value}` })

// 9. Devices: a sign-in names its browser; the list marks this one; another can be signed out.
const UA_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
const signInAs = async (userAgent, device) => {
  const res = await fetch(`${APP}/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Origin: APP, 'User-Agent': userAgent },
    body: JSON.stringify({ email: EMAIL, password: PASSWORD, device }),
  })
  return { body: await res.json(), cookies: cookiesFrom(res) }
}
const mac = await signInAs(UA_MAC, { browser: 'Chrome', os: 'macOS', timeZone: 'Europe/Lisbon', screen: { width: 1512, height: 982 }, dpr: 2 })
const other = await signInAs('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', {})
r = await s.request({ action: 'myDevices', accessToken: mac.body.accessToken })
const mine = (r.body.devices ?? []).find((d) => d.current)
const iphone = (r.body.devices ?? []).find((d) => d.label === 'Safari on iPhone')
check('myDevices marks this browser, named from its details', r.status === 200 && mine?.label === 'Chrome on macOS', JSON.stringify(mine))
check('…and lists the other one, named from its User-Agent', !!iphone && !iphone.current)
r = await s.request({ action: 'signOutDevice', accessToken: mac.body.accessToken, deviceId: iphone?.id })
check('signOutDevice → 200, not this one', r.status === 200 && r.body.current === false, String(r.status))
await sleep(300)
check("the signed-out device's token → 401", (await s.request({ action: 'myProfile', accessToken: other.body.accessToken })).status === 401)
check('…this one still works', (await s.request({ action: 'myProfile', accessToken: mac.body.accessToken })).status === 200)
r = await s.request({ action: 'signOutDevice', accessToken: mac.body.accessToken, deviceId: 999999 })
check("someone else's or an unknown device → 400", r.status === 400, String(r.status))
await post('logout', {}, { cookie: `__Host-rg_refresh=${mac.cookies['__Host-rg_refresh'].value}` })

// 10. The old session path still works (parallel until phase 5).
r = await s.request({ action: 'login', email: EMAIL, password: PASSWORD })
const hash = r.body.sessionHash
check('WS login still returns a session hash', r.status === 200 && typeof hash === 'string')
r = await s.request({ action: 'myProfile', sessionHash: hash })
check('…which still works', r.status === 200 && Number(r.body.id) === userId, String(r.status))
await s.request({ action: 'logout', sessionHash: hash })

s.close()
console.log(failures ? `${failures} FAILED` : 'ALL PASSED')
process.exit(failures ? 1 : 0)
