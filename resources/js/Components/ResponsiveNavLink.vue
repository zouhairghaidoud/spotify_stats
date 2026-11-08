<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    href: {
        type: String,
        required: true,
    },
    active: {
        type: Boolean,
    },
});

const classes = computed(() =>
    props.active
        ? 'block w-full rounded-xl bg-white/10 px-4 py-3 text-start text-base font-semibold text-white transition'
        : 'block w-full rounded-xl px-4 py-3 text-start text-base font-medium text-ink-300 transition hover:bg-white/5 hover:text-white',
);
</script>

<template>
    <Link :href="href" :class="classes">
        <slot />
    </Link>
</template>
