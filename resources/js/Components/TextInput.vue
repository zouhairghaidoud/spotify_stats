<script setup>
import { onMounted, ref } from 'vue';

const model = defineModel({
    type: String,
    required: true,
});

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
        class="rounded-xl border-white/10 bg-white/[0.04] px-3.5 py-2.5 text-sm text-white shadow-sm transition placeholder:text-ink-400 focus:border-brand-500/60 focus:bg-white/[0.06] focus:ring-2 focus:ring-brand-500/30"
        v-model="model"
        ref="input"
    />
</template>
