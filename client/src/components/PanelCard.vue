<script setup lang="ts">
/**
 * The white elevated panel the prototype calls a "mock" — a titled card with a
 * body. Every form and deck on the app sits inside one.
 */
defineProps<{
  title?: string
  subtitle?: string
}>()
</script>

<template>
  <article class="panel">
    <header v-if="title || subtitle || $slots.header">
      <slot name="header">
        <strong>{{ title }}</strong>
        <p v-if="subtitle" class="tiny muted">{{ subtitle }}</p>
      </slot>
    </header>
    <main>
      <slot />
    </main>
    <footer v-if="$slots.footer">
      <slot name="footer" />
    </footer>
  </article>
</template>

<style scoped lang="scss">
.panel {
  background: rgba(255, 255, 255, 0.9);
  border: 1px solid var(--rg-line);
  border-radius: var(--rg-radius-card);
  box-shadow: var(--rg-shadow);
  overflow: hidden;
  margin-bottom: 0;

  > header {
    padding: 1rem 1.1rem 0.4rem;
    background: none;
    border: 0;
    margin: 0;

    p {
      margin: 0.25rem 0 0;
    }
  }

  > main {
    padding: 0.7rem 1rem 1rem;
  }

  > footer {
    padding: 0 1rem 1rem;
    background: none;
    border: 0;
    margin: 0;
  }
}
</style>
