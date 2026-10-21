<script setup lang="ts">
import { useRoute } from 'vue-router'

const route = useRoute()
</script>

<template>
  <section>
    <h1>{{ route.meta.title }}</h1>
    <p class="notice">
      Not built yet — this is phase {{ route.meta.phase }} of the CMS plan (docs/auth-cms-plan.md).
    </p>
  </section>
</template>
