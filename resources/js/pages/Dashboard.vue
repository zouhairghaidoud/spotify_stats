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
    { value: 'short_term', label: 'Last 4 Weeks' },
    { value: 'medium_term', label: 'Last 6 Months' },
    { value: 'long_term', label: 'Last Year' },
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
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2
                        class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200"
                    >
                        Spotify Stats
                    </h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Live data straight from the Spotify Web API.
                    </p>
                </div>

                <a
                    v-if="connected"
                    :href="route('spotify.redirect')"
                    class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                >
                    Reconnect Spotify
                </a>
            </div>
        </template>

        <div class="py-10">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div
                    v-if="$page.props.flash?.success"
                    class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-800 dark:border-green-800 dark:bg-green-900/40 dark:text-green-200"
                >
                    {{ $page.props.flash.success }}
                </div>

                <div
                    v-if="$page.props.flash?.error"
                    class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800 dark:border-red-800 dark:bg-red-900/40 dark:text-red-200"
                >
                    {{ $page.props.flash.error }}
                </div>

                <div
                    v-if="spotifyError"
                    class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800 dark:border-amber-800 dark:bg-amber-900/40 dark:text-amber-200"
                >
                    {{ spotifyError }}
                </div>

                <!-- Not connected -->
                <div
                    v-if="!connected"
                    class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"
                >
                    <div class="px-6 py-16 text-center sm:px-12">
                        <div
                            class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-[#1DB954]"
                        >
                            <svg
                                class="h-9 w-9 text-white"
                                viewBox="0 0 24 24"
                                fill="currentColor"
                                aria-hidden="true"
                            >
                                <path
                                    d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0Zm5.5 17.28a.75.75 0 0 1-1.03.25c-2.82-1.72-6.37-2.11-10.55-1.16a.75.75 0 0 1-.33-1.46c4.57-1.05 8.5-.6 11.66 1.34.35.21.46.67.25 1.03Zm1.47-3.27a.94.94 0 0 1-1.29.31c-3.23-1.98-8.15-2.56-11.97-1.4a.94.94 0 1 1-.54-1.8c4.36-1.32 9.78-.68 13.49 1.6.44.27.58.85.31 1.29Zm.13-3.4C15.24 8.32 8.94 8.11 5.26 9.23a1.12 1.12 0 1 1-.65-2.15c4.22-1.28 11.18-1.03 15.58 1.58a1.12 1.12 0 1 1-1.15 1.92Z"
                                />
                            </svg>
                        </div>

                        <h3
                            class="mt-6 text-xl font-semibold text-gray-900 dark:text-gray-100"
                        >
                            Connect your Spotify account
                        </h3>
                        <p
                            class="mx-auto mt-2 max-w-md text-sm text-gray-500 dark:text-gray-400"
                        >
                            Link your account to see your top tracks, top
                            artists, what you are playing right now, and your
                            recent listening history.
                        </p>

                        <a
                            :href="route('spotify.redirect')"
                            class="mt-8 inline-flex items-center gap-2 rounded-full bg-[#1DB954] px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-[#1aa34a] focus:outline-none focus:ring-2 focus:ring-[#1DB954] focus:ring-offset-2"
                        >
                            Connect with Spotify
                        </a>
                    </div>
                </div>

                <template v-else>
                    <!-- Time range filter -->
                    <div
                        class="flex flex-wrap items-center gap-3 rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"
                    >
                        <span
                            class="px-2 text-xs font-semibold uppercase tracking-wide text-gray-400"
                        >
                            Time range
                        </span>

                        <button
                            v-for="option in ranges"
                            :key="option.value"
                            type="button"
                            class="rounded-full px-4 py-2 text-sm font-medium transition"
                            :class="
                                range === option.value
                                    ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900'
                                    : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'
                            "
                            @click="setRange(option.value)"
                        >
                            {{ option.label }}
                        </button>
                    </div>

                    <!-- Currently playing -->
                    <div
                        v-if="currentTrack"
                        class="overflow-hidden rounded-xl bg-gradient-to-r from-gray-900 to-gray-700 p-5 text-white shadow-sm"
                    >
                        <div class="flex items-center gap-5">
                            <img
                                v-if="coverUrl(currentTrack.album?.images)"
                                :src="coverUrl(currentTrack.album?.images)"
                                :alt="currentTrack.album?.name"
                                class="h-20 w-20 rounded-lg object-cover shadow-lg"
                            />
                            <div class="min-w-0 flex-1">
                                <p
                                    class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-[#1DB954]"
                                >
                                    <span
                                        class="inline-flex h-2 w-2 animate-pulse rounded-full bg-[#1DB954]"
                                    ></span>
                                    Now playing
                                </p>
                                <p class="mt-1 truncate text-lg font-semibold">
                                    {{ currentTrack.name }}
                                </p>
                                <p class="truncate text-sm text-gray-300">
                                    {{ artistNames(currentTrack) }}
                                    <span v-if="currentTrack.album?.name">
                                        &middot; {{ currentTrack.album.name }}
                                    </span>
                                </p>

                                <div class="mt-3 flex items-center gap-3">
                                    <div
                                        class="h-1.5 flex-1 overflow-hidden rounded-full bg-white/20"
                                    >
                                        <div
                                            class="h-full rounded-full bg-[#1DB954]"
                                            :style="{ width: `${progress}%` }"
                                        ></div>
                                    </div>
                                    <span
                                        class="shrink-0 text-xs tabular-nums text-gray-300"
                                    >
                                        {{ formatDuration(
                                            currentlyPlaying.progress_ms,
                                        ) }}
                                        /
                                        {{ formatDuration(currentTrack.duration_ms) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Top tracks + top artists -->
                    <div class="grid gap-6 lg:grid-cols-2">
                        <!-- Top tracks -->
                        <section
                            class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"
                        >
                            <header
                                class="border-b border-gray-100 px-5 py-4 dark:border-gray-700"
                            >
                                <h3
                                    class="text-base font-semibold text-gray-900 dark:text-gray-100"
                                >
                                    Top Tracks
                                </h3>
                            </header>

                            <ol
                                v-if="topTracks.length"
                                class="divide-y divide-gray-100 dark:divide-gray-700"
                            >
                                <li
                                    v-for="(track, index) in topTracks"
                                    :key="track.id"
                                    class="flex items-center gap-4 px-5 py-3"
                                >
                                    <span
                                        class="w-7 shrink-0 text-right text-sm font-semibold tabular-nums text-gray-400"
                                    >
                                        {{ index + 1 }}
                                    </span>

                                    <img
                                        v-if="coverUrl(track.album?.images)"
                                        :src="coverUrl(track.album?.images)"
                                        :alt="track.album?.name"
                                        class="h-12 w-12 rounded object-cover"
                                        loading="lazy"
                                    />
                                    <div
                                        v-else
                                        class="flex h-12 w-12 items-center justify-center rounded bg-gray-100 text-gray-400 dark:bg-gray-700"
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
                                            class="truncate text-sm font-medium text-gray-900 dark:text-gray-100"
                                        >
                                            {{ track.name }}
                                        </p>
                                        <p
                                            class="truncate text-xs text-gray-500 dark:text-gray-400"
                                        >
                                            {{ artistNames(track) }}
                                        </p>
                                    </div>

                                    <span
                                        class="shrink-0 text-xs tabular-nums text-gray-400"
                                    >
                                        {{ formatDuration(track.duration_ms) }}
                                    </span>
                                </li>
                            </ol>

                            <p
                                v-else
                                class="px-5 py-10 text-center text-sm text-gray-500 dark:text-gray-400"
                            >
                                No top tracks for this time range yet.
                            </p>
                        </section>

                        <!-- Top artists -->
                        <section
                            class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"
                        >
                            <header
                                class="border-b border-gray-100 px-5 py-4 dark:border-gray-700"
                            >
                                <h3
                                    class="text-base font-semibold text-gray-900 dark:text-gray-100"
                                >
                                    Top Artists
                                </h3>
                            </header>

                            <ol
                                v-if="topArtists.length"
                                class="divide-y divide-gray-100 dark:divide-gray-700"
                            >
                                <li
                                    v-for="(artist, index) in topArtists"
                                    :key="artist.id"
                                    class="flex items-center gap-4 px-5 py-3"
                                >
                                    <span
                                        class="w-7 shrink-0 text-right text-sm font-semibold tabular-nums text-gray-400"
                                    >
                                        {{ index + 1 }}
                                    </span>

                                    <img
                                        v-if="coverUrl(artist.images)"
                                        :src="coverUrl(artist.images)"
                                        :alt="artist.name"
                                        class="h-12 w-12 rounded-full object-cover"
                                        loading="lazy"
                                    />
                                    <div
                                        v-else
                                        class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-gray-700"
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
                                            class="truncate text-sm font-medium text-gray-900 dark:text-gray-100"
                                        >
                                            {{ artist.name }}
                                        </p>
                                        <p
                                            class="truncate text-xs text-gray-500 dark:text-gray-400"
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
                                class="px-5 py-10 text-center text-sm text-gray-500 dark:text-gray-400"
                            >
                                No top artists for this time range yet.
                            </p>
                        </section>
                    </div>

                    <!-- Recently played -->
                    <section
                        class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"
                    >
                        <header
                            class="flex items-center justify-between border-b border-gray-100 px-5 py-4 dark:border-gray-700"
                        >
                            <h3
                                class="text-base font-semibold text-gray-900 dark:text-gray-100"
                            >
                                Recently Played
                            </h3>
                            <span class="text-xs text-gray-400">
                                Last {{ recentlyPlayed.length }} tracks
                            </span>
                        </header>

                        <ol
                            v-if="recentlyPlayed.length"
                            class="divide-y divide-gray-100 dark:divide-gray-700"
                        >
                            <li
                                v-for="(item, index) in recentlyPlayed"
                                :key="`${item.played_at}-${index}`"
                                class="flex items-center gap-4 px-5 py-3"
                            >
                                <img
                                    v-if="coverUrl(item.track?.album?.images)"
                                    :src="coverUrl(item.track?.album?.images)"
                                    :alt="item.track?.album?.name"
                                    class="h-10 w-10 rounded object-cover"
                                    loading="lazy"
                                />
                                <div
                                    v-else
                                    class="flex h-10 w-10 items-center justify-center rounded bg-gray-100 text-gray-400 dark:bg-gray-700"
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
                                        class="truncate text-sm font-medium text-gray-900 dark:text-gray-100"
                                    >
                                        {{ item.track?.name }}
                                    </p>
                                    <p
                                        class="truncate text-xs text-gray-500 dark:text-gray-400"
                                    >
                                        {{ artistNames(item.track) }}
                                    </p>
                                </div>

                                <span
                                    class="shrink-0 text-xs text-gray-400"
                                >
                                    {{ formatPlayedAt(item.played_at) }}
                                </span>
                            </li>
                        </ol>

                        <p
                            v-else
                            class="px-5 py-10 text-center text-sm text-gray-500 dark:text-gray-400"
                        >
                            No recently played tracks found.
                        </p>
                    </section>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
