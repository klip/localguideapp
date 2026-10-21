<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError, api, type AppRoute, type PageMap, type RouteAccess } from '@/api'

/**
 * The app's routes (admins, `pages.manage`): what the app builds its router
 * from at start-up — which address shows which page, redirects, who may open
 * a page, and whether it's on. Changes reach the app on its next page load.
 * What a page contains is the app's code; its texts are edited under Content.
 *
 * - A page route names a page component from the app's code; a redirect
 *   sends its address elsewhere. Changing a page's address keeps the old one
 *   working (the server adds a redirect).
 * - "System" routes (start, log in, discover, not found) can't be removed,
 *   disabled or moved.
 * - The open route is in the URL (`?route=<id>`, `?route=new`).
 */
const ACCESS: { value: RouteAccess; label: string }[] = [
  { value: 'public', label: 'Everyone' },
  { value: 'signed_in', label: 'Signed in only (others → log in)' },
  { value: 'signed_out', label: 'Signed out only (others → start)' },
]
const ROLES = [
  { value: '', label: 'Either role' },
  { value: 'guest', label: 'Travellers' },
  { value: 'guide', label: 'Guides' },
]

const route = useRoute()
const router = useRouter()

const map = ref<PageMap | null>(null)
const loading = ref(true)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const fieldError = reactive({ field: '', message: '' })
const confirmingDelete = ref(false)

const form = reactive({
  name: '',
  kind: 'page' as 'page' | 'redirect',
  title: '',
  path: '',
  component: '',
  redirect: '',
  passParams: false,
  access: 'public' as RouteAccess,
  role: '',
  enabled: true,
  position: 0,
})

const selectedId = computed(() => String(route.query.route ?? ''))
const isNew = computed(() => selectedId.value === 'new')
const selected = computed(
  () => map.value?.routes.find((r) => String(r.id) === selectedId.value) ?? null,
)
const pathChanged = computed(
  () =>
    !!selected.value &&
    selected.value.kind === 'page' &&
    form.kind === 'page' &&
    form.path.trim() !== selected.value.path,
)

function clearMessages() {
  error.value = ''
  notice.value = ''
  fieldError.field = ''
  fieldError.message = ''
}

function report(err: unknown, fallback: string) {
  if (err instanceof ApiError && err.field) {
    fieldError.field = err.field
    fieldError.message = err.message
  } else {
    error.value = err instanceof ApiError ? err.message : fallback
  }
}

/** `aria-invalid` for a field the server refused. */
function invalid(field: string) {
  return fieldError.field === field ? 'true' : undefined
}

function fillForm() {
  confirmingDelete.value = false
  const r = selected.value
  Object.assign(form, {
    name: r?.name ?? '',
    kind: r?.kind ?? 'page',
    title: r?.title ?? '',
    path: r?.path ?? '',
    component: r?.component ?? '',
    redirect: r?.redirect ?? '',
    passParams: r?.passParams ?? false,
    access: r?.access ?? 'public',
    role: r?.role ?? '',
    enabled: r?.enabled ?? true,
    position: r?.position ?? 0,
  })
}

async function load() {
  try {
    map.value = await api.pageMap()
  } catch (err) {
    report(err, 'Could not load the routes.')
  } finally {
    loading.value = false
  }
}

function open(id: number | 'new') {
  clearMessages()
  void router.push({ query: { route: String(id) } })
}

function adopt(next: PageMap) {
  map.value = { routes: next.routes, components: next.components }
}

async function saveRoute() {
  if (busy.value) return
  clearMessages()
  busy.value = true
  try {
    const result = await api.routeSave({
      ...(isNew.value ? { name: form.name.trim() } : { id: selected.value!.id }),
      kind: form.kind,
      title: form.title.trim(),
      path: form.path.trim(),
      component: form.kind === 'page' ? form.component.trim() : null,
      redirect: form.kind === 'redirect' ? form.redirect.trim() : null,
      passParams: form.passParams,
      access: form.access,
      role: form.role === '' ? null : (form.role as 'guest' | 'guide'),
      enabled: form.enabled,
      position: Number(form.position) || 0,
    })
    const moved = pathChanged.value
    adopt(result)
    notice.value = moved
      ? 'Saved. The old address now redirects to the new one.'
      : 'Saved — live on the app’s next page load.'
    if (isNew.value) await router.replace({ query: { route: String(result.route.id) } })
    else fillForm()
  } catch (err) {
    report(err, 'Could not save the route.')
  } finally {
    busy.value = false
  }
}

async function deleteRoute() {
  if (!selected.value || busy.value) return
  clearMessages()
  busy.value = true
  try {
    const title = selected.value.title
    adopt(await api.routeDelete(selected.value.id))
    await router.replace({ query: {} })
    notice.value = `Removed “${title}”.`
  } catch (err) {
    report(err, 'Could not remove the route.')
  } finally {
    busy.value = false
    confirmingDelete.value = false
  }
}

function who(r: AppRoute) {
  const access =
    r.access === 'signed_in' ? 'signed in' : r.access === 'signed_out' ? 'signed out' : ''
  const role = r.role === 'guest' ? 'travellers' : r.role === 'guide' ? 'guides' : ''
  return [access, role].filter(Boolean).join(', ') || 'everyone'
}

onMounted(async () => {
  await load()
  fillForm()
})
watch(selectedId, () => fillForm())
</script>

<template>
  <section class="pages-editor">
    <h1>Pages</h1>
    <p class="muted">
      The app builds its routes from this list when it loads: which address shows which page,
      redirects, and who may open a page. What a page contains stays in the app’s code.
    </p>
    <p v-if="loading" aria-busy="true" class="muted">Loading…</p>
    <p v-if="error" class="notice error" role="alert" data-test="error">{{ error }}</p>
    <p v-if="notice" class="notice" role="status" data-test="notice">{{ notice }}</p>

    <div v-if="map" class="columns">
      <div class="list">
        <button
          type="button"
          class="secondary outline add"
          data-test="add-route"
          @click="open('new')"
        >
          + Add a route
        </button>
        <table class="striped" data-test="routes">
          <tbody>
            <tr
              v-for="r in map.routes"
              :key="r.id"
              :data-route="r.name"
              :aria-current="String(r.id) === selectedId ? 'true' : undefined"
              :class="{ off: !r.enabled }"
            >
              <td>
                <a href="#" @click.prevent="open(r.id)">{{ r.title }}</a>
                <span v-if="r.system" class="tag" title="The app relies on it">system</span>
                <span v-if="!r.enabled" class="tag off-tag">off</span>
                <div class="tiny muted">
                  <template v-if="r.kind === 'redirect'">
                    <code>{{ r.path }}</code> → <code>{{ r.redirect }}</code>
                  </template>
                  <template v-else>
                    <code>{{ r.path }}</code> · {{ r.component }} · {{ who(r) }}
                  </template>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="editor">
        <p v-if="!selected && !isNew" class="muted" data-test="pick-hint">
          Pick a route to change it, or add one.
        </p>

        <article v-else>
          <header>
            <h2 data-test="route-title">
              {{ isNew ? 'New route' : selected!.title }}
              <small v-if="selected" class="muted">{{ selected.name }}</small>
            </h2>
          </header>

          <form data-test="route-form" @submit.prevent="saveRoute">
            <div class="grid">
              <label>
                Title (for staff)
                <input
                  v-model="form.title"
                  type="text"
                  maxlength="80"
                  required
                  :aria-invalid="invalid('title')"
                />
                <small v-if="invalid('title')" class="field-error">{{ fieldError.message }}</small>
              </label>
              <label v-if="isNew">
                Name (permanent)
                <input
                  v-model="form.name"
                  type="text"
                  placeholder="e.g. help"
                  pattern="[a-z][a-z0-9\-]{0,59}"
                  required
                  :aria-invalid="invalid('name')"
                />
                <small v-if="invalid('name')" class="field-error">{{ fieldError.message }}</small>
              </label>
            </div>

            <fieldset v-if="!selected?.system">
              <legend>It is</legend>
              <label><input v-model="form.kind" type="radio" value="page" /> a page</label>
              <label><input v-model="form.kind" type="radio" value="redirect" /> a redirect</label>
            </fieldset>

            <label>
              Address (path)
              <input
                v-model="form.path"
                type="text"
                placeholder="/help or /guides/:id"
                required
                :disabled="selected?.system"
                data-test="path"
                :aria-invalid="invalid('path')"
              />
              <small v-if="invalid('path')" class="field-error">{{ fieldError.message }}</small>
              <small v-else-if="selected?.system" class="muted">
                The app’s code relies on this address.
              </small>
              <small v-else-if="pathChanged" class="muted" data-test="path-moved">
                The old address <code>{{ selected!.path }}</code> will redirect here.
              </small>
            </label>

            <template v-if="form.kind === 'page'">
              <label>
                Page (from the app’s code)
                <input
                  v-model="form.component"
                  type="text"
                  list="components"
                  placeholder="e.g. DiscoverPage"
                  required
                  :aria-invalid="invalid('component')"
                />
                <datalist id="components">
                  <option v-for="c in map.components" :key="c" :value="c" />
                </datalist>
                <small v-if="invalid('component')" class="field-error">{{
                  fieldError.message
                }}</small>
                <small v-else class="muted">
                  A page the app doesn’t have is skipped — developers add pages in the code.
                </small>
              </label>
              <label>
                <input v-model="form.passParams" type="checkbox" />
                Pass the address’s parameters (<code>:id</code>) to the page
              </label>
              <div class="grid">
                <label>
                  Who may open it
                  <select
                    v-model="form.access"
                    data-test="access"
                    :aria-invalid="invalid('access')"
                  >
                    <option v-for="a in ACCESS" :key="a.value" :value="a.value">
                      {{ a.label }}
                    </option>
                  </select>
                  <small v-if="invalid('access')" class="field-error">{{
                    fieldError.message
                  }}</small>
                </label>
                <label>
                  For
                  <select v-model="form.role" data-test="role" :aria-invalid="invalid('role')">
                    <option v-for="r in ROLES" :key="r.value" :value="r.value">
                      {{ r.label }}
                    </option>
                  </select>
                  <small class="muted">
                    Someone with both roles is switched; others see “not found”.
                  </small>
                </label>
              </div>
            </template>
            <label v-else>
              Redirect to
              <input
                v-model="form.redirect"
                type="text"
                placeholder="/discover"
                required
                :aria-invalid="invalid('redirect')"
              />
              <small v-if="invalid('redirect')" class="field-error">{{ fieldError.message }}</small>
            </label>

            <div class="grid">
              <label>
                <input
                  v-model="form.enabled"
                  type="checkbox"
                  role="switch"
                  :disabled="selected?.system"
                />
                On
                <small v-if="invalid('enabled')" class="field-error">{{
                  fieldError.message
                }}</small>
              </label>
              <label>
                Order in this list
                <input v-model.number="form.position" type="number" step="10" />
              </label>
            </div>

            <div class="buttons">
              <button type="submit" :disabled="busy" :aria-busy="busy">
                {{ isNew ? 'Add route' : 'Save route' }}
              </button>
              <template v-if="selected && !selected.system">
                <button
                  v-if="!confirmingDelete"
                  type="button"
                  class="secondary outline"
                  data-test="delete"
                  @click="confirmingDelete = true"
                >
                  Remove…
                </button>
                <template v-else>
                  <button
                    type="button"
                    class="contrast"
                    data-test="confirm-delete"
                    @click="deleteRoute"
                  >
                    Confirm: remove
                  </button>
                  <button type="button" class="secondary outline" @click="confirmingDelete = false">
                    Keep
                  </button>
                </template>
              </template>
            </div>
            <p v-if="selected" class="tiny muted">
              Last changed {{ new Date(selected.updatedAt).toLocaleString()
              }}{{ selected.updatedBy ? ` by ${selected.updatedBy}` : '' }}
            </p>
          </form>

          <p v-if="selected?.kind === 'page'" class="tiny muted" data-test="texts-link">
            What this page shows is the app’s code; its texts are under
            <RouterLink :to="{ path: '/content', query: { page: selected.name } }"
              >Content</RouterLink
            >.
          </p>
        </article>
      </div>
    </div>
  </section>
</template>

<style scoped>
.columns {
  display: grid;
  gap: 1.5rem;
}

@media (min-width: 960px) {
  .columns {
    grid-template-columns: minmax(18rem, 26rem) 1fr;
    align-items: start;
  }
}

.add {
  width: 100%;
  margin-bottom: 0.75rem;
}

.list table {
  margin: 0;
}

.list td {
  padding-block: 0.45rem;
}

.list tr[aria-current='true'] a {
  font-weight: 700;
}

.list tr.off a {
  color: var(--pico-muted-color);
}

.tag {
  margin-left: 0.4rem;
  padding: 0 0.4rem;
  border-radius: 0.5rem;
  font-size: 0.7rem;
  background: var(--pico-muted-border-color);
}

.off-tag {
  background: var(--pico-del-color);
  color: #fff;
}

.editor h2 small {
  display: block;
  font-size: 0.75rem;
  font-weight: 400;
}

.field-error {
  color: var(--pico-del-color);
}

fieldset label {
  display: inline-flex;
  margin-right: 1rem;
}

.buttons {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.buttons button {
  width: auto;
  margin: 0;
}
</style>
