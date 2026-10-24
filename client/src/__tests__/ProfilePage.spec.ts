import { describe, it, expect, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
import ProfilePage from '../pages/ProfilePage.vue'
import { API_KEY, ApiError, type Api, type AttributeCategory, type MyProfile } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useMessagesStore } from '@/stores/messages'
import { fakeApi } from './fakeApi'

const sampleProfile: MyProfile = {
  id: 42,
  name: 'Sofia',
  email: 'sofia@example.com',
  phone: '+44 7700 900123',
  two_factor_method: 'email',
  trusted_device_count: 0,
  verified: false,
  verification_method: null,
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

async function mountProfilePage(api: Api, authenticated = true, path = '/profile') {
  // stores/auth.ts reads the session and the chosen role back from storage.
  localStorage.clear()
  const pinia = createPinia()
  setActivePinia(pinia)
  if (authenticated) {
    useAuthStore().setUser({
      id: 42,
      email: 'sofia@example.com',
      name: 'Sofia',
      role: 'guide',
    })
  }

  const router = createRouter({
    history: createWebHistory(),
    routes: [
      { path: '/profile', name: 'profile', component: ProfilePage },
      { path: '/login', name: 'login', component: { template: '<div />' } },
    ],
  })
  router.push(path)
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

/** The page opened on one of its sub-views: `contact` (contact & sign-in) or `security` (password & devices). */
function mountProfilePageAt(section: 'contact' | 'security', api: Api) {
  return mountProfilePage(api, true, `/profile?section=${section}`)
}

/** jsdom's <dialog> is partial: stand in for showModal()/close() for a test that opens one. */
function stubDialog() {
  const { showModal, close } = HTMLDialogElement.prototype
  HTMLDialogElement.prototype.showModal = function (this: HTMLDialogElement) {
    this.setAttribute('open', '')
  }
  HTMLDialogElement.prototype.close = function (this: HTMLDialogElement) {
    if (!this.hasAttribute('open')) return
    this.removeAttribute('open')
    this.dispatchEvent(new Event('close'))
  }
  return () => {
    HTMLDialogElement.prototype.showModal = showModal
    HTMLDialogElement.prototype.close = close
  }
}

/** The page's three forms — each on its own view (profile, `contact`, `security`). */
function forms(wrapper: ReturnType<typeof mount>) {
  return {
    password: wrapper.find('[data-test="form-password"]'),
    account: wrapper.find('[data-test="form-account"]'),
    details: wrapper.find('[data-test="form-details"]'),
  }
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
    expect((labelled(wrapper, 'Headline').find('input').element as HTMLInputElement).value).toBe(
      'Rock scrambles',
    )
    expect(
      (labelled(wrapper, 'Date of birth').find('input').element as HTMLInputElement).value,
    ).toBe('1996-05-20')
    // Gender as segments: the saved one is chosen.
    expect(
      wrapper.get<HTMLInputElement>('[data-test="profile-gender"] input[value="male"]').element
        .checked,
    ).toBe(true)
    expect(wrapper.text()).toContain('English')
    expect(wrapper.text()).toContain('Nature')
    expect(wrapper.text()).not.toContain('History')

    // The contact fields are on their own view.
    const contact = await mountProfilePageAt('contact', fakeApi({ myProfile, attributeCategories }))
    expect((contact.find('input[type="email"]').element as HTMLInputElement).value).toBe(
      'sofia@example.com',
    )
    expect(contact.find('[data-test="verification"]').text()).toMatch(/Not verified yet/)
    expect(
      (labelled(contact, 'Country code').find('input').element as HTMLInputElement).value,
    ).toBe('United Kingdom (+44)')
    expect((labelled(contact, 'Phone').find('input').element as HTMLInputElement).value).toBe(
      '7700900123',
    )
    expect(
      (labelled(contact, 'Two-factor').find('select').element as HTMLSelectElement).value,
    ).toBe('email')
  })

  it('links to contact & sign-in and password & devices, each with a way back', async () => {
    const api = fakeApi({
      myProfile: vi.fn<Api['myProfile']>().mockResolvedValue(sampleProfile),
      attributeCategories: vi
        .fn<Api['attributeCategories']>()
        .mockResolvedValue({ categories: sampleCategories }),
    })
    const wrapper = await mountProfilePage(api)
    expect(wrapper.get('[data-test="link-contact"]').attributes('href')).toBe(
      '/profile?section=contact',
    )
    expect(wrapper.get('[data-test="link-security"]').attributes('href')).toBe(
      '/profile?section=security',
    )
    expect(wrapper.find('[data-test="profile-back"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Tap photo to change')

    const security = await mountProfilePageAt('security', api)
    expect(security.get('h1').text()).toBe('Password & devices')
    expect(security.get('[data-test="profile-back"]').attributes('href')).toBe('/profile')
    expect(security.find('[data-test="form-details"]').exists()).toBe(false)
  })

  it('shows a verified identity and the document it was checked with', async () => {
    const wrapper = await mountProfilePageAt(
      'contact',
      fakeApi({
        myProfile: vi
          .fn<Api['myProfile']>()
          .mockResolvedValue({ ...sampleProfile, verified: true, verification_method: 'passport' }),
        attributeCategories: vi
          .fn<Api['attributeCategories']>()
          .mockResolvedValue({ categories: sampleCategories }),
      }),
    )

    const line = wrapper.find('[data-test="verification"]')
    expect(line.classes()).toContain('verification--done')
    expect(line.text()).toContain('Your identity is verified')
    expect(line.text()).toContain('Passport')
  })

  it('leaves the two-factor select unselected for a legacy "none" account instead of offering it as a choice', async () => {
    const wrapper = await mountProfilePageAt(
      'contact',
      fakeApi({
        myProfile: vi
          .fn<Api['myProfile']>()
          .mockResolvedValue({ ...sampleProfile, two_factor_method: 'none' }),
        attributeCategories: vi
          .fn<Api['attributeCategories']>()
          .mockResolvedValue({ categories: sampleCategories }),
      }),
    )

    const select = labelled(wrapper, 'Two-factor').find('select').element as HTMLSelectElement
    expect(select.value).toBe('')
    expect(Array.from(select.options).map((o) => o.value)).not.toContain('none')
    expect(Array.from(select.options).map((o) => o.value)).toEqual(['', 'email', 'sms', 'whatsapp'])
  })

  it('shows remembered devices and forgets them all', async () => {
    const forgetTrustedDevices = vi
      .fn<Api['forgetTrustedDevices']>()
      .mockResolvedValue({ ok: true })
    localStorage.setItem(
      'belikelocal.trustedDevices',
      JSON.stringify({
        [sampleProfile.email]: { token: 'd'.repeat(64), until: '2099-01-01T00:00:00Z' },
      }),
    )
    const wrapper = await mountProfilePageAt(
      'security',
      fakeApi({
        myProfile: vi
          .fn<Api['myProfile']>()
          .mockResolvedValue({ ...sampleProfile, trusted_device_count: 2 }),
        attributeCategories: vi
          .fn<Api['attributeCategories']>()
          .mockResolvedValue({ categories: sampleCategories }),
        forgetTrustedDevices,
      }),
    )

    expect(wrapper.text()).toContain('Codes are skipped on 2 remembered devices')
    const forget = wrapper.findAll('button').find((b) => b.text() === 'Forget all devices')!
    await forget.trigger('click')
    await flushPromises()

    expect(forgetTrustedDevices).toHaveBeenCalled()
    expect(wrapper.text()).not.toContain('remembered device')
    expect(localStorage.getItem('belikelocal.trustedDevices')).toBeNull()
    expect(
      useMessagesStore()
        .items.map((item) => item.text)
        .join(),
    ).toContain('every device will ask for a code')
  })

  it('lists the signed-in devices, marks this one, and signs another out', async () => {
    const recent = new Date(Date.now() - 2 * 3600_000).toISOString()
    const signOutDevice = vi
      .fn<Api['signOutDevice']>()
      .mockResolvedValue({ ok: true, current: false })
    const wrapper = await mountProfilePageAt(
      'security',
      fakeApi({
        myProfile: vi.fn<Api['myProfile']>().mockResolvedValue(sampleProfile),
        attributeCategories: vi
          .fn<Api['attributeCategories']>()
          .mockResolvedValue({ categories: sampleCategories }),
        myDevices: vi.fn<Api['myDevices']>().mockResolvedValue({
          devices: [
            {
              id: 3,
              label: 'Chrome on macOS',
              place: 'Lisbon',
              country: 'PT',
              last_seen_at: recent,
              first_seen_at: recent,
              current: true,
            },
            {
              id: 9,
              label: 'Safari on iPhone',
              place: null,
              country: null,
              last_seen_at: recent,
              first_seen_at: recent,
              current: false,
            },
          ],
        }),
        signOutDevice,
      }),
    )

    const list = wrapper.find('[data-test="devices"]')
    expect(list.text()).toContain('Chrome on macOS')
    expect(list.text()).toContain('Lisbon · Active 2 hours ago')
    expect(list.text()).toContain('This device')
    // Only the other device offers "Sign out".
    const buttons = list.findAll('[data-test="sign-out-device"]')
    expect(buttons).toHaveLength(1)

    await buttons[0]!.trigger('click')
    await flushPromises()

    expect(signOutDevice).toHaveBeenCalledWith(9)
    expect(wrapper.find('[data-test="devices"]').text()).not.toContain('Safari on iPhone')
    expect(
      useMessagesStore()
        .items.map((item) => item.text)
        .join(),
    ).toContain('Signed out of Safari on iPhone.')
  })

  it('signs out on all devices and goes to the login page', async () => {
    const logoutEverywhere = vi.fn<Api['logoutEverywhere']>().mockResolvedValue({ ok: true })
    const wrapper = await mountProfilePageAt(
      'security',
      fakeApi({
        myProfile: vi.fn<Api['myProfile']>().mockResolvedValue(sampleProfile),
        attributeCategories: vi
          .fn<Api['attributeCategories']>()
          .mockResolvedValue({ categories: sampleCategories }),
        logoutEverywhere,
      }),
    )

    await wrapper.find('[data-test="sign-out-everywhere"]').trigger('click')
    await flushPromises()

    expect(logoutEverywhere).toHaveBeenCalled()
    expect(wrapper.vm.$router.currentRoute.value.path).toBe('/login')
    expect(
      useMessagesStore()
        .items.map((item) => item.text)
        .join(),
    ).toContain('signed out on every device')
  })

  it('offers only email for two-factor codes, and leaves a legacy SMS choice unselected', async () => {
    const wrapper = await mountProfilePageAt(
      'contact',
      fakeApi({
        myProfile: vi
          .fn<Api['myProfile']>()
          .mockResolvedValue({ ...sampleProfile, two_factor_method: 'sms' }),
        attributeCategories: vi
          .fn<Api['attributeCategories']>()
          .mockResolvedValue({ categories: sampleCategories }),
      }),
    )

    const select = labelled(wrapper, 'Two-factor').find('select').element as HTMLSelectElement
    expect(select.value).toBe('')
    const enabled = Array.from(select.options)
      .filter((o) => !o.disabled)
      .map((o) => o.value)
    expect(enabled).toEqual(['email'])
  })

  it('saves personal & contact info, concatenating country code + number into one phone string', async () => {
    const updateAccount = vi.fn<Api['updateAccount']>().mockResolvedValue({ ok: true })
    const wrapper = await mountProfilePageAt(
      'contact',
      fakeApi({
        myProfile: vi.fn<Api['myProfile']>().mockResolvedValue(sampleProfile),
        attributeCategories: vi
          .fn<Api['attributeCategories']>()
          .mockResolvedValue({ categories: sampleCategories }),
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
        twoFactorMethod: 'email',
      }),
    )
    const messages = useMessagesStore()
    expect(messages.items.map((item) => item.text)).toContain('Contact details saved.')
  })

  it('points a contact-details refusal at the field that held them, until it is edited', async () => {
    const updateProfile = vi
      .fn<Api['updateProfile']>()
      .mockRejectedValue(
        new ApiError('Contact details can’t be shared here.', 400, 'contact_details', 'bio'),
      )
    const wrapper = await mountProfilePage(
      fakeApi({
        myProfile: vi.fn<Api['myProfile']>().mockResolvedValue(sampleProfile),
        attributeCategories: vi
          .fn<Api['attributeCategories']>()
          .mockResolvedValue({ categories: sampleCategories }),
        updateProfile,
      }),
    )

    const bio = wrapper.find('textarea[data-field="bio"]')
    await bio.setValue('WhatsApp me on 56002731')
    await forms(wrapper).details.trigger('submit.prevent')
    await flushPromises()

    expect(bio.attributes('aria-invalid')).toBe('true')
    const items = useMessagesStore().items
    const toast = items[items.length - 1]!
    expect(toast.text).toBe('Contact details can’t be shared here.')
    expect(toast.placement).toMatchObject({ type: 'input' })

    await bio.setValue('History walks')
    expect(bio.attributes('aria-invalid')).toBeUndefined()
  })

  it('adds an interest via the category/option comboboxes and saves it', async () => {
    const updateProfile = vi.fn<Api['updateProfile']>().mockResolvedValue({ ok: true })
    const wrapper = await mountProfilePage(
      fakeApi({
        myProfile: vi.fn<Api['myProfile']>().mockResolvedValue(sampleProfile),
        attributeCategories: vi
          .fn<Api['attributeCategories']>()
          .mockResolvedValue({ categories: sampleCategories }),
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
    const historyItem = interestsGroup
      .findAll('.combobox-list li')
      .find((li) => li.text() === 'History')!
    await historyItem.trigger('mousedown')

    // The dropdown must stay open after a pick, not just the input's focus
    // — picking a second option shouldn't require blurring and clicking
    // "+" again (the reported bug: selection closed the dropdown outright).
    expect(interestsGroup.find('.combobox-list').exists()).toBe(true)
    const foodItem = interestsGroup
      .findAll('.combobox-list li')
      .find((li) => li.text() === 'Food & drink')!
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
        attributeCategories: vi
          .fn<Api['attributeCategories']>()
          .mockResolvedValue({ categories: sampleCategories }),
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
        attributeCategories: vi
          .fn<Api['attributeCategories']>()
          .mockResolvedValue({ categories: sampleCategories }),
      }),
    )

    // food_allergies has no selections in sampleProfile — no row for it at all yet.
    const hasAllergiesRow = () =>
      forms(wrapper)
        .details.findAll('.attribute-group')
        .some((group) => group.text().includes('Food allergies'))
    expect(hasAllergiesRow()).toBe(false)

    // Reached via the bottom "Add another category" control, since it has no row of its own.
    const categoryInput = forms(wrapper).details.find('.add-category .combobox input')
    await categoryInput.trigger('focus')
    const allergiesItem = wrapper
      .findAll('.combobox-list li')
      .find((li) => li.text() === 'Food allergies')!
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
        attributeCategories: vi
          .fn<Api['attributeCategories']>()
          .mockResolvedValue({ categories: sampleCategories }),
        updateProfile,
      }),
    )

    const categoryInput = forms(wrapper).details.find('.add-category .combobox input')
    await categoryInput.trigger('focus')
    const allergiesItem = wrapper
      .findAll('.combobox-list li')
      .find((li) => li.text() === 'Food allergies')!
    await allergiesItem.trigger('mousedown')

    const allergiesGroup = forms(wrapper)
      .details.findAll('.attribute-group')
      .find((group) => group.text().includes('Food allergies'))!

    const optionInput = allergiesGroup.find('.combobox input')
    await optionInput.trigger('focus')
    const glutenItem = allergiesGroup
      .findAll('.combobox-list li')
      .find((li) => li.text() === 'Gluten')!
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
      expect.objectContaining({
        attributes: expect.objectContaining({ food_allergies: ['gluten'] }),
      }),
    )
  })

  it('creates a brand new category and option from the comboboxes', async () => {
    const updateProfile = vi.fn<Api['updateProfile']>().mockResolvedValue({ ok: true })
    const wrapper = await mountProfilePage(
      fakeApi({
        myProfile: vi.fn<Api['myProfile']>().mockResolvedValue(sampleProfile),
        attributeCategories: vi
          .fn<Api['attributeCategories']>()
          .mockResolvedValue({ categories: sampleCategories }),
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

  it('lets a guide pick where they guide and how far they travel on a slider, 0 meaning flexible', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const updateProfile = vi.fn<Api['updateProfile']>().mockResolvedValue({ ok: true })
    const lisbon = {
      label: 'Lisbon, Portugal',
      name: 'Lisbon',
      countryCode: 'PT',
      latitude: 38.72,
      longitude: -9.14,
      kind: 'city',
    }
    const geocode = vi.fn<Api['geocode']>().mockResolvedValue({ places: [lisbon] })
    const wrapper = await mountProfilePage(
      fakeApi({
        myProfile: vi.fn<Api['myProfile']>().mockResolvedValue(sampleProfile),
        attributeCategories: vi
          .fn<Api['attributeCategories']>()
          .mockResolvedValue({ categories: sampleCategories }),
        updateProfile,
        geocode,
      }),
    )

    const base = wrapper.find('[data-test="guide-base"]')
    const radius = () =>
      base.find<HTMLInputElement>('[data-test="guide-radius"] input[type="range"]')
    expect(radius().element.disabled).toBe(true)
    await base.find('[data-test="place-input"]').setValue('lisb')
    await vi.advanceTimersByTimeAsync(350)
    await flushPromises()
    expect(geocode).toHaveBeenCalledWith('lisb', 'en', undefined)
    await base.find('[data-test="place-option-0"]').trigger('mousedown')
    await radius().setValue(30)
    expect(base.find('[data-test="guide-radius"] [data-test="distance-value"]').text()).toBe(
      'Within 30 km',
    )

    await forms(wrapper).details.trigger('submit.prevent')
    await flushPromises()
    expect(updateProfile).toHaveBeenLastCalledWith(
      expect.objectContaining({
        base: { label: 'Lisbon, Portugal', countryCode: 'PT', latitude: 38.72, longitude: -9.14 },
        radiusKm: 30,
      }),
    )

    await radius().setValue(0)
    expect(base.find('[data-test="guide-radius"] [data-test="distance-value"]').text()).toBe(
      'Unlimited',
    )
    await forms(wrapper).details.trigger('submit.prevent')
    await flushPromises()
    expect(updateProfile).toHaveBeenLastCalledWith(expect.objectContaining({ radiusKm: null }))

    // No separate line repeats the place under the box; emptying the box removes it.
    const input = base.find('[data-test="place-input"]')
    await input.setValue('')
    await input.trigger('blur')
    await vi.advanceTimersByTimeAsync(150)
    await flushPromises()
    await forms(wrapper).details.trigger('submit.prevent')
    await flushPromises()
    expect(updateProfile).toHaveBeenLastCalledWith(expect.objectContaining({ base: null }))
    vi.useRealTimers()
  })

  it('prefills a saved base, and never sends one while editing as a traveller', async () => {
    const updateProfile = vi.fn<Api['updateProfile']>().mockResolvedValue({ ok: true })
    const wrapper = await mountProfilePage(
      fakeApi({
        myProfile: vi.fn<Api['myProfile']>().mockResolvedValue({
          ...sampleProfile,
          guide_base: {
            label: 'Kyoto, Japan',
            countryCode: 'JP',
            latitude: 35.01,
            longitude: 135.77,
            radiusKm: null,
          },
        }),
        attributeCategories: vi
          .fn<Api['attributeCategories']>()
          .mockResolvedValue({ categories: sampleCategories }),
        updateProfile,
      }),
    )
    expect(
      wrapper.find<HTMLInputElement>('[data-test="guide-base"] [data-test="place-input"]').element
        .value,
    ).toBe('Kyoto, Japan')
    expect(wrapper.find('.base-chosen').exists()).toBe(false)
    expect(
      wrapper.find<HTMLInputElement>('[data-test="guide-radius"] input[type="range"]').element
        .value,
    ).toBe('0')

    const auth = useAuthStore()
    auth.setUser({ ...auth.user!, roles: ['guide', 'guest'] })
    auth.setActiveRole('guest')
    await flushPromises()
    expect(wrapper.find('[data-test="guide-base"]').exists()).toBe(false)
    await forms(wrapper).details.trigger('submit.prevent')
    await flushPromises()
    expect(updateProfile.mock.lastCall![0]).not.toHaveProperty('base')
  })

  it('rejects a password change when confirmation does not match, without calling the API', async () => {
    const changePassword = vi.fn<Api['changePassword']>()
    const wrapper = await mountProfilePageAt(
      'security',
      fakeApi({
        myProfile: vi.fn<Api['myProfile']>().mockResolvedValue(sampleProfile),
        attributeCategories: vi
          .fn<Api['attributeCategories']>()
          .mockResolvedValue({ categories: sampleCategories }),
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
    const wrapper = await mountProfilePageAt(
      'security',
      fakeApi({
        myProfile: vi.fn<Api['myProfile']>().mockResolvedValue(sampleProfile),
        attributeCategories: vi
          .fn<Api['attributeCategories']>()
          .mockResolvedValue({ categories: sampleCategories }),
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
    expect(
      (password.find('input[autocomplete="current-password"]').element as HTMLInputElement).value,
    ).toBe('')
  })

  it('shows an error toast when changing the password fails', async () => {
    const changePassword = vi
      .fn<Api['changePassword']>()
      .mockRejectedValue(new ApiError('Current password is incorrect.', 400))
    const wrapper = await mountProfilePageAt(
      'security',
      fakeApi({
        myProfile: vi.fn<Api['myProfile']>().mockResolvedValue(sampleProfile),
        attributeCategories: vi
          .fn<Api['attributeCategories']>()
          .mockResolvedValue({ categories: sampleCategories }),
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

  it('offers the role a guide-only account lacks, and adds it once its agreement is accepted', async () => {
    const restore = stubDialog()
    const legalDocument = vi.fn<Api['legalDocument']>().mockResolvedValue({
      slug: 'traveller_agreement',
      version: '2026-10-18',
      language: 'en',
      title: 'Traveller Agreement',
      body: '## 1. Scope\n\nApplies.',
    })
    const addRole = vi.fn<Api['addRole']>().mockResolvedValue({ roles: ['guide', 'guest'] })
    const wrapper = await mountProfilePage(
      fakeApi({
        myProfile: vi.fn<Api['myProfile']>().mockResolvedValue(sampleProfile),
        attributeCategories: vi
          .fn<Api['attributeCategories']>()
          .mockResolvedValue({ categories: sampleCategories }),
        legalDocument,
        addRole,
      }),
      true,
      '/profile?addRole=guest',
    )
    const auth = useAuthStore()

    // The header link lands here with ?addRole=: the traveller agreement opens.
    expect(wrapper.find('[data-test="add-role-banner"]').text()).toContain('I want to travel')
    expect(legalDocument).toHaveBeenCalledWith('traveller_agreement', 'en')
    expect(wrapper.find('dialog').attributes('open')).toBe('')
    expect(wrapper.find('dialog').text()).toContain('Traveller Agreement')

    await wrapper.find('[data-test="accept-role"]').trigger('click')
    await flushPromises()

    expect(addRole).toHaveBeenCalledWith({
      role: 'guest',
      agreementSlug: 'traveller_agreement',
      agreementVersion: '2026-10-18',
      language: 'en',
    })
    expect(auth.roles).toEqual(['guide', 'guest'])
    // …and switched into it, so the page shows the traveller's fields.
    expect(auth.activeRole).toBe('guest')
    restore()
  })

  it('shows which role is being edited for an account holding both, as segments that switch it', async () => {
    const wrapper = await mountProfilePage(
      fakeApi({
        myProfile: vi.fn<Api['myProfile']>().mockResolvedValue(sampleProfile),
        attributeCategories: vi
          .fn<Api['attributeCategories']>()
          .mockResolvedValue({ categories: sampleCategories }),
      }),
    )
    const auth = useAuthStore()
    auth.setUser({ ...auth.user!, roles: ['guide', 'guest'] })
    await flushPromises()

    expect(wrapper.find('[data-test="add-role-banner"]').exists()).toBe(false)
    // "As a traveller | As a guide", the one being edited chosen; picking the other switches.
    const segments = wrapper.get('[data-test="profile-role"]')
    expect(segments.text()).toContain('As a traveller')
    const guide = segments.get<HTMLInputElement>('input[value="guide"]')
    expect(guide.element.checked).toBe(true)
    await segments.get<HTMLInputElement>('input[value="guest"]').setValue(true)
    expect(auth.activeRole).toBe('guest')
  })
})
