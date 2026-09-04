<script setup>
import { ref, watch } from 'vue'
import { api, ago } from '../api.js'
import GatedControl from './GatedControl.vue'

const props = defineProps({ target: Object, meta: Object })
const emit = defineEmits(['close', 'changed', 'error'])

const jobs = ref([])
const error = ref(null)
const loading = ref(false)

async function load() {
  if (!props.target) return
  loading.value = true
  error.value = null

  try {
    jobs.value = (await api.jobs(props.target.connection, props.target.queue, 50)).data
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function act(fn, job) {
  try {
    await fn(props.target.connection, props.target.queue, job.id)
    await load()
    emit('changed')
  } catch (e) {
    error.value = e.message
  }
}

watch(() => props.target, load, { immediate: true })
</script>

<template>
  <div class="drawer-backdrop" @click.self="emit('close')">
    <aside class="drawer" role="dialog" aria-label="Queued jobs">
      <header>
        <h2>{{ target.connection }} / {{ target.queue }}</h2>
        <span class="spacer"></span>
        <button class="btn" @click="load">Refresh</button>
        <button class="btn" style="margin-left: 6px" @click="emit('close')">Close</button>
      </header>

      <div class="body">
        <div v-if="error" class="banner">{{ error }}</div>

        <div class="panel scroll">
          <table>
            <thead>
              <tr><th>Job</th><th>State</th><th class="num">Available</th><th></th></tr>
            </thead>
            <tbody>
              <tr v-for="job in jobs" :key="job.id">
                <td>
                  <span class="truncate" :title="job.job_class">{{ job.job_class.split('\\').pop() }}</span>
                  <span class="mono" style="font-size: 11px; color: var(--faint)">{{ job.uuid }}</span>
                </td>
                <td>
                  <span class="pill" :class="job.delayed ? 'released' : 'processing'">
                    {{ job.delayed ? 'delayed' : 'ready' }}
                  </span>
                </td>
                <td class="num" style="color: var(--muted)">
                  {{ job.available_at ? new Date(job.available_at * 1000).toLocaleTimeString() : ago(job.created_at) }}
                </td>
                <td style="text-align: right; white-space: nowrap">
                  <GatedControl
                    v-if="job.delayed"
                    :meta="meta" :connection="target.connection" capability="promote_delayed" label="Run now"
                    @click="act(api.promote, job)"
                  />
                  <GatedControl
                    :meta="meta" :connection="target.connection" capability="delete_by_id" label="Delete" danger
                    style="margin-left: 6px"
                    @click="act(api.forgetJob, job)"
                  />
                </td>
              </tr>
              <tr v-if="!jobs.length">
                <td colspan="4">
                  <div class="empty">
                    <strong>{{ loading ? 'Loading…' : 'This queue is empty' }}</strong>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </aside>
  </div>
</template>
