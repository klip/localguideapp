<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import PanelCard from '@/components/PanelCard.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import { useCurrentPosition } from '@/composables/useCurrentPosition'
import { useMediaQuery, LG_QUERY } from '@/composables/useMediaQuery'
import { t, tn } from '@/i18n'
import { ApiError, useApi, type ChatMessage, type Conversation, type ChatThread } from '@/plugins/api'
import { socket } from '@/plugins/socket'
import { useAuthStore } from '@/stores/auth'
import { reportApiError } from '@/utils/reportApiError'
import { detectMapsPlatform, mapsLinksFor, type MapsLink } from '@/utils/mapsLink'

/**
 * Messages — direct chat with the people you're matched with, for both roles
 * (`Messages.php`). The point is that a trip can be arranged here instead of
 * by swapping phone numbers: contact details in a message arrive as [•••]
 * (`ContactFilter`), which the banner explains.
 *
 * `/messages` lists conversations; `/messages/:id` opens the one with user
 * `id` (side by side from `lg` up, one at a time below). The socket keeps it
 * live without refetching: `message` pushes are appended to the open
 * conversation (marked read while the tab is visible, or once it's shown
 * again) and patched into the list; `messages_read` moves the "Seen" marker.
 */
const props = defineProps<{ id?: string }>()

const api = useApi()
const auth = useAuthStore()
const router = useRouter()
const desktop = useMediaQuery(LG_QUERY)
// Enter sends only with a real keyboard; on a touchscreen it's a new line.
const finePointer = useMediaQuery('(pointer: fine)')

const conversations = ref<Conversation[]>([])
const listLoaded = ref(false)
const thread = ref<ChatThread | null>(null)
const threadLoading = ref(false)
const loadingOlder = ref(false)
const draft = ref('')
const sending = ref(false)
const logRef = ref<HTMLElement | null>(null)
const composerRef = ref<HTMLTextAreaElement | null>(null)

const MAX_LENGTH = 2000
const openId = computed(() => {
  const id = Number(props.id)
  return Number.isInteger(id) && id > 0 ? id : null
})
const showList = computed(() => desktop.value || openId.value === null)
const showThread = computed(() => openId.value !== null)
const remaining = computed(() => MAX_LENGTH - Array.from(draft.value).length)

async function loadConversations() {
  if (!auth.user) return
  try {
    conversations.value = (await api.conversations()).conversations
  } catch (err) {
    reportApiError(err, t('messages.toast.load_failed'))
  } finally {
    listLoaded.value = true
  }
}

/**
 * Bumped per conversation load, so a slow response for a conversation the
 * user already left is dropped rather than shown under another one.
 */
let threadRequest = 0
/** Pushes that arrive while the open conversation is loading, merged in after. */
let pendingPushes: ChatMessage[] = []

async function loadThread(id: number) {
  const request = ++threadRequest
  threadLoading.value = true
  thread.value = null
  pendingPushes = []
  try {
    const loaded = await api.messages({ targetId: id })
    if (request !== threadRequest) return
    thread.value = loaded
    clearUnread(id)
    const buffered = pendingPushes
    pendingPushes = []
    buffered.forEach(receive)
    await scrollToEnd()
  } catch (err) {
    if (request === threadRequest) reportApiError(err, t('messages.toast.thread_failed'))
  } finally {
    if (request === threadRequest) threadLoading.value = false
  }
}

async function loadOlder() {
  const current = thread.value
  const id = openId.value
  if (!current || !id || loadingOlder.value || !current.messages.length) return
  loadingOlder.value = true
  const request = threadRequest
  const log = logRef.value
  const fromBottom = log ? log.scrollHeight - log.scrollTop : 0
  try {
    const older = await api.messages({ targetId: id, beforeId: current.messages[0]!.id })
    if (request !== threadRequest || thread.value !== current) return
    current.messages = [...older.messages, ...current.messages]
    current.has_more = older.has_more
    await nextTick()
    // Keep the message the reader was looking at in place.
    if (log) log.scrollTop = log.scrollHeight - fromBottom
  } catch (err) {
    reportApiError(err, t('messages.toast.thread_failed'))
  } finally {
    loadingOlder.value = false
  }
}

async function send() {
  const body = draft.value.trim()
  const id = openId.value
  if (!body || !id || sending.value || remaining.value < 0) return
  sending.value = true
  try {
    const message = await api.sendMessage({ targetId: id, body })
    draft.value = ''
    receive(message)
  } catch (err) {
    reportApiError(err, t('messages.toast.send_failed'))
    // Refused — most likely the match is gone; reload to show it read-only.
    if (err instanceof ApiError && err.status === 400) void loadThread(id)
  } finally {
    sending.value = false
    composerRef.value?.focus()
  }
}

/**
 * "Share my location": asks first (where you are is personal), then reads
 * the device's position once and sends it as a `location` message — no
 * tracking, nothing kept running afterwards.
 */
const confirmingLocation = ref(false)
/**
 * The position request (`useCurrentPosition`). Leaving the conversation (or
 * the page) cancels it, so a permission prompt answered later never sends
 * your position to someone you've moved away from.
 */
const position = useCurrentPosition()
const sendingLocation = ref(false)
/** Bumped on every share and every cancel: a send that returns after either is ignored. */
let shareRequest = 0
const locating = computed(() => position.locating.value || sendingLocation.value)

function cancelLocation() {
  shareRequest++
  position.cancel()
  sendingLocation.value = false
  confirmingLocation.value = false
}

async function shareLocation() {
  const id = openId.value
  confirmingLocation.value = false
  // The confirm panel (which had focus) is gone; keep focus in the composer.
  void nextTick(() => composerRef.value?.focus())
  if (!id || locating.value) return

  const request = ++shareRequest
  const result = await position.locate()
  if (!result || request !== shareRequest || openId.value !== id) return
  if (!result.ok) {
    reportApiError(null, t(`messages.location.${result.reason}`))
    return
  }
  sendingLocation.value = true
  try {
    const { latitude, longitude, accuracy } = result.position
    const message = await api.shareLocation({ targetId: id, latitude, longitude, ...(accuracy !== null ? { accuracy } : {}) })
    // Sent either way; only shown here if this conversation is still the one open.
    if (request === shareRequest) receive(message)
  } catch (err) {
    reportApiError(err, t('messages.toast.location_failed'))
  } finally {
    if (request === shareRequest) sendingLocation.value = false
  }
}

/** Links for a shared point, the viewer's own device's maps app first. */
const mapsPlatform = detectMapsPlatform()
function locationLinks(message: ChatMessage): MapsLink[] {
  if (!message.location) return []
  const label = message.mine ? undefined : displayName(thread.value?.counterpart.name ?? null, thread.value?.counterpart.role)
  return mapsLinksFor(message.location.latitude, message.location.longitude, mapsPlatform, label)
}

function onComposerKeydown(event: KeyboardEvent) {
  // Enter sends, Shift+Enter is a new line; never while an IME is composing.
  if (finePointer.value && event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
    event.preventDefault()
    void send()
  }
}

async function scrollToEnd() {
  await nextTick()
  const log = logRef.value
  if (log) log.scrollTop = log.scrollHeight
}

function clearUnread(id: number) {
  const row = conversations.value.find((c) => c.counterpart_id === id)
  if (row) row.unread = 0
}

/**
 * A message — my own send, or any push — shown where it belongs: appended
 * to the open conversation (once, by id) and moved to the top of the list.
 */
function receive(message: ChatMessage) {
  const otherId = message.mine ? message.recipient_id : message.sender_id
  const isOpen = otherId === openId.value

  if (isOpen && !thread.value) {
    pendingPushes.push(message)
    return
  }
  if (isOpen && thread.value && !thread.value.messages.some((m) => m.id === message.id)) {
    thread.value.messages.push(message)
    void scrollToEnd()
    if (!message.mine) {
      if (document.visibilityState === 'visible') markRead(otherId, message.id)
      else unreadUpTo = message.id
    }
  }

  const index = conversations.value.findIndex((c) => c.counterpart_id === otherId)
  if (index === -1) {
    // Someone not in the list yet (a brand-new match): fetch it once.
    void loadConversations()
    return
  }
  const row = conversations.value[index]!
  if (row.last_message?.id === message.id) return
  row.last_message = {
    id: message.id,
    mine: message.mine,
    kind: message.kind,
    body: message.body,
    created_at: message.created_at,
  }
  if (!message.mine && !isOpen) row.unread += 1
  conversations.value.splice(index, 1)
  conversations.value.unshift(row)
}

/**
 * The newest message that arrived in the open conversation while the tab
 * was hidden — left unread until the tab is shown again, since nobody has
 * seen it yet.
 */
let unreadUpTo = 0

function markRead(otherId: number, upToId: number) {
  unreadUpTo = 0
  clearUnread(otherId)
  void api.markMessagesRead({ targetId: otherId, upToId }).catch(() => {})
}

function onVisibilityChange() {
  if (document.visibilityState === 'visible' && unreadUpTo && openId.value) markRead(openId.value, unreadUpTo)
}
document.addEventListener('visibilitychange', onVisibilityChange)

const stopMessages = socket.on('message', (payload) => {
  const message = payload.message as ChatMessage | undefined
  if (message) receive(message)
})

/** The other side read my messages up to `up_to_id` — move "Seen". */
const stopReads = socket.on('messages_read', (payload) => {
  const current = thread.value
  const readerId = Number(payload.reader_id)
  const upTo = Number(payload.up_to_id)
  if (!current || readerId !== current.counterpart.id) return
  const readAt = String(payload.read_at || new Date().toISOString())
  for (const m of current.messages) {
    if (m.mine && m.id <= upTo && !m.read_at) m.read_at = readAt
  }
})

onBeforeUnmount(() => {
  cancelLocation()
  stopMessages()
  stopReads()
  document.removeEventListener('visibilitychange', onVisibilityChange)
})

onMounted(() => {
  void loadConversations()
  if (openId.value) void loadThread(openId.value)
})
watch(openId, (id) => {
  draft.value = ''
  unreadUpTo = 0
  cancelLocation()
  if (id) {
    void loadThread(id)
  } else {
    threadRequest++
    thread.value = null
  }
})

function displayName(name: string | null, role?: string | null) {
  return name || t(role === 'guide' ? 'messages.thread.unnamed_guide' : 'messages.thread.unnamed_visitor')
}

function photo(image: string | null, role?: string | null) {
  if (image) return `url("${image}")`
  return role === 'guide' ? 'var(--rg-photo-guide)' : 'var(--rg-photo-visitor)'
}

function initial(name: string | null, role?: string | null) {
  return displayName(name, role).trim().charAt(0).toUpperCase()
}

function profileLink(id: number, role?: string | null) {
  return role === 'guide' ? `/guides/${id}` : `/visitors/${id}`
}

const timeFormat = computed(() => new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' }))
const dayFormat = computed(() => new Intl.DateTimeFormat(undefined, { weekday: 'short', day: 'numeric', month: 'short' }))

function timeOf(iso: string) {
  return timeFormat.value.format(new Date(iso))
}

function dayOf(iso: string) {
  return dayFormat.value.format(new Date(iso))
}

/** A day heading before the first message of each day. */
function startsDay(index: number) {
  const messages = thread.value?.messages ?? []
  if (index === 0) return true
  return new Date(messages[index - 1]!.created_at).toDateString() !== new Date(messages[index]!.created_at).toDateString()
}

function preview(conversation: Conversation) {
  const last = conversation.last_message
  if (!last) return t('messages.list.say_hello')
  const text = last.kind === 'location' ? t('messages.list.location') : last.body
  return last.mine ? t('messages.list.you', { text }) : text
}

/** The last of my messages the other side has read — "Seen" goes under it. */
const lastSeenId = computed(() => {
  const mine = (thread.value?.messages ?? []).filter((m) => m.mine && m.read_at)
  return mine.length ? mine[mine.length - 1]!.id : null
})

function backToList() {
  router.push('/messages')
}
</script>

<template>
  <section class="section shell">
    <SectionTitle
      :eyebrow="t('messages.header.eyebrow')"
      :heading="t('messages.header.heading')"
      :lead="t('messages.header.lead')"
    />

    <PanelCard v-if="!auth.isAuthenticated" class="narrow" :title="t('messages.signed_out.title')">
      <p class="muted">{{ t('messages.signed_out.body') }}</p>
      <RouterLink to="/login" role="button">{{ t('messages.signed_out.log_in') }}</RouterLink>
    </PanelCard>

    <div v-else class="chat-layout" :class="{ 'chat-layout--thread': showThread }">
      <PanelCard v-if="showList" class="conversation-panel">
        <p v-if="!listLoaded" class="muted empty">{{ t('messages.list.loading') }}</p>
        <div v-else-if="!conversations.length" class="empty">
          <p class="muted">{{ t('messages.list.empty') }}</p>
          <RouterLink to="/matches" class="soft-btn" role="button">{{ t('messages.list.go_to_matches') }}</RouterLink>
        </div>
        <ul v-else class="conversation-list">
          <li v-for="conversation in conversations" :key="conversation.counterpart_id">
            <RouterLink
              :to="`/messages/${conversation.counterpart_id}`"
              class="conversation"
              :class="{ 'conversation--open': conversation.counterpart_id === openId }"
              :aria-current="conversation.counterpart_id === openId ? 'page' : undefined"
            >
              <span
                class="avatar"
                :style="{ backgroundImage: photo(conversation.image, conversation.role) }"
                aria-hidden="true"
              >
                <span v-if="!conversation.image">{{ initial(conversation.name, conversation.role) }}</span>
              </span>
              <span class="conversation-text">
                <strong>{{ displayName(conversation.name, conversation.role) }}</strong>
                <span class="tiny muted preview">{{ preview(conversation) }}</span>
              </span>
              <span v-if="conversation.unread" class="unread" data-test="unread">
                <span aria-hidden="true">{{ conversation.unread > 99 ? '99+' : conversation.unread }}</span>
                <span class="visually-hidden">{{ tn('messages.list.unread', conversation.unread) }}</span>
              </span>
            </RouterLink>
          </li>
        </ul>
      </PanelCard>

      <PanelCard v-if="showThread" class="thread-panel">
        <template #header>
          <div class="thread-head">
            <button v-if="!desktop" type="button" class="back" @click="backToList">
              <span aria-hidden="true">←</span>
              <span class="visually-hidden">{{ t('messages.thread.back') }}</span>
            </button>
            <template v-if="thread">
              <span
                class="avatar small"
                :style="{ backgroundImage: photo(thread.counterpart.image, thread.counterpart.role) }"
                aria-hidden="true"
              >
                <span v-if="!thread.counterpart.image">{{
                  initial(thread.counterpart.name, thread.counterpart.role)
                }}</span>
              </span>
              <RouterLink
                :to="profileLink(thread.counterpart.id, thread.counterpart.role)"
                class="who-name"
              >
                <strong>{{ displayName(thread.counterpart.name, thread.counterpart.role) }}</strong>
              </RouterLink>
            </template>
          </div>
        </template>

        <p class="stay-banner tiny">{{ t('messages.thread.stay_banner') }}</p>

        <p v-if="threadLoading" class="muted empty">{{ t('messages.thread.loading') }}</p>
        <template v-else-if="thread">
          <div
            ref="logRef"
            class="log"
            role="log"
            aria-live="polite"
            :aria-busy="loadingOlder || undefined"
            :aria-label="t('messages.thread.log_label')"
          >
            <button
              v-if="thread.has_more"
              type="button"
              class="soft-btn older"
              :aria-busy="loadingOlder"
              @click="loadOlder"
            >
              {{ t('messages.thread.load_older') }}
            </button>
            <p v-if="!thread.messages.length" class="muted empty">{{ t('messages.thread.empty') }}</p>
            <template v-for="(message, index) in thread.messages" :key="message.id">
              <p v-if="startsDay(index)" class="day tiny muted">{{ dayOf(message.created_at) }}</p>
              <div class="bubble-row" :class="{ 'bubble-row--mine': message.mine }">
                <div class="bubble">
                  <template v-if="message.kind === 'location' && message.location">
                    <span class="body location-title"><span aria-hidden="true">📍 </span>{{ t('messages.location.title') }}</span>
                    <span v-if="message.location.accuracy_m" class="tiny muted">{{
                      t('messages.location.accuracy', { meters: message.location.accuracy_m })
                    }}</span>
                    <span class="location-links">
                      <a
                        v-for="(link, index) in locationLinks(message)"
                        :key="link.provider"
                        :href="link.href"
                        :class="{ primary: index === 0 }"
                        :target="link.provider === 'geo' ? undefined : '_blank'"
                        rel="noopener noreferrer"
                        :data-provider="link.provider"
                        >{{ t(`messages.location.open_${link.provider}`) }}</a
                      >
                    </span>
                  </template>
                  <span v-else class="body">{{ message.body }}</span>
                  <span class="meta">
                    <time :datetime="message.created_at">{{ timeOf(message.created_at) }}</time>
                    <template v-if="message.id === lastSeenId"> · {{ t('messages.thread.seen') }}</template>
                  </span>
                </div>
              </div>
            </template>
          </div>

          <form v-if="thread.can_send" class="composer" @submit.prevent="send">
            <label class="visually-hidden" for="composer">{{ t('messages.thread.composer_label') }}</label>
            <textarea
              id="composer"
              ref="composerRef"
              v-model="draft"
              rows="2"
              :placeholder="t('messages.thread.placeholder')"
              :aria-invalid="remaining < 0 || undefined"
              :aria-describedby="finePointer ? 'composer-hint' : undefined"
              @keydown="onComposerKeydown"
            />
            <div class="composer-actions">
              <button
                type="button"
                class="soft-btn location-btn"
                :aria-busy="locating || undefined"
                :disabled="locating"
                :aria-expanded="confirmingLocation"
                :aria-controls="confirmingLocation ? 'location-confirm' : undefined"
                @click="confirmingLocation = !confirmingLocation"
              >
                <span aria-hidden="true">📍</span>
                {{ locating ? t('messages.location.locating') : t('messages.location.share') }}
              </button>
              <small v-if="remaining < 200" class="muted count" :class="{ over: remaining < 0 }">{{ remaining }}</small>
              <button type="submit" :aria-busy="sending" :disabled="!draft.trim() || remaining < 0">
                {{ t('messages.thread.send') }}
              </button>
            </div>
            <small v-if="finePointer" id="composer-hint" class="muted hint">{{ t('messages.thread.enter_hint') }}</small>
            <div v-if="confirmingLocation" id="location-confirm" class="location-confirm">
              <p class="tiny">
                {{
                  t('messages.location.confirm', {
                    name: displayName(thread.counterpart.name, thread.counterpart.role),
                  })
                }}
              </p>
              <div class="confirm-actions">
                <button type="button" class="soft-btn" @click="confirmingLocation = false">
                  {{ t('messages.location.cancel') }}
                </button>
                <button type="button" data-test="send-location" @click="shareLocation">
                  {{ t('messages.location.send') }}
                </button>
              </div>
            </div>
          </form>
          <p v-else class="muted tiny closed">{{ t('messages.thread.closed') }}</p>
        </template>
      </PanelCard>

      <PanelCard v-else-if="desktop" class="thread-panel thread-panel--placeholder">
        <p class="muted empty">{{ t('messages.thread.pick') }}</p>
      </PanelCard>
    </div>
  </section>
</template>

<style scoped lang="scss">
@use 'breakpoints' as bp;

.chat-layout {
  display: grid;
  gap: 1rem;

  @include bp.lg {
    grid-template-columns: minmax(16rem, 22rem) minmax(0, 1fr);
    align-items: start;
  }
}

.empty {
  text-align: center;
  padding: 1.5rem 0.5rem;
  margin: 0;

  [role='button'] {
    width: auto;
  }
}

.conversation-list {
  list-style: none;
  padding: 0;
  margin: 0;

  > li {
    list-style: none;
    margin: 0;
  }
}

.conversation {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  padding: 0.6rem 0.5rem;
  border-radius: var(--rg-radius-soft);
  color: inherit;
  text-decoration: none;

  &:hover,
  &:focus-visible {
    background: var(--rg-orange-50);
  }
}

.conversation--open {
  background: var(--rg-orange-100);
}

.conversation-text {
  min-width: 0;
  flex: 1;
  display: flex;
  flex-direction: column;

  strong {
    overflow-wrap: anywhere;
  }
}

.preview {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.unread {
  flex-shrink: 0;
  min-width: 1.4rem;
  padding: 0 0.4rem;
  border-radius: 999px;
  background: var(--rg-red);
  color: #fff;
  font-size: 0.75rem;
  font-weight: 700;
  line-height: 1.4rem;
  text-align: center;
}

.avatar {
  flex-shrink: 0;
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 50%;
  background-size: cover;
  background-position: center;
  display: grid;
  place-items: center;
  color: #fff;
  font-weight: 800;

  &.small {
    width: 2.25rem;
    height: 2.25rem;
    font-size: 0.9rem;
  }
}

.thread-head {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  min-width: 0;
}

.back {
  width: auto;
  margin: 0;
  padding: 0.3rem 0.6rem;
  border: 1px solid var(--rg-line);
  background: #fff;
  color: var(--rg-ink);
}

.who-name {
  color: inherit;
  text-decoration: none;
  overflow-wrap: anywhere;

  &:hover,
  &:focus-visible {
    text-decoration: underline;
  }
}

.stay-banner {
  margin: 0 0 0.75rem;
  padding: 0.5rem 0.75rem;
  border-radius: var(--rg-radius-soft);
  background: var(--rg-green-soft);
  border: 1px solid var(--rg-green-border);
}

.log {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  max-height: min(60vh, 32rem);
  min-height: 12rem;
  overflow-y: auto;
  padding: 0.25rem 0.1rem 0.5rem;
  overscroll-behavior: contain;
}

.older {
  align-self: center;
  width: auto;
  margin-bottom: 0.5rem;
}

.day {
  align-self: center;
  margin: 0.5rem 0 0.25rem;
}

.bubble-row {
  display: flex;
}

.bubble-row--mine {
  justify-content: flex-end;
}

.bubble {
  max-width: min(80%, 32rem);
  padding: 0.5rem 0.75rem;
  border-radius: 1rem 1rem 1rem 0.3rem;
  background: var(--rg-cream);
  border: 1px solid var(--rg-line);
  display: flex;
  flex-direction: column;
  gap: 0.15rem;

  .bubble-row--mine & {
    border-radius: 1rem 1rem 0.3rem 1rem;
    background: var(--rg-orange-100);
    border-color: var(--rg-orange-200);
  }
}

.body {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.meta {
  align-self: flex-end;
  font-size: 0.7rem;
  color: var(--rg-muted);
}

.composer {
  margin: 0.75rem 0 0;
  padding-top: 0.75rem;
  border-top: 1px solid var(--rg-line);

  textarea {
    margin-bottom: 0.5rem;
    resize: vertical;
  }
}

.composer-actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.5rem 0.75rem;

  button {
    width: auto;
    margin: 0;
  }

  // The location button on the left, the counter and Send on the right.
  .location-btn {
    margin-right: auto;
  }

  .over {
    color: var(--rg-red);
  }
}

.hint {
  display: block;
  margin-top: 0.4rem;
}

.confirm-actions {
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 0.5rem;

  button {
    width: auto;
    margin: 0;
  }
}

.location-title {
  font-weight: 600;
}

.location-links {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem 0.75rem;
  font-size: 0.85rem;

  .primary {
    font-weight: 700;
  }
}

.location-btn {
  white-space: nowrap;
}

.location-confirm {
  margin-top: 0.6rem;
  padding: 0.6rem 0.75rem;
  border-radius: var(--rg-radius-soft);
  background: var(--rg-orange-50);
  border: 1px solid var(--rg-orange-200);

  p {
    margin: 0 0 0.5rem;
  }
}

.closed {
  margin: 0.75rem 0 0;
  text-align: center;
}
</style>
