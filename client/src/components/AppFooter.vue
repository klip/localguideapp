<script setup lang="ts">
import BrandMark from './BrandMark.vue'
import { t } from '@/i18n'
import { useLegalStore } from '@/stores/legal'

// Both open the one terms dialog App.vue renders (`stores/legal.ts`); the
// privacy link scrolls it to the privacy notice (section 9).
const legal = useLegalStore()
</script>

<template>
  <footer>
    <hr />
    <div class="shell">
      <div class="row-between wrap">
        <BrandMark as="text" />
        <nav :aria-label="t('common.app_footer.legal')" class="legal-links tiny">
          <button type="button" class="link" @click="legal.showTerms()">
            {{ t('common.app_footer.terms') }}
          </button>
          <span aria-hidden="true">·</span>
          <button type="button" class="link" @click="legal.showTerms('9')">
            {{ t('common.app_footer.privacy') }}
          </button>
        </nav>
      </div>
    </div>
  </footer>
</template>

<style scoped lang="scss">
footer {
  padding: 2.5rem 0 3rem;
  color: var(--rg-muted);
}

.legal-links {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

// Opens a dialog rather than navigating, so a button — styled as a link.
.link {
  margin: 0;
  padding: 0;
  width: auto;
  border: 0;
  background: none;
  color: inherit;
  font-size: inherit;
  text-decoration: underline;
  text-underline-offset: 0.2em;
  cursor: pointer;

  &:hover,
  &:focus-visible {
    color: var(--rg-ink);
  }
}
</style>
