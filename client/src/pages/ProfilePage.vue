<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AttributeCombobox, { type ComboboxSuggestion } from '@/components/AttributeCombobox.vue'
import RangePicker from '@/components/RangePicker.vue'
import ImageCropper from '@/components/ImageCropper.vue'
import LevelPanel from '@/components/LevelPanel.vue'
import PanelCard from '@/components/PanelCard.vue'
import PlaceSearch from '@/components/PlaceSearch.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import TermsDialog from '@/components/TermsDialog.vue'
import { t, tn } from '@/i18n'
import { COUNTRY_CODES, countryForDialCode } from '@/data/countryCodes'
import {
  ApiError,
  roleAgreementSlug,
  type AttributeCategory,
  type LegalDocumentView,
  type LegalSlug,
  type MyDevice,
  type MyProfile,
  type PlacePoint,
  type Role,
  type TwoFactorChannel,
  useApi,
} from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useAvatarStore } from '@/stores/avatar'
import { anchorTo, useMessagesStore } from '@/stores/messages'
import { reportApiError } from '@/utils/reportApiError'
import { timeAgo } from '@/utils/reviews'
import { rangeFormatFor } from '@/utils/formatRange'
import { forgetTrustedDevice } from '@/utils/trustedDevice'
import type { Gender, NumericRange, RangeMap } from '@/types'

/**
 * Self-service account/profile editor. Three independent sections, each its
 * own save action, mirroring the three backend concerns:
 *   - Change password                                  → api.changePassword()
 *     (nested inside the personal-info panel below, at the very top, behind
 *     a collapsed <details> — see the template)
 *   - Personal & contact info + photo + 2FA channel  → api.updateAccount()
 *   - Profile details (bio/price/etc.) plus every category → api.updateProfile()
 *     (option categories and `range` ones alike, from
 *     api.attributeCategories(); a guide sees a category under its
 *     provider-side label, so `interests` reads as "Specialities")
 */

const api = useApi()
const auth = useAuthStore()
const avatar = useAvatarStore()
const messages = useMessagesStore()

const loading = ref(true)
const loadError = ref('')

const profile = ref<MyProfile | null>(null)
/** The profile shows the fields of the role the account is acting as. */
const isGuide = computed(() => auth.activeRole === 'guide')

const route = useRoute()
const router = useRouter()

/**
 * "Become a guide" / "I want to travel" (the header link, `?addRole=`, or the
 * button here): the role the account doesn't hold yet, offered only then.
 */
const missingRole = computed<Role | null>(() => {
  if (auth.roles.length !== 1) return null
  return auth.roles[0] === 'guide' ? 'guest' : 'guide'
})

/** The role being added — its agreement is open in the modal while set. */
const addingRole = ref<Role | null>(null)
const addingRoleBusy = ref(false)
const agreementSlugs = computed(() =>
  addingRole.value ? [roleAgreementSlug(addingRole.value)] : [],
)

function startAddingRole(role: Role) {
  if (role === missingRole.value) addingRole.value = role
}

function cancelAddingRole() {
  addingRole.value = null
  if (route.query.addRole) void router.replace({ path: '/profile' })
}

/**
 * Accepting the agreement grants the role and switches into it. Switching
 * re-renders the page as that role (App.vue keys the view by role), so the
 * reload lands on `?focus=details`, which scrolls to the role's fields.
 */
async function acceptRole(documents: LegalDocumentView[] | null) {
  const role = addingRole.value
  const agreement = documents?.[0]
  if (!role || !agreement || addingRoleBusy.value || !auth.user) return
  addingRoleBusy.value = true
  try {
    const { roles } = await api.addRole({
      role,
      agreementSlug: agreement.slug as LegalSlug,
      agreementVersion: agreement.version,
      language: agreement.language,
    })
    auth.setUser({ ...auth.user, roles })
    addingRole.value = null
    messages.success(
      t(role === 'guide' ? 'profile.add_role.added_guide' : 'profile.add_role.added_guest'),
    )
    await router.replace({ path: '/profile', query: { focus: 'details' } })
    auth.setActiveRole(role)
  } catch (err) {
    reportApiError(err, t('profile.add_role.failed'))
  } finally {
    addingRoleBusy.value = false
  }
}

// --- Personal & contact info ------------------------------------------------
const name = ref('')
const email = ref('')
const phoneCountryCode = ref('')
const phoneCountryQuery = ref('')
const phoneNumber = ref('')
const twoFactorMethod = ref<'' | TwoFactorChannel>('')
const savingAccount = ref(false)
const accountPanelRef = ref<HTMLElement | null>(null)
const cropperRef = ref<InstanceType<typeof ImageCropper> | null>(null)

// Computed so country names follow a language switch (`common.country.<iso2>`).
const countryCodeSuggestions = computed<ComboboxSuggestion[]>(() =>
  COUNTRY_CODES.map((country) => ({
    value: country.dialCode,
    label: t('profile.contact.country_option', {
      country: t(`common.country.${country.iso2}`),
      dial_code: country.dialCode,
    }),
  })),
)

/**
 * `users.phone` is one combined string (e.g. "+350 56002731") — the country
 * code/local number split only exists here, in the UI. Matches the longest
 * known dial code that prefixes the stored number (ties, e.g. US/CA both
 * "+1", are unresolvable and arbitrary — harmless, since both fields get
 * concatenated straight back into the same string on save regardless of
 * which country label happened to display).
 */
function splitPhone(raw: string): { code: string; number: string } {
  const compact = raw.replace(/\s+/g, '')
  if (!compact.startsWith('+')) return { code: '', number: raw.trim() }

  const byLongestCode = [...COUNTRY_CODES].sort((a, b) => b.dialCode.length - a.dialCode.length)
  const match = byLongestCode.find((country) => compact.startsWith(country.dialCode))
  return match
    ? { code: match.dialCode, number: compact.slice(match.dialCode.length) }
    : { code: '', number: raw.trim() }
}

function selectCountryCode(suggestion: ComboboxSuggestion) {
  phoneCountryCode.value = suggestion.value
  phoneCountryQuery.value = suggestion.label
}

// --- Profile details ---------------------------------------------------------
const dateOfBirth = ref('')
const todayIso = computed(() => new Date().toISOString().slice(0, 10))
const gender = ref<'' | Gender>('')
const headline = ref('')
const bio = ref('')
const priceAmount = ref<number | null>(null)
const priceLabel = ref('')
const priceNote = ref('')
const includes = ref('')
const party = ref('')
const durationHours = ref<number | null>(null)
// Where a guide works, and how far they'll travel (empty = flexible).
const guideBase = ref<PlacePoint | null>(null)
const radiusKm = ref<number | ''>('')
const savingProfile = ref(false)
const profilePanelRef = ref<HTMLElement | null>(null)

// Dynamic categories: a mutable local copy (grown in-place when the user
// creates a new category/option, so it's reflected immediately without a
// refetch) plus the values actually selected, and the display labels for
// any category the user just created this session (sent alongside
// `attributes` so the server knows what to call a brand-new key — see
// `Profiles::setAttributeValues()`).
const localCategories = ref<AttributeCategory[]>([])
const selectedAttributes = reactive<Record<string, string[]>>({})
/** Spans per `range` category — the hours a guide works. Empty means "whenever". */
const selectedRanges = reactive<RangeMap>({})
const newCategoryLabels = reactive<Record<string, string>>({})

const categoryQuery = ref('')

/**
 * Which category rows are currently "active" — showing their option-adding
 * combobox instead of a "+" button. A category enters this set only when
 * the user explicitly reaches for it (`expandCategory()`, via a row's own
 * "+" or via picking/creating one from the bottom "add a category"
 * control), and *stays* in it across the empty→non-empty transition
 * (adding the first option doesn't yank the input away mid-interaction)
 * until the input actually loses focus (`collapseCategory()`, wired to
 * `AttributeCombobox`'s `@blur`).
 *
 * This doubles as the visibility gate for an otherwise-empty category —
 * see `visibleCategories` below: a category with no selections is only
 * ever shown while it's in this set (being actively added to), and
 * disappears again on blur if nothing was added. A category with at least
 * one selection is always shown regardless of this set.
 */
const manuallyExpandedCategories = reactive<Set<string>>(new Set())
/** One query string per category row — each row's combobox is independent, unlike the single shared one this replaced. */
const optionQueries = reactive<Record<string, string>>({})

/**
 * Empty categories stay hidden by default (nothing to show) — a category
 * only appears here once it has a selection, or while the user is actively
 * adding to it (freshly created via the bottom control, or reached through
 * it while still empty). `categorySuggestions` below deliberately searches
 * every known category regardless of this filter, so a hidden empty one is
 * still reachable through "Add another category".
 */
/**
 * The categories the role being edited has: everything but the other role's
 * own (`scope` — hours are a guide's, food allergies a traveller's). Shared
 * ones (languages) are the same set in both roles; per-role ones
 * (interests/specialities, distance) hold a set each.
 */
const roleCategories = computed(() => {
  const other = isGuide.value ? 'guest' : 'guide'
  return localCategories.value.filter((category) => category.scope !== other)
})

/** `range` categories this role has — a guide's hours; distance for either. */
const rangeCategories = computed(() =>
  roleCategories.value.filter((category) => category.kind === 'range'),
)

function addRange(categoryKey: string, range: NumericRange) {
  const current = selectedRanges[categoryKey] ?? []
  if (current.some((span) => span[0] === range[0] && span[1] === range[1])) return
  selectedRanges[categoryKey] = [...current, range]
}

function removeRange(categoryKey: string, index: number) {
  const next = (selectedRanges[categoryKey] ?? []).filter((_, position) => position !== index)
  if (next.length) {
    selectedRanges[categoryKey] = next
  } else {
    delete selectedRanges[categoryKey]
  }
}

const visibleCategories = computed(() =>
  roleCategories.value.filter(
    (category) =>
      (selectedAttributes[category.key]?.length ?? 0) > 0 ||
      manuallyExpandedCategories.has(category.key),
  ),
)

const categorySuggestions = computed<ComboboxSuggestion[]>(() =>
  roleCategories.value
    .filter((category) => category.kind !== 'range')
    .map((category) => ({ value: category.key, label: category.label })),
)

function optionSuggestionsFor(categoryKey: string): ComboboxSuggestion[] {
  const category = localCategories.value.find((candidate) => candidate.key === categoryKey)
  if (!category) return []
  const selected = selectedAttributes[categoryKey] ?? []
  return category.options
    .filter((option) => !selected.includes(option.value))
    .map((option) => ({ value: option.value, label: option.label }))
}

function slugifyCategoryKey(label: string): string {
  return (
    label
      .trim()
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '_')
      .replace(/^_+|_+$/g, '') || 'category'
  )
}

function expandCategory(categoryKey: string) {
  manuallyExpandedCategories.add(categoryKey)
}

function collapseCategory(categoryKey: string) {
  manuallyExpandedCategories.delete(categoryKey)
}

/** From the bottom "add a category" combobox — picks an existing category or creates a new one, then expands it for adding an option, same as clicking that row's "+" would. */
function selectCategory(suggestion: ComboboxSuggestion & { isNew: boolean }) {
  let key = suggestion.value
  if (suggestion.isNew) {
    key = slugifyCategoryKey(suggestion.label)
    if (!localCategories.value.some((category) => category.key === key)) {
      localCategories.value.push({
        key,
        label: suggestion.label,
        multi: true,
        options: [],
        // A brand-new category is always an option list: `range` needs bounds
        // that only a migration can set (see `Profiles::setRanges()`).
        kind: 'options',
        providerLabel: null,
        rangeMin: null,
        rangeMax: null,
      })
      newCategoryLabels[key] = suggestion.label
    }
  }
  expandCategory(key)
  categoryQuery.value = ''
}

function selectOptionFor(categoryKey: string, suggestion: ComboboxSuggestion & { isNew: boolean }) {
  const category = localCategories.value.find((candidate) => candidate.key === categoryKey)
  if (!category) return

  const list = selectedAttributes[categoryKey] ?? (selectedAttributes[categoryKey] = [])
  if (!list.includes(suggestion.value)) list.push(suggestion.value)

  if (suggestion.isNew && !category.options.some((option) => option.value === suggestion.value)) {
    category.options.push({ value: suggestion.value, label: suggestion.label })
  }
  optionQueries[categoryKey] = ''
}

function removeAttribute(categoryKey: string, value: string) {
  const list = selectedAttributes[categoryKey]
  if (!list) return

  const index = list.indexOf(value)
  if (index !== -1) list.splice(index, 1)
  if (list.length === 0) delete selectedAttributes[categoryKey]
}

function optionLabelFor(categoryKey: string, value: string): string {
  const category = localCategories.value.find((candidate) => candidate.key === categoryKey)
  return category?.options.find((option) => option.value === value)?.label ?? value
}

// --- Password ----------------------------------------------------------------
const currentPassword = ref('')
const newPassword = ref('')
const confirmPassword = ref('')
const changingPassword = ref(false)
const passwordPanelRef = ref<HTMLElement | null>(null)

function applyProfile(data: MyProfile) {
  profile.value = data
  name.value = data.name ?? ''
  email.value = data.email

  const { code, number } = splitPhone(data.phone ?? '')
  phoneCountryCode.value = code
  const country = countryForDialCode(code)
  phoneCountryQuery.value = country
    ? t('profile.contact.country_option', {
        country: t(`common.country.${country.iso2}`),
        dial_code: country.dialCode,
      })
    : ''
  phoneNumber.value = number

  // Only email can be chosen for now; anything else loads unselected so the
  // next save forces a real pick (see the select's disabled options).
  twoFactorMethod.value = data.two_factor_method === 'email' ? 'email' : ''

  dateOfBirth.value = data.date_of_birth ?? ''
  gender.value = data.gender ?? ''
  headline.value = data.headline ?? ''
  bio.value = data.bio ?? ''
  priceAmount.value = data.price_amount
  priceLabel.value = data.price_label ?? ''
  priceNote.value = data.price_note ?? ''
  includes.value = data.includes ?? ''
  party.value = data.party ?? ''
  durationHours.value = data.duration_hours
  const base = data.guide_base ?? null
  guideBase.value = base
    ? {
        label: base.label,
        countryCode: base.countryCode,
        latitude: base.latitude,
        longitude: base.longitude,
      }
    : null
  radiusKm.value = base?.radiusKm ?? ''

  for (const key of Object.keys(selectedAttributes)) delete selectedAttributes[key]
  Object.assign(selectedAttributes, data.attributes)

  for (const key of Object.keys(selectedRanges)) delete selectedRanges[key]
  Object.assign(selectedRanges, data.ranges)
}

/** Devices that skip the login code (see `AccountSecurity::trustDevice()`). */
const trustedDeviceCount = computed(() => profile.value?.trusted_device_count ?? 0)
const forgettingDevices = ref(false)

async function forgetDevices(event: MouseEvent) {
  if (!profile.value || forgettingDevices.value) return
  const placement = anchorTo('element', event.currentTarget as HTMLElement | null)
  forgettingDevices.value = true
  try {
    await api.forgetTrustedDevices()
    forgetTrustedDevice(profile.value.email)
    profile.value = { ...profile.value, trusted_device_count: 0 }
    messages.success(t('profile.toast.devices_forgotten'), placement)
  } catch (err) {
    reportApiError(err, t('profile.toast.devices_failed'), placement)
  } finally {
    forgettingDevices.value = false
  }
}

/** The browsers this account is signed in on — this one can't be signed out from the list. */
const devices = ref<MyDevice[]>([])
const signingOutDevice = ref<number | null>(null)

async function loadDevices() {
  try {
    devices.value = (await api.myDevices()).devices
  } catch {
    // Best-effort: the rest of the page doesn't need it.
  }
}

function deviceMeta(device: MyDevice): string {
  return [device.place, t('profile.devices.last_active', { time: timeAgo(device.last_seen_at) })]
    .filter(Boolean)
    .join(' · ')
}

async function signOutDevice(device: MyDevice, event: MouseEvent) {
  if (signingOutDevice.value !== null) return
  const placement = anchorTo('element', event.currentTarget as HTMLElement | null)
  signingOutDevice.value = device.id
  try {
    await api.signOutDevice(device.id)
    devices.value = devices.value.filter((d) => d.id !== device.id)
    messages.success(
      t('profile.toast.device_signed_out', {
        device: device.label || t('profile.devices.unknown'),
      }),
      placement,
    )
  } catch (err) {
    reportApiError(err, t('profile.toast.device_sign_out_failed'), placement)
  } finally {
    signingOutDevice.value = null
  }
}

/** Every sign-in of the account ends — this one too — so it's back to the login page. */
const signingOutEverywhere = ref(false)

async function signOutEverywhere(event: MouseEvent) {
  if (signingOutEverywhere.value) return
  const placement = anchorTo('element', event.currentTarget as HTMLElement | null)
  signingOutEverywhere.value = true
  try {
    await api.logoutEverywhere()
    messages.success(t('profile.toast.signed_out_everywhere'))
    await router.push('/login')
  } catch (err) {
    reportApiError(err, t('profile.toast.sign_out_everywhere_failed'), placement)
  } finally {
    signingOutEverywhere.value = false
  }
}

onMounted(async () => {
  if (!auth.user) {
    loading.value = false
    return
  }
  void loadDevices()

  try {
    const [profileResult, categoriesResult] = await Promise.all([
      api.myProfile(),
      api.attributeCategories(),
    ])
    applyProfile(profileResult)
    localCategories.value = categoriesResult.categories
  } catch (err) {
    loadError.value = err instanceof ApiError ? err.message : t('profile.toast.load_failed')
  } finally {
    loading.value = false
  }

  const wanted = route.query.addRole
  if (wanted === 'guide' || wanted === 'guest') startAddingRole(wanted)
  if (route.query.focus === 'details') {
    await nextTick()
    profilePanelRef.value?.scrollIntoView?.({ block: 'start', behavior: 'smooth' })
    void router.replace({ path: '/profile' })
  }
})

/**
 * The input a refused save pointed at (`ApiError.field` for a
 * `contact_details` error — see `ContactFilter`): marked invalid until it's
 * edited, and the toast sits under it rather than on the panel.
 */
const invalidField = ref<string | null>(null)

function reportSaveError(err: unknown, fallback: string, panel: HTMLElement | null) {
  const field = err instanceof ApiError && err.code === 'contact_details' ? err.field : undefined
  const input = field ? panel?.querySelector<HTMLElement>(`[data-field="${field}"]`) : null
  invalidField.value = input ? (field ?? null) : null
  // Toasts are fixed to the rect measured now, so the field must already be
  // where it'll stay: no smooth scroll still under way when it's measured.
  input?.scrollIntoView?.({ block: 'center', behavior: 'instant' })
  input?.focus({ preventScroll: true })
  reportApiError(err, fallback, input ? anchorTo('input', input) : anchorTo('element', panel))
}

function clearInvalid(field: string) {
  if (invalidField.value === field) invalidField.value = null
}

async function saveAccount() {
  if (!auth.user || savingAccount.value || !twoFactorMethod.value) return
  savingAccount.value = true

  try {
    const image = cropperRef.value?.getCroppedDataUrl() ?? undefined
    const phone = phoneCountryCode.value
      ? `${phoneCountryCode.value} ${phoneNumber.value.trim()}`.trim()
      : phoneNumber.value.trim()

    await api.updateAccount({
      name: name.value.trim(),
      email: email.value.trim(),
      phone,
      twoFactorMethod: twoFactorMethod.value,
      ...(image ? { image } : {}),
    })
    auth.setUser({
      id: auth.user.id,
      email: email.value.trim(),
      name: name.value.trim(),
      role: auth.user.role,
      roles: auth.user.roles,
    })
    if (image) avatar.set(image)
    messages.success(t('profile.toast.contact_saved'), anchorTo('element', accountPanelRef.value))
  } catch (err) {
    reportSaveError(err, t('profile.toast.contact_failed'), accountPanelRef.value)
  } finally {
    savingAccount.value = false
  }
}

async function saveProfile() {
  if (!auth.user || savingProfile.value) return
  savingProfile.value = true

  try {
    await api.updateProfile({
      ...(dateOfBirth.value ? { dateOfBirth: dateOfBirth.value } : {}),
      ...(gender.value ? { gender: gender.value } : {}),
      headline: headline.value.trim(),
      bio: bio.value.trim(),
      ...(priceAmount.value !== null ? { priceAmount: priceAmount.value } : {}),
      priceLabel: priceLabel.value.trim(),
      priceNote: priceNote.value.trim(),
      includes: includes.value.trim(),
      party: party.value.trim(),
      ...(durationHours.value !== null ? { durationHours: durationHours.value } : {}),
      attributes: { ...selectedAttributes },
      newCategoryLabels: { ...newCategoryLabels },
      ranges: { ...selectedRanges },
      ...(isGuide.value
        ? {
            base: guideBase.value,
            radiusKm: radiusKm.value === '' ? null : Math.round(radiusKm.value),
          }
        : {}),
    })
    messages.success(t('profile.toast.profile_saved'), anchorTo('element', profilePanelRef.value))
  } catch (err) {
    reportSaveError(err, t('profile.toast.profile_failed'), profilePanelRef.value)
  } finally {
    savingProfile.value = false
  }
}

async function changePassword() {
  if (!auth.user || changingPassword.value) return

  if (newPassword.value !== confirmPassword.value) {
    messages.error(
      t('profile.toast.password_mismatch'),
      anchorTo('element', passwordPanelRef.value),
    )
    return
  }

  changingPassword.value = true
  try {
    await api.changePassword({
      currentPassword: currentPassword.value,
      newPassword: newPassword.value,
    })
    currentPassword.value = ''
    newPassword.value = ''
    confirmPassword.value = ''
    messages.success(
      t('profile.toast.password_changed'),
      anchorTo('element', passwordPanelRef.value),
    )
  } catch (err) {
    reportApiError(
      err,
      t('profile.toast.password_failed'),
      anchorTo('element', passwordPanelRef.value),
    )
  } finally {
    changingPassword.value = false
  }
}
</script>

<template>
  <section class="section shell">
    <div class="profile-head">
      <SectionTitle
        :eyebrow="t('profile.header.eyebrow')"
        :heading="t('profile.header.heading')"
        :lead="t('profile.header.lead')"
      />
      <LevelPanel v-if="profile && auth.activeRole" class="profile-level" :role="auth.activeRole" />
    </div>

    <div
      v-if="auth.user && !loading && missingRole"
      class="add-role-banner"
      data-test="add-role-banner"
    >
      <p class="tiny muted">
        {{
          t(missingRole === 'guide' ? 'profile.add_role.hint_guide' : 'profile.add_role.hint_guest')
        }}
      </p>
      <button
        type="button"
        class="secondary"
        data-test="add-role-button"
        @click="startAddingRole(missingRole)"
      >
        {{
          t(
            missingRole === 'guide'
              ? 'profile.add_role.become_guide'
              : 'profile.add_role.want_to_travel',
          )
        }}
      </button>
    </div>
    <p v-else-if="auth.user && !loading && auth.roles.length > 1" class="tiny muted editing-role">
      {{ t(isGuide ? 'profile.add_role.editing_guide' : 'profile.add_role.editing_guest') }}
    </p>

    <TermsDialog :open="addingRole !== null" :slugs="agreementSlugs" @close="cancelAddingRole">
      <template #intro>
        <p class="muted">
          {{
            t(
              addingRole === 'guide'
                ? 'profile.add_role.intro_guide'
                : 'profile.add_role.intro_guest',
            )
          }}
        </p>
      </template>
      <template #footer="{ documents }">
        <button
          type="button"
          class="secondary outline"
          :disabled="addingRoleBusy"
          @click="cancelAddingRole"
        >
          {{ t('profile.add_role.cancel') }}
        </button>
        <button
          type="button"
          data-test="accept-role"
          :disabled="!documents || addingRoleBusy"
          :aria-busy="addingRoleBusy"
          @click="acceptRole(documents)"
        >
          {{ t('profile.add_role.accept') }}
        </button>
      </template>
    </TermsDialog>

    <PanelCard v-if="!auth.user" class="narrow" :title="t('profile.signed_out.title')">
      <p class="muted">{{ t('profile.signed_out.body') }}</p>
      <RouterLink to="/login" role="button">{{ t('profile.signed_out.log_in') }}</RouterLink>
    </PanelCard>

    <PanelCard v-else-if="loading" :title="t('profile.page.loading')" />

    <PanelCard v-else-if="loadError" :title="t('profile.page.load_error')">
      <p class="muted">{{ loadError }}</p>
    </PanelCard>

    <div v-else class="profile-grid">
      <div ref="accountPanelRef">
        <PanelCard :title="t('profile.contact.title')" :subtitle="t('profile.contact.subtitle')">
          <details ref="passwordPanelRef" class="password-disclosure">
            <summary>{{ t('profile.password.title') }}</summary>
            <form @submit.prevent="changePassword">
              <label>
                {{ t('profile.password.current') }}
                <input
                  v-model="currentPassword"
                  type="password"
                  autocomplete="current-password"
                  required
                />
              </label>
              <label>
                {{ t('profile.password.new') }}
                <input v-model="newPassword" type="password" autocomplete="new-password" required />
              </label>
              <label>
                {{ t('profile.password.confirm') }}
                <input
                  v-model="confirmPassword"
                  type="password"
                  autocomplete="new-password"
                  required
                />
              </label>

              <button
                type="submit"
                class="full"
                :aria-busy="changingPassword"
                :disabled="changingPassword"
              >
                {{
                  changingPassword ? t('profile.password.changing') : t('profile.password.submit')
                }}
              </button>
            </form>
          </details>

          <form @submit.prevent="saveAccount">
            <label>
              {{ t('profile.contact.name') }}
              <input
                v-model="name"
                type="text"
                autocomplete="name"
                data-field="name"
                :aria-invalid="invalidField === 'name' || undefined"
                @input="clearInvalid('name')"
              />
            </label>
            <label>
              {{ t('common.contact.email') }}
              <input v-model="email" type="email" autocomplete="email" required />
            </label>

            <div class="phone-row">
              <label class="phone-code">
                {{ t('profile.contact.country_code') }}
                <AttributeCombobox
                  v-model="phoneCountryQuery"
                  :suggestions="countryCodeSuggestions"
                  :allow-create="false"
                  :placeholder="t('profile.contact.country_placeholder')"
                  @select="selectCountryCode"
                />
              </label>
              <label class="phone-number">
                {{ t('common.contact.phone') }}
                <input v-model="phoneNumber" type="tel" autocomplete="tel" placeholder="56002731" />
              </label>
            </div>

            <label>
              {{ t('profile.contact.two_factor') }}
              <select v-model="twoFactorMethod" required>
                <option value="" disabled>{{ t('profile.contact.two_factor_choose') }}</option>
                <option value="email">{{ t('common.contact.email') }}</option>
                <!-- Not wired to a provider yet — AccountSecurity.php only sends by email. -->
                <option value="sms" disabled>{{ t('profile.contact.two_factor_sms') }}</option>
                <option value="whatsapp" disabled>
                  {{ t('profile.contact.two_factor_whatsapp') }}
                </option>
              </select>
              <small class="tiny muted">
                {{ t('profile.contact.two_factor_hint') }}
              </small>
            </label>
            <p v-if="trustedDeviceCount > 0" class="tiny muted trusted-devices">
              {{ tn('profile.contact.trusted_devices', trustedDeviceCount) }}
              <button
                type="button"
                class="link-btn"
                :aria-busy="forgettingDevices"
                :disabled="forgettingDevices"
                @click="forgetDevices"
              >
                {{ t('profile.contact.forget_devices') }}
              </button>
            </p>
            <div v-if="devices.length" class="devices" data-test="devices">
              <p class="tiny muted devices-title">{{ t('profile.devices.title') }}</p>
              <ul>
                <li v-for="device in devices" :key="device.id">
                  <span class="device-text">
                    <span class="device-name">{{
                      device.label || t('profile.devices.unknown')
                    }}</span>
                    <span class="tiny muted">{{ deviceMeta(device) }}</span>
                  </span>
                  <span v-if="device.current" class="tiny this-device">
                    {{ t('profile.devices.this_device') }}
                  </span>
                  <button
                    v-else
                    type="button"
                    class="link-btn tiny"
                    data-test="sign-out-device"
                    :aria-busy="signingOutDevice === device.id"
                    :disabled="signingOutDevice !== null"
                    @click="signOutDevice(device, $event)"
                  >
                    {{ t('profile.devices.sign_out') }}
                  </button>
                </li>
              </ul>
            </div>
            <p class="tiny muted sign-out-everywhere">
              <button
                type="button"
                class="link-btn"
                data-test="sign-out-everywhere"
                :aria-busy="signingOutEverywhere"
                :disabled="signingOutEverywhere"
                @click="signOutEverywhere"
              >
                {{ t('profile.contact.sign_out_everywhere') }}
              </button>
              {{ t('profile.contact.sign_out_everywhere_hint') }}
            </p>

            <div v-if="profile?.image" class="current-photo">
              <img :src="profile.image" :alt="t('profile.contact.photo_alt')" />
              <span class="tiny muted">{{ t('profile.contact.current_photo') }}</span>
            </div>
            <ImageCropper ref="cropperRef" />

            <!-- Set by the identity check, not editable here (see Ranking.php). -->
            <p
              v-if="profile"
              class="verification tiny"
              :class="{ 'verification--done': profile.verified }"
              data-test="verification"
            >
              <template v-if="profile.verified">
                <strong
                  ><span aria-hidden="true">✓</span>
                  {{ t('profile.verification.verified') }}</strong
                >
                <template v-if="profile.verification_method">
                  · {{ t(`profile.verification.method_${profile.verification_method}`) }}</template
                >
              </template>
              <template v-else>
                <strong>{{ t('profile.verification.not_yet') }}</strong>
                {{ t('profile.verification.coming_soon') }}
              </template>
            </p>

            <button type="submit" class="full" :aria-busy="savingAccount" :disabled="savingAccount">
              {{ savingAccount ? t('profile.contact.saving') : t('profile.contact.submit') }}
            </button>
          </form>
        </PanelCard>
      </div>

      <div ref="profilePanelRef">
        <PanelCard :title="t('profile.details.title')" :subtitle="t('profile.details.subtitle')">
          <form @submit.prevent="saveProfile">
            <label>
              {{ t('profile.details.date_of_birth') }}
              <input v-model="dateOfBirth" type="date" :max="todayIso" />
            </label>

            <label v-if="isGuide">
              {{ t('profile.details.gender') }}
              <select v-model="gender">
                <option value="">{{ t('profile.details.gender_unset') }}</option>
                <option value="male">{{ t('common.gender.male') }}</option>
                <option value="female">{{ t('common.gender.female') }}</option>
              </select>
            </label>

            <label v-if="isGuide">
              {{ t('profile.details.headline') }}
              <input
                v-model="headline"
                type="text"
                :placeholder="t('profile.details.headline_placeholder')"
                data-field="headline"
                :aria-invalid="invalidField === 'headline' || undefined"
                @input="clearInvalid('headline')"
              />
            </label>

            <label v-if="!isGuide">
              {{ t('profile.details.party') }}
              <input
                v-model="party"
                type="text"
                :placeholder="t('profile.details.party_placeholder')"
                data-field="party"
                :aria-invalid="invalidField === 'party' || undefined"
                @input="clearInvalid('party')"
              />
            </label>

            <label>
              {{ t('profile.details.bio') }}
              <textarea
                v-model="bio"
                rows="3"
                data-field="bio"
                aria-describedby="contact-hint"
                :aria-invalid="invalidField === 'bio' || undefined"
                @input="clearInvalid('bio')"
              />
              <small id="contact-hint" class="muted">{{ t('profile.details.contact_hint') }}</small>
            </label>

            <template v-if="isGuide">
              <fieldset class="guide-base" data-test="guide-base">
                <legend>{{ t('profile.details.base_title') }}</legend>
                <label for="guide-base-place" class="tiny muted">{{
                  t('profile.details.base_hint')
                }}</label>
                <PlaceSearch v-model="guideBase" input-id="guide-base-place" />
                <p v-if="guideBase" class="tiny base-chosen">
                  📍 {{ guideBase.label }}
                  <button
                    type="button"
                    class="link-btn"
                    data-test="guide-base-remove"
                    @click="guideBase = null"
                  >
                    {{ t('profile.details.base_remove') }}
                  </button>
                </p>
                <label>
                  {{ t('profile.details.radius_label') }}
                  <input
                    v-model.number="radiusKm"
                    type="number"
                    min="1"
                    max="1000"
                    step="1"
                    :disabled="!guideBase"
                    aria-describedby="radius-hint"
                    data-test="guide-radius"
                  />
                  <small id="radius-hint" class="muted">{{
                    t('profile.details.radius_hint')
                  }}</small>
                </label>
              </fieldset>
              <label>
                {{ t('profile.details.price_amount') }}
                <input
                  v-model.number="priceAmount"
                  type="number"
                  min="0"
                  step="0.01"
                  aria-describedby="price-amount-hint"
                />
                <small id="price-amount-hint" class="muted">
                  {{ t('profile.details.price_amount_hint') }}
                </small>
              </label>
              <label>
                {{ t('profile.details.price_label') }}
                <input
                  v-model="priceLabel"
                  type="text"
                  :placeholder="t('profile.details.price_label_placeholder')"
                  data-field="priceLabel"
                  :aria-invalid="invalidField === 'priceLabel' || undefined"
                  @input="clearInvalid('priceLabel')"
                />
              </label>
              <label>
                {{ t('profile.details.price_note') }}
                <input
                  v-model="priceNote"
                  type="text"
                  data-field="priceNote"
                  :aria-invalid="invalidField === 'priceNote' || undefined"
                  @input="clearInvalid('priceNote')"
                />
              </label>
              <label>
                {{ t('profile.details.includes') }}
                <textarea
                  v-model="includes"
                  rows="2"
                  data-field="includes"
                  :aria-invalid="invalidField === 'includes' || undefined"
                  @input="clearInvalid('includes')"
                />
              </label>
            </template>

            <label v-else>
              {{ t('profile.details.duration') }}
              <input v-model.number="durationHours" type="number" min="1" max="24" />
            </label>

            <div class="attributes-editor" data-field="attribute" tabindex="-1">
              <strong class="tiny">{{ t('profile.details.about') }}</strong>

              <div
                v-for="category in visibleCategories"
                :key="category.key"
                class="attribute-group"
              >
                <span class="tiny muted">{{
                  (isGuide && category.providerLabel) || category.label
                }}</span>
                <div class="chips">
                  <span
                    v-for="value in selectedAttributes[category.key] ?? []"
                    :key="value"
                    class="chip"
                  >
                    {{ optionLabelFor(category.key, value) }}
                    <button
                      type="button"
                      class="chip-remove"
                      :aria-label="t('profile.details.remove_option')"
                      @click="removeAttribute(category.key, value)"
                    >
                      ×
                    </button>
                  </span>
                  <button
                    v-if="!manuallyExpandedCategories.has(category.key)"
                    type="button"
                    class="chip-add"
                    :aria-label="t('profile.details.add_option', { category: category.label })"
                    @click="expandCategory(category.key)"
                  >
                    +
                  </button>
                </div>

                <AttributeCombobox
                  v-if="manuallyExpandedCategories.has(category.key)"
                  :model-value="optionQueries[category.key] ?? ''"
                  :suggestions="optionSuggestionsFor(category.key)"
                  :placeholder="t('profile.details.option_placeholder')"
                  autofocus
                  class="option-combobox"
                  @update:model-value="(value) => (optionQueries[category.key] = value)"
                  @select="(suggestion) => selectOptionFor(category.key, suggestion)"
                  @blur="collapseCategory(category.key)"
                />
              </div>

              <div v-for="category in rangeCategories" :key="category.key" class="attribute-group">
                <span class="tiny muted">
                  {{ (isGuide && category.providerLabel) || category.label }}
                  <em v-if="!(selectedRanges[category.key] ?? []).length">{{
                    t('profile.details.range_empty_hint')
                  }}</em>
                </span>
                <RangePicker
                  :ranges="selectedRanges[category.key] ?? []"
                  :min="category.rangeMin ?? 0"
                  :max="category.rangeMax ?? 23"
                  :format="rangeFormatFor(category.key)"
                  @add="(range) => addRange(category.key, range)"
                  @remove="(index) => removeRange(category.key, index)"
                />
              </div>

              <label class="tiny add-category">
                {{ t('profile.details.add_category') }}
                <AttributeCombobox
                  v-model="categoryQuery"
                  :suggestions="categorySuggestions"
                  :placeholder="t('profile.details.category_placeholder')"
                  @select="selectCategory"
                />
              </label>
            </div>

            <button type="submit" class="full" :aria-busy="savingProfile" :disabled="savingProfile">
              {{ savingProfile ? t('profile.details.saving') : t('profile.details.submit') }}
            </button>
          </form>
        </PanelCard>
      </div>
    </div>
  </section>
</template>

<style scoped lang="scss">
@use 'breakpoints' as bp;

// The level badge sits at the top right, level with the eyebrow.
.profile-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;

  .profile-level {
    flex-shrink: 0;
  }
}

.guide-base {
  margin: 0 0 1rem;
  padding: 0.75rem 1rem 0.25rem;
  border: 1px solid var(--rg-line);
  border-radius: var(--rg-radius-soft);

  legend {
    padding: 0 0.35rem;
    font-weight: 600;
  }
}

.base-chosen {
  margin: 0 0 0.75rem;
}

.trusted-devices {
  margin: -0.5rem 0 1rem;
}

.devices {
  margin: 1rem 0 0.75rem;

  ul {
    margin: 0;
    padding: 0;
  }

  li {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.4rem 0;
    border-bottom: 1px solid var(--pico-muted-border-color);
    list-style: none;
  }
}

.devices-title {
  margin-bottom: 0.25rem;
}

.device-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.device-name {
  font-size: 0.9rem;
}

.this-device {
  flex-shrink: 0;
  font-weight: 600;
}

.link-btn {
  display: inline;
  width: auto;
  margin: 0;
  padding: 0;
  border: 0;
  background: none;
  color: var(--pico-primary);
  font-size: inherit;
  text-decoration: underline;
  cursor: pointer;
}

.profile-grid {
  display: grid;
  gap: 1.25rem;

  @include bp.lg {
    grid-template-columns: repeat(2, 1fr);
    align-items: start;
  }
}

label {
  display: block;
  margin-bottom: 1rem;
}

.password-disclosure {
  border: 1px solid var(--rg-line);
  border-radius: var(--rg-radius-panel);
  background: rgba(255, 255, 255, 0.6);
  padding: 0.75rem 1rem;
  margin-bottom: 1.25rem;

  summary {
    cursor: pointer;
    font-weight: 600;
  }

  form {
    margin-top: 1rem;
  }
}

.phone-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.75rem;

  label {
    margin-bottom: 1rem;
  }
}

.verification {
  margin: 0.75rem 0;
  padding: 0.5rem 0.75rem;
  border-radius: var(--rg-radius-soft);
  background: var(--rg-orange-50);
  border: 1px solid var(--rg-orange-200);
}

.verification--done {
  background: var(--rg-green-soft);
  border-color: var(--rg-green-border);
  color: var(--rg-green);
}

.current-photo {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.35rem;
  margin-bottom: 0.75rem;

  img {
    width: 96px;
    height: 96px;
    border-radius: 50%;
    object-fit: cover;
    border: 1px solid var(--rg-line);
  }
}

.attributes-editor {
  margin-bottom: 1rem;
}

.attribute-group {
  margin-bottom: 0.6rem;
}

.chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  margin-top: 0.25rem;
}

.chip {
  display: inline-flex;
  align-items: baseline;
  gap: 0.3rem;
  padding: 0.2rem 0.5rem;
  border-radius: 999px;
  background: var(--rg-cream, #f5f0ea);
  border: 1px solid var(--rg-line);
  font-size: 0.85rem;
}

.chip-remove {
  width: auto;
  padding: 0;
  border: 0;
  background: none;
  line-height: 1;
  cursor: pointer;
  font-size: 1rem;
  margin: 0;
  color: var(--pico-secondary);
}

.chip-add {
  width: 1.6rem;
  height: 1.6rem;
  padding: 0;
  border: 1px dashed var(--rg-line);
  border-radius: 50%;
  background: none;
  line-height: 1;
  cursor: pointer;
  font-size: 1rem;
  color: var(--rg-ink, inherit);
  margin: 0;
}

.option-combobox {
  margin-top: 0.4rem;
}

.add-category {
  margin-top: 1rem;
  padding-top: 0.75rem;
  border-top: 1px dashed var(--rg-line);
}

button {
  margin-top: 0.25rem;
}
.add-role-banner {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem 1rem;
  margin: 0 0 1rem;
  padding: 0.75rem 1rem;
  border: 1px dashed var(--rg-orange-200);
  border-radius: var(--rg-radius-panel);
  background: var(--rg-orange-50);

  p {
    margin: 0;
  }

  button {
    width: auto;
    margin: 0;
  }
}

.editing-role {
  margin: 0 0 1rem;
}
</style>
