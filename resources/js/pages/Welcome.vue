<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    canLogin: {
        type: Boolean,
    },
    canRegister: {
        type: Boolean,
    },
});

const previewTracks = [
    {
        title: 'Blinding Lights',
        artist: 'The Weeknd',
        duration: '3:20',
        cover: 'from-orange-400 to-rose-500',
    },
    {
        title: 'As It Was',
        artist: 'Harry Styles',
        duration: '2:47',
        cover: 'from-sky-400 to-indigo-500',
    },
    {
        title: 'Heat Waves',
        artist: 'Glass Animals',
        duration: '3:58',
        cover: 'from-emerald-400 to-teal-600',
    },
    {
        title: 'Levitating',
        artist: 'Dua Lipa',
        duration: '3:23',
        cover: 'from-fuchsia-400 to-purple-600',
    },
];

const features = [
    {
        title: 'Top tracks & artists',
        description:
            'See exactly what you have had on repeat across 4 weeks, 6 months, and a full year.',
    },
    {
        title: 'Now playing',
        description:
            'A live card that shows the track playing right now, complete with progress.',
    },
    {
        title: 'Recent history',
        description:
            'Your last 50 plays with the exact time each track was started.',
    },
];
</script>

<template>
    <Head title="Spotify Stats" />

    <div class="relative min-h-screen overflow-hidden bg-ink-950">
        <!-- Ambient background -->
        <div aria-hidden="true" class="pointer-events-none absolute inset-0">
            <div
                class="absolute -top-48 left-1/2 h-[34rem] w-[52rem] -translate-x-1/2 rounded-full bg-brand-500/[0.14] blur-3xl"
            ></div>
            <div
                class="absolute -bottom-32 -left-24 h-80 w-80 rounded-full bg-emerald-400/[0.07] blur-3xl"
            ></div>
            <div
                class="absolute inset-0 bg-[linear-gradient(to_right,rgba(255,255,255,0.03)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.03)_1px,transparent_1px)] bg-[size:72px_72px] [mask-image:radial-gradient(ellipse_at_top,black,transparent_70%)]"
            ></div>
        </div>

        <!-- Navigation -->
        <header class="relative z-10">
            <div
                class="mx-auto flex max-w-7xl items-center justify-between px-4 py-6 sm:px-6 lg:px-8"
            >
                <Link href="/" class="flex items-center gap-2.5">
                    <ApplicationLogo class="h-9 w-9" />
                    <span class="text-base font-bold tracking-tight text-white">
                        Spotify <span class="text-brand-400">Stats</span>
                    </span>
                </Link>

                <nav class="flex items-center gap-2 sm:gap-3">
                    <template v-if="$page.props.auth.user">
                        <Link
                            :href="route('dashboard')"
                            class="rounded-full bg-brand-500 px-4 py-2 text-sm font-bold text-ink-950 shadow-lg shadow-brand-500/20 transition hover:bg-brand-400"
                        >
                            Go to dashboard
                        </Link>
                    </template>
                    <template v-else>
                        <Link
                            v-if="canLogin"
                            :href="route('login')"
                            class="rounded-full px-4 py-2 text-sm font-semibold text-ink-200 transition hover:bg-white/5 hover:text-white"
                        >
                            Log in
                        </Link>
                        <Link
                            v-if="canRegister"
                            :href="route('register')"
                            class="rounded-full border border-white/10 bg-white/[0.04] px-4 py-2 text-sm font-semibold text-white transition hover:bg-white/[0.08]"
                        >
                            Create account
                        </Link>
                    </template>
                </nav>
            </div>
        </header>

        <!-- Hero -->
        <main class="relative z-10">
            <div
                class="mx-auto grid max-w-7xl items-center gap-14 px-4 pb-20 pt-10 sm:px-6 lg:grid-cols-2 lg:gap-16 lg:px-8 lg:pb-28 lg:pt-16"
            >
                <div>
                    <span
                        class="inline-flex items-center gap-2 rounded-full border border-brand-500/20 bg-brand-500/10 px-3 py-1.5 text-xs font-semibold text-brand-300"
                    >
                        <span
                            class="inline-block h-1.5 w-1.5 rounded-full bg-brand-400"
                        ></span>
                        Personal Spotify analytics
                    </span>

                    <h1
                        class="mt-6 text-4xl font-extrabold leading-[1.05] tracking-tight text-white sm:text-5xl lg:text-6xl"
                    >
                        Your listening,
                        <span
                            class="bg-gradient-to-r from-brand-300 via-brand-400 to-emerald-300 bg-clip-text text-transparent"
                        >
                            quantified.
                        </span>
                    </h1>

                    <p
                        class="mt-5 max-w-xl text-base leading-relaxed text-ink-300 sm:text-lg"
                    >
                        A clean, fast dashboard for your Spotify habits. Connect
                        once and instantly see your top tracks, favorite artists,
                        what is playing right now, and your recent history.
                    </p>

                    <div class="mt-8 flex flex-wrap items-center gap-3">
                        <a
                            :href="route('spotify.redirect')"
                            class="inline-flex items-center gap-2 rounded-full bg-brand-500 px-6 py-3 text-sm font-bold text-ink-950 shadow-lg shadow-brand-500/25 transition hover:bg-brand-400"
                        >
                            <svg
                                class="h-5 w-5"
                                viewBox="0 0 24 24"
                                fill="currentColor"
                                aria-hidden="true"
                            >
                                <path
                                    d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0Zm5.5 17.28a.75.75 0 0 1-1.03.25c-2.82-1.72-6.37-2.11-10.55-1.16a.75.75 0 0 1-.33-1.46c4.57-1.05 8.5-.6 11.66 1.34.35.21.46.67.25 1.03Zm1.47-3.27a.94.94 0 0 1-1.29.31c-3.23-1.98-8.15-2.56-11.97-1.4a.94.94 0 1 1-.54-1.8c4.36-1.32 9.78-.68 13.49 1.6.44.27.58.85.31 1.29Zm.13-3.4C15.24 8.32 8.94 8.11 5.26 9.23a1.12 1.12 0 1 1-.65-2.15c4.22-1.28 11.18-1.03 15.58 1.58a1.12 1.12 0 1 1-1.15 1.92Z"
                                />
                            </svg>
                            Continue with Spotify
                        </a>

                        <Link
                            :href="$page.props.auth.user ? route('dashboard') : route('login')"
                            class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.04] px-6 py-3 text-sm font-semibold text-white transition hover:bg-white/[0.08]"
                        >
                            Explore the dashboard
                        </Link>
                    </div>

                    <p class="mt-5 text-xs text-ink-500">
                        Read-only access. Revoke anytime from your Spotify
                        account.
                    </p>
                </div>

                <!-- Preview card -->
                <div class="relative mx-auto w-full max-w-md lg:max-w-none">
                    <div
                        aria-hidden="true"
                        class="absolute -inset-6 rounded-[2rem] bg-brand-500/10 blur-2xl"
                    ></div>

                    <div
                        class="relative overflow-hidden rounded-3xl border border-white/10 bg-ink-900/80 p-5 shadow-2xl shadow-black/60 backdrop-blur-xl"
                    >
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span
                                    class="inline-block h-2 w-2 animate-pulse rounded-full bg-brand-400"
                                ></span>
                                <span
                                    class="text-xs font-bold uppercase tracking-[0.18em] text-brand-300"
                                >
                                    Now playing
                                </span>
                            </div>
                            <span class="text-xs text-ink-500">Live</span>
                        </div>

                        <div class="mt-4 flex items-center gap-4">
                            <div
                                class="h-16 w-16 rounded-xl bg-gradient-to-br from-brand-400 to-emerald-700 shadow-lg"
                            ></div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-bold text-white">
                                    Midnight City
                                </p>
                                <p class="truncate text-sm text-ink-400">
                                    M83 &middot; Hurry Up, We're Dreaming
                                </p>
                                <div class="mt-3 h-1.5 rounded-full bg-white/10">
                                    <div
                                        class="h-full w-2/3 rounded-full bg-brand-400"
                                    ></div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-6 flex items-center justify-between">
                            <h2 class="text-sm font-bold text-white">
                                Top Tracks
                            </h2>
                            <span class="text-xs text-ink-500">This month</span>
                        </div>

                        <ul class="mt-3 space-y-1">
                            <li
                                v-for="(track, index) in previewTracks"
                                :key="track.title"
                                class="flex items-center gap-3 rounded-xl px-2 py-2 transition hover:bg-white/[0.04]"
                            >
                                <span
                                    class="w-4 text-right text-xs font-bold text-ink-500"
                                >
                                    {{ index + 1 }}
                                </span>
                                <div
                                    class="h-9 w-9 rounded-lg bg-gradient-to-br shadow"
                                    :class="track.cover"
                                ></div>
                                <div class="min-w-0 flex-1">
                                    <p
                                        class="truncate text-sm font-semibold text-white"
                                    >
                                        {{ track.title }}
                                    </p>
                                    <p class="truncate text-xs text-ink-400">
                                        {{ track.artist }}
                                    </p>
                                </div>
                                <span class="text-xs tabular-nums text-ink-500">
                                    {{ track.duration }}
                                </span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Features -->
            <div
                class="border-t border-white/[0.06] bg-ink-950/60 py-16 backdrop-blur"
            >
                <div
                    class="mx-auto grid max-w-7xl gap-4 px-4 sm:px-6 sm:grid-cols-3 lg:px-8"
                >
                    <div
                        v-for="feature in features"
                        :key="feature.title"
                        class="surface-card p-6"
                    >
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-500/10 text-brand-400"
                        >
                            <svg
                                class="h-5 w-5"
                                viewBox="0 0 20 20"
                                fill="currentColor"
                                aria-hidden="true"
                            >
                                <path
                                    fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                    clip-rule="evenodd"
                                />
                            </svg>
                        </div>
                        <h3 class="mt-4 text-sm font-bold text-white">
                            {{ feature.title }}
                        </h3>
                        <p class="mt-1.5 text-sm leading-relaxed text-ink-400">
                            {{ feature.description }}
                        </p>
                    </div>
                </div>
            </div>
        </main>

        <footer class="relative z-10 border-t border-white/[0.06] py-8">
            <div
                class="mx-auto flex max-w-7xl flex-col items-center justify-between gap-3 px-4 text-xs text-ink-500 sm:flex-row sm:px-6 lg:px-8"
            >
                <p>Spotify Stats &middot; Personal listening analytics</p>
                <p>Not affiliated with Spotify.</p>
            </div>
        </footer>
    </div>
</template>
