<script setup lang="ts">
import { computed } from 'vue'
import { api, can, session } from '@/api'

/** The CMS's sections, each shown only to roles that may use it. */
const sections = computed(() =>
  [
    { to: '/content', label: 'Content', capability: 'content.edit' },
    { to: '/pages', label: 'Pages', capability: 'pages.manage' },
    { to: '/users', label: 'Users', capability: 'users.view' },
    { to: '/moderation', label: 'Moderation', capability: 'disputes.resolve' },
    { to: '/staff', label: 'Staff', capability: 'staff.manage' },
    { to: '/audit', label: 'Audit log', capability: null },
  ].filter((section) => section.capability === null || can(section.capability)),
)
</script>

<template>
  <header v-if="session.me" class="container cms-header">
    <nav>
      <ul>
        <li>
          <RouterLink to="/" class="brand"><strong>BeLikeLocal</strong> CMS</RouterLink>
        </li>
      </ul>
      <ul class="sections" data-test="sections">
        <li v-for="section in sections" :key="section.to">
          <RouterLink :to="section.to">{{ section.label }}</RouterLink>
        </li>
      </ul>
      <ul>
        <li class="tiny muted" data-test="whoami">
          {{ session.me.name || session.me.email }} · {{ session.me.role }}
        </li>
        <li>
          <button class="outline secondary" type="button" @click="api.logout()">Sign out</button>
        </li>
      </ul>
    </nav>
  </header>
  <main class="container">
    <RouterView v-if="session.ready" />
    <p v-else aria-busy="true" class="muted">Loading…</p>
  </main>
</template>

<style scoped>
.cms-header {
  border-bottom: 1px solid var(--pico-muted-border-color);
  margin-bottom: 1.5rem;
}

.brand {
  text-decoration: none;
  color: var(--pico-color);
}

.brand strong {
  color: var(--cms-accent);
}

.sections a.router-link-active {
  font-weight: 600;
  text-decoration: underline;
}
</style>
