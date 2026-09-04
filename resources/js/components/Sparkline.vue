<script setup>
import { computed } from 'vue'

const props = defineProps({
  points: { type: Array, default: () => [] },
})

const W = 900
const H = 150
const PAD = { top: 8, right: 4, bottom: 20, left: 40 }

const peak = computed(() => Math.max(1, ...props.points.map((p) => p.total)))

const bars = computed(() => {
  const n = props.points.length || 1
  const width = (W - PAD.left - PAD.right) / n
  const height = H - PAD.top - PAD.bottom

  return props.points.map((point, i) => {
    const total = (point.total / peak.value) * height
    const failed = (point.failed / peak.value) * height

    return {
      at: point.at,
      x: PAD.left + i * width,
      w: Math.max(1, width - 1.5),
      okY: PAD.top + height - total,
      okH: Math.max(0, total - failed),
      failY: PAD.top + height - failed,
      failH: failed,
      total: point.total,
      failed: point.failed,
    }
  })
})

// Ticks name values the chart actually reaches, so the axis is readable rather
// than decorative.
const ticks = computed(() => [0, Math.round(peak.value / 2), peak.value])
const y = (value) => PAD.top + (H - PAD.top - PAD.bottom) * (1 - value / peak.value)
const clock = (at) => new Date(at * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
const edges = computed(() => (props.points.length ? [props.points[0], props.points[props.points.length - 1]] : []))
</script>

<template>
  <div class="chart">
    <svg :viewBox="`0 0 ${W} ${H}`" role="img" aria-label="Job throughput over the selected window, failures shaded separately">
      <g>
        <line
          v-for="tick in ticks" :key="`g${tick}`"
          :x1="PAD.left" :x2="W - PAD.right" :y1="y(tick)" :y2="y(tick)"
          stroke="currentColor" stroke-width="1" opacity=".12"
        />
        <text
          v-for="tick in ticks" :key="`t${tick}`"
          :x="PAD.left - 8" :y="y(tick) + 4" text-anchor="end"
          font-size="11" fill="currentColor" opacity=".55"
        >{{ tick }}</text>
      </g>

      <g v-for="bar in bars" :key="bar.at">
        <rect :x="bar.x" :y="bar.okY" :width="bar.w" :height="bar.okH" fill="var(--accent)" opacity=".75" />
        <rect v-if="bar.failH > 0" :x="bar.x" :y="bar.failY" :width="bar.w" :height="bar.failH" fill="var(--crit)" />
        <title>{{ clock(bar.at) }} — {{ bar.total }} run{{ bar.total === 1 ? '' : 's' }}, {{ bar.failed }} failed</title>
      </g>

      <text
        v-for="(edge, i) in edges" :key="`x${edge.at}`"
        :x="i === 0 ? PAD.left : W - PAD.right" :y="H - 5"
        :text-anchor="i === 0 ? 'start' : 'end'"
        font-size="11" fill="currentColor" opacity=".55"
      >{{ clock(edge.at) }}</text>
    </svg>

    <div class="legend">
      <span><i style="background: var(--accent)"></i>Completed</span>
      <span><i style="background: var(--crit)"></i>Failed</span>
    </div>
  </div>
</template>
