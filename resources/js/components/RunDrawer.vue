<script setup>
import { ref, watch } from 'vue'
import { api, ago, ms } from '../api.js'

const props = defineProps({ uuid: String })
const emit = defineEmits(['close'])

const run = ref(null)
const error = ref(null)

watch(() => props.uuid, async (uuid) => {
  run.value = null
  error.value = null
  if (!uuid) return

  try {
    run.value = (await api.run(uuid)).data
  } catch (e) {
    error.value = e.message
  }
}, { immediate: true })

const when = (at) => (at ? new Date(at * 1000).toLocaleString() : '—')
</script>

<template>
  <div class="drawer-backdrop" @click.self="emit('close')">
    <aside class="drawer" role="dialog" aria-label="Run detail">
      <header>
        <h2>{{ run ? run.job_class.split('\\').pop() : 'Run' }}</h2>
        <span class="spacer"></span>
        <button class="btn" @click="emit('close')">Close</button>
      </header>

      <div class="body">
        <div v-if="error" class="banner">{{ error }}</div>

        <template v-if="run">
          <dl class="kv">
            <dt>Class</dt><dd class="mono">{{ run.job_class }}</dd>
            <dt>Status</dt><dd><span class="pill" :class="run.status">{{ run.status.replace('_', ' ') }}</span></dd>
            <dt>Connection / queue</dt><dd>{{ run.connection }} / {{ run.queue }}</dd>
            <dt>Attempt</dt><dd>{{ run.attempt }}</dd>
            <dt>Queued</dt><dd>{{ when(run.queued_at) }}</dd>
            <dt>Started</dt><dd>{{ when(run.started_at) }} <span style="color: var(--muted)">({{ ago(run.started_at) }})</span></dd>
            <dt>Waited</dt><dd>{{ ms(run.wait_ms) }}</dd>
            <dt>Ran for</dt><dd>{{ ms(run.runtime_ms) }}</dd>
            <dt v-if="run.peak_memory_kb">Peak memory</dt>
            <dd v-if="run.peak_memory_kb">{{ (run.peak_memory_kb / 1024).toFixed(1) }} MB</dd>
            <dt>Job id</dt><dd class="mono">{{ run.job_uuid ?? '—' }}</dd>
            <dt v-if="run.batch_id">Batch</dt><dd v-if="run.batch_id" class="mono">{{ run.batch_id }}</dd>
            <dt v-if="run.tags?.length">Tags</dt>
            <dd v-if="run.tags?.length" class="mono">{{ run.tags.join(', ') }}</dd>
          </dl>

          <template v-if="run.exception_class">
            <h3 class="section">Exception</h3>
            <div class="banner">
              <div>
                <strong class="mono">{{ run.exception_class }}</strong>
                <div style="margin-top: 4px">{{ run.exception_message }}</div>
              </div>
            </div>
          </template>

          <template v-if="run.attempts?.length > 1">
            <h3 class="section">Attempt timeline</h3>
            <div class="panel scroll">
              <table>
                <thead><tr><th class="num">#</th><th>Status</th><th class="num">Runtime</th><th class="num">When</th></tr></thead>
                <tbody>
                  <tr v-for="attempt in run.attempts" :key="attempt.uuid">
                    <td class="num">{{ attempt.attempt }}</td>
                    <td><span class="pill" :class="attempt.status">{{ attempt.status.replace('_', ' ') }}</span></td>
                    <td class="num">{{ ms(attempt.runtime_ms) }}</td>
                    <td class="num" style="color: var(--muted)">{{ ago(attempt.started_at) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>

          <template v-if="run.children?.length">
            <h3 class="section">Dispatched by this job</h3>
            <div class="panel scroll">
              <table>
                <thead><tr><th>Class</th><th>Status</th><th class="num">Runtime</th></tr></thead>
                <tbody>
                  <tr v-for="child in run.children" :key="child.uuid">
                    <td><span class="truncate mono" :title="child.job_class">{{ child.job_class }}</span></td>
                    <td><span class="pill" :class="child.status">{{ child.status.replace('_', ' ') }}</span></td>
                    <td class="num">{{ ms(child.runtime_ms) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>

          <h3 class="section">Payload</h3>
          <pre class="payload" v-if="run.payload">{{ JSON.stringify(run.payload, null, 2) }}</pre>
          <p v-else style="color: var(--muted)">
            The payload was dropped by retention, or payload capture is off.
          </p>
        </template>

        <div v-else-if="!error" class="empty">Loading…</div>
      </div>
    </aside>
  </div>
</template>
