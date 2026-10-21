<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ApiError, api, type AuditEntry } from '@/api'

/**
 * Every change made in the CMS, newest first, 50 at a time. What you see
 * depends on your role (the server decides): admins everything, managers
 * everything but staff management, editors their own changes.
 */
const entries = ref<AuditEntry[]>([])
const hasMore = ref(false)
const scope = ref('')
const filter = ref('')
const loading = ref(false)
const error = ref('')

const SCOPES: Record<string, string> = {
  all: 'Showing every change.',
  operations: 'Showing every change except staff management.',
  own: 'Showing your own changes.',
}

async function load(more = false) {
  if (loading.value) return
  loading.value = true
  error.value = ''
  try {
    const last = entries.value[entries.value.length - 1]
    const page = await api.auditLog({
      ...(more && last ? { beforeId: last.id } : {}),
      ...(filter.value.trim() ? { filter: filter.value.trim() } : {}),
    })
    entries.value = more ? [...entries.value, ...page.entries] : page.entries
    hasMore.value = page.hasMore
    scope.value = page.scope
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Could not load the audit log.'
  } finally {
    loading.value = false
  }
}

/** "role: editor → manager" — only the fields that changed. */
function changes(entry: AuditEntry): string {
  const before = entry.before ?? {}
  const after = entry.after ?? {}
  return Object.keys({ ...before, ...after })
    .filter((key) => JSON.stringify(before[key]) !== JSON.stringify(after[key]))
    .map((key) =>
      entry.before
        ? `${key}: ${String(before[key] ?? '—')} → ${String(after[key] ?? '—')}`
        : `${key}: ${String(after[key] ?? '—')}`,
    )
    .join('; ')
}

onMounted(() => load())
</script>

<template>
  <section>
    <h1>Audit log</h1>
    <p class="muted" data-test="scope">{{ SCOPES[scope] }}</p>
    <form role="search" @submit.prevent="load()">
      <input
        v-model="filter"
        type="search"
        placeholder="Filter by action, e.g. staff."
        aria-label="Filter by action"
      />
      <button type="submit">Filter</button>
    </form>
    <p v-if="error" class="notice error" role="alert">{{ error }}</p>

    <div class="overflow-auto">
      <table class="striped" data-test="audit-table">
        <thead>
          <tr>
            <th>When</th>
            <th>Who</th>
            <th>Action</th>
            <th>On</th>
            <th>Change</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="entry in entries" :key="entry.id">
            <td class="tiny">{{ new Date(entry.createdAt).toLocaleString() }}</td>
            <td>{{ entry.staffEmail }}</td>
            <td>
              <code>{{ entry.action }}</code>
            </td>
            <td>{{ entry.targetType }} #{{ entry.targetId }}</td>
            <td class="tiny">{{ changes(entry) }}</td>
          </tr>
          <tr v-if="!loading && entries.length === 0">
            <td colspan="5" class="muted">Nothing yet.</td>
          </tr>
        </tbody>
      </table>
    </div>
    <button v-if="hasMore" type="button" class="secondary" :aria-busy="loading" @click="load(true)">
      Load more
    </button>
  </section>
</template>
