<script setup>
import { theme, toggleTheme } from '@/theme';

defineProps({
    size: {
        type: String,
        default: 'md',
    },
});

const sizes = {
    sm: 'h-8 w-8',
    md: 'h-9 w-9',
};
</script>

<template>
    <button
        type="button"
        :class="sizes[size] ?? sizes.md"
        class="inline-flex shrink-0 items-center justify-center rounded-full border border-white/10 bg-white/[0.04] text-ink-300 transition hover:bg-white/[0.08] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400/50"
        :aria-label="
            theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'
        "
        :title="theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
        @click="toggleTheme"
    >
        <svg
            v-if="theme === 'dark'"
            class="h-4 w-4"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.8"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <circle cx="12" cy="12" r="4" />
            <path
                d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"
            />
        </svg>

        <svg
            v-else
            class="h-4 w-4"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.8"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <path
                d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z"
            />
        </svg>
    </button>
</template>
