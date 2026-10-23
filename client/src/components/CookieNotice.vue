<script setup lang="ts">
import { ref } from 'vue'
import { t } from '@/i18n'
import { useLegalStore } from '@/stores/legal'

/**
 * The GDPR / ePrivacy storage notice, at the foot of every page until it's
 * acknowledged. The app stores only what it needs to work (session,
 * language, remembered devices, choices not yet sent — see the privacy
 * notice's section 9.8), which needs no consent, only telling people — so
 * there's a link to the details and an "OK", not a consent choice. Offering
 * "Reject" for storage the app can't work without would be a choice that
 * changes nothing.
 *
 * **If optional storage is ever added** (analytics, ads), this must become a
 * real choice — nothing optional set before a yes, "Reject" as easy as
 * "Accept", and the answer kept as proof — and `NOTICE_VERSION` bumped so
 * everyone is asked again.
 */
const STORAGE_KEY = 'belikelocal.cookieNotice'
const NOTICE_VERSION = '1'

function acknowledged(): boolean {
  try {
    return localStorage.getItem(STORAGE_KEY) === NOTICE_VERSION
  } catch {
    return false
  }
}

const visible = ref(!acknowledged())
const legal = useLegalStore()

function dismiss() {
  visible.value = false
  try {
    localStorage.setItem(STORAGE_KEY, NOTICE_VERSION)
  } catch {
    // Not remembered: the notice shows again on the next visit.
  }
}
</script>

<template>
  <section v-if="visible" class="cookie-notice" :aria-label="t('common.cookie_notice.label')">
    <p>{{ t('common.cookie_notice.text') }}</p>
    <div class="actions">
      <button type="button" class="secondary outline" @click="legal.showTerms('9.8')">
        {{ t('common.cookie_notice.privacy') }}
      </button>
      <button type="button" @click="dismiss">{{ t('common.cookie_notice.ok') }}</button>
    </div>
  </section>
</template>

<style scoped lang="scss">
@use 'breakpoints' as bp;

.cookie-notice {
  position: fixed;
  inset-inline: 1rem;
  // Above the mobile tab bar (AppHeader.vue); at the foot from `lg`.
  bottom: calc(4.75rem + env(safe-area-inset-bottom));
  z-index: 50;

  @include bp.lg {
    bottom: 1rem;
  }
  margin-inline: auto;
  max-width: 40rem;
  padding: 1rem 1.25rem;
  border-radius: var(--rg-radius-panel);
  background: var(--rg-surface);
  box-shadow: var(--rg-shadow);
  display: flex;
  flex-direction: column;
  gap: 0.75rem;

  p {
    margin: 0;
    font-size: 0.9rem;
  }

  @include bp.md {
    flex-direction: row;
    align-items: center;
  }
}

.actions {
  display: flex;
  gap: 0.5rem;
  flex-shrink: 0;

  button {
    margin: 0;
    padding: 0.45rem 0.9rem;
    font-size: 0.9rem;
  }
}
</style>
