const meta = (name, fallback = '') =>
  document.querySelector(`meta[name="${name}"]`)?.getAttribute('content') ?? fallback

export const basePath = meta('yardmaster-base', '/yardmaster')
const csrf = meta('yardmaster-csrf')

async function request(method, path, { query = {}, body = null } = {}) {
  const url = new URL(`${basePath}/api/v1/${path}`, window.location.origin)

  for (const [key, value] of Object.entries(query)) {
    if (value !== null && value !== undefined && value !== '') url.searchParams.set(key, value)
  }

  const response = await fetch(url, {
    method,
    headers: {
      Accept: 'application/json',
      ...(body ? { 'Content-Type': 'application/json' } : {}),
      ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
      'X-Requested-With': 'XMLHttpRequest',
    },
    credentials: 'same-origin',
    body: body ? JSON.stringify(body) : null,
  })

  const payload = response.status === 204 ? null : await response.json().catch(() => null)

  if (!response.ok) {
    // Carry the server's own wording through: a refused capability already
    // explains itself, and rewording it here would only make it vaguer.
    const error = new Error(payload?.message ?? `Request failed (${response.status})`)
    error.status = response.status
    error.payload = payload
    throw error
  }

  return payload
}

export const api = {
  meta: () => request('GET', 'meta'),
  queues: (connection) => request('GET', 'queues', { query: { connection } }),
  jobs: (connection, queue, limit = 25) => request('GET', 'queues/jobs', { query: { connection, queue, limit } }),
  runs: (filters) => request('GET', 'runs', { query: filters }),
  run: (uuid) => request('GET', `runs/${encodeURIComponent(uuid)}`),
  options: () => request('GET', 'runs/options'),
  metrics: (query) => request('GET', 'metrics', { query }),
  failures: (query) => request('GET', 'failures', { query }),
  actions: (query) => request('GET', 'actions', { query }),

  purge: (connection, queue) => request('POST', 'queues/purge', { body: { connection, queue } }),
  forgetJob: (connection, queue, id) => request('DELETE', 'queues/jobs', { body: { connection, queue, id } }),
  promote: (connection, queue, id) => request('POST', 'queues/jobs/promote', { body: { connection, queue, id } }),
  retry: (uuids) => request('POST', 'failures/retry', { body: { uuids } }),
  forgetFailures: (uuids) => request('DELETE', 'failures', { body: { uuids } }),
}

/** Format a duration the way an operator reads it, not the way it is stored. */
export function ms(value) {
  if (value === null || value === undefined) return '—'
  if (value < 1) return '<1ms'
  if (value < 1000) return `${Math.round(value)}ms`
  if (value < 60000) return `${(value / 1000).toFixed(value < 10000 ? 2 : 1)}s`
  return `${Math.floor(value / 60000)}m ${Math.round((value % 60000) / 1000)}s`
}

export function ago(unixSeconds) {
  if (!unixSeconds) return '—'
  const seconds = Math.max(0, Math.floor(Date.now() / 1000 - unixSeconds))
  if (seconds < 60) return `${seconds}s ago`
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`
  return `${Math.floor(seconds / 86400)}d ago`
}

export function count(value, approximate = false) {
  if (value === null || value === undefined) return null
  return `${approximate ? '~' : ''}${value.toLocaleString()}`
}
