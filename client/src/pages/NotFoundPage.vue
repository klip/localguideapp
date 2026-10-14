<script setup lang="ts">
import PanelCard from '@/components/PanelCard.vue'
import { t } from '@/i18n'
</script>

<template>
  <section class="section shell">
    <PanelCard
      class="narrow"
      :title="t('not_found.panel.title')"
      :subtitle="t('not_found.panel.subtitle')"
    >
      <RouterLink to="/" role="button" class="full">{{ t('not_found.panel.home') }}</RouterLink>
    </PanelCard>
  </section>
</template>
