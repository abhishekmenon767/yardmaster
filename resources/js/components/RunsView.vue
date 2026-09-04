<script setup>
import { ref, watch } from 'vue'
import { api, ago, ms } from '../api.js'

const props = defineProps({ options: Object, failedOnly: Boolean })
const emit = defineEmits(['open', 'error'])

const filters = ref({ connection: '', queue: '', status: '', job_class: '', search: '', page: 1, per_page: 50 })
const result = ref({ data: [], meta: { total: 0, page: 1, last_page: 1 } })
const loading = ref(false)
const selected = ref(new Set())

async function load() {
  loading.value = true
  try {
    const query = { ...filters.value }
    result.value = props.failedOnly ? await api.failures(query) : await api.runs(query)
    selected.value = new Set()
  } catch (e) {
    emit('error', e.message)
  } finally {
    loading.value = false
  }
}

function reset() {
  filters.value.page = 1
  load()
}

function toggle(uuid) {
  const next = new Set(selected.value)
  next.has(uuid) ? next.delete(uuid) : next.add(uuid)
  selected.value = next
}

function toggleAll() {
  selected.value = selected.value.size === result.value.data.length
    ? new Set()
    : new Set(result.value.data.map((r) => r.job_uuid).filter(Boolean))
}

async function bulk(action) {
  const uuids = [...selected.value]
  if (!uuids.length) return

  if (action === 'forget' && !window.confirm(`Discard ${uuids.length} failed job(s) without retrying?`)) return

  try {
    // Bulk by design: a bad deploy produces thousands of identical failures,
    // and retrying them one row at a time is not a workflow anyone uses.
    await (action === 'retry' ? api.retry(uuids) : api.forgetFailures(uuids))
    await load()
  } catch (e) {
    emit('error', e.message)
  }
}

watch(() => props.failedOnly, reset, { immediate: true })
defineExpose({ reload: load })
</script>

<template>
  <div class="filters">
    <input type="search" v-model="filters.search" placeholder="Search job class, exception, or job id…" @keyup.enter="reset">
    <select v-model="filters.connection" @change="reset">
      <option value="">All connections</option>
      <option v-for="c in options?.connections ?? []" :key="c" :value="c">{{ c }}</option>
    </select>
    <select v-model="filters.queue" @change="reset">
      <option value="">All queues</option>
      <option v-for="q in options?.queues ?? []" :key="q" :value="q">{{ q }}</option>
    </select>
    <select v-if="!failedOnly" v-model="filters.status" @change="reset">
      <option value="">Any status</option>
      <option value="processed">Processed</option>
      <option value="released">Released</option>
      <option value="failed">Failed</option>
      <option value="timed_out">Timed out</option>
    </select>
    <button class="btn" @click="reset">Apply</button>
  </div>

  <div class="panel">
    <header>
      <h2>{{ failedOnly ? 'Failures' : 'Runs' }}</h2>
      <span class="hint">{{ result.meta.total.toLocaleString() }} attempt{{ result.meta.total === 1 ? '' : 's' }}</span>
      <span class="spacer"></span>
      <template v-if="failedOnly">
        <button class="btn primary" :disabled="!selected.size" @click="bulk('retry')">
          Retry{{ selected.size ? ` ${selected.size}` : '' }}
        </button>
        <button class="btn danger" :disabled="!selected.size" @click="bulk('forget')" style="margin-left: 6px">
          Discard
        </button>
      </template>
    </header>

    <div class="scroll">
      <table>
        <thead>
          <tr>
            <th v-if="failedOnly" style="width: 34px">
              <input type="checkbox" :checked="selected.size > 0 && selected.size === result.data.length" @change="toggleAll">
            </th>
            <th>Job</th>
            <th>Connection</th>
            <th>Queue</th>
            <th>Status</th>
            <th class="num">Attempt</th>
            <th class="num">Wait</th>
            <th class="num">Runtime</th>
            <th class="num">When</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="run in result.data" :key="run.uuid" class="clickable" @click="emit('open', run.uuid)">
            <td v-if="failedOnly" @click.stop>
              <input type="checkbox" :checked="selected.has(run.job_uuid)" :disabled="!run.job_uuid" @change="toggle(run.job_uuid)">
            </td>
            <td>
              <span class="truncate" :title="run.job_class">{{ run.job_class.split('\\').pop() }}</span>
              <span v-if="run.exception_message" class="truncate" style="color: var(--crit); font-size: 12px">
                {{ run.exception_message }}
              </span>
            </td>
            <td>{{ run.connection }}</td>
            <td>{{ run.queue }}</td>
            <td><span class="pill" :class="run.status">{{ run.status.replace('_', ' ') }}</span></td>
            <td class="num">{{ run.attempt }}</td>
            <td class="num">{{ ms(run.wait_ms) }}</td>
            <td class="num">{{ ms(run.runtime_ms) }}</td>
            <td class="num" style="color: var(--muted)">{{ ago(run.started_at) }}</td>
          </tr>
          <tr v-if="!result.data.length">
            <td :colspan="failedOnly ? 9 : 8">
              <div class="empty">
                <strong>{{ loading ? 'Loading…' : failedOnly ? 'No failures in range' : 'No runs match these filters' }}</strong>
                <template v-if="!loading">Attempts are trimmed after the retention window; widen the range or clear the filters.</template>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <header v-if="result.meta.last_page > 1" style="border-top: 1px solid var(--line-soft); border-bottom: 0">
      <span class="hint">Page {{ result.meta.page }} of {{ result.meta.last_page }}</span>
      <span class="spacer"></span>
      <button class="btn" :disabled="filters.page <= 1" @click="filters.page--; load()">Previous</button>
      <button class="btn" style="margin-left: 6px" :disabled="filters.page >= result.meta.last_page" @click="filters.page++; load()">Next</button>
    </header>
  </div>
</template>
