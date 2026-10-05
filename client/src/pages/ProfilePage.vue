<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import AttributeCombobox, { type ComboboxSuggestion } from '@/components/AttributeCombobox.vue'
import RangePicker from '@/components/RangePicker.vue'
import ImageCropper from '@/components/ImageCropper.vue'
import PanelCard from '@/components/PanelCard.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import { COUNTRY_CODES } from '@/data/countryCodes'
import {
  ApiError,
  type AttributeCategory,
  type MyProfile,
  type TwoFactorChannel,
  useApi,
} from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { anchorTo, useMessagesStore } from '@/stores/messages'
import { reportApiError } from '@/utils/reportApiError'
import { rangeFormatFor } from '@/utils/formatRange'
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
const messages = useMessagesStore()

const loading = ref(true)
const loadError = ref('')

const profile = ref<MyProfile | null>(null)
const isGuide = computed(() => profile.value?.role === 'guide')

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

const countryCodeSuggestions: ComboboxSuggestion[] = COUNTRY_CODES.map((country) => ({
  value: country.dialCode,
  label: `${country.name} (${country.dialCode})`,
}))

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
 * `range` categories (the hours a guide works). Guides only: a visitor picks
 * the hours they *want* in the discovery filter instead of storing any, so
 * there'd be nothing for these to mean on their profile.
 */
const rangeCategories = computed(() =>
  isGuide.value ? localCategories.value.filter((category) => category.kind === 'range') : [],
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
  localCategories.value.filter(
    (category) =>
      (selectedAttributes[category.key]?.length ?? 0) > 0 || manuallyExpandedCategories.has(category.key),
  ),
)

const categorySuggestions = computed<ComboboxSuggestion[]>(() =>
  localCategories.value.map((category) => ({ value: category.key, label: category.label })),
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
  return label.trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'category'
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
      localCategories.value.push({ key, label: suggestion.label, multi: true, options: [],
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
  phoneCountryQuery.value = countryCodeSuggestions.find((s) => s.value === code)?.label ?? ''
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

  for (const key of Object.keys(selectedAttributes)) delete selectedAttributes[key]
  Object.assign(selectedAttributes, data.attributes)

  for (const key of Object.keys(selectedRanges)) delete selectedRanges[key]
  Object.assign(selectedRanges, data.ranges)
}

onMounted(async () => {
  if (!auth.user) {
    loading.value = false
    return
  }

  try {
    const [profileResult, categoriesResult] = await Promise.all([
      api.myProfile(),
      api.attributeCategories(),
    ])
    applyProfile(profileResult)
    localCategories.value = categoriesResult.categories
  } catch (err) {
    loadError.value = err instanceof ApiError ? err.message : 'Could not load your profile.'
  } finally {
    loading.value = false
  }
})

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
      sessionHash: auth.user.sessionHash,
    })
    messages.success('Contact details saved.', anchorTo('element', accountPanelRef.value))
  } catch (err) {
    reportApiError(err, 'Could not save your details.', anchorTo('element', accountPanelRef.value))
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
    })
    messages.success('Profile saved.', anchorTo('element', profilePanelRef.value))
  } catch (err) {
    reportApiError(err, 'Could not save your profile.', anchorTo('element', profilePanelRef.value))
  } finally {
    savingProfile.value = false
  }
}

async function changePassword() {
  if (!auth.user || changingPassword.value) return

  if (newPassword.value !== confirmPassword.value) {
    messages.error('New passwords do not match.', anchorTo('element', passwordPanelRef.value))
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
    messages.success('Password changed.', anchorTo('element', passwordPanelRef.value))
  } catch (err) {
    reportApiError(err, 'Could not change your password.', anchorTo('element', passwordPanelRef.value))
  } finally {
    changingPassword.value = false
  }
}
</script>

<template>
  <section class="section shell">
    <SectionTitle
      eyebrow="Account"
      heading="Your profile"
      lead="Manage your personal info, contact details, security and interests."
    />

    <PanelCard v-if="!auth.user" class="narrow" title="Log in to manage your profile">
      <p class="muted">You need to be signed in to see this page.</p>
      <RouterLink to="/login" role="button">Log in</RouterLink>
    </PanelCard>

    <PanelCard v-else-if="loading" title="Loading…" />

    <PanelCard v-else-if="loadError" title="Could not load your profile">
      <p class="muted">{{ loadError }}</p>
    </PanelCard>

    <div v-else class="profile-grid">
      <div ref="accountPanelRef">
        <PanelCard title="Personal &amp; contact info" subtitle="Your name, how to reach you, and your photo.">
          <details ref="passwordPanelRef" class="password-disclosure">
            <summary>Change password</summary>
            <form @submit.prevent="changePassword">
              <label>
                Current password
                <input v-model="currentPassword" type="password" autocomplete="current-password" required />
              </label>
              <label>
                New password
                <input v-model="newPassword" type="password" autocomplete="new-password" required />
              </label>
              <label>
                Confirm new password
                <input v-model="confirmPassword" type="password" autocomplete="new-password" required />
              </label>

              <button type="submit" class="full" :aria-busy="changingPassword" :disabled="changingPassword">
                {{ changingPassword ? 'Changing…' : 'Change password' }}
              </button>
            </form>
          </details>

          <form @submit.prevent="saveAccount">
            <label>
              Name
              <input v-model="name" type="text" autocomplete="name" />
            </label>
            <label>
              Email
              <input v-model="email" type="email" autocomplete="email" required />
            </label>

            <div class="phone-row">
              <label class="phone-code">
                Country code
                <AttributeCombobox
                  v-model="phoneCountryQuery"
                  :suggestions="countryCodeSuggestions"
                  :allow-create="false"
                  placeholder="Search country…"
                  @select="selectCountryCode"
                />
              </label>
              <label class="phone-number">
                Phone
                <input v-model="phoneNumber" type="tel" autocomplete="tel" placeholder="56002731" />
              </label>
            </div>

            <label>
              Two-factor code sent via
              <select v-model="twoFactorMethod" required>
                <option value="" disabled>Choose a method</option>
                <option value="email">Email</option>
                <!-- Not wired to a provider yet — AccountSecurity.php only sends by email. -->
                <option value="sms" disabled>SMS (coming soon)</option>
                <option value="whatsapp" disabled>WhatsApp (coming soon)</option>
              </select>
              <small class="tiny muted">
                Each time you log in, we’ll email you a 6-digit code to enter after your password.
              </small>
            </label>

            <div v-if="profile?.image" class="current-photo">
              <img :src="profile.image" alt="Current profile photo" />
              <span class="tiny muted">Current photo</span>
            </div>
            <ImageCropper ref="cropperRef" />

            <button type="submit" class="full" :aria-busy="savingAccount" :disabled="savingAccount">
              {{ savingAccount ? 'Saving…' : 'Save contact info' }}
            </button>
          </form>
        </PanelCard>
      </div>

      <div ref="profilePanelRef">
        <PanelCard title="Profile details" subtitle="What visitors and guides see about you.">
          <form @submit.prevent="saveProfile">
            <label>
              Date of birth
              <input v-model="dateOfBirth" type="date" :max="todayIso" />
            </label>

            <label v-if="isGuide">
              Gender
              <select v-model="gender">
                <option value="">Prefer not to say</option>
                <option value="male">Male</option>
                <option value="female">Female</option>
              </select>
            </label>

            <label v-if="isGuide">
              Headline
              <input v-model="headline" type="text" placeholder="e.g. Rock scrambles &amp; sunset views" />
            </label>

            <label v-if="!isGuide">
              Party
              <input v-model="party" type="text" placeholder="e.g. 2 adults · Aurora Vista cruise" />
            </label>

            <label>
              Bio
              <textarea v-model="bio" rows="3" />
            </label>

            <template v-if="isGuide">
              <label>
                Price amount (£)
                <input
                  v-model.number="priceAmount"
                  type="number"
                  min="0"
                  step="0.01"
                  aria-describedby="price-amount-hint"
                />
                <small id="price-amount-hint" class="muted">
                  The fee a visitor pays to get your contact details. Leave it at 0 to guide for free — you'll swap
                  contact details as soon as you match.
                </small>
              </label>
              <label>
                Price label
                <input v-model="priceLabel" type="text" placeholder="e.g. £95 / 6 hours" />
              </label>
              <label>
                Price note
                <input v-model="priceNote" type="text" />
              </label>
              <label>
                What's included
                <textarea v-model="includes" rows="2" />
              </label>
            </template>

            <label v-else>
              Requested tour length (hours)
              <input v-model.number="durationHours" type="number" min="1" max="24" />
            </label>

            <div class="attributes-editor">
              <strong class="tiny">About</strong>

              <div v-for="category in visibleCategories" :key="category.key" class="attribute-group">
                <span class="tiny muted">{{ (isGuide && category.providerLabel) || category.label }}</span>
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
                      aria-label="Remove"
                      @click="removeAttribute(category.key, value)"
                    >
                      ×
                    </button>
                  </span>
                  <button
                    v-if="!manuallyExpandedCategories.has(category.key)"
                    type="button"
                    class="chip-add"
                    :aria-label="`Add a ${category.label} option`"
                    @click="expandCategory(category.key)"
                  >
                    +
                  </button>
                </div>

                <AttributeCombobox
                  v-if="manuallyExpandedCategories.has(category.key)"
                  :model-value="optionQueries[category.key] ?? ''"
                  :suggestions="optionSuggestionsFor(category.key)"
                  placeholder="Search or create an option…"
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
                  <em v-if="!(selectedRanges[category.key] ?? []).length">— empty means always available</em>
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
                Add another category
                <AttributeCombobox
                  v-model="categoryQuery"
                  :suggestions="categorySuggestions"
                  placeholder="Search or create a category…"
                  @select="selectCategory"
                />
              </label>
            </div>

            <button type="submit" class="full" :aria-busy="savingProfile" :disabled="savingProfile">
              {{ savingProfile ? 'Saving…' : 'Save profile' }}
            </button>
          </form>
        </PanelCard>
      </div>
    </div>
  </section>
</template>

<style scoped lang="scss">
@use 'breakpoints' as bp;

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
  color:  var(--pico-secondary);
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
</style>
