<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError, api, type ContentBlock, type ContentChange, type ContentLayout } from '@/api'

/**
 * The UI-text editor (docs/auth-cms-plan.md, phase 7). Left: pick a page of
 * the app and see it as a wireframe — header, the page's own blocks, the
 * shared pieces it uses, footer — every block a link. Right: the block's
 * texts in one language, each labelled by its key's last part, with the
 * English as the placeholder. Saving checks placeholders and HTML on the
 * server; every change is in the block's history and can be reverted.
 *
 * Where you are lives in the URL (`?page=discover&block=discover.deck&lang=es`),
 * so a block can be linked to a colleague.
 */
type Area = 'header' | 'main' | 'shared' | 'footer' | 'always' | 'unplaced'

const route = useRoute()
const router = useRouter()

const layout = ref<ContentLayout | null>(null)
const block = ref<ContentBlock | null>(null)
const values = reactive<Record<string, string>>({})
const loading = ref(true)
const loadingBlock = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const fieldError = reactive<{ field: string; message: string }>({ field: '', message: '' })
/** A navigation held back while there are unsaved changes. */
const pendingLeave = ref<Record<string, string> | null>(null)

const history = ref<ContentChange[] | null>(null)
const historyOpen = ref(false)
const adding = reactive({ open: false, name: '', english: '', busy: false })

const pageRoute = computed(() => String(route.query.page ?? ''))
const prefix = computed(() => String(route.query.block ?? ''))
const language = computed(() => {
  const lang = String(route.query.lang ?? 'en')
  return layout.value?.languages.includes(lang) ? lang : 'en'
})

const page = computed(
  () =>
    layout.value?.pages.find((p) => p.route === pageRoute.value) ?? layout.value?.pages[0] ?? null,
)

/** Where the open block sits, for the form's "[page] > [block]" title. */
const area = computed<Area | null>(() => {
  const l = layout.value
  const p = prefix.value
  if (!l || !p) return null
  if (l.shell.header.includes(p)) return 'header'
  if (l.shell.footer.includes(p)) return 'footer'
  if (page.value?.main.includes(p)) return 'main'
  if (page.value?.shared.includes(p)) return 'shared'
  if (l.shell.always.includes(p)) return 'always'
  return 'unplaced'
})

const areaTitle = computed(() => {
  switch (area.value) {
    case 'header':
      return 'Header'
    case 'footer':
      return 'Footer'
    case 'always':
      return 'Everywhere'
    case 'unplaced':
      return 'Other'
    default:
      return page.value?.title ?? ''
  }
})

/** `discover.deck` → `deck`, `common.app_header` → `app header`. */
function blockName(key: string) {
  return key.slice(key.indexOf('.') + 1).replace(/_/g, ' ')
}

const changed = computed(() => {
  const out: Record<string, string> = {}
  for (const entry of block.value?.entries ?? []) {
    const now = (values[entry.key] ?? '').trim()
    if (now !== (entry.value ?? '').trim()) out[entry.key] = now
  }
  return out
})
const dirty = computed(() => Object.keys(changed.value).length > 0)

function fill(next: ContentBlock) {
  block.value = next
  for (const key of Object.keys(values)) delete values[key]
  for (const entry of next.entries) values[entry.key] = entry.value ?? ''
}

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

async function loadBlock() {
  clearMessages()
  history.value = null
  historyOpen.value = false
  adding.open = false
  if (!prefix.value) {
    block.value = null
    return
  }
  loadingBlock.value = true
  try {
    fill(await api.contentBlock(prefix.value, language.value))
  } catch (err) {
    block.value = null
    report(err, 'Could not load that block.')
  } finally {
    loadingBlock.value = false
  }
}

/** Moves to another page/block/language — asking first if there are unsaved edits. */
function go(change: Record<string, string>, force = false) {
  if (dirty.value && !force && (change.block !== undefined || change.lang !== undefined)) {
    pendingLeave.value = change
    return
  }
  pendingLeave.value = null
  void router.push({ query: { ...route.query, ...change } })
}

function discardAndGo() {
  if (pendingLeave.value) go(pendingLeave.value, true)
}

async function save() {
  if (!dirty.value || saving.value) return
  clearMessages()
  saving.value = true
  try {
    const result = await api.contentSave(prefix.value, language.value, changed.value)
    fill(result)
    notice.value = result.changed === 1 ? 'Saved 1 text.' : `Saved ${result.changed} texts.`
    if (historyOpen.value) await loadHistory()
  } catch (err) {
    report(err, 'Could not save.')
  } finally {
    saving.value = false
  }
}

function reset() {
  if (block.value) fill(block.value)
  clearMessages()
}

async function addKey() {
  if (adding.busy) return
  clearMessages()
  adding.busy = true
  try {
    const result = await api.contentAddKey(
      prefix.value,
      adding.name.trim(),
      adding.english,
      language.value,
    )
    fill(result)
    notice.value = `Added ${result.key}.`
    adding.name = ''
    adding.english = ''
    adding.open = false
  } catch (err) {
    report(err, 'Could not add the key.')
  } finally {
    adding.busy = false
  }
}

async function loadHistory() {
  try {
    history.value = (await api.contentHistory(prefix.value)).history
  } catch (err) {
    report(err, 'Could not load the history.')
  }
}

function toggleHistory(event: Event) {
  historyOpen.value = (event.target as HTMLDetailsElement).open
  if (historyOpen.value && history.value === null) void loadHistory()
}

async function revert(change: ContentChange) {
  clearMessages()
  try {
    await api.contentRevert(change.id)
    notice.value = `Reverted ${change.key.slice(prefix.value.length + 1)} (${change.language}).`
    await loadHistory()
    if (change.language === language.value)
      fill(await api.contentBlock(prefix.value, language.value))
  } catch (err) {
    report(err, 'Could not revert that change.')
  }
}

function rows(text: string) {
  return Math.min(8, Math.max(1, Math.ceil(text.length / 70), text.split('\n').length))
}

function shown(value: string | null) {
  return value === null ? '(none — English shown)' : value
}

onMounted(async () => {
  try {
    layout.value = await api.contentLayout()
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Could not load the app’s layout.'
  } finally {
    loading.value = false
  }
  await loadBlock()
})

watch([prefix, language], () => void loadBlock())
</script>

<template>
  <section class="content-editor">
    <h1>Content</h1>
    <p v-if="loading" aria-busy="true" class="muted">Loading…</p>
    <p v-if="error" class="notice error" role="alert" data-test="error">{{ error }}</p>

    <div v-if="layout" class="columns">
      <!-- The app, page by page -->
      <aside class="map" aria-label="Pages and blocks">
        <label>
          Page
          <select
            :value="page?.route"
            data-test="page-picker"
            @change="go({ page: ($event.target as HTMLSelectElement).value })"
          >
            <option v-for="p in layout.pages" :key="p.route" :value="p.route">
              {{ p.title }} — {{ p.path }}{{ p.enabled ? '' : ' (off)' }}
            </option>
          </select>
        </label>

        <div class="wireframe" data-test="wireframe">
          <div class="frame header">
            <span class="frame-label">Header</span>
            <a
              v-for="key in layout.shell.header"
              :key="key"
              href="#"
              :aria-current="key === prefix ? 'true' : undefined"
              :data-block="key"
              @click.prevent="go({ block: key })"
              >{{ blockName(key) }}</a
            >
          </div>
          <div class="frame main">
            <span class="frame-label">{{ page?.title }}</span>
            <a
              v-for="key in page?.main ?? []"
              :key="key"
              href="#"
              class="block"
              :aria-current="key === prefix ? 'true' : undefined"
              :data-block="key"
              @click.prevent="go({ block: key })"
              >{{ blockName(key) }}</a
            >
            <template v-if="page?.shared.length">
              <span class="frame-label sub">Shared pieces on this page</span>
              <a
                v-for="key in page.shared"
                :key="key"
                href="#"
                class="block shared"
                :aria-current="key === prefix ? 'true' : undefined"
                :data-block="key"
                @click.prevent="go({ block: key })"
                >{{ blockName(key) }}</a
              >
            </template>
          </div>
          <div class="frame footer">
            <span class="frame-label">Footer</span>
            <a
              v-for="key in layout.shell.footer"
              :key="key"
              href="#"
              :aria-current="key === prefix ? 'true' : undefined"
              :data-block="key"
              @click.prevent="go({ block: key })"
              >{{ blockName(key) }}</a
            >
          </div>
        </div>

        <details>
          <summary>Everywhere (messages, dialogs, errors)</summary>
          <a
            v-for="key in layout.shell.always"
            :key="key"
            href="#"
            class="plain"
            :aria-current="key === prefix ? 'true' : undefined"
            :data-block="key"
            @click.prevent="go({ block: key })"
            >{{ blockName(key) }}</a
          >
        </details>
        <details v-if="layout.unplaced.length">
          <summary>Other</summary>
          <a
            v-for="key in layout.unplaced"
            :key="key"
            href="#"
            class="plain"
            :data-block="key"
            @click.prevent="go({ block: key })"
            >{{ key }}</a
          >
        </details>
      </aside>

      <!-- The open block -->
      <div class="editor">
        <p v-if="!prefix" class="muted" data-test="pick-hint">
          Pick a block on the left to edit its texts.
        </p>
        <article v-else data-test="block-form">
          <header class="form-head">
            <h2 data-test="block-title">
              {{ areaTitle }} <span aria-hidden="true">›</span> {{ blockName(prefix) }}
              <small class="muted">{{ prefix }}</small>
            </h2>
            <select
              :value="language"
              aria-label="Language"
              data-test="language"
              @change="go({ lang: ($event.target as HTMLSelectElement).value })"
            >
              <option v-for="lang in layout.languages" :key="lang" :value="lang">
                {{ lang.toUpperCase() }}
              </option>
            </select>
          </header>

          <p v-if="pendingLeave" class="notice error" role="alert" data-test="unsaved">
            You have unsaved changes here.
            <button type="button" class="secondary outline" @click="pendingLeave = null">
              Keep editing
            </button>
            <button type="button" class="contrast" data-test="discard" @click="discardAndGo">
              Discard and leave
            </button>
          </p>
          <p v-if="notice" class="notice" role="status" data-test="notice">{{ notice }}</p>
          <p v-if="loadingBlock" aria-busy="true" class="muted">Loading…</p>

          <template v-else-if="block">
            <ul
              v-if="block.missingPluralForms.length"
              class="notice error plural"
              data-test="plural-warning"
            >
              <li v-for="item in block.missingPluralForms" :key="item.base">
                <code>{{ item.base.slice(prefix.length + 1) }}</code> needs the
                {{ item.missing.join(', ') }} form{{ item.missing.length > 1 ? 's' : '' }} in
                {{ language.toUpperCase() }} — add them as new keys (<code
                  >{{ item.base.slice(prefix.length + 1) }}_{{ item.missing[0] }}</code
                >).
              </li>
            </ul>

            <form data-test="texts" @submit.prevent="save">
              <label v-for="entry in block.entries" :key="entry.key" :data-key="entry.key">
                <span class="field-name">
                  {{ entry.name }}
                  <span v-if="entry.missing && language !== 'en'" class="tag" data-test="missing">
                    not translated
                  </span>
                  <span v-if="changed[entry.key] !== undefined" class="tag edited">edited</span>
                </span>
                <textarea
                  v-model="values[entry.key]"
                  :rows="rows(values[entry.key] || entry.english)"
                  :placeholder="entry.english"
                  :aria-invalid="fieldError.field === entry.key ? 'true' : undefined"
                ></textarea>
                <small
                  v-if="fieldError.field === entry.key"
                  class="field-error"
                  data-test="field-error"
                  >{{ fieldError.message }}</small
                >
                <small v-else-if="language !== 'en'" class="muted english">
                  EN: {{ entry.english }}
                </small>
              </label>
              <p v-if="language !== 'en'" class="tiny muted">
                Leave a text empty to remove the translation — the app then shows the English.
              </p>
              <div class="buttons">
                <button type="submit" :disabled="!dirty || saving" :aria-busy="saving">
                  Save {{ language.toUpperCase() }}
                </button>
                <button type="button" class="secondary outline" :disabled="!dirty" @click="reset">
                  Undo edits
                </button>
              </div>
            </form>

            <details
              :open="adding.open"
              @toggle="adding.open = ($event.target as HTMLDetailsElement).open"
            >
              <summary>Add a text to this block</summary>
              <form data-test="add-key" @submit.prevent="addKey">
                <label>
                  Name
                  <input
                    v-model="adding.name"
                    type="text"
                    placeholder="e.g. empty_hint"
                    pattern="[a-z0-9_]{1,60}"
                    required
                    :aria-invalid="fieldError.field === 'name' ? 'true' : undefined"
                  />
                  <small v-if="fieldError.field === 'name'" class="field-error">{{
                    fieldError.message
                  }}</small>
                  <small v-else class="muted">
                    Becomes <code>{{ prefix }}.{{ adding.name || '…' }}</code
                    >. A developer still has to use it in the app.
                  </small>
                </label>
                <label>
                  English text
                  <textarea
                    v-model="adding.english"
                    rows="2"
                    required
                    :aria-invalid="fieldError.field === 'value' ? 'true' : undefined"
                  ></textarea>
                  <small v-if="fieldError.field === 'value'" class="field-error">{{
                    fieldError.message
                  }}</small>
                </label>
                <button type="submit" :disabled="adding.busy" :aria-busy="adding.busy">Add</button>
              </form>
            </details>

            <details data-test="history" @toggle="toggleHistory">
              <summary>History</summary>
              <p v-if="history === null" aria-busy="true" class="muted">Loading…</p>
              <p v-else-if="!history.length" class="muted">No changes yet.</p>
              <ul v-else class="history">
                <li v-for="change in history" :key="change.id" :data-change="change.id">
                  <div class="tiny muted">
                    {{ new Date(change.createdAt).toLocaleString() }} · {{ change.staffEmail }} ·
                    {{ change.language.toUpperCase() }} ·
                    <code>{{ change.key.slice(prefix.length + 1) }}</code>
                  </div>
                  <div class="diff">
                    <del>{{ shown(change.oldValue) }}</del>
                    <ins>{{ shown(change.newValue) }}</ins>
                  </div>
                  <button
                    v-if="!(change.oldValue === null && change.language === 'en')"
                    type="button"
                    class="secondary outline"
                    data-test="revert"
                    @click="revert(change)"
                  >
                    Revert
                  </button>
                </li>
              </ul>
            </details>
          </template>
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
    grid-template-columns: minmax(16rem, 22rem) 1fr;
    align-items: start;
  }

  .map {
    position: sticky;
    top: 1rem;
    max-height: calc(100vh - 2rem);
    overflow-y: auto;
  }
}

.wireframe {
  display: grid;
  gap: 0.5rem;
  margin-bottom: 1rem;
}

.frame {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem 0.75rem;
  padding: 0.6rem 0.75rem;
  border: 1px dashed var(--pico-muted-border-color);
  border-radius: var(--pico-border-radius);
}

.frame.main {
  display: grid;
  align-content: start;
  gap: 0.35rem;
  min-height: 12rem;
}

.frame-label {
  flex-basis: 100%;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--pico-muted-color);
}

.frame-label.sub {
  margin-top: 0.5rem;
}

.frame.main .block {
  padding: 0.4rem 0.6rem;
  border: 1px solid var(--pico-muted-border-color);
  border-radius: var(--pico-border-radius);
  text-decoration: none;
}

.frame.main .block.shared {
  border-style: dotted;
}

.map a[aria-current='true'] {
  font-weight: 700;
  text-decoration: underline;
}

.map .plain {
  display: inline-block;
  margin: 0 0.75rem 0.25rem 0;
}

.form-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
}

.form-head h2 {
  margin: 0;
  font-size: 1.25rem;
}

.form-head h2 small {
  display: block;
  font-size: 0.75rem;
  font-weight: 400;
}

.form-head select {
  width: auto;
  margin: 0;
}

.field-name {
  font-weight: 600;
}

.tag {
  margin-left: 0.5rem;
  padding: 0 0.4rem;
  border-radius: 0.5rem;
  font-size: 0.7rem;
  font-weight: 400;
  background: var(--pico-del-color);
  color: #fff;
}

.tag.edited {
  background: var(--pico-primary-background);
}

.field-error {
  color: var(--pico-del-color);
}

.english {
  display: block;
  margin-top: -0.5rem;
  margin-bottom: 1rem;
  white-space: pre-wrap;
}

textarea {
  margin-bottom: 0.25rem;
}

.buttons {
  display: flex;
  gap: 0.5rem;
}

.buttons button {
  width: auto;
  margin: 0;
}

.plural {
  padding-left: 1.5rem;
}

.history {
  list-style: none;
  padding: 0;
}

.history li {
  padding: 0.5rem 0;
  border-bottom: 1px solid var(--pico-muted-border-color);
}

.diff {
  display: grid;
  gap: 0.15rem;
  margin: 0.25rem 0;
  white-space: pre-wrap;
}

.history button {
  padding: 0.2rem 0.6rem;
  font-size: 0.8rem;
  margin: 0;
}

.notice button {
  margin: 0.25rem 0.25rem 0 0;
  padding: 0.2rem 0.6rem;
  font-size: 0.85rem;
}
</style>
