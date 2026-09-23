/**
 * Country calling codes for the profile page's phone field — a static
 * reference list, not DB-backed (there's no server-side use for it; the
 * split between "country code" and "local number" only exists in the UI,
 * see `ProfilePage.vue`). Not exhaustive — covers the countries visitors/
 * guides on a Gibraltar-focused app are most likely to need; add more here
 * if a real user needs one that's missing.
 */
export interface CountryCode {
  /** Country name, shown in the combobox suggestion list. */
  name: string
  /** E.164 dial code including the leading "+". */
  dialCode: string
  /** ISO 3166-1 alpha-2, kept for a future flag icon — unused today. */
  iso2: string
}

export const COUNTRY_CODES: readonly CountryCode[] = [
  { name: 'Gibraltar', dialCode: '+350', iso2: 'gi' },
  { name: 'Spain', dialCode: '+34', iso2: 'es' },
  { name: 'United Kingdom', dialCode: '+44', iso2: 'gb' },
  { name: 'Ireland', dialCode: '+353', iso2: 'ie' },
  { name: 'Portugal', dialCode: '+351', iso2: 'pt' },
  { name: 'France', dialCode: '+33', iso2: 'fr' },
  { name: 'Germany', dialCode: '+49', iso2: 'de' },
  { name: 'Italy', dialCode: '+39', iso2: 'it' },
  { name: 'Netherlands', dialCode: '+31', iso2: 'nl' },
  { name: 'Belgium', dialCode: '+32', iso2: 'be' },
  { name: 'Switzerland', dialCode: '+41', iso2: 'ch' },
  { name: 'Austria', dialCode: '+43', iso2: 'at' },
  { name: 'Luxembourg', dialCode: '+352', iso2: 'lu' },
  { name: 'Denmark', dialCode: '+45', iso2: 'dk' },
  { name: 'Sweden', dialCode: '+46', iso2: 'se' },
  { name: 'Norway', dialCode: '+47', iso2: 'no' },
  { name: 'Finland', dialCode: '+358', iso2: 'fi' },
  { name: 'Iceland', dialCode: '+354', iso2: 'is' },
  { name: 'Poland', dialCode: '+48', iso2: 'pl' },
  { name: 'Czech Republic', dialCode: '+420', iso2: 'cz' },
  { name: 'Slovakia', dialCode: '+421', iso2: 'sk' },
  { name: 'Hungary', dialCode: '+36', iso2: 'hu' },
  { name: 'Romania', dialCode: '+40', iso2: 'ro' },
  { name: 'Bulgaria', dialCode: '+359', iso2: 'bg' },
  { name: 'Greece', dialCode: '+30', iso2: 'gr' },
  { name: 'Croatia', dialCode: '+385', iso2: 'hr' },
  { name: 'Slovenia', dialCode: '+386', iso2: 'si' },
  { name: 'Malta', dialCode: '+356', iso2: 'mt' },
  { name: 'Cyprus', dialCode: '+357', iso2: 'cy' },
  { name: 'Morocco', dialCode: '+212', iso2: 'ma' },
  { name: 'Algeria', dialCode: '+213', iso2: 'dz' },
  { name: 'Tunisia', dialCode: '+216', iso2: 'tn' },
  { name: 'Egypt', dialCode: '+20', iso2: 'eg' },
  { name: 'South Africa', dialCode: '+27', iso2: 'za' },
  { name: 'Nigeria', dialCode: '+234', iso2: 'ng' },
  { name: 'Kenya', dialCode: '+254', iso2: 'ke' },
  { name: 'United States', dialCode: '+1', iso2: 'us' },
  { name: 'Canada', dialCode: '+1', iso2: 'ca' },
  { name: 'Mexico', dialCode: '+52', iso2: 'mx' },
  { name: 'Brazil', dialCode: '+55', iso2: 'br' },
  { name: 'Argentina', dialCode: '+54', iso2: 'ar' },
  { name: 'Chile', dialCode: '+56', iso2: 'cl' },
  { name: 'Colombia', dialCode: '+57', iso2: 'co' },
  { name: 'Peru', dialCode: '+51', iso2: 'pe' },
  { name: 'Venezuela', dialCode: '+58', iso2: 've' },
  { name: 'Uruguay', dialCode: '+598', iso2: 'uy' },
  { name: 'China', dialCode: '+86', iso2: 'cn' },
  { name: 'Japan', dialCode: '+81', iso2: 'jp' },
  { name: 'South Korea', dialCode: '+82', iso2: 'kr' },
  { name: 'India', dialCode: '+91', iso2: 'in' },
  { name: 'Pakistan', dialCode: '+92', iso2: 'pk' },
  { name: 'Bangladesh', dialCode: '+880', iso2: 'bd' },
  { name: 'Indonesia', dialCode: '+62', iso2: 'id' },
  { name: 'Philippines', dialCode: '+63', iso2: 'ph' },
  { name: 'Vietnam', dialCode: '+84', iso2: 'vn' },
  { name: 'Thailand', dialCode: '+66', iso2: 'th' },
  { name: 'Malaysia', dialCode: '+60', iso2: 'my' },
  { name: 'Singapore', dialCode: '+65', iso2: 'sg' },
  { name: 'Turkey', dialCode: '+90', iso2: 'tr' },
  { name: 'Israel', dialCode: '+972', iso2: 'il' },
  { name: 'United Arab Emirates', dialCode: '+971', iso2: 'ae' },
  { name: 'Saudi Arabia', dialCode: '+966', iso2: 'sa' },
  { name: 'Qatar', dialCode: '+974', iso2: 'qa' },
  { name: 'Russia', dialCode: '+7', iso2: 'ru' },
  { name: 'Ukraine', dialCode: '+380', iso2: 'ua' },
  { name: 'Australia', dialCode: '+61', iso2: 'au' },
  { name: 'New Zealand', dialCode: '+64', iso2: 'nz' },
] as const
