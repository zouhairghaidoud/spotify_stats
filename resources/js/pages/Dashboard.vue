<script setup>
import HistoryImport from '@/Components/HistoryImport.vue';
import SpotifyAppForm from '@/Components/SpotifyAppForm.vue';
import ArtistLinks from '@/Components/ArtistLinks.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { spotifyArtistUrl, spotifyItemUrl, spotifyTrackUrl } from '@/spotifyLinks';
import { Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

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
    spotifyStale: {
        type: Boolean,
        default: false,
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
    topAlbums: {
        type: Array,
        default: () => [],
    },
    recentlyPlayed: {
        type: Array,
        default: () => [],
    },
    spotifyApp: {
        type: Object,
        default: () => ({
            configured: false,
            client_id: null,
            redirect_uri: '',
            env_configured: false,
        }),
    },
    importSummary: {
        type: Object,
        default: () => ({
            imported_plays: 0,
            total_plays: 0,
            last_imported_at: null,
        }),
    },
});

const ranges = [
    { value: 'short_term', label: '4 weeks' },
    { value: 'medium_term', label: '6 months' },
    { value: 'long_term', label: '1 year' },
];

const PREVIEW_TRACKS = 5;
const PREVIEW_RECENT = 5;

const showAllTracks = ref(false);
const showAllArtists = ref(false);
const showAllRecent = ref(false);

const visibleTopTracks = computed(() =>
    showAllTracks.value ? props.topTracks : props.topTracks.slice(0, PREVIEW_TRACKS),
);

const visibleTopArtists = computed(() =>
    showAllArtists.value ? props.topArtists : props.topArtists.slice(0, PREVIEW_TRACKS),
);

const visibleRecent = computed(() =>
    showAllRecent.value
        ? props.recentlyPlayed
        : props.recentlyPlayed.slice(0, PREVIEW_RECENT),
);

const playback = ref(props.currentlyPlaying);
const receivedAt = ref(Date.now());
const clock = ref(Date.now());

const currentTrack = computed(() => {
    if (playback.value?.item?.type !== 'track') {
        return null;
    }

    return playback.value.item;
});

const positionMs = computed(() => {
    if (!currentTrack.value) {
        return 0;
    }

    const base = playback.value?.progress_ms ?? 0;
    const elapsed = playback.value?.is_playing
        ? Math.max(0, clock.value - receivedAt.value)
        : 0;

    return Math.min(
        currentTrack.value.duration_ms || 0,
        Math.max(0, base + elapsed),
    );
});

const progress = computed(() => {
    if (!currentTrack.value) {
        return 0;
    }

    return Math.min(
        100,
        Math.round((positionMs.value / (currentTrack.value.duration_ms || 1)) * 100),
    );
});

watch(
    () => props.currentlyPlaying,
    (value) => {
        playback.value = value;
        receivedAt.value = Date.now();
    },
);

let pollTimer = null;
let tickTimer = null;

async function refreshNowPlaying() {
    if (!props.connected || document.visibilityState === 'hidden') {
        return;
    }

    try {
        const response = await fetch(route('now-playing'), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            return;
        }

        const payload = await response.json();

        playback.value = payload?.item ? payload : null;
        receivedAt.value = payload?.fetched_at
            ? new Date(payload.fetched_at).getTime()
            : Date.now();
        clock.value = Date.now();
    } catch {
        // Offline or a server hiccup: keep the last known track on screen.
    }
}

function handleVisibilityChange() {
    if (document.visibilityState !== 'visible') {
        return;
    }

    clock.value = Date.now();
    refreshNowPlaying();
}

onMounted(() => {
    tickTimer = window.setInterval(() => (clock.value = Date.now()), 1000);
    pollTimer = window.setInterval(refreshNowPlaying, 5000);
    document.addEventListener('visibilitychange', handleVisibilityChange);
});

onBeforeUnmount(() => {
    window.clearInterval(tickTimer);
    window.clearInterval(pollTimer);
    document.removeEventListener('visibilitychange', handleVisibilityChange);
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

function trackUrl(track) {
    return spotifyItemUrl(track) ?? spotifyTrackUrl(track?.id);
}

function artistUrl(artist) {
    return spotifyItemUrl(artist) ?? spotifyArtistUrl(artist?.id);
}

function formatDuration(milliseconds) {
    const totalSeconds = Math.round((milliseconds ?? 0) / 1000);
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;

    return `${minutes}:${String(seconds).padStart(2, '0')}`;
}

function formatPlayedAt(value) {
    const seconds = Math.round((new Date(value).getTime() - Date.now()) / 1000);
    const absolute = Math.abs(seconds);
    const formatter = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

    if (absolute < 60) {
        return formatter.format(seconds, 'second');
    }

    if (absolute < 3600) {
        return formatter.format(Math.round(seconds / 60), 'minute');
    }

    if (absolute < 86400) {
        return formatter.format(Math.round(seconds / 3600), 'hour');
    }

    return formatter.format(Math.round(seconds / 86400), 'day');
}

function formatPlayedAtAbsolute(value) {
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

        <div class="space-y-5">
            <div
                v-if="spotifyError"
                class="rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-sm font-medium text-amber-200"
            >
                {{ spotifyError }}
            </div>

            <div
                v-else-if="spotifyStale"
                class="flex items-start gap-3 rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-sm font-medium text-amber-200"
            >
                <svg
                    class="mt-0.5 h-4 w-4 shrink-0"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    aria-hidden="true"
                >
                    <path
                        fill-rule="evenodd"
                        d="M10 18a8 8 0 100-16 8 8 0 000 16zm.75-11.5a.75.75 0 00-1.5 0v4a.75.75 0 001.5 0v-4zM10 14a1 1 0 100-2 1 1 0 000 2z"
                        clip-rule="evenodd"
                    />
                </svg>
                <span>
                    Spotify could not be reached, so we are showing your last
                    saved snapshot. We will refresh it automatically.
                </span>
            </div>

            <!-- Not connected -->
            <section
                v-if="!connected"
                class="relative overflow-hidden rounded-3xl border border-white/[0.06] bg-ink-900/60 px-6 py-10 text-center shadow-card sm:px-12 sm:py-14"
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
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div
                        class="inline-flex rounded-xl border border-white/[0.06] bg-ink-900/70 p-1"
                    >
                        <button
                            v-for="option in ranges"
                            :key="option.value"
                            type="button"
                            class="rounded-lg px-3.5 py-1.5 text-xs font-semibold transition"
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

                    <p class="text-xs text-ink-500">
                        Now playing updates automatically
                    </p>
                </div>

                <!-- Currently playing -->
                <section
                    v-if="currentTrack"
                    class="relative overflow-hidden rounded-2xl border border-brand-500/20 bg-gradient-to-br from-brand-500/15 via-ink-900 to-ink-900 p-4 shadow-card sm:p-5"
                >
                    <div
                        aria-hidden="true"
                        class="pointer-events-none absolute -right-10 -top-16 h-48 w-48 rounded-full bg-brand-500/20 blur-3xl"
                    ></div>

                    <div class="relative flex items-center gap-4">
                        <img
                            v-if="coverUrl(currentTrack.album?.images)"
                            :src="coverUrl(currentTrack.album?.images)"
                            :alt="currentTrack.album?.name"
                            class="h-16 w-16 rounded-xl object-cover shadow-2xl shadow-black/50 sm:h-20 sm:w-20"
                        />
                        <div
                            v-else
                            class="flex h-16 w-16 items-center justify-center rounded-xl bg-ink-800 text-ink-500 sm:h-20 sm:w-20"
                        >
                            <svg
                                class="h-7 w-7"
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
                                    class="inline-flex h-2 w-2 rounded-full bg-brand-400"
                                    :class="{ 'animate-pulse': playback?.is_playing !== false }"
                                ></span>
                                {{ playback?.is_playing === false ? 'Paused' : 'Now playing' }}
                            </p>
                            <p
                                class="mt-1 truncate text-base font-bold text-white sm:text-lg"
                            >
                                {{ currentTrack.name }}
                            </p>
                            <p class="truncate text-sm text-ink-300">
                                {{ artistNames(currentTrack) }}
                                <span v-if="currentTrack.album?.name">
                                    &middot; {{ currentTrack.album.name }}
                                </span>
                            </p>

                            <div class="mt-3 flex items-center gap-3">
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
                                    {{ formatDuration(positionMs) }}
                                    /
                                    {{ formatDuration(currentTrack.duration_ms) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Top tracks, top artists, recently played -->
                <div class="grid gap-5 lg:grid-cols-3">
                    <!-- Top tracks -->
                    <section class="surface-card overflow-hidden">
                        <header
                            class="flex items-center justify-between border-b border-white/[0.06] px-4 py-3"
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
                            :class="showAllTracks ? 'max-h-[26rem] overflow-y-auto' : ''"
                        >
                            <li
                                v-for="(track, index) in visibleTopTracks"
                                :key="track.id"
                                class="group flex items-center gap-3 px-4 py-2.5 transition hover:bg-white/[0.03]"
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
                                    class="h-10 w-10 rounded-lg object-cover ring-1 ring-white/5"
                                    loading="lazy"
                                />
                                <div
                                    v-else
                                    class="flex h-10 w-10 items-center justify-center rounded-lg bg-ink-800 text-ink-500"
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
                                    <a
                                        v-if="trackUrl(track)"
                                        :href="trackUrl(track)"
                                        target="_blank"
                                        rel="noreferrer"
                                        class="block truncate text-sm font-semibold text-white transition hover:text-brand-300 hover:underline"
                                        :title="`Open ${track.name} on Spotify`"
                                    >
                                        {{ track.name }}
                                    </a>
                                    <p
                                        v-else
                                        class="truncate text-sm font-semibold text-white"
                                    >
                                        {{ track.name }}
                                    </p>
                                    <p
                                        class="truncate text-xs text-ink-400"
                                    >
                                        <ArtistLinks :artists="track.artists" />
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
                            class="px-4 py-8 text-center text-sm text-ink-400"
                        >
                            No top tracks for this time range yet.
                        </p>

                        <button
                            v-if="topTracks.length > PREVIEW_TRACKS"
                            type="button"
                            class="w-full border-t border-white/[0.06] px-4 py-2.5 text-left text-xs font-semibold text-ink-400 transition hover:bg-white/[0.03] hover:text-white"
                            @click="showAllTracks = !showAllTracks"
                        >
                            {{
                                showAllTracks
                                    ? 'Show less'
                                    : `Show all ${topTracks.length}`
                            }}
                        </button>
                    </section>

                    <!-- Top artists -->
                    <section class="surface-card overflow-hidden">
                        <header
                            class="flex items-center justify-between border-b border-white/[0.06] px-4 py-3"
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
                            :class="showAllArtists ? 'max-h-[26rem] overflow-y-auto' : ''"
                        >
                            <li
                                v-for="(artist, index) in visibleTopArtists"
                                :key="artist.id"
                                class="group flex items-center gap-3 px-4 py-2.5 transition hover:bg-white/[0.03]"
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
                                    class="h-10 w-10 rounded-full object-cover ring-1 ring-white/5"
                                    loading="lazy"
                                />
                                <div
                                    v-else
                                    class="flex h-10 w-10 items-center justify-center rounded-full bg-ink-800 text-ink-500"
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
                                    <a
                                        v-if="artistUrl(artist)"
                                        :href="artistUrl(artist)"
                                        target="_blank"
                                        rel="noreferrer"
                                        class="block truncate text-sm font-semibold text-white transition hover:text-brand-300 hover:underline"
                                        :title="`Open ${artist.name} on Spotify`"
                                    >
                                        {{ artist.name }}
                                    </a>
                                    <p
                                        v-else
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
                            class="px-4 py-8 text-center text-sm text-ink-400"
                        >
                            No top artists for this time range yet.
                        </p>

                        <button
                            v-if="topArtists.length > PREVIEW_TRACKS"
                            type="button"
                            class="w-full border-t border-white/[0.06] px-4 py-2.5 text-left text-xs font-semibold text-ink-400 transition hover:bg-white/[0.03] hover:text-white"
                            @click="showAllArtists = !showAllArtists"
                        >
                            {{
                                showAllArtists
                                    ? 'Show less'
                                    : `Show all ${topArtists.length}`
                            }}
                        </button>
                    </section>
                <!-- Recently played -->
                <section class="surface-card overflow-hidden">
                    <header
                        class="flex items-center justify-between border-b border-white/[0.06] px-4 py-3"
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
                        :class="showAllRecent ? 'max-h-[26rem] overflow-y-auto' : ''"
                    >
                        <li
                            v-for="(item, index) in visibleRecent"
                            :key="`${item.played_at}-${index}`"
                            class="flex items-center gap-3 px-4 py-2.5 transition hover:bg-white/[0.03]"
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
                                <a
                                    v-if="trackUrl(item.track)"
                                    :href="trackUrl(item.track)"
                                    target="_blank"
                                    rel="noreferrer"
                                    class="block truncate text-sm font-semibold text-white transition hover:text-brand-300 hover:underline"
                                    :title="`Open ${item.track?.name} on Spotify`"
                                >
                                    {{ item.track?.name }}
                                </a>
                                <p
                                    v-else
                                    class="truncate text-sm font-semibold text-white"
                                >
                                    {{ item.track?.name }}
                                </p>
                                <p class="truncate text-xs text-ink-400">
                                    <ArtistLinks :artists="item.track?.artists" />
                                </p>
                            </div>

                            <span
                                class="shrink-0 text-xs tabular-nums text-ink-500"
                                :title="formatPlayedAtAbsolute(item.played_at)"
                            >
                                {{ formatPlayedAt(item.played_at) }}
                            </span>
                        </li>
                    </ol>

                    <p
                        v-else
                        class="px-4 py-8 text-center text-sm text-ink-400"
                    >
                        No recently played tracks found.
                    </p>

                    <button
                        v-if="recentlyPlayed.length > PREVIEW_RECENT"
                        type="button"
                        class="w-full border-t border-white/[0.06] px-4 py-2.5 text-left text-xs font-semibold text-ink-400 transition hover:bg-white/[0.03] hover:text-white"
                        @click="showAllRecent = !showAllRecent"
                    >
                        {{
                            showAllRecent
                                ? 'Show less'
                                : `Show all ${recentlyPlayed.length}`
                        }}
                    </button>
                </section>
                </div>

                <!-- Top albums -->
                <section class="surface-card overflow-hidden">
                    <header
                        class="flex items-center justify-between border-b border-white/[0.06] px-4 py-3"
                    >
                        <div>
                            <h2 class="text-base font-bold text-white">
                                Top Albums
                            </h2>
                            <p class="mt-0.5 text-xs text-ink-400">
                                Albums with the most tracks in your current top
                                50
                            </p>
                        </div>
                        <span
                            v-if="topAlbums.length"
                            class="rounded-full bg-white/5 px-2.5 py-1 text-xs font-semibold text-ink-300"
                        >
                            Top {{ topAlbums.length }}
                        </span>
                    </header>

                    <ol
                        v-if="topAlbums.length"
                        class="grid grid-cols-2 gap-4 px-4 py-4 sm:grid-cols-3 lg:grid-cols-6"
                    >
                        <li
                            v-for="album in topAlbums"
                            :key="album.id ?? album.name"
                            class="group min-w-0"
                        >
                            <a
                                v-if="album.url"
                                :href="album.url"
                                target="_blank"
                                rel="noreferrer"
                                class="block"
                                :title="`Open ${album.name} on Spotify`"
                            >
                                <img
                                    v-if="album.cover_url"
                                    :src="album.cover_url"
                                    :alt="album.name"
                                    class="aspect-square w-full rounded-xl object-cover ring-1 ring-white/5 transition group-hover:ring-brand-500/40"
                                    loading="lazy"
                                />
                                <div
                                    v-else
                                    class="flex aspect-square w-full items-center justify-center rounded-xl bg-ink-800 text-ink-500"
                                >
                                    <svg
                                        class="h-7 w-7"
                                        viewBox="0 0 24 24"
                                        fill="currentColor"
                                        aria-hidden="true"
                                    >
                                        <path
                                            d="M12 3v10.55A4 4 0 1 0 14 17V7h4V3h-6Z"
                                        />
                                    </svg>
                                </div>
                            </a>
                            <template v-else>
                                <img
                                    v-if="album.cover_url"
                                    :src="album.cover_url"
                                    :alt="album.name"
                                    class="aspect-square w-full rounded-xl object-cover ring-1 ring-white/5"
                                    loading="lazy"
                                />
                                <div
                                    v-else
                                    class="flex aspect-square w-full items-center justify-center rounded-xl bg-ink-800 text-ink-500"
                                >
                                    <svg
                                        class="h-7 w-7"
                                        viewBox="0 0 24 24"
                                        fill="currentColor"
                                        aria-hidden="true"
                                    >
                                        <path
                                            d="M12 3v10.55A4 4 0 1 0 14 17V7h4V3h-6Z"
                                        />
                                    </svg>
                                </div>
                            </template>

                            <p class="mt-2 truncate text-sm font-semibold text-white">
                                {{ album.name }}
                            </p>
                            <p class="truncate text-xs text-ink-400">
                                {{ album.artist_name ?? 'Unknown artist' }}
                            </p>
                            <p class="mt-1 text-[11px] text-ink-500">
                                {{ album.track_count }}
                                {{ album.track_count === 1 ? 'track' : 'tracks' }}
                                in your top 50
                            </p>
                        </li>
                    </ol>

                    <p
                        v-else
                        class="px-4 py-8 text-center text-sm text-ink-400"
                    >
                        No albums found in your current top tracks yet.
                    </p>
                </section>
            </template>

            <!-- BYOK when Spotify is not connected or the default app failed -->
            <section
                v-if="!connected || spotifyError"
                class="surface-card p-5 sm:p-6"
            >
                <SpotifyAppForm :spotify-app="spotifyApp" />
            </section>

            <!-- History import works with or without Spotify OAuth -->
            <section class="surface-card p-5 sm:p-6">
                <HistoryImport :summary="importSummary" />
            </section>
        </div>
    </AuthenticatedLayout>
</template>
