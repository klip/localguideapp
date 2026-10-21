import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import ModerationPage from '../pages/ModerationPage.vue'
import ComplaintPage from '../pages/ComplaintPage.vue'
import AuditPage from '../pages/AuditPage.vue'
import { ApiError, session, type Complaint, type ModerationPerson } from '@/api'

vi.mock('@/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api')>()
  type Api = typeof actual.api
  return {
    ...actual,
    api: {
      moderationComplaints: vi.fn<Api['moderationComplaints']>(),
      moderationComplaint: vi.fn<Api['moderationComplaint']>(),
      complaintMessages: vi.fn<Api['complaintMessages']>(),
      complaintAction: vi.fn<Api['complaintAction']>(),
      moderationLinks: vi.fn<Api['moderationLinks']>(),
      linkVerdict: vi.fn<Api['linkVerdict']>(),
      auditLog: vi.fn<Api['auditLog']>(),
    },
  }
})
const { api } = await import('@/api')

function person(id: number, name: string, extra: Partial<ModerationPerson> = {}): ModerationPerson {
  return {
    id,
    name,
    email: `${name.toLowerCase()}@example.test`,
    image: null,
    roles: ['guide'],
    createdAt: '2026-04-19T19:30:34Z',
    verified: false,
    freeze: null,
    complaintsAbout: 0,
    complaintsBy: 0,
    activeSanctions: 0,
    ...extra,
  }
}

function complaint(overrides: Partial<Complaint> = {}): Complaint {
  return {
    id: 7,
    title: 'Rude on the trip',
    description: 'He shouted at us.\nTwice.',
    status: 'open',
    resolution: null,
    createdAt: '2026-10-20T10:00:00Z',
    assignedTo: null,
    assignedAt: null,
    resolvedAt: null,
    resolvedBy: null,
    reporterToldAt: null,
    reporter: person(217, 'Ryan', { roles: ['guest'] }),
    subject: person(156, 'Morris', { complaintsAbout: 3 }),
    between: {
      matches: [
        {
          guestId: 217,
          guideId: 156,
          active: true,
          guestDecision: 'interested',
          guideDecision: 'interested',
          matchedAt: '2026-10-01T10:00:00Z',
        },
      ],
      bookings: [],
      messageCount: 2,
    },
    notes: [],
    sanctions: [],
    ...overrides,
  }
}

async function mountAt(path: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/moderation', component: ModerationPage },
      { path: '/moderation/complaints/:id', component: ComplaintPage, props: true },
      { path: '/audit', component: AuditPage },
      { path: '/users/:id', component: { template: '<div />' } },
      { path: '/pages', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  const wrapper = mount({ template: '<RouterView />' }, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

beforeEach(() => {
  session.ready = true
  session.me = {
    id: 3,
    email: 'mod@example.test',
    name: 'Mod',
    role: 'manager',
    disabled: false,
    hasPassword: true,
    lastSignInAt: null,
    createdAt: '2026-10-21T09:00:00Z',
    capabilities: ['disputes.resolve', 'users.view'],
  }
  vi.mocked(api.moderationComplaints).mockResolvedValue({
    complaints: [
      {
        id: 7,
        title: 'Rude on the trip',
        status: 'open',
        createdAt: '2026-10-20T10:00:00Z',
        reporter: { id: 217, name: 'Ryan' },
        subject: { id: 156, name: 'Morris', frozen: false },
        aboutSubject: 3,
        notes: 1,
        assignedTo: null,
      },
    ],
    total: 1,
    page: 1,
    pageSize: 50,
    counts: { open: 1, reviewing: 2, resolved: 4, dismissed: 1 },
  })
  vi.mocked(api.moderationLinks).mockResolvedValue({
    links: [
      {
        id: 4,
        a: person(201, 'Ana'),
        b: person(202, 'Bea', { complaintsAbout: 2 }),
        signal: 'device',
        strength: 3,
        hits: 5,
        firstSeenAt: '2026-10-01T10:00:00Z',
        lastSeenAt: '2026-10-20T10:00:00Z',
        verdict: null,
        reviewedAt: null,
        reviewedBy: null,
        note: null,
      },
    ],
    total: 1,
    unreviewed: 1,
    page: 1,
    pageSize: 50,
  })
  vi.mocked(api.moderationComplaint).mockResolvedValue(complaint())
})

afterEach(() => {
  session.me = null
  vi.clearAllMocks()
})

describe('the Moderation page', () => {
  it('lists what’s to do, with counts per status, and filters by status', async () => {
    const { wrapper, router } = await mountAt('/moderation')
    expect(api.moderationComplaints).toHaveBeenCalledWith({ status: 'active', page: 1 })
    expect(wrapper.find('[data-status="active"]').text()).toContain('(3)')
    const row = wrapper.find('[data-complaint="7"]')
    expect(row.text()).toContain('Rude on the trip')
    expect(row.text()).toContain('3 reports')

    await wrapper.find('[data-status="resolved"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query.status).toBe('resolved')
    expect(api.moderationComplaints).toHaveBeenLastCalledWith({ status: 'resolved', page: 1 })
  })

  it('reviews linked accounts with a verdict and an optional note', async () => {
    vi.mocked(api.linkVerdict).mockResolvedValue({ ok: true })
    const { wrapper } = await mountAt('/moderation?tab=links')
    expect(api.moderationLinks).toHaveBeenCalledWith({ status: 'unreviewed', page: 1 })
    const link = wrapper.find('[data-link="4"]')
    expect(link.text()).toContain('Same browser')
    expect(link.text()).toContain('2 complaints about them')

    await link.find('input').setValue('Siblings, same laptop')
    await link.find('[data-verdict="different_people"]').trigger('click')
    await flushPromises()
    expect(api.linkVerdict).toHaveBeenCalledWith(4, 'different_people', 'Siblings, same laptop')
    expect(wrapper.find('[data-test="notice"]').text()).toContain('Different people')
  })
})

describe('a complaint', () => {
  it('shows the report, both people and what’s between them — the chat only when asked', async () => {
    vi.mocked(api.complaintMessages).mockResolvedValue({
      messages: [
        {
          id: 1,
          from: 'reporter',
          kind: 'text',
          body: 'Where are you?',
          location: null,
          createdAt: '2026-10-20T09:00:00Z',
        },
        {
          id: 2,
          from: 'subject',
          kind: 'location',
          body: null,
          location: { latitude: 36.7, longitude: -4.4 },
          createdAt: '2026-10-20T09:01:00Z',
        },
      ],
    })
    const { wrapper } = await mountAt('/moderation/complaints/7')
    expect(api.moderationComplaint).toHaveBeenCalledWith(7)
    expect(wrapper.find('[data-test="description"]').text()).toContain('Twice.')
    expect(wrapper.find('[data-person="subject"]').text()).toContain('3 complaint(s) about them')
    expect(api.complaintMessages).not.toHaveBeenCalled()

    await wrapper.find('[data-test="show-messages"]').trigger('click')
    await flushPromises()
    expect(api.complaintMessages).toHaveBeenCalledWith(7)
    const chat = wrapper.find('[data-test="messages"]')
    expect(chat.text()).toContain('Where are you?')
    expect(chat.find('a').attributes('href')).toBe('https://www.google.com/maps?q=36.7,-4.4')
  })

  it('takes it, adds a note (errors pinned), and resolves with a sanction', async () => {
    const { wrapper } = await mountAt('/moderation/complaints/7')
    vi.mocked(api.complaintAction).mockResolvedValueOnce(
      complaint({ status: 'reviewing', assignedTo: 'mod@example.test' }),
    )
    await wrapper.find('[data-test="take"]').trigger('click')
    await flushPromises()
    expect(api.complaintAction).toHaveBeenLastCalledWith(7, 'take', {})
    expect(wrapper.find('[data-test="status"]').text()).toBe('Under review')
    expect(wrapper.find('[data-test="take"]').exists()).toBe(false)

    vi.mocked(api.complaintAction).mockRejectedValueOnce(
      new ApiError('A note of up to 2,000 characters.', 400, 'note'),
    )
    await wrapper.find('[data-test="note-form"] textarea').setValue('x')
    await wrapper.find('[data-test="note-form"]').trigger('submit.prevent')
    await flushPromises()
    expect(wrapper.find('[data-test="note-form"] .field-error').text()).toContain('2,000')

    vi.mocked(api.complaintAction).mockResolvedValueOnce(
      complaint({
        status: 'resolved',
        resolution: 'Warned',
        resolvedBy: 'mod@example.test',
        reporterToldAt: '2026-10-21T10:00:00Z',
      }),
    )
    await wrapper.find('[data-test="resolve"]').trigger('click')
    const form = wrapper.find('[data-test="close-form"]')
    await form.find('textarea').setValue('Warned')
    await form.find('[data-test="with-sanction"]').setValue(true)
    await wrapper.find('[data-test="close-form"]').trigger('submit.prevent')
    await flushPromises()
    expect(api.complaintAction).toHaveBeenLastCalledWith(7, 'resolve', {
      resolution: 'Warned',
      sanction: { levels: 1, days: 30 },
    })
    expect(wrapper.find('[data-test="status"]').text()).toBe('Resolved')
    expect(wrapper.find('[data-test="reopen"]').exists()).toBe(true)
  })

  it('dismisses with a reason, and can reopen', async () => {
    const { wrapper } = await mountAt('/moderation/complaints/7')
    vi.mocked(api.complaintAction).mockResolvedValueOnce(
      complaint({ status: 'dismissed', resolution: 'No evidence' }),
    )
    await wrapper.find('[data-test="dismiss"]').trigger('click')
    await wrapper.find('[data-test="close-form"] textarea').setValue('No evidence')
    await wrapper.find('[data-test="close-form"]').trigger('submit.prevent')
    await flushPromises()
    expect(api.complaintAction).toHaveBeenLastCalledWith(7, 'dismiss', {
      resolution: 'No evidence',
    })

    vi.mocked(api.complaintAction).mockResolvedValueOnce(
      complaint({ status: 'reviewing', assignedTo: 'mod@example.test' }),
    )
    await wrapper.find('[data-test="reopen"]').trigger('click')
    await flushPromises()
    expect(api.complaintAction).toHaveBeenLastCalledWith(7, 'reopen', {})
  })
})

describe('the audit log', () => {
  it('narrows to one target from the URL and links targets to their pages', async () => {
    vi.mocked(api.auditLog).mockResolvedValue({
      entries: [
        {
          id: 1,
          staffId: 3,
          staffEmail: 'mod@example.test',
          action: 'users.addSanction',
          targetType: 'user',
          targetId: '156',
          before: null,
          after: { levels: 1 },
          ip: null,
          createdAt: '2026-10-21T10:00:00Z',
        },
        {
          id: 2,
          staffId: 3,
          staffEmail: 'mod@example.test',
          action: 'moderation.resolve',
          targetType: 'complaint',
          targetId: '7',
          before: null,
          after: null,
          ip: null,
          createdAt: '2026-10-21T10:00:00Z',
        },
      ],
      hasMore: false,
      scope: 'operations',
    })
    const { wrapper } = await mountAt('/audit?target=user:156')
    expect(api.auditLog).toHaveBeenCalledWith({ target: 'user:156' })
    expect(wrapper.find('[data-test="target"]').text()).toContain('user #156')
    const links = wrapper.findAll('[data-test="audit-table"] a').map((a) => a.attributes('href'))
    expect(links).toEqual(['/users/156', '/moderation/complaints/7'])
  })
})
