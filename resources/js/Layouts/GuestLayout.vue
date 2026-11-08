<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div
        class="relative flex min-h-screen flex-col items-center justify-center overflow-hidden bg-ink-950 px-4 py-10 sm:px-6"
    >
        <!-- Ambient background -->
        <div aria-hidden="true" class="pointer-events-none absolute inset-0">
            <div
                class="absolute -top-40 left-1/2 h-[28rem] w-[46rem] -translate-x-1/2 rounded-full bg-brand-500/10 blur-3xl"
            ></div>
            <div
                class="absolute -bottom-24 right-0 h-72 w-72 rounded-full bg-emerald-400/[0.06] blur-3xl"
            ></div>
            <div
                class="absolute inset-0 bg-[linear-gradient(to_right,rgb(var(--white)/0.05)_1px,transparent_1px),linear-gradient(to_bottom,rgb(var(--white)/0.05)_1px,transparent_1px)] bg-[size:64px_64px] [mask-image:radial-gradient(ellipse_at_center,black,transparent_72%)]"
            ></div>
        </div>

        <div class="absolute right-4 top-4 z-10 sm:right-6 sm:top-6">
            <ThemeToggle />
        </div>

        <div class="relative w-full max-w-md">
            <Link
                href="/"
                class="flex flex-col items-center gap-3 text-center"
            >
                <ApplicationLogo class="h-14 w-14 drop-shadow-[0_8px_24px_rgba(29,185,84,0.35)]" />
                <span class="text-lg font-bold tracking-tight text-white">
                    Spotify <span class="text-brand-400">Stats</span>
                </span>
            </Link>

            <div
                class="mt-8 rounded-2xl border border-white/[0.08] bg-ink-900/70 p-6 shadow-2xl shadow-black/50 backdrop-blur-xl sm:p-8"
            >
                <slot />
            </div>

            <p class="mt-6 text-center text-xs text-ink-400">
                Your listening history, beautifully visualized.
            </p>
        </div>
    </div>
</template>
