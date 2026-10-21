<script setup lang="ts">
import { computed } from 'vue'
import { can, session } from '@/api'

const cards = computed(() =>
  [
    {
      to: '/content',
      title: 'Content',
      text: 'Edit the app’s text, page by page, in every language.',
      capability: 'content.edit',
    },
    {
      to: '/users',
      title: 'Users',
      text: 'Find travellers and guides; levels, badges, bonuses, verification, freezing.',
      capability: 'users.view',
    },
    {
      to: '/moderation',
      title: 'Moderation',
      text: 'Complaints, disputes and linked accounts.',
      capability: 'disputes.resolve',
    },
    {
      to: '/staff',
      title: 'Staff',
      text: 'Invite staff, change roles, disable accounts.',
      capability: 'staff.manage',
    },
    { to: '/audit', title: 'Audit log', text: 'Every change made in the CMS.', capability: null },
  ].filter((card) => card.capability === null || can(card.capability)),
)
</script>

<template>
  <section>
    <h1>Hello, {{ session.me?.name || session.me?.email }}</h1>
    <p class="muted">You're signed in as {{ session.me?.role }}.</p>
    <div class="grid cards">
      <article v-for="card in cards" :key="card.to">
        <h3>
          <RouterLink :to="card.to">{{ card.title }}</RouterLink>
        </h3>
        <p class="muted">{{ card.text }}</p>
      </article>
    </div>
  </section>
</template>

<style scoped>
.cards {
  grid-template-columns: repeat(auto-fill, minmax(15rem, 1fr));
}
</style>
