<script setup lang="ts">
import BrandMark from './BrandMark.vue'
import { t } from '@/i18n'
</script>

<template>
  <footer>
    <hr />
    <div class=" shell">
      <div class="row-between wrap">
        <BrandMark as="text" />
        <span class="tiny muted">{{ t('common.app_footer.tagline') }}</span>
      </div>
    </div>
  </footer>
</template>

<style scoped lang="scss">
footer {
  padding: 2.5rem 0 3rem;
  color: var(--rg-muted);
}
</style>
