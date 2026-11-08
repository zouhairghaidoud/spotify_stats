<script setup>
defineProps({
    for: {
        type: String,
    },
    value: {
        type: String,
    },
});
</script>

<template>
    <label
        :for="$props.for"
        class="block text-sm font-medium text-ink-200"
    >
        <span v-if="value">{{ value }}</span>
        <span v-else><slot /></span>
    </label>
</template>
