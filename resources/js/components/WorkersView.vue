<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { api } from '../api.js'

const emit = defineEmits(['error'])
const workers = ref([])
const loading = ref(true)
let poll = null

async function load() {
  try {
    workers.value = (await api.workers()).data
  } catch (e) {
    emit('error', e.message)
  } finally {
    loading.value = false
  }
}

function duration(seconds) {
  if (seconds === null || seconds === undefined) return '—'
  if (seconds < 60) return `${Math.round(seconds)}s`
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m`
  return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`
}

onMounted(() => {
  load()
  poll = setInterval(load, 5000)
})

onUnmounted(() => clearInterval(poll))
</script>

<template>
  <div class="panel">
    <header>
      <h2>Workers</h2>
      <span class="hint">observed, not supervised — Supervisor and systemd already do that</span>
    </header>

    <div class="scroll">
      <table>
        <thead>
          <tr>
            <th>Worker</th>
            <th>Connection</th>
            <th>Queues</th>
            <th>State</th>
            <th>Current job</th>
            <th class="num">Memory</th>
            <th class="num">Processed</th>
            <th class="num">Uptime</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="worker in workers" :key="worker.name">
            <td><span class="mono">{{ worker.host }}</span><span class="mono" style="color: var(--faint)">:{{ worker.pid }}</span></td>
            <td>{{ worker.connection }}</td>
            <td class="mono">{{ worker.queues.join(', ') }}</td>
            <td>
              <span class="pill" :class="{ processed: worker.status === 'working', processing: worker.status === 'idle', failed: worker.status === 'stale' }">
                {{ worker.status }}
              </span>
              <span v-if="worker.status === 'stale'" style="color: var(--crit); font-size: 12px; margin-left: 6px">
                silent {{ duration(worker.silent_for_seconds) }}
              </span>
            </td>
            <td>
              <template v-if="worker.current_job">
                <span class="truncate" :title="worker.current_job">{{ worker.current_job.split('\\').pop() }}</span>
                <span class="mono" style="font-size: 11px; color: var(--faint)">{{ duration(worker.current_job_seconds) }}</span>
              </template>
              <span v-else style="color: var(--faint)">—</span>
            </td>
            <td class="num">{{ worker.memory_kb ? (worker.memory_kb / 1024).toFixed(0) + ' MB' : '—' }}</td>
            <td class="num">{{ worker.processed.toLocaleString() }}<span v-if="worker.failed" style="color: var(--crit)"> / {{ worker.failed }} failed</span></td>
            <td class="num" style="color: var(--muted)">{{ duration(worker.uptime_seconds) }}</td>
          </tr>
          <tr v-if="!workers.length">
            <td colspan="8">
              <div class="empty">
                <strong>{{ loading ? 'Loading…' : 'No workers reporting' }}</strong>
                <template v-if="!loading">
                  Nothing is consuming your queues right now — or the workers predate this install.
                </template>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
