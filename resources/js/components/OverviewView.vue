<script setup>
import { computed } from 'vue'
import { api, count, ms } from '../api.js'
import GatedControl from './GatedControl.vue'
import Sparkline from './Sparkline.vue'

const props = defineProps({
  meta: Object,
  metrics: Object,
  queues: { type: Array, default: () => [] },
})

const emit = defineEmits(['refresh', 'error', 'inspect'])

const summary = computed(() => props.metrics?.summary ?? {})
const failing = computed(() => (summary.value.failure_rate ?? 0) > 0.05)

/** Backlog divided by throughput — the number an operator wants mid-incident. */
function drainEstimate(queue) {
  const pending = queue.pending
  const rate = summary.value.throughput_per_minute ?? 0
  if (!pending) return '—'
  if (rate <= 0) return 'no throughput'
  return `${ms((pending / rate) * 60000)}`
}

async function toggle(queue) {
  try {
    queue.paused
      ? await api.resume(queue.connection, queue.queue)
      : await api.pause(queue.connection, queue.queue)
    emit('refresh')
  } catch (e) {
    emit('error', e.message)
  }
}

async function purge(queue) {
  if (!window.confirm(`Delete every job on ${queue.connection} / ${queue.queue}? This cannot be undone.`)) return

  try {
    await api.purge(queue.connection, queue.queue)
    emit('refresh')
  } catch (e) {
    emit('error', e.message)
  }
}
</script>

<template>
  <div class="stats">
    <div class="stat">
      <div class="k">Throughput</div>
      <div class="v">{{ (summary.throughput_per_minute ?? 0).toLocaleString() }}<small>/min</small></div>
    </div>
    <div class="stat" :class="{ alarm: failing }">
      <div class="k">Failure rate</div>
      <div class="v">{{ ((summary.failure_rate ?? 0) * 100).toFixed(1) }}<small>%</small></div>
    </div>
    <div class="stat">
      <div class="k">Runtime p95</div>
      <div class="v">{{ ms(summary.runtime_ms?.p95) }}</div>
    </div>
    <div class="stat">
      <div class="k">Wait p95</div>
      <div class="v">{{ ms(summary.wait_ms?.p95) }}</div>
    </div>
    <div class="stat">
      <div class="k">Attempts</div>
      <div class="v">{{ (summary.total ?? 0).toLocaleString() }}</div>
    </div>
  </div>

  <div class="panel" style="margin-top: 18px">
    <header>
      <h2>Throughput</h2>
      <span class="hint">{{ summary.period }} buckets</span>
    </header>
    <Sparkline :points="metrics?.series ?? []" />
  </div>

  <div class="panel">
    <header>
      <h2>Queues</h2>
      <span class="hint">live depth, from each driver</span>
    </header>

    <div class="scroll">
      <table>
        <thead>
          <tr>
            <th>Connection</th>
            <th>Queue</th>
            <th>Driver</th>
            <th class="num">Pending</th>
            <th class="num">Delayed</th>
            <th class="num">Reserved</th>
            <th class="num">Drains in</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="q in queues" :key="`${q.connection}:${q.queue}`">
            <td>{{ q.connection }}</td>
            <td>
              <strong>{{ q.queue }}</strong>
              <span v-if="q.paused" class="pill released" style="margin-left: 6px">paused</span>
            </td>
            <td class="mono" style="color: var(--muted)">{{ q.driver }}</td>
            <td class="num" :class="{ approx: q.approximate }">
              <span v-if="q.pending !== null">{{ count(q.pending, q.approximate) }}</span>
              <span v-else class="unknown" title="This driver cannot report pending messages">unknown</span>
            </td>
            <td class="num" :class="{ approx: q.approximate }">
              <span v-if="q.delayed !== null">{{ count(q.delayed, q.approximate) }}</span>
              <span v-else class="unknown">unknown</span>
            </td>
            <td class="num" :class="{ approx: q.approximate }">
              <span v-if="q.reserved !== null">{{ count(q.reserved, q.approximate) }}</span>
              <span v-else class="unknown">unknown</span>
            </td>
            <td class="num" style="color: var(--muted)">{{ drainEstimate(q) }}</td>
            <td style="text-align: right; white-space: nowrap">
              <GatedControl
                :meta="meta" :connection="q.connection" capability="peek_payloads"
                :requires-manage="false" label="Inspect"
                @click="emit('inspect', q)"
              />
              <button
                class="btn" style="margin-left: 6px"
                :disabled="meta?.can?.manage !== true"
                :title="meta?.can?.manage === true
                  ? (q.paused ? 'Let workers pick up jobs again' : 'Stop workers picking up new jobs')
                  : 'You do not have permission to change queue state.'"
                @click="toggle(q)"
              >{{ q.paused ? 'Resume' : 'Pause' }}</button>
              <GatedControl
                :meta="meta" :connection="q.connection" capability="purge_queue"
                :cooldown="q.purge_cooldown" label="Purge" danger
                style="margin-left: 6px"
                @click="purge(q)"
              />
            </td>
          </tr>
          <tr v-if="!queues.length">
            <td colspan="8">
              <div class="empty">
                <strong>No queues seen yet</strong>
                Dispatch a job and it will appear here within a minute.
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="panel">
    <header><h2>Job classes</h2><span class="hint">ranked by volume</span></header>
    <div class="scroll">
      <table>
        <thead>
          <tr>
            <th>Class</th>
            <th class="num">Runs</th>
            <th class="num">Failed</th>
            <th class="num">Failure rate</th>
            <th class="num">Mean</th>
            <th class="num">p95</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="job in metrics?.top_job_classes ?? []" :key="job.job_class">
            <td><span class="truncate mono" :title="job.job_class">{{ job.job_class }}</span></td>
            <td class="num">{{ job.total.toLocaleString() }}</td>
            <td class="num">{{ job.failed.toLocaleString() }}</td>
            <td class="num" :style="job.failure_rate > 0 ? 'color: var(--crit)' : ''">
              {{ (job.failure_rate * 100).toFixed(1) }}%
            </td>
            <td class="num">{{ ms(job.mean_ms) }}</td>
            <td class="num">{{ ms(job.p95_ms) }}</td>
          </tr>
          <tr v-if="!(metrics?.top_job_classes ?? []).length">
            <td colspan="6"><div class="empty"><strong>Nothing recorded in this window</strong>Widen the time range.</div></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
