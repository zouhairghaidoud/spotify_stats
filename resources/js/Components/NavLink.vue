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
        ? 'inline-flex items-center rounded-full bg-white/10 px-4 py-2 text-sm font-semibold text-white transition'
        : 'inline-flex items-center rounded-full px-4 py-2 text-sm font-medium text-ink-300 transition hover:bg-white/5 hover:text-white',
);
</script>

<template>
    <Link :href="href" :class="classes">
        <slot />
    </Link>
</template>
