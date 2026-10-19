import { vi } from 'vitest'
import type { Api } from '@/plugins/api'

/**
 * A stub `Api` for component tests — every method is a no-op `vi.fn()`
 * unless overridden (`markSeen` resolves, since `useLiveSection` calls it
 * after every load). Specs layer their own defaults on top:
 * `fakeApi({ trips: vi.fn<Api['trips']>().mockResolvedValue(...), ...overrides })`.
 */
export function fakeApi(overrides: Partial<Api> = {}): Api {
  return {
    register: vi.fn<Api['register']>(),
    login: vi.fn<Api['login']>(),
    verifyTwoFactor: vi.fn<Api['verifyTwoFactor']>(),
    resendTwoFactor: vi.fn<Api['resendTwoFactor']>(),
    requestPasswordReset: vi.fn<Api['requestPasswordReset']>(),
    resetPassword: vi.fn<Api['resetPassword']>(),
    forgetTrustedDevices: vi.fn<Api['forgetTrustedDevices']>(),
    guides: vi.fn<Api['guides']>(),
    visitors: vi.fn<Api['visitors']>(),
    updateProfile: vi.fn<Api['updateProfile']>(),
    attributeCategories: vi.fn<Api['attributeCategories']>(),
    myProfile: vi.fn<Api['myProfile']>(),
    updateAccount: vi.fn<Api['updateAccount']>(),
    changePassword: vi.fn<Api['changePassword']>(),
    addRole: vi.fn<Api['addRole']>(),
    logout: vi.fn<Api['logout']>(),
    session: vi.fn<Api['session']>(),
    unlockPack: vi.fn<Api['unlockPack']>(),
    paymentState: vi.fn<Api['paymentState']>(),
    payGuideFee: vi.fn<Api['payGuideFee']>(),
    bookingAction: vi.fn<Api['bookingAction']>(),
    cancelBooking: vi.fn<Api['cancelBooking']>(),
    markSeen: vi.fn<Api['markSeen']>().mockResolvedValue({ ok: true }),
    decide: vi.fn<Api['decide']>(),
    revokeSelection: vi.fn<Api['revokeSelection']>(),
    matchOverview: vi.fn<Api['matchOverview']>(),
    trips: vi.fn<Api['trips']>(),
    publicProfile: vi.fn<Api['publicProfile']>(),
    reviews: vi.fn<Api['reviews']>(),
    submitReview: vi.fn<Api['submitReview']>(),
    deleteReview: vi.fn<Api['deleteReview']>(),
    translations: vi.fn<Api['translations']>(),
    legalDocument: vi.fn<Api['legalDocument']>(),
    geocode: vi.fn<Api['geocode']>().mockResolvedValue({ places: [] }),
    reverseGeocode: vi.fn<Api['reverseGeocode']>().mockResolvedValue({ place: null }),
    setSearchArea: vi.fn<Api['setSearchArea']>().mockResolvedValue({ ok: true }),
    myGuideBase: vi.fn<Api['myGuideBase']>().mockResolvedValue({ base: null }),
    conversations: vi.fn<Api['conversations']>(),
    messages: vi.fn<Api['messages']>(),
    sendMessage: vi.fn<Api['sendMessage']>(),
    shareLocation: vi.fn<Api['shareLocation']>(),
    reportUser: vi.fn<Api['reportUser']>(),
    myLevel: vi.fn<Api['myLevel']>(),
    claimBonus: vi.fn<Api['claimBonus']>(),
    markMessagesRead: vi.fn<Api['markMessagesRead']>().mockResolvedValue({ ok: true }),
    ...overrides,
  }
}
