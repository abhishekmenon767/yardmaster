<script setup>
import { onMounted, ref } from 'vue'
import { api, ago } from '../api.js'

const emit = defineEmits(['error'])
const actions = ref([])
const loading = ref(true)

async function load() {
  loading.value = true
  try {
    actions.value = (await api.actions({ per_page: 100 })).data
  } catch (e) {
    emit('error', e.message)
  } finally {
    loading.value = false
  }
}

onMounted(load)

const wording = {
  purge_queue: 'Purged queue',
  delete_by_id: 'Deleted job',
  promote_delayed: 'Promoted job',
  retry_failed: 'Retried job',
  forget_failed: 'Discarded failure',
}
</script>

<template>
  <div class="panel">
    <header>
      <h2>Audit trail</h2>
      <span class="hint">every operation that changed queue state</span>
      <span class="spacer"></span>
      <button class="btn" @click="load">Refresh</button>
    </header>

    <div class="scroll">
      <table>
        <thead>
          <tr><th>Action</th><th>Who</th><th>Connection</th><th>Queue</th><th>Target</th><th class="num">Affected</th><th class="num">When</th></tr>
        </thead>
        <tbody>
          <tr v-for="(action, i) in actions" :key="i">
            <td>
              <strong>{{ wording[action.action] ?? action.action }}</strong>
              <span v-if="!action.succeeded" class="pill failed" style="margin-left: 6px">no effect</span>
            </td>
            <td>
              {{ action.actor_name ?? 'console' }}
              <span v-if="action.actor_ip" style="color: var(--faint)"> · {{ action.actor_ip }}</span>
            </td>
            <td>{{ action.connection }}</td>
            <td>{{ action.queue ?? '—' }}</td>
            <td><span class="truncate mono" :title="action.target">{{ action.target ?? '—' }}</span></td>
            <td class="num">{{ action.affected ?? '—' }}</td>
            <td class="num" style="color: var(--muted)">{{ ago(action.created_at) }}</td>
          </tr>
          <tr v-if="!actions.length">
            <td colspan="7">
              <div class="empty">
                <strong>{{ loading ? 'Loading…' : 'Nothing has changed queue state yet' }}</strong>
                <template v-if="!loading">Purges, deletions, promotions and retries are all recorded here.</template>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
