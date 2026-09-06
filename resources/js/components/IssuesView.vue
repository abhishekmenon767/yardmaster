<script setup>
import { onMounted, ref } from 'vue'
import { api, ago } from '../api.js'

const emit = defineEmits(['error'])

const status = ref('open')
const search = ref('')
const issues = ref([])
const loading = ref(true)
const busy = ref(null)

async function load() {
  loading.value = true
  try {
    issues.value = (await api.issues({ status: status.value, search: search.value })).data
  } catch (e) {
    emit('error', e.message)
  } finally {
    loading.value = false
  }
}

async function act(issue, fn) {
  busy.value = issue.fingerprint
  try {
    await fn()
    await load()
  } catch (e) {
    emit('error', e.message)
  } finally {
    busy.value = null
  }
}

const retry = (issue) => {
  if (!window.confirm(`Retry all ${issue.occurrences.toLocaleString()} failed job(s) in this issue?`)) return
  act(issue, () => api.retryIssue(issue.fingerprint))
}

const setStatus = (issue, next) => act(issue, () => api.setIssueStatus(issue.fingerprint, next))

const shortClass = (name) => name.split('\\').pop()

onMounted(load)
</script>

<template>
  <div class="filters">
    <input type="search" v-model="search" placeholder="Search exception, message, job class or frame…" @keyup.enter="load">
    <select v-model="status" @change="load">
      <option value="open">Open</option>
      <option value="ignored">Ignored</option>
      <option value="resolved">Resolved</option>
      <option value="all">All</option>
    </select>
    <button class="btn" @click="load">Apply</button>
  </div>

  <div class="panel">
    <header>
      <h2>Issues</h2>
      <span class="hint">failures grouped by what actually went wrong</span>
    </header>

    <div class="scroll">
      <table>
        <thead>
          <tr>
            <th>Problem</th>
            <th>Job</th>
            <th class="num">Occurrences</th>
            <th class="num">First seen</th>
            <th class="num">Last seen</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="issue in issues" :key="issue.fingerprint">
            <td>
              <strong class="mono">{{ shortClass(issue.exception_class) }}</strong>
              <span class="truncate" :title="issue.sample_message ?? issue.message">{{ issue.message }}</span>
              <span v-if="issue.frame" class="truncate mono" style="font-size: 11px; color: var(--faint)">
                {{ issue.frame }}
              </span>
            </td>
            <td><span class="truncate" :title="issue.job_class">{{ shortClass(issue.job_class) }}</span></td>
            <td class="num">
              <strong>{{ issue.occurrences.toLocaleString() }}</strong>
              <span v-if="issue.status !== 'open'" class="pill" :class="issue.status === 'ignored' ? 'released' : 'processed'" style="margin-left: 6px">
                {{ issue.status }}
              </span>
            </td>
            <td class="num" style="color: var(--muted)">{{ ago(issue.first_seen) }}</td>
            <td class="num" style="color: var(--muted)">{{ ago(issue.last_seen) }}</td>
            <td style="text-align: right; white-space: nowrap">
              <button class="btn primary" :disabled="busy === issue.fingerprint" @click="retry(issue)">
                Retry all
              </button>
              <button
                v-if="issue.status !== 'ignored'"
                class="btn" style="margin-left: 6px"
                :disabled="busy === issue.fingerprint"
                title="Stop showing this issue — it is expected"
                @click="setStatus(issue, 'ignored')"
              >Ignore</button>
              <button
                v-if="issue.status === 'open'"
                class="btn" style="margin-left: 6px"
                :disabled="busy === issue.fingerprint"
                title="Mark as dealt with — it reopens if it happens again"
                @click="setStatus(issue, 'resolved')"
              >Resolve</button>
              <button
                v-if="issue.status !== 'open'"
                class="btn" style="margin-left: 6px"
                :disabled="busy === issue.fingerprint"
                @click="setStatus(issue, 'open')"
              >Reopen</button>
            </td>
          </tr>
          <tr v-if="!issues.length">
            <td colspan="6">
              <div class="empty">
                <strong>{{ loading ? 'Loading…' : status === 'open' ? 'Nothing is failing' : 'No issues match' }}</strong>
                <template v-if="!loading && status === 'open'">
                  Failures are grouped here by exception, message and the line they came from.
                </template>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
