<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter, type LocationQuery } from 'vue-router'
import { ApiError, api, type UserLocations, type UserRow, type UserSearch } from '@/api'

/**
 * Finding app users (`users.view`): one search box that understands an id
 * (`#123`), an email, a phone (any formatting), or a name, town or country
 * (a guide's base, a traveller's search area) — plus filters: role, country
 * and city (dropdowns of where users actually are), level, verified, frozen,
 * joined between. Server-side, 50 a page. Everything is in the URL, so "back"
 * from a user returns to the same results.
 */
type Sort = NonNullable<UserSearch['sort']>

const route = useRoute()
const router = useRouter()

const users = ref<UserRow[]>([])
const total = ref(0)
const pageSize = ref(50)
const loading = ref(false)
const error = ref('')

/** The form, filled from the URL. */
const form = reactive({
  q: '',
  role: '' as UserSearch['role'],
  country: '',
  city: '',
  levelRole: '' as UserSearch['levelRole'],
  levelMin: '' as number | '',
  levelMax: '' as number | '',
  verified: '' as UserSearch['verified'],
  frozen: '' as UserSearch['frozen'],
  createdFrom: '',
  createdTo: '',
})
const locations = ref<UserLocations>({ cities: [], countries: [] })
const countryName = (() => {
  try {
    const names = new Intl.DisplayNames(['en'], { type: 'region' })
    return (code: string) => names.of(code) ?? code
  } catch {
    return (code: string) => code
  }
})()
/** The cities in the chosen country (all of them with none chosen). */
const cities = computed(() =>
  locations.value.cities.filter((c) => !form.country || c.countryCode === form.country),
)
/** Countries A–Z by name. */
const countries = computed(() =>
  [...locations.value.countries].sort((a, b) =>
    countryName(a.code).localeCompare(countryName(b.code)),
  ),
)

const sort = computed(() => (String(route.query.sort ?? 'id') as Sort) || 'id')
const dir = computed(() => (route.query.dir === 'asc' ? 'asc' : 'desc'))
const page = computed(() => Math.max(1, Number(route.query.page ?? 1) || 1))
const pages = computed(() => Math.max(1, Math.ceil(total.value / pageSize.value)))
const filterCount = computed(
  () =>
    [
      form.role,
      form.country,
      form.city,
      form.levelRole,
      form.verified,
      form.frozen,
      form.createdFrom,
      form.createdTo,
    ].filter(Boolean).length,
)

function str(value: LocationQuery[string] | undefined) {
  return typeof value === 'string' ? value : ''
}

function readQuery(query: LocationQuery) {
  form.q = str(query.q)
  form.role = str(query.role) as UserSearch['role']
  form.country = str(query.country)
  form.city = str(query.city)
  form.levelRole = str(query.levelRole) as UserSearch['levelRole']
  form.levelMin =
    query.levelMin !== undefined && str(query.levelMin) !== '' ? Number(query.levelMin) : ''
  form.levelMax =
    query.levelMax !== undefined && str(query.levelMax) !== '' ? Number(query.levelMax) : ''
  form.verified = str(query.verified) as UserSearch['verified']
  form.frozen = str(query.frozen) as UserSearch['frozen']
  form.createdFrom = str(query.createdFrom)
  form.createdTo = str(query.createdTo)
}

/** The form as a URL query (empty values left out). */
function toQuery(extra: Record<string, string | number> = {}) {
  const query: Record<string, string> = {}
  const set = (key: string, value: unknown) => {
    if (value !== '' && value !== null && value !== undefined) query[key] = String(value)
  }
  set('q', form.q.trim())
  set('role', form.role)
  set('country', form.country)
  set('city', form.city)
  set('levelRole', form.levelRole)
  if (form.levelRole) {
    set('levelMin', form.levelMin)
    set('levelMax', form.levelMax)
  }
  set('verified', form.verified)
  set('frozen', form.frozen)
  set('createdFrom', form.createdFrom)
  set('createdTo', form.createdTo)
  if (sort.value !== 'id') set('sort', sort.value)
  if (dir.value !== 'desc') set('dir', dir.value)
  for (const [key, value] of Object.entries(extra)) set(key, value)
  return query
}

async function load() {
  readQuery(route.query)
  loading.value = true
  error.value = ''
  try {
    const result = await api.searchUsers({
      q: form.q,
      role: form.role,
      country: form.country,
      city: form.city,
      levelRole: form.levelRole,
      levelMin: form.levelMin,
      levelMax: form.levelMax,
      verified: form.verified,
      frozen: form.frozen,
      createdFrom: form.createdFrom,
      createdTo: form.createdTo,
      sort: sort.value,
      dir: dir.value,
      page: page.value,
    })
    users.value = result.users
    total.value = result.total
    pageSize.value = result.pageSize
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Could not search.'
  } finally {
    loading.value = false
  }
}

/** Runs the search the form describes, from page 1. */
/** Runs the search the form describes, from page 1 — now (Enter) rather than after the pause. */
function search() {
  clearTimeout(typingTimer)
  const query = toQuery()
  // Unchanged apart from the page means nothing to do (e.g. the form was
  // just filled from the URL on page 3); a real change starts from page 1.
  const current: Record<string, unknown> = { ...route.query }
  delete current.page
  if (JSON.stringify(sorted(query)) !== JSON.stringify(sorted(current)))
    void router.replace({ query })
}

function sorted(query: Record<string, unknown>) {
  return Object.fromEntries(Object.entries(query).sort(([a], [b]) => a.localeCompare(b)))
}

/**
 * No Search or Apply button: any change searches — typing after a short
 * pause, a dropdown, number or date at once. The URL is replaced, not
 * pushed, so "back" leaves the list instead of stepping through keystrokes.
 * (Filling the form from the URL changes nothing, so it doesn't loop.)
 */
const TYPING_PAUSE_MS = 350
let typingTimer: ReturnType<typeof setTimeout> | undefined
watch(
  () => ({ ...form }),
  (now, before) => {
    clearTimeout(typingTimer)
    if (now.q !== before.q) typingTimer = setTimeout(search, TYPING_PAUSE_MS)
    else search()
  },
)

function clearFilters() {
  Object.assign(form, {
    role: '',
    country: '',
    city: '',
    levelRole: '',
    levelMin: '',
    levelMax: '',
    verified: '',
    frozen: '',
    createdFrom: '',
    createdTo: '',
  })
  search()
}

function sortBy(column: Sort) {
  const nextDir =
    sort.value === column
      ? dir.value === 'asc'
        ? 'desc'
        : 'asc'
      : column === 'name' || column === 'email'
        ? 'asc'
        : 'desc'
  void router.push({ query: { ...toQuery(), sort: column, dir: nextDir } })
}

function goPage(n: number) {
  void router.push({ query: toQuery(n > 1 ? { page: n } : {}) })
}

function ariaSort(column: Sort) {
  if (sort.value !== column) return undefined
  return dir.value === 'asc' ? 'ascending' : 'descending'
}

/** A different country drops a city that isn't in it. */
function onCountry() {
  if (form.city && !form.city.startsWith(`${form.country}|`)) form.city = ''
}

function initial(user: UserRow) {
  return (user.name || user.email).charAt(0).toUpperCase()
}

function status(user: UserRow) {
  if (user.frozen)
    return user.frozenUntil
      ? `Frozen until ${new Date(user.frozenUntil).toLocaleDateString()}`
      : 'Frozen permanently'
  return user.verified ? 'Verified' : 'Active'
}

const ROLE_LABEL = { guest: 'Traveller', guide: 'Guide' } as const

watch(() => route.query, load, { immediate: true })

// The cities and countries users are in, for the two dropdowns.
api
  .userLocations()
  .then((result) => (locations.value = result))
  .catch(() => {})
</script>

<template>
  <section class="users">
    <h1>Users</h1>

    <form class="search" role="search" data-test="search" @submit.prevent="search">
      <input
        v-model="form.q"
        type="search"
        placeholder="#id, email, phone, name, town or country"
        aria-label="Search users"
        data-test="q"
      />
    </form>

    <details class="filters" :open="filterCount > 0">
      <summary>
        Filters<span v-if="filterCount" class="count"> · {{ filterCount }} on</span>
      </summary>
      <form data-test="filters" @submit.prevent="search">
        <div class="grid">
          <label>
            Role
            <select v-model="form.role" data-test="role">
              <option value="">Any</option>
              <option value="guest">Travellers</option>
              <option value="guide">Guides</option>
              <option value="both">Both roles</option>
            </select>
          </label>
          <label>
            Verified
            <select v-model="form.verified">
              <option value="">Any</option>
              <option value="yes">Verified</option>
              <option value="no">Not verified</option>
            </select>
          </label>
          <label>
            Frozen
            <select v-model="form.frozen" data-test="frozen">
              <option value="">Any</option>
              <option value="yes">Frozen</option>
              <option value="no">Not frozen</option>
            </select>
          </label>
        </div>
        <div class="grid">
          <label>
            Level as
            <select v-model="form.levelRole">
              <option value="">—</option>
              <option value="guest">Traveller</option>
              <option value="guide">Guide</option>
            </select>
          </label>
          <label>
            From
            <input
              v-model.number="form.levelMin"
              type="number"
              min="0"
              max="7"
              :disabled="!form.levelRole"
            />
          </label>
          <label>
            To
            <input
              v-model.number="form.levelMax"
              type="number"
              min="0"
              max="7"
              :disabled="!form.levelRole"
            />
          </label>
        </div>
        <div class="grid">
          <label>
            Country
            <select v-model="form.country" data-test="country" @change="onCountry">
              <option value="">Any</option>
              <option v-for="c in countries" :key="c.code" :value="c.code">
                {{ countryName(c.code) }} ({{ c.users }})
              </option>
            </select>
          </label>
          <label>
            City (a guide’s base or a traveller’s area)
            <select v-model="form.city" data-test="city">
              <option value="">Any</option>
              <option v-for="c in cities" :key="c.value" :value="c.value">
                {{ c.city }}{{ form.country ? '' : `, ${countryName(c.countryCode)}` }} ({{
                  c.users
                }})
              </option>
            </select>
          </label>
        </div>
        <div class="grid">
          <label>
            Joined from
            <input v-model="form.createdFrom" type="date" />
          </label>
          <label>
            Joined until
            <input v-model="form.createdTo" type="date" />
          </label>
        </div>
        <div class="buttons">
          <button
            type="button"
            class="secondary outline"
            :disabled="!filterCount"
            @click="clearFilters"
          >
            Clear filters
          </button>
        </div>
      </form>
    </details>

    <p v-if="error" class="notice error" role="alert" data-test="error">{{ error }}</p>
    <p class="tiny muted" data-test="total" aria-live="polite">
      <span v-if="loading" aria-busy="true">Searching…</span>
      <template v-else>{{ total === 1 ? '1 user' : `${total} users` }}</template>
    </p>

    <div class="overflow-auto">
      <table class="striped" data-test="results">
        <thead>
          <tr>
            <th :aria-sort="ariaSort('id')">
              <button type="button" class="link" @click="sortBy('id')">#</button>
            </th>
            <th></th>
            <th :aria-sort="ariaSort('name')">
              <button type="button" class="link" data-test="sort-name" @click="sortBy('name')">
                Name
              </button>
              /
              <button type="button" class="link" @click="sortBy('email')">email</button>
            </th>
            <th>Roles</th>
            <th :aria-sort="ariaSort('guestLevel')">
              <button type="button" class="link" @click="sortBy('guestLevel')">
                Traveller lvl
              </button>
            </th>
            <th :aria-sort="ariaSort('guideLevel')">
              <button type="button" class="link" @click="sortBy('guideLevel')">Guide lvl</button>
            </th>
            <th>Location</th>
            <th :aria-sort="ariaSort('created')">
              <button type="button" class="link" @click="sortBy('created')">Joined</button>
            </th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="user in users"
            :key="user.id"
            :data-user="user.id"
            :class="{ frozen: user.frozen }"
          >
            <td class="tiny muted">{{ user.id }}</td>
            <td>
              <img v-if="user.image" :src="user.image" alt="" class="avatar" />
              <span v-else class="avatar initial" aria-hidden="true">{{ initial(user) }}</span>
            </td>
            <td>
              <RouterLink :to="`/users/${user.id}`">{{ user.name || '(no name)' }}</RouterLink>
              <div class="tiny muted">{{ user.email }}</div>
            </td>
            <td class="tiny">{{ user.roles.map((r) => ROLE_LABEL[r]).join(', ') || '—' }}</td>
            <td>{{ user.levels.guest ?? '—' }}</td>
            <td>{{ user.levels.guide ?? '—' }}</td>
            <td class="tiny">{{ user.location ?? '—' }}</td>
            <td class="tiny">{{ new Date(user.createdAt).toLocaleDateString() }}</td>
            <td class="tiny">
              <span :class="{ 'status-frozen': user.frozen }">{{ status(user) }}</span>
            </td>
          </tr>
          <tr v-if="!loading && !users.length">
            <td colspan="9" class="muted">Nobody matches.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <nav v-if="pages > 1" class="pager" aria-label="Pages of results">
      <button
        type="button"
        class="secondary outline"
        :disabled="page <= 1"
        @click="goPage(page - 1)"
      >
        ← Previous
      </button>
      <span class="tiny muted">Page {{ page }} of {{ pages }}</span>
      <button
        type="button"
        class="secondary outline"
        :disabled="page >= pages"
        data-test="next"
        @click="goPage(page + 1)"
      >
        Next →
      </button>
    </nav>
  </section>
</template>

<style scoped>
.search {
  display: grid;
  grid-template-columns: 1fr;
  gap: 0.5rem;
}

.search > * {
  margin: 0;
}

.filters {
  margin: 1rem 0;
}

.filters .count {
  color: var(--pico-primary);
}

.buttons {
  display: flex;
  gap: 0.5rem;
}

.buttons button {
  width: auto;
}

button.link {
  padding: 0;
  margin: 0;
  border: 0;
  background: none;
  color: var(--pico-primary);
  font: inherit;
  width: auto;
}

th button.link {
  color: inherit;
  font-weight: 600;
}

th[aria-sort] button.link {
  text-decoration: underline;
}

.avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  border-radius: 50%;
  object-fit: cover;
}

.avatar.initial {
  background: var(--pico-muted-border-color);
  font-weight: 600;
}

tr.frozen td {
  opacity: 0.75;
}

.status-frozen {
  color: var(--pico-del-color);
  font-weight: 600;
}

.pager {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  margin-top: 1rem;
}

.pager button {
  width: auto;
  margin: 0;
}
</style>
