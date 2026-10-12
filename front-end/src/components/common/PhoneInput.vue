<script setup>
import { computed, ref } from 'vue'
import { localPhone } from '../../utils/phone'
const props = defineProps({ modelValue: { type: [String, Number], default: '' } })
const emit = defineEmits(['update:modelValue'])
const element = ref(null)
defineExpose({ focus: () => element.value?.focus() })
const displayed = computed(() => localPhone(props.modelValue))
function input(event) {
  const value = localPhone(event.target.value)
  if (event.target.value !== value) event.target.value = value
  emit('update:modelValue', value)
}
</script>
<template>
  <input ref="element" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" placeholder="05xxxxxxxx" maxlength="20" pattern="(?:05[0-9]{8}|\+[1-9][0-9]{7,14})" :value="displayed" @input="input">
</template>
