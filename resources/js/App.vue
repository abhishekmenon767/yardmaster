<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { api, basePath } from './api.js'
import AuditView from './components/AuditView.vue'
import IssuesView from './components/IssuesView.vue'
import WorkersView from './components/WorkersView.vue'
import OverviewView from './components/OverviewView.vue'
import PeekDrawer from './components/PeekDrawer.vue'
import RunDrawer from './components/RunDrawer.vue'
import RunsView from './components/RunsView.vue'

const TABS = [
  { id: 'overview', label: 'Overview' },
  { id: 'issues', label: 'Issues' },
  { id: 'runs', label: 'Runs' },
  { id: 'failures', label: 'Failures' },
  { id: 'workers', label: 'Workers' },
  { id: 'audit', label: 'Audit' },
]

const RANGES = [
  { id: 3600, label: 'Last hour' },
  { id: 21600, label: 'Last 6 hours' },
  { id: 86400, label: 'Last 24 hours' },
  { id: 604800, label: 'Last 7 days' },
]

// The tab lives in the URL. An operations console is something people paste
// into a chat thread mid-incident; a dashboard that always opens on Overview
// makes "look at this failure" impossible to say.
const tabFromUrl = () => {
  const slug = window.location.pathname.replace(basePath, '').replace(/^\/+|\/+$/g, '')
  return TABS.some((t) => t.id === slug) ? slug : 'overview'
}

const tab = ref(tabFromUrl())
const range = ref(3600)
const meta = ref(null)
const metrics = ref(null)
const queues = ref([])
const options = ref(null)
const error = ref(null)
const live = ref(false)
const openRun = ref(null)
const peeking = ref(null)

let stream = null
let poll = null

const failingHard = computed(() => (metrics.value?.summary?.failure_rate ?? 0) > 0.25)

async function loadMeta() {
  try {
    meta.value = await api.meta()
    options.value = await api.options()
  } catch (e) {
    error.value = e.message
  }
}

async function refresh() {
  try {
    const to = Math.floor(Date.now() / 1000)
    const [m, q] = await Promise.all([
      api.metrics({ from: to - range.value, to }),
      api.queues(),
    ])
    metrics.value = m
    queues.value = q.data
    error.value = null
  } catch (e) {
    error.value = e.message
  }
}

/**
 * Server-sent events, with polling as the fallback. One long-lived read
 * connection is all a dashboard needs, and the browser reconnects on its own —
 * so this needs no websocket server to be running before a number goes live.
 */
function connect() {
  if (typeof EventSource === 'undefined') return

  // ?live=0 falls back to polling. Some proxies buffer text/event-stream into
  // uselessness, and a dashboard that appears frozen is worse than one that
  // visibly refreshes every fifteen seconds.
  if (new URLSearchParams(window.location.search).get('live') === '0') return

  stream = new EventSource(`${basePath}/api/v1/stream?duration=300&interval=3`)

  stream.addEventListener('tick', (event) => {
    live.value = true
    const tick = JSON.parse(event.data)
    queues.value = tick.queues
  })

  stream.addEventListener('error', () => {
    live.value = false
    stream?.close()
    // The endpoint closes itself rather than holding a PHP worker forever, so
    // a clean end is expected — reconnect after a beat.
    setTimeout(connect, 4000)
  })
}

function show(id) {
  tab.value = id
  window.history.pushState({ tab: id }, '', `${basePath}/${id === 'overview' ? '' : id}${window.location.search}`)
}

onMounted(async () => {
  window.addEventListener('popstate', () => { tab.value = tabFromUrl() })

  await loadMeta()
  await refresh()
  connect()
  poll = setInterval(refresh, 15000)
})

onUnmounted(() => {
  stream?.close()
  clearInterval(poll)
})
</script>

<template>
  <div class="shell">
    <header class="top">
      <div class="brand">Yardmaster<i>.</i></div>
      <div class="live" :class="{ on: live }">
        <span class="dot"></span>{{ live ? 'Live' : 'Polling' }}
      </div>
      <span class="spacer"></span>
      <select v-model.number="range" @change="refresh">
        <option v-for="r in RANGES" :key="r.id" :value="r.id">{{ r.label }}</option>
      </select>
      <button class="btn" @click="refresh">Refresh</button>
    </header>

    <nav class="tabs">
      <button
        v-for="t in TABS" :key="t.id"
        :aria-current="tab === t.id ? 'page' : undefined"
        @click="show(t.id)"
      >
        {{ t.label }}
        <span v-if="t.id === 'failures' && metrics?.summary?.failed" class="pill failed" style="margin-left: 6px">
          {{ metrics.summary.failed }}
        </span>
      </button>
    </nav>

    <div v-if="error" class="banner">{{ error }}</div>
    <div v-else-if="failingHard" class="banner">
      More than a quarter of attempts in this window failed.
    </div>

    <OverviewView
      v-if="tab === 'overview'"
      :meta="meta" :metrics="metrics" :queues="queues"
      @refresh="refresh" @error="error = $event" @inspect="peeking = $event"
    />

    <RunsView
      v-else-if="tab === 'runs'"
      :options="options" :failed-only="false"
      @open="openRun = $event" @error="error = $event"
    />

    <RunsView
      v-else-if="tab === 'failures'"
      :options="options" :failed-only="true"
      @open="openRun = $event" @error="error = $event"
    />

    <IssuesView v-else-if="tab === 'issues'" @error="error = $event" />

    <WorkersView v-else-if="tab === 'workers'" @error="error = $event" />

    <AuditView v-else-if="tab === 'audit'" @error="error = $event" />

    <RunDrawer v-if="openRun" :uuid="openRun" @close="openRun = null" />
    <PeekDrawer
      v-if="peeking" :target="peeking" :meta="meta"
      @close="peeking = null" @changed="refresh" @error="error = $event"
    />
  </div>
</template>
