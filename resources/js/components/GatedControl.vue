<script setup>
import { computed } from 'vue'

/**
 * A control that renders from the driver's declared capabilities.
 *
 * This is the capability gate made visible. The button is disabled with the
 * server's own reason attached, so an operator learns that SQS cannot delete a
 * single message *before* clicking, not after a failed request.
 */
const props = defineProps({
  meta: { type: Object, default: null },
  connection: { type: String, required: true },
  capability: { type: String, required: true },
  requiresManage: { type: Boolean, default: true },
  cooldown: { type: Number, default: 0 },
  label: { type: String, required: true },
  danger: { type: Boolean, default: false },
})

const emit = defineEmits(['click'])

const capability = computed(
  () => props.meta?.connections?.find((c) => c.name === props.connection)?.capabilities?.[props.capability]
)

const allowed = computed(() => !props.requiresManage || props.meta?.can?.manage === true)

const reason = computed(() => {
  if (capability.value && capability.value.supported === false) return capability.value.reason
  if (!allowed.value) return 'You do not have permission to change queue state.'
  if (props.cooldown > 0) return `This driver allows it again in ${props.cooldown}s.`
  return null
})
</script>

<template>
  <span class="gated">
    <button
      class="btn"
      :class="{ danger }"
      :disabled="reason !== null"
      :title="reason ?? label"
      :aria-describedby="reason ? undefined : null"
      @click="emit('click')"
    >
      {{ label }}
    </button>
  </span>
</template>
