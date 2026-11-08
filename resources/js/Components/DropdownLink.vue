<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <Link
        :href="href"
        class="block w-full px-4 py-2.5 text-start text-sm font-medium text-ink-200 transition duration-150 ease-in-out hover:bg-white/5 hover:text-white focus:bg-white/5 focus:text-white focus:outline-none"
    >
        <slot />
    </Link>
</template>
