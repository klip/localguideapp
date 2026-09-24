import { describe, it, expect, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
import ProfilePage from '../pages/ProfilePage.vue'
import { API_KEY, ApiError, type Api, type AttributeCategory, type MyProfile } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useMessagesStore } from '@/stores/messages'

/** A stub `Api` — every method is a no-op `vi.fn()` unless overridden. */
function fakeApi(overrides: Partial<Api> = {}): Api {
  return {
    register: vi.fn<Api['register']>(),
    login: vi.fn<Api['login']>(),
    guides: vi.fn<Api['guides']>(),
    visitors: vi.fn<Api['visitors']>(),
    updateProfile: vi.fn<Api['updateProfile']>(),
    attributeCategories: vi.fn<Api['attributeCategories']>(),
    myProfile: vi.fn<Api['myProfile']>(),
    updateAccount: vi.fn<Api['updateAccount']>(),
    changePassword: vi.fn<Api['changePassword']>(),
    logout: vi.fn<Api['logout']>(),
    session: vi.fn<Api['session']>(),
    unlockPack: vi.fn<Api['unlockPack']>(),
    paymentState: vi.fn<Api['paymentState']>(),
    payGuideFee: vi.fn<Api['payGuideFee']>(),
    cancelBooking: vi.fn<Api['cancelBooking']>(),
    notifications: vi.fn<Api['notifications']>(),
    decide: vi.fn<Api['decide']>(),
    revokeSelection: vi.fn<Api['revokeSelection']>(),
    matches: vi.fn<Api['matches']>(),
    matchOverview: vi.fn<Api['matchOverview']>(),
    publicProfile: vi.fn<Api['publicProfile']>(),
    reviews: vi.fn<Api['reviews']>(),
    submitReview: vi.fn<Api['submitReview']>(),
    deleteReview: vi.fn<Api['deleteReview']>(),
    ...overrides,
  }
}

const sampleProfile: MyProfile = {
  id: 42,
  name: 'Sofia',
  email: 'sofia@example.com',
  phone: '+44 7700 900123',
  two_factor_method: 'sms',
  image: null,
  created_at: '2026-01-01 00:00:00',
  date_of_birth: '1996-05-20',
  gender: 'male',
  headline: 'Rock scrambles',
  bio: 'Loves hiking',
  price_amount: 50,
  price_label: '£50/day',
  price_note: 'per group',
  includes: 'Water',
  party: null,
  duration_hours: null,
  role: 'guide',
  attributes: { languages: ['English'], interests: ['nature'] },
  ranges: {},
}

const sampleCategories: AttributeCategory[] = [
  {
    key: 'languages',
    label: 'Languages',
    multi: true,
    kind: 'options',
    providerLabel: null,
    rangeMin: null,
    rangeMax: null,
    options: [
      { value: 'English', label: 'English' },
      { value: 'Spanish', label: 'Spanish' },
    ],
  },
  {
    key: 'interests',
    label: 'Interests',
    multi: true,
    kind: 'options',
    providerLabel: null,
    rangeMin: null,
    rangeMax: null,
    options: [
      { value: 'nature', label: 'Nature' },
      { value: 'history', label: 'History' },
      { value: 'food', label: 'Food & drink' },
    ],
  },
  {
    key: 'food_allergies',
    label: 'Food allergies',
    multi: true,
    kind: 'options',
    providerLabel: null,
    rangeMin: null,
    rangeMax: null,
    options: [{ value: 'gluten', label: 'Gluten' }],
  },
]

async function mountProfilePage(api: Api, authenticated = true) {
  const pinia = createPinia()
  setActivePinia(pinia)
  if (authenticated) {
    useAuthStore().setUser({
      id: 42,
      email: 'sofia@example.com',
      name: 'Sofia',
      role: 'guide',
      sessionHash: 'test-session-hash',
    })
  }

  const router = createRouter({
    history: createWebHistory(),
    routes: [
      { path: '/profile', name: 'profile', component: ProfilePage },
      { path: '/login', name: 'login', component: { template: '<div />' } },
    ],
  })
  router.push('/profile')
  await router.isReady()

  const wrapper = mount(ProfilePage, {
    global: {
      plugins: [pinia, router],
      provide: { [API_KEY]: api },
    },
  })
  await flushPromises()

  return wrapper
}

/**
 * Password, account, and profile-details forms render in this fixed template
 * order — the password form is nested at the very top of the personal-info
 * panel (inside a collapsed `<details>`), ahead of the account form itself.
 */
function forms(wrapper: ReturnType<typeof mount>) {
  const all = wrapper.findAll('form')
  return { password: all[0]!, account: all[1]!, details: all[2]! }
}

function labelled(wrapper: ReturnType<typeof mount>, text: string) {
  return wrapper.findAll('label').find((label) => label.text().startsWith(text))!
}

describe('ProfilePage', () => {
  it('prompts to log in when signed out', async () => {
    const wrapper = await mountProfilePage(fakeApi(), false)

    expect(wrapper.text()).toContain('Log in to manage your profile')
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('loads and prefills the current profile, splitting the phone into country code + number', async () => {
    const myProfile = vi.fn<Api['myProfile']>().mockResolvedValue(sampleProfile)
    const attributeCategories = vi
      .fn<Api['attributeCategories']>()
      .mockResolvedValue({ categories: sampleCategories })
    const wrapper = await mountProfilePage(fakeApi({ myProfile, attributeCategories }))

    expect(myProfile).toHaveBeenCalledWith()
    expect((wrapper.find('input[type="email"]').element as HTMLInputElement).value).toBe('sofia@example.com')
    expect((labelled(wrapper, 'Headline').find('input').element as HTMLInputElement).value).toBe(
      'Rock scrambles',
    )
    expect((labelled(wrapper, 'Date of birth').find('input').element as HTMLInputElement).value).toBe(
      '1996-05-20',
    )
    expect((labelled(wrapper, 'Country code').find('input').element as HTMLInputElement).value).toBe(
      'United Kingdom (+44)',
    )
    expect((labelled(wrapper, 'Phone').find('input').element as HTMLInputElement).value).toBe('7700900123')
    expect((labelled(wrapper, 'Two-factor').find('select').element as HTMLSelectElement).value).toBe('sms')

    expect(wrapper.text()).toContain('English')
    expect(wrapper.text()).toContain('Nature')
    expect(wrapper.text()).not.toContain('History')
  })

  it('leaves the two-factor select unselected for a legacy "none" account instead of offering it as a choice', async () => {
    const wrapper = await mountProfilePage(
      fakeApi({
        myProfile: vi.fn<Api['myProfile']>().mockResolvedValue({ ...sampleProfile, two_factor_method: 'none' }),
        attributeCategories: vi.fn<Api['attributeCategories']>().mockResolvedValue({ categories: sampleCategories }),
      }),
    )

    const select = labelled(wrapper, 'Two-factor').find('select').element as HTMLSelectElement
    expect(select.value).toBe('')
    expect(Array.from(select.options).map((o) => o.value)).not.toContain('none')
    expect(Array.from(select.options).map((o) => o.value)).toEqual(['', 'email', 'sms', 'whatsapp'])
  })

  it('saves personal & contact info, concatenating country code + number into one phone string', async () => {
    const updateAccount = vi.fn<Api['updateAccount']>().mockResolvedValue({ ok: true })
    const wrapper = await mountProfilePage(
      fakeApi({
        myProfile: vi.fn<Api['myProfile']>().mockResolvedValue(sampleProfile),
        attributeCategories: vi.fn<Api['attributeCategories']>().mockResolvedValue({ categories: sampleCategories }),
        updateAccount,
      }),
    )

    await wrapper.find('input[type="email"]').setValue('new-sofia@example.com')
    await forms(wrapper).account.trigger('submit.prevent')
    await flushPromises()

    expect(updateAccount).toHaveBeenCalledWith(
      expect.objectContaining({
        email: 'new-sofia@example.com',
        phone: '+44 7700900123',
        twoFactorMethod: 'sms',
      }),
    )
    const messages = useMessagesStore()
    expect(messages.items.map((item) => item.text)).toContain('Contact details saved.')
  })

  it('adds an interest via the category/option comboboxes and saves it', async () => {
    const updateProfile = vi.fn<Api['updateProfile']>().mockResolvedValue({ ok: true })
    const wrapper = await mountProfilePage(
      fakeApi({
        myProfile: vi.fn<Api['myProfile']>().mockResolvedValue(sampleProfile),
        attributeCategories: vi.fn<Api['attributeCategories']>().mockResolvedValue({ categories: sampleCategories }),
        updateProfile,
      }),
    )

    // "interests" already has a selection (nature — see sampleProfile), so
    // its row starts collapsed to a "+" button rather than auto-expanded.
    const interestsGroup = forms(wrapper)
      .details.findAll('.attribute-group')
      .find((group) => group.text().includes('Interests'))!
    await interestsGroup.find('.chip-add').trigger('click')

    const optionInput = interestsGroup.find('.combobox input')
    await optionInput.trigger('focus')
    const historyItem = interestsGroup.findAll('.combobox-list li').find((li) => li.text() === 'History')!
    await historyItem.trigger('mousedown')

    // The dropdown must stay open after a pick, not just the input's focus
    // — picking a second option shouldn't require blurring and clicking
    // "+" again (the reported bug: selection closed the dropdown outright).
    expect(interestsGroup.find('.combobox-list').exists()).toBe(true)
    const foodItem = interestsGroup.findAll('.combobox-list li').find((li) => li.text() === 'Food & drink')!
    await foodItem.trigger('mousedown')

    await forms(wrapper).details.trigger('submit.prevent')
    await flushPromises()

    expect(updateProfile).toHaveBeenCalledWith(
      expect.objectContaining({
        attributes: expect.objectContaining({
          interests: expect.arrayContaining(['nature', 'history', 'food']),
        }),
      }),
    )
  })

  it('removes an existing interest chip and omits the now-empty category on save', async () => {
    const updateProfile = vi.fn<Api['updateProfile']>().mockResolvedValue({ ok: true })
    const wrapper = await mountProfilePage(
      fakeApi({
        myProfile: vi.fn<Api['myProfile']>().mockResolvedValue(sampleProfile),
        attributeCategories: vi.fn<Api['attributeCategories']>().mockResolvedValue({ categories: sampleCategories }),
        updateProfile,
      }),
    )

    const chip = wrapper.findAll('.chip').find((candidate) => candidate.text().includes('English'))!
    await chip.find('.chip-remove').trigger('click')

    await forms(wrapper).details.trigger('submit.prevent')
    await flushPromises()

    const payload = updateProfile.mock.calls[0]![0]
    expect(payload.attributes).not.toHaveProperty('languages')
  })

  it('hides an empty category by default, and hides it again on blur if nothing was added', async () => {
    const wrapper = await mountProfilePage(
      fakeApi({
        myProfile: vi.fn<Api['myProfile']>().mockResolvedValue(sampleProfile),
        attributeCategories: vi.fn<Api['attributeCategories']>().mockResolvedValue({ categories: sampleCategories }),
      }),
    )

    // food_allergies has no selections in sampleProfile — no row for it at all yet.
    const hasAllergiesRow = () =>
      forms(wrapper).details.findAll('.attribute-group').some((group) => group.text().includes('Food allergies'))
    expect(hasAllergiesRow()).toBe(false)

    // Reached via the bottom "Add another category" control, since it has no row of its own.
    const categoryInput = forms(wrapper).details.find('.add-category .combobox input')
    await categoryInput.trigger('focus')
    const allergiesItem = wrapper.findAll('.combobox-list li').find((li) => li.text() === 'Food allergies')!
    await allergiesItem.trigger('mousedown')

    const allergiesGroup = forms(wrapper)
      .details.findAll('.attribute-group')
      .find((group) => group.text().includes('Food allergies'))!
    expect(allergiesGroup.find('.combobox input').exists()).toBe(true)
    expect(allergiesGroup.find('.chip-add').exists()).toBe(false)

    await allergiesGroup.find('.combobox input').trigger('blur')

    // Still empty after blur — the whole row disappears, not just the combobox.
    expect(hasAllergiesRow()).toBe(false)
  })

  it('keeps a category visible with a "+" (not hidden) after picking an option and blurring', async () => {
    const updateProfile = vi.fn<Api['updateProfile']>().mockResolvedValue({ ok: true })
    const wrapper = await mountProfilePage(
      fakeApi({
        myProfile: vi.fn<Api['myProfile']>().mockResolvedValue(sampleProfile),
        attributeCategories: vi.fn<Api['attributeCategories']>().mockResolvedValue({ categories: sampleCategories }),
        updateProfile,
      }),
    )

    const categoryInput = forms(wrapper).details.find('.add-category .combobox input')
    await categoryInput.trigger('focus')
    const allergiesItem = wrapper.findAll('.combobox-list li').find((li) => li.text() === 'Food allergies')!
    await allergiesItem.trigger('mousedown')

    const allergiesGroup = forms(wrapper)
      .details.findAll('.attribute-group')
      .find((group) => group.text().includes('Food allergies'))!

    const optionInput = allergiesGroup.find('.combobox input')
    await optionInput.trigger('focus')
    const glutenItem = allergiesGroup.findAll('.combobox-list li').find((li) => li.text() === 'Gluten')!
    await glutenItem.trigger('mousedown')

    // Still expanded right after picking — the row shouldn't collapse mid-interaction.
    expect(allergiesGroup.find('.combobox input').exists()).toBe(true)
    expect(allergiesGroup.text()).toContain('Gluten')

    await allergiesGroup.find('.combobox input').trigger('blur')

    // Now non-empty — stays visible with a "+", rather than disappearing.
    const allergiesGroupAfterBlur = forms(wrapper)
      .details.findAll('.attribute-group')
      .find((group) => group.text().includes('Food allergies'))!
    expect(allergiesGroupAfterBlur.find('.chip-add').exists()).toBe(true)

    await forms(wrapper).details.trigger('submit.prevent')
    await flushPromises()

    expect(updateProfile).toHaveBeenCalledWith(
      expect.objectContaining({ attributes: expect.objectContaining({ food_allergies: ['gluten'] }) }),
    )
  })

  it('creates a brand new category and option from the comboboxes', async () => {
    const updateProfile = vi.fn<Api['updateProfile']>().mockResolvedValue({ ok: true })
    const wrapper = await mountProfilePage(
      fakeApi({
        myProfile: vi.fn<Api['myProfile']>().mockResolvedValue(sampleProfile),
        attributeCategories: vi.fn<Api['attributeCategories']>().mockResolvedValue({ categories: sampleCategories }),
        updateProfile,
      }),
    )

    // Category creation lives in the "Add another category" combobox at the
    // bottom of the section; a freshly created (empty) category's own
    // option combobox is auto-expanded, no "+" click needed.
    const categoryInput = forms(wrapper).details.find('.add-category .combobox input')
    await categoryInput.setValue('Dietary needs')
    await wrapper.find('.combobox-list .create-row').trigger('mousedown')

    const dietaryGroup = forms(wrapper)
      .details.findAll('.attribute-group')
      .find((group) => group.text().includes('Dietary needs'))!
    const optionInput = dietaryGroup.find('.combobox input')
    await optionInput.setValue('Vegan')
    await dietaryGroup.find('.combobox-list .create-row').trigger('mousedown')

    await forms(wrapper).details.trigger('submit.prevent')
    await flushPromises()

    const payload = updateProfile.mock.calls[0]![0]
    expect(payload.attributes?.dietary_needs).toEqual(['Vegan'])
    expect(payload.newCategoryLabels?.dietary_needs).toBe('Dietary needs')
  })

  it('rejects a password change when confirmation does not match, without calling the API', async () => {
    const changePassword = vi.fn<Api['changePassword']>()
    const wrapper = await mountProfilePage(
      fakeApi({
        myProfile: vi.fn<Api['myProfile']>().mockResolvedValue(sampleProfile),
        attributeCategories: vi.fn<Api['attributeCategories']>().mockResolvedValue({ categories: sampleCategories }),
        changePassword,
      }),
    )

    const { password } = forms(wrapper)
    await password.find('input[autocomplete="current-password"]').setValue('oldpassword')
    await password.findAll('input[autocomplete="new-password"]')[0]!.setValue('newpassword1')
    await password.findAll('input[autocomplete="new-password"]')[1]!.setValue('different')
    await password.trigger('submit.prevent')
    await flushPromises()

    expect(changePassword).not.toHaveBeenCalled()
    const messages = useMessagesStore()
    expect(messages.items.map((item) => item.text)).toContain('New passwords do not match.')
  })

  it('changes the password and clears the form on success', async () => {
    const changePassword = vi.fn<Api['changePassword']>().mockResolvedValue({ ok: true })
    const wrapper = await mountProfilePage(
      fakeApi({
        myProfile: vi.fn<Api['myProfile']>().mockResolvedValue(sampleProfile),
        attributeCategories: vi.fn<Api['attributeCategories']>().mockResolvedValue({ categories: sampleCategories }),
        changePassword,
      }),
    )

    const { password } = forms(wrapper)
    await password.find('input[autocomplete="current-password"]').setValue('oldpassword')
    await password.findAll('input[autocomplete="new-password"]')[0]!.setValue('newpassword1')
    await password.findAll('input[autocomplete="new-password"]')[1]!.setValue('newpassword1')
    await password.trigger('submit.prevent')
    await flushPromises()

    expect(changePassword).toHaveBeenCalledWith({
      currentPassword: 'oldpassword',
      newPassword: 'newpassword1',
    })
    expect((password.find('input[autocomplete="current-password"]').element as HTMLInputElement).value).toBe('')
  })

  it('shows an error toast when changing the password fails', async () => {
    const changePassword = vi
      .fn<Api['changePassword']>()
      .mockRejectedValue(new ApiError('Current password is incorrect.', 400))
    const wrapper = await mountProfilePage(
      fakeApi({
        myProfile: vi.fn<Api['myProfile']>().mockResolvedValue(sampleProfile),
        attributeCategories: vi.fn<Api['attributeCategories']>().mockResolvedValue({ categories: sampleCategories }),
        changePassword,
      }),
    )

    const { password } = forms(wrapper)
    await password.find('input[autocomplete="current-password"]').setValue('wrongpassword')
    await password.findAll('input[autocomplete="new-password"]')[0]!.setValue('newpassword1')
    await password.findAll('input[autocomplete="new-password"]')[1]!.setValue('newpassword1')
    await password.trigger('submit.prevent')
    await flushPromises()

    const messages = useMessagesStore()
    expect(messages.items.map((item) => item.text)).toContain('Current password is incorrect.')
  })
})
