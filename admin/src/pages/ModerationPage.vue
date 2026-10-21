<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ApiError,
  api,
  type AccountLink,
  type ComplaintRow,
  type ComplaintStatus,
  type ModerationPerson,
} from '@/api'

/**
 * Moderation (`disputes.resolve` — managers and admins), two tabs:
 *
 * - **Complaints**: what users reported from the app's Report button. The
 *   default view is what's still to do (open and under review), oldest
 *   first; each opens its own page (ComplaintPage.vue).
 * - **Linked accounts**: accounts the sign-in system found on the same
 *   browser, address + fingerprint, or fingerprint — strongest first, each
 *   to be judged: same person, different people, or unsure.
 *
 * The tab, status filter and page are in the URL.
 */
type Tab = 'complaints' | 'links'
const STATUS_TABS: { value: string; label: string }[] = [
  { value: 'active', label: 'To do' },
  { value: 'open', label: 'Open' },
  { value: 'reviewing', label: 'Under review' },
  { value: 'resolved', label: 'Resolved' },
  { value: 'dismissed', label: 'Dismissed' },
  { value: 'all', label: 'All' },
]
const SIGNAL_LABEL: Record<AccountLink['signal'], string> = {
  device: 'Same browser',
  ip_fingerprint: 'Same address and device details',
  fingerprint: 'Same device details',
}
const VERDICT_LABEL = {
  same_person: 'Same person',
  different_people: 'Different people',
  unsure: 'Unsure',
} as const

const route = useRoute()
const router = useRouter()

const tab = computed<Tab>(() => (route.query.tab === 'links' ? 'links' : 'complaints'))
const status = computed(() =>
  String(route.query.status ?? (tab.value === 'links' ? 'unreviewed' : 'active')),
)
const page = computed(() => Math.max(1, Number(route.query.page ?? 1) || 1))

const complaints = ref<ComplaintRow[]>([])
const counts = ref<Record<ComplaintStatus, number>>({
  open: 0,
  reviewing: 0,
  resolved: 0,
  dismissed: 0,
})
const links = ref<AccountLink[]>([])
const unreviewed = ref(0)
const total = ref(0)
const pageSize = ref(50)
const loading = ref(false)
const error = ref('')
const notice = ref('')
const linkNotes = reactive<Record<number, string>>({})

const pages = computed(() => Math.max(1, Math.ceil(total.value / pageSize.value)))

function countFor(value: string) {
  const c = counts.value
  if (value === 'active') return c.open + c.reviewing
  if (value === 'all') return c.open + c.reviewing + c.resolved + c.dismissed
  return c[value as ComplaintStatus] ?? 0
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    if (tab.value === 'complaints') {
      const result = await api.moderationComplaints({ status: status.value, page: page.value })
      complaints.value = result.complaints
      counts.value = result.counts
      total.value = result.total
      pageSize.value = result.pageSize
    } else {
      const result = await api.moderationLinks({
        status: status.value as 'unreviewed' | 'reviewed' | 'all',
        page: page.value,
      })
      links.value = result.links
      unreviewed.value = result.unreviewed
      total.value = result.total
      pageSize.value = result.pageSize
    }
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Could not load.'
  } finally {
    loading.value = false
  }
}

/** Changes the tab, status or page in the URL (defaults left out). */
function go(change: { tab?: Tab; status?: string; page?: number }) {
  const next: Record<string, string> = {}
  const nextTab = change.tab ?? tab.value
  if (nextTab === 'links') next.tab = 'links'
  if (change.status) next.status = change.status
  if (change.page && change.page > 1) next.page = String(change.page)
  void router.push({ query: next })
}

async function judge(link: AccountLink, verdict: NonNullable<AccountLink['verdict']>) {
  notice.value = ''
  error.value = ''
  try {
    await api.linkVerdict(link.id, verdict, linkNotes[link.id] ?? '')
    notice.value = `Marked “${VERDICT_LABEL[verdict]}”.`
    await load()
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Could not save the verdict.'
  }
}

function date(iso: string | null) {
  return iso ? new Date(iso).toLocaleDateString() : '—'
}

function age(iso: string) {
  const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000)
  return days < 1 ? 'today' : days === 1 ? '1 day' : `${days} days`
}

function personLine(p: ModerationPerson | null) {
  if (!p) return 'Deleted account'
  return [
    p.roles.map((r) => (r === 'guest' ? 'Traveller' : 'Guide')).join(' & '),
    `joined ${date(p.createdAt)}`,
  ]
    .filter(Boolean)
    .join(' · ')
}

watch(() => route.query, load, { immediate: true })
</script>

<template>
  <section class="moderation">
    <h1>Moderation</h1>
    <nav class="tabs" aria-label="Moderation">
      <a
        href="#"
        :aria-current="tab === 'complaints' ? 'page' : undefined"
        data-test="tab-complaints"
        @click.prevent="go({ tab: 'complaints', status: undefined })"
      >
        Complaints <span v-if="countFor('active')" class="badge">{{ countFor('active') }}</span>
      </a>
      <a
        href="#"
        :aria-current="tab === 'links' ? 'page' : undefined"
        data-test="tab-links"
        @click.prevent="go({ tab: 'links', status: undefined })"
      >
        Linked accounts <span v-if="unreviewed" class="badge">{{ unreviewed }}</span>
      </a>
    </nav>

    <p v-if="error" class="notice error" role="alert" data-test="error">{{ error }}</p>
    <p v-if="notice" class="notice" role="status" data-test="notice">{{ notice }}</p>

    <!-- Complaints -->
    <template v-if="tab === 'complaints'">
      <div class="statuses" role="group" aria-label="Status">
        <button
          v-for="s in STATUS_TABS"
          :key="s.value"
          type="button"
          :class="status === s.value ? 'primary' : 'secondary outline'"
          :aria-pressed="status === s.value"
          :data-status="s.value"
          @click="go({ status: s.value === 'active' ? undefined : s.value })"
        >
          {{ s.label }} <span class="tiny">({{ countFor(s.value) }})</span>
        </button>
      </div>
      <p v-if="loading" aria-busy="true" class="muted">Loading…</p>
      <table v-else class="striped" data-test="complaints">
        <thead>
          <tr>
            <th>#</th>
            <th>Report</th>
            <th>About</th>
            <th>By</th>
            <th>Status</th>
            <th>Waiting</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in complaints" :key="c.id" :data-complaint="c.id">
            <td class="tiny muted">{{ c.id }}</td>
            <td>
              <RouterLink :to="`/moderation/complaints/${c.id}`">{{ c.title }}</RouterLink>
              <span v-if="c.notes" class="tiny muted">
                · {{ c.notes }} note{{ c.notes > 1 ? 's' : '' }}</span
              >
            </td>
            <td>
              <template v-if="c.subject">
                <RouterLink :to="`/users/${c.subject.id}`">{{
                  c.subject.name || `#${c.subject.id}`
                }}</RouterLink>
                <span v-if="c.aboutSubject > 1" class="warn tiny">
                  · {{ c.aboutSubject }} reports</span
                >
                <span v-if="c.subject.frozen" class="pill frozen">frozen</span>
              </template>
              <span v-else class="muted">Deleted account</span>
            </td>
            <td class="tiny">
              <RouterLink v-if="c.reporter" :to="`/users/${c.reporter.id}`">{{
                c.reporter.name || `#${c.reporter.id}`
              }}</RouterLink>
              <span v-else class="muted">Deleted account</span>
            </td>
            <td class="tiny">
              {{
                c.status === 'reviewing'
                  ? `Under review${c.assignedTo ? ` · ${c.assignedTo}` : ''}`
                  : c.status
              }}
            </td>
            <td class="tiny">
              {{
                c.status === 'open' || c.status === 'reviewing'
                  ? age(c.createdAt)
                  : date(c.createdAt)
              }}
            </td>
          </tr>
          <tr v-if="!complaints.length">
            <td colspan="6" class="muted">
              {{ status === 'active' ? 'Nothing to do — every complaint is closed.' : 'None.' }}
            </td>
          </tr>
        </tbody>
      </table>
    </template>

    <!-- Linked accounts -->
    <template v-else>
      <p class="tiny muted">
        Found at sign-in: two accounts on the same browser (strongest), the same address and device
        details, or the same device details. Families and shared computers are common — a link alone
        proves nothing; freeze from a user’s page if it’s abuse.
      </p>
      <div class="statuses" role="group" aria-label="Status">
        <button
          v-for="s in [
            { value: 'unreviewed', label: 'To review' },
            { value: 'reviewed', label: 'Reviewed' },
            { value: 'all', label: 'All' },
          ]"
          :key="s.value"
          type="button"
          :class="status === s.value ? 'primary' : 'secondary outline'"
          :aria-pressed="status === s.value"
          @click="go({ status: s.value === 'unreviewed' ? undefined : s.value })"
        >
          {{ s.label }}
        </button>
      </div>
      <p v-if="loading" aria-busy="true" class="muted">Loading…</p>
      <div v-else class="links" data-test="links">
        <article v-for="link in links" :key="link.id" :data-link="link.id">
          <header>
            <strong>{{ SIGNAL_LABEL[link.signal] }}</strong>
            <span class="tiny muted">
              · strength {{ link.strength }} · seen {{ link.hits }}× · last
              {{ date(link.lastSeenAt) }}</span
            >
            <span v-if="link.verdict" class="pill"
              >{{ VERDICT_LABEL[link.verdict] }} — {{ link.reviewedBy }},
              {{ date(link.reviewedAt) }}</span
            >
          </header>
          <div class="pair">
            <div v-for="(p, i) in [link.a, link.b]" :key="i" class="person">
              <template v-if="p">
                <img v-if="p.image" :src="p.image" alt="" class="avatar" />
                <div>
                  <RouterLink :to="`/users/${p.id}`">{{ p.name || '(no name)' }}</RouterLink>
                  <span v-if="p.freeze" class="pill frozen">frozen</span>
                  <div class="tiny muted">{{ p.email }} · {{ personLine(p) }}</div>
                  <div v-if="p.complaintsAbout || p.activeSanctions" class="tiny warn">
                    {{ p.complaintsAbout }} complaint{{ p.complaintsAbout === 1 ? '' : 's' }} about
                    them<template v-if="p.activeSanctions"
                      >, −{{ p.activeSanctions }} levels in force</template
                    >
                  </div>
                </div>
              </template>
              <span v-else class="muted">Deleted account</span>
            </div>
          </div>
          <p v-if="link.note" class="tiny">Note: {{ link.note }}</p>
          <form class="verdict" @submit.prevent>
            <input
              v-model="linkNotes[link.id]"
              type="text"
              maxlength="500"
              placeholder="Note (optional)"
              aria-label="Note"
            />
            <button
              type="button"
              class="secondary outline"
              data-verdict="same_person"
              @click="judge(link, 'same_person')"
            >
              Same person
            </button>
            <button
              type="button"
              class="secondary outline"
              data-verdict="different_people"
              @click="judge(link, 'different_people')"
            >
              Different people
            </button>
            <button
              type="button"
              class="secondary outline"
              data-verdict="unsure"
              @click="judge(link, 'unsure')"
            >
              Unsure
            </button>
          </form>
        </article>
        <p v-if="!links.length" class="muted">
          {{ status === 'unreviewed' ? 'Nothing to review.' : 'None.' }}
        </p>
      </div>
    </template>

    <nav v-if="pages > 1" class="pager" aria-label="Pages">
      <button
        type="button"
        class="secondary outline"
        :disabled="page <= 1"
        @click="go({ status: route.query.status as string | undefined, page: page - 1 })"
      >
        ← Previous
      </button>
      <span class="tiny muted">Page {{ page }} of {{ pages }}</span>
      <button
        type="button"
        class="secondary outline"
        :disabled="page >= pages"
        @click="go({ status: route.query.status as string | undefined, page: page + 1 })"
      >
        Next →
      </button>
    </nav>
  </section>
</template>

<style scoped>
.tabs {
  display: flex;
  justify-content: flex-start;
  gap: 1.5rem;
  margin-bottom: 1rem;
  border-bottom: 1px solid var(--pico-muted-border-color);
}

.tabs a {
  padding: 0.5rem 0;
  text-decoration: none;
}

.tabs a[aria-current='page'] {
  font-weight: 700;
  border-bottom: 3px solid var(--pico-primary);
}

.badge {
  display: inline-block;
  min-width: 1.4rem;
  padding: 0 0.4rem;
  border-radius: 1rem;
  font-size: 0.75rem;
  text-align: center;
  background: var(--pico-del-color);
  color: #fff;
}

.statuses {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin-bottom: 1rem;
}

.statuses button {
  width: auto;
  margin: 0;
  padding: 0.25rem 0.75rem;
  font-size: 0.85rem;
}

.pill {
  display: inline-block;
  margin-left: 0.35rem;
  padding: 0 0.45rem;
  border-radius: 1rem;
  font-size: 0.75rem;
  background: var(--pico-muted-border-color);
}

.pill.frozen {
  background: var(--pico-del-color);
  color: #fff;
}

.warn {
  color: var(--pico-del-color);
}

.links article {
  margin-bottom: 1rem;
}

.pair {
  display: grid;
  gap: 1rem;
}

@media (min-width: 800px) {
  .pair {
    grid-template-columns: 1fr 1fr;
  }
}

.person {
  display: flex;
  gap: 0.75rem;
  align-items: flex-start;
}

.avatar {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 50%;
  object-fit: cover;
}

.verdict {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin: 0.75rem 0 0;
}

.verdict input {
  flex: 1 1 14rem;
  margin: 0;
}

.verdict button {
  width: auto;
  margin: 0;
}

.pager {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 1rem;
}

.pager button {
  width: auto;
  margin: 0;
}
</style>
