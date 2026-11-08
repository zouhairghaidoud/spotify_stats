<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    connected: {
        type: Boolean,
        default: false,
    },
    range: {
        type: String,
        default: 'short_term',
    },
    spotifyError: {
        type: String,
        default: null,
    },
    currentlyPlaying: {
        type: Object,
        default: null,
    },
    topTracks: {
        type: Array,
        default: () => [],
    },
    topArtists: {
        type: Array,
        default: () => [],
    },
    recentlyPlayed: {
        type: Array,
        default: () => [],
    },
});

const ranges = [
    { value: 'short_term', label: '4 weeks' },
    { value: 'medium_term', label: '6 months' },
    { value: 'long_term', label: '1 year' },
];

const currentTrack = computed(() => {
    if (props.currentlyPlaying?.item?.type !== 'track') {
        return null;
    }

    return props.currentlyPlaying.item;
});

const progress = computed(() => {
    if (!currentTrack.value) {
        return 0;
    }

    return Math.min(
        100,
        Math.round(
            ((props.currentlyPlaying.progress_ms ?? 0) /
                (currentTrack.value.duration_ms || 1)) *
                100,
        ),
    );
});

function setRange(range) {
    if (range === props.range) {
        return;
    }

    router.get(
        '/dashboard',
        { range },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

function coverUrl(images) {
    return images?.[0]?.url ?? null;
}

function artistNames(track) {
    return (track?.artists ?? []).map((artist) => artist.name).join(', ');
}

function formatDuration(milliseconds) {
    const totalSeconds = Math.round((milliseconds ?? 0) / 1000);
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;

    return `${minutes}:${String(seconds).padStart(2, '0')}`;
}

function formatPlayedAt(value) {
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p
                        class="text-xs font-semibold uppercase tracking-[0.22em] text-brand-400"
                    >
                        Spotify Stats
                    </p>
                    <h1
                        class="mt-2 text-2xl font-bold tracking-tight text-white sm:text-3xl"
                    >
                        Hey, {{ $page.props.auth.user.name }}
                    </h1>
                    <p class="mt-1 text-sm text-ink-300">
                        A live look at what you are playing, loving, and
                        looping.
                    </p>
                </div>

                <div v-if="connected" class="flex items-center gap-2">
                    <span
                        class="inline-flex items-center gap-2 rounded-full border border-brand-500/20 bg-brand-500/10 px-3 py-1.5 text-xs font-semibold text-brand-300"
                    >
                        <span
                            class="inline-block h-1.5 w-1.5 rounded-full bg-brand-400"
                        ></span>
                        Connected
                    </span>

                    <a
                        :href="route('spotify.redirect')"
                        class="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/[0.04] px-3 py-1.5 text-xs font-semibold text-ink-200 transition hover:bg-white/[0.08] hover:text-white"
                    >
                        Reconnect Spotify
                    </a>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <div
                v-if="spotifyError"
                class="rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-sm font-medium text-amber-200"
            >
                {{ spotifyError }}
            </div>

            <!-- Not connected -->
            <section
                v-if="!connected"
                class="relative overflow-hidden rounded-3xl border border-white/[0.06] bg-ink-900/60 px-6 py-14 text-center shadow-card sm:px-12 sm:py-20"
            >
                <div
                    aria-hidden="true"
                    class="pointer-events-none absolute -top-24 left-1/2 h-72 w-96 -translate-x-1/2 rounded-full bg-brand-500/15 blur-3xl"
                ></div>

                <div
                    class="relative mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-brand-500 shadow-glow"
                >
                    <svg
                        class="h-9 w-9 text-ink-950"
                        viewBox="0 0 24 24"
                        fill="currentColor"
                        aria-hidden="true"
                    >
                        <path
                            d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0Zm5.5 17.28a.75.75 0 0 1-1.03.25c-2.82-1.72-6.37-2.11-10.55-1.16a.75.75 0 0 1-.33-1.46c4.57-1.05 8.5-.6 11.66 1.34.35.21.46.67.25 1.03Zm1.47-3.27a.94.94 0 0 1-1.29.31c-3.23-1.98-8.15-2.56-11.97-1.4a.94.94 0 1 1-.54-1.8c4.36-1.32 9.78-.68 13.49 1.6.44.27.58.85.31 1.29Zm.13-3.4C15.24 8.32 8.94 8.11 5.26 9.23a1.12 1.12 0 1 1-.65-2.15c4.22-1.28 11.18-1.03 15.58 1.58a1.12 1.12 0 1 1-1.15 1.92Z"
                        />
                    </svg>
                </div>

                <h2
                    class="relative mt-6 text-xl font-bold tracking-tight text-white sm:text-2xl"
                >
                    Connect your Spotify account
                </h2>
                <p
                    class="relative mx-auto mt-2 max-w-md text-sm leading-relaxed text-ink-300"
                >
                    Link your account to see your top tracks, top artists, what
                    is playing right now, and your recent listening history.
                </p>

                <a
                    :href="route('spotify.redirect')"
                    class="relative mt-8 inline-flex items-center gap-2 rounded-full bg-brand-500 px-6 py-3 text-sm font-bold text-ink-950 shadow-lg shadow-brand-500/20 transition hover:bg-brand-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:ring-offset-2 focus-visible:ring-offset-ink-950"
                >
                    Connect with Spotify
                </a>

                <div
                    class="relative mx-auto mt-12 grid max-w-2xl gap-3 text-left sm:grid-cols-3"
                >
                    <div
                        class="rounded-xl border border-white/[0.06] bg-white/[0.02] p-4"
                    >
                        <p class="text-sm font-semibold text-white">
                            Top tracks
                        </p>
                        <p class="mt-1 text-xs leading-relaxed text-ink-400">
                            Your most-played songs across 4 weeks, 6 months, and
                            a full year.
                        </p>
                    </div>
                    <div
                        class="rounded-xl border border-white/[0.06] bg-white/[0.02] p-4"
                    >
                        <p class="text-sm font-semibold text-white">
                            Top artists
                        </p>
                        <p class="mt-1 text-xs leading-relaxed text-ink-400">
                            The artists and genres you keep coming back to.
                        </p>
                    </div>
                    <div
                        class="rounded-xl border border-white/[0.06] bg-white/[0.02] p-4"
                    >
                        <p class="text-sm font-semibold text-white">
                            Live activity
                        </p>
                        <p class="mt-1 text-xs leading-relaxed text-ink-400">
                            Now playing and the last 50 tracks, updated as you
                            listen.
                        </p>
                    </div>
                </div>
            </section>

            <template v-else>
                <!-- Time range filter -->
                <div
                    class="flex flex-wrap items-center gap-2 rounded-2xl border border-white/[0.06] bg-ink-900/70 p-2"
                >
                    <span
                        class="px-3 text-xs font-semibold uppercase tracking-wide text-ink-500"
                    >
                        Time range
                    </span>

                    <button
                        v-for="option in ranges"
                        :key="option.value"
                        type="button"
                        class="rounded-xl px-4 py-2 text-sm font-semibold transition"
                        :class="
                            range === option.value
                                ? 'bg-brand-500 text-ink-950 shadow-lg shadow-brand-500/20'
                                : 'text-ink-300 hover:bg-white/5 hover:text-white'
                        "
                        @click="setRange(option.value)"
                    >
                        {{ option.label }}
                    </button>
                </div>

                <!-- Currently playing -->
                <section
                    v-if="currentTrack"
                    class="relative overflow-hidden rounded-2xl border border-brand-500/20 bg-gradient-to-br from-brand-500/15 via-ink-900 to-ink-900 p-5 shadow-card sm:p-6"
                >
                    <div
                        aria-hidden="true"
                        class="pointer-events-none absolute -right-10 -top-16 h-48 w-48 rounded-full bg-brand-500/20 blur-3xl"
                    ></div>

                    <div class="relative flex items-center gap-5">
                        <img
                            v-if="coverUrl(currentTrack.album?.images)"
                            :src="coverUrl(currentTrack.album?.images)"
                            :alt="currentTrack.album?.name"
                            class="h-20 w-20 rounded-xl object-cover shadow-2xl shadow-black/50 sm:h-24 sm:w-24"
                        />
                        <div
                            v-else
                            class="flex h-20 w-20 items-center justify-center rounded-xl bg-ink-800 text-ink-500 sm:h-24 sm:w-24"
                        >
                            <svg
                                class="h-8 w-8"
                                viewBox="0 0 24 24"
                                fill="currentColor"
                            >
                                <path d="M12 3v10.55A4 4 0 1 0 14 17V7h4V3h-6Z" />
                            </svg>
                        </div>

                        <div class="min-w-0 flex-1">
                            <p
                                class="flex items-center gap-2 text-xs font-bold uppercase tracking-[0.18em] text-brand-300"
                            >
                                <span
                                    class="inline-flex h-2 w-2 animate-pulse rounded-full bg-brand-400"
                                ></span>
                                Now playing
                            </p>
                            <p
                                class="mt-1.5 truncate text-lg font-bold text-white sm:text-xl"
                            >
                                {{ currentTrack.name }}
                            </p>
                            <p class="truncate text-sm text-ink-300">
                                {{ artistNames(currentTrack) }}
                                <span v-if="currentTrack.album?.name">
                                    &middot; {{ currentTrack.album.name }}
                                </span>
                            </p>

                            <div class="mt-4 flex items-center gap-3">
                                <div
                                    class="h-1.5 flex-1 overflow-hidden rounded-full bg-white/10"
                                >
                                    <div
                                        class="h-full rounded-full bg-brand-400 transition-all"
                                        :style="{ width: `${progress}%` }"
                                    ></div>
                                </div>
                                <span
                                    class="shrink-0 text-xs tabular-nums text-ink-400"
                                >
                                    {{ formatDuration(currentlyPlaying.progress_ms) }}
                                    /
                                    {{ formatDuration(currentTrack.duration_ms) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Top tracks + top artists -->
                <div class="grid gap-6 lg:grid-cols-2">
                    <!-- Top tracks -->
                    <section class="surface-card overflow-hidden">
                        <header
                            class="flex items-center justify-between border-b border-white/[0.06] px-5 py-4"
                        >
                            <div>
                                <h2 class="text-base font-bold text-white">
                                    Top Tracks
                                </h2>
                                <p class="mt-0.5 text-xs text-ink-400">
                                    Ranked by your listening
                                </p>
                            </div>
                            <span
                                class="rounded-full bg-white/5 px-2.5 py-1 text-xs font-semibold text-ink-300"
                            >
                                Top {{ topTracks.length }}
                            </span>
                        </header>

                        <ol
                            v-if="topTracks.length"
                            class="divide-y divide-white/[0.04]"
                        >
                            <li
                                v-for="(track, index) in topTracks"
                                :key="track.id"
                                class="group flex items-center gap-4 px-5 py-3 transition hover:bg-white/[0.03]"
                            >
                                <span
                                    class="w-6 shrink-0 text-right text-sm font-bold tabular-nums text-ink-500 group-hover:text-brand-400"
                                >
                                    {{ index + 1 }}
                                </span>

                                <img
                                    v-if="coverUrl(track.album?.images)"
                                    :src="coverUrl(track.album?.images)"
                                    :alt="track.album?.name"
                                    class="h-11 w-11 rounded-lg object-cover ring-1 ring-white/5"
                                    loading="lazy"
                                />
                                <div
                                    v-else
                                    class="flex h-11 w-11 items-center justify-center rounded-lg bg-ink-800 text-ink-500"
                                >
                                    <svg
                                        class="h-5 w-5"
                                        viewBox="0 0 24 24"
                                        fill="currentColor"
                                    >
                                        <path
                                            d="M12 3v10.55A4 4 0 1 0 14 17V7h4V3h-6Z"
                                        />
                                    </svg>
                                </div>

                                <div class="min-w-0 flex-1">
                                    <p
                                        class="truncate text-sm font-semibold text-white"
                                    >
                                        {{ track.name }}
                                    </p>
                                    <p
                                        class="truncate text-xs text-ink-400"
                                    >
                                        {{ artistNames(track) }}
                                    </p>
                                </div>

                                <span
                                    class="shrink-0 text-xs tabular-nums text-ink-500"
                                >
                                    {{ formatDuration(track.duration_ms) }}
                                </span>
                            </li>
                        </ol>

                        <p
                            v-else
                            class="px-5 py-12 text-center text-sm text-ink-400"
                        >
                            No top tracks for this time range yet.
                        </p>
                    </section>

                    <!-- Top artists -->
                    <section class="surface-card overflow-hidden">
                        <header
                            class="flex items-center justify-between border-b border-white/[0.06] px-5 py-4"
                        >
                            <div>
                                <h2 class="text-base font-bold text-white">
                                    Top Artists
                                </h2>
                                <p class="mt-0.5 text-xs text-ink-400">
                                    Artists you have on repeat
                                </p>
                            </div>
                            <span
                                class="rounded-full bg-white/5 px-2.5 py-1 text-xs font-semibold text-ink-300"
                            >
                                Top {{ topArtists.length }}
                            </span>
                        </header>

                        <ol
                            v-if="topArtists.length"
                            class="divide-y divide-white/[0.04]"
                        >
                            <li
                                v-for="(artist, index) in topArtists"
                                :key="artist.id"
                                class="group flex items-center gap-4 px-5 py-3 transition hover:bg-white/[0.03]"
                            >
                                <span
                                    class="w-6 shrink-0 text-right text-sm font-bold tabular-nums text-ink-500 group-hover:text-brand-400"
                                >
                                    {{ index + 1 }}
                                </span>

                                <img
                                    v-if="coverUrl(artist.images)"
                                    :src="coverUrl(artist.images)"
                                    :alt="artist.name"
                                    class="h-11 w-11 rounded-full object-cover ring-1 ring-white/5"
                                    loading="lazy"
                                />
                                <div
                                    v-else
                                    class="flex h-11 w-11 items-center justify-center rounded-full bg-ink-800 text-ink-500"
                                >
                                    <svg
                                        class="h-5 w-5"
                                        viewBox="0 0 24 24"
                                        fill="currentColor"
                                    >
                                        <path
                                            d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-4.42 0-8 2.24-8 5v1h16v-1c0-2.76-3.58-5-8-5Z"
                                        />
                                    </svg>
                                </div>

                                <div class="min-w-0 flex-1">
                                    <p
                                        class="truncate text-sm font-semibold text-white"
                                    >
                                        {{ artist.name }}
                                    </p>
                                    <p
                                        class="truncate text-xs text-ink-400"
                                    >
                                        {{
                                            artist.genres?.length
                                                ? artist.genres
                                                      .slice(0, 3)
                                                      .join(', ')
                                                : 'No genres listed'
                                        }}
                                    </p>
                                </div>
                            </li>
                        </ol>

                        <p
                            v-else
                            class="px-5 py-12 text-center text-sm text-ink-400"
                        >
                            No top artists for this time range yet.
                        </p>
                    </section>
                </div>

                <!-- Recently played -->
                <section class="surface-card overflow-hidden">
                    <header
                        class="flex items-center justify-between border-b border-white/[0.06] px-5 py-4"
                    >
                        <div>
                            <h2 class="text-base font-bold text-white">
                                Recently Played
                            </h2>
                            <p class="mt-0.5 text-xs text-ink-400">
                                Your last {{ recentlyPlayed.length }} tracks
                            </p>
                        </div>
                    </header>

                    <ol
                        v-if="recentlyPlayed.length"
                        class="divide-y divide-white/[0.04]"
                    >
                        <li
                            v-for="(item, index) in recentlyPlayed"
                            :key="`${item.played_at}-${index}`"
                            class="flex items-center gap-4 px-5 py-3 transition hover:bg-white/[0.03]"
                        >
                            <img
                                v-if="coverUrl(item.track?.album?.images)"
                                :src="coverUrl(item.track?.album?.images)"
                                :alt="item.track?.album?.name"
                                class="h-10 w-10 rounded-lg object-cover ring-1 ring-white/5"
                                loading="lazy"
                            />
                            <div
                                v-else
                                class="flex h-10 w-10 items-center justify-center rounded-lg bg-ink-800 text-ink-500"
                            >
                                <svg
                                    class="h-4 w-4"
                                    viewBox="0 0 24 24"
                                    fill="currentColor"
                                >
                                    <path
                                        d="M12 3v10.55A4 4 0 1 0 14 17V7h4V3h-6Z"
                                    />
                                </svg>
                            </div>

                            <div class="min-w-0 flex-1">
                                <p
                                    class="truncate text-sm font-semibold text-white"
                                >
                                    {{ item.track?.name }}
                                </p>
                                <p class="truncate text-xs text-ink-400">
                                    {{ artistNames(item.track) }}
                                </p>
                            </div>

                            <span
                                class="shrink-0 text-xs tabular-nums text-ink-500"
                            >
                                {{ formatPlayedAt(item.played_at) }}
                            </span>
                        </li>
                    </ol>

                    <p
                        v-else
                        class="px-5 py-12 text-center text-sm text-ink-400"
                    >
                        No recently played tracks found.
                    </p>
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
