<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { spotifyTrackUrl } from '@/spotifyLinks';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    connected: {
        type: Boolean,
        default: false,
    },
    canCreate: {
        type: Boolean,
        default: false,
    },
    ranges: {
        type: Object,
        required: true,
    },
    topTrackLimit: {
        type: Number,
        default: 30,
    },
    forgotten: {
        type: Array,
        default: () => [],
    },
    forgottenDays: {
        type: Number,
        default: 14,
    },
    forgottenMinPlays: {
        type: Number,
        default: 2,
    },
    createdPlaylist: {
        type: Object,
        default: null,
    },
});

const selectedRange = ref('short_term');
const submitting = ref(null);

const rangeOptions = computed(() =>
    Object.entries(props.ranges).map(([value, label]) => ({ value, label })),
);

const canSubmit = computed(
    () => props.connected && props.canCreate && submitting.value === null,
);

function createTopTracks() {
    if (!canSubmit.value) {
        return;
    }

    router.post(
        route('playlists.top-tracks'),
        { range: selectedRange.value },
        {
            preserveScroll: true,
            onStart: () => (submitting.value = 'top'),
            onFinish: () => (submitting.value = null),
        },
    );
}

function createForgotten() {
    if (!canSubmit.value || props.forgotten.length === 0) {
        return;
    }

    router.post(route('playlists.forgotten'), {}, {
        preserveScroll: true,
        onStart: () => (submitting.value = 'forgotten'),
        onFinish: () => (submitting.value = null),
    });
}

function formatNumber(value) {
    return new Intl.NumberFormat().format(value ?? 0);
}

function formatRelative(value) {
    const playedAt = new Date(value);
    const days = Math.round((Date.now() - playedAt.getTime()) / 86400000);

    if (days < 1) {
        return 'today';
    }

    return new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' }).format(
        -days,
        'day',
    );
}
</script>

<template>
    <Head title="Playlist tools" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p
                        class="text-xs font-semibold uppercase tracking-[0.22em] text-brand-400"
                    >
                        Playlist tools
                    </p>
                    <h1
                        class="mt-2 text-2xl font-bold tracking-tight text-white sm:text-3xl"
                    >
                        Turn your history into playlists
                    </h1>
                    <p class="mt-1 text-sm text-ink-300">
                        Create private playlists from what you love now or what
                        you used to love.
                    </p>
                </div>

                <span
                    v-if="connected"
                    class="inline-flex items-center gap-2 rounded-full border border-brand-500/20 bg-brand-500/10 px-3 py-1.5 text-xs font-semibold text-brand-300"
                >
                    <span
                        class="inline-block h-1.5 w-1.5 rounded-full bg-brand-400"
                    ></span>
                    Spotify connected
                </span>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Created playlist -->
            <section
                v-if="createdPlaylist"
                class="rounded-2xl border border-brand-500/25 bg-brand-500/10 p-5"
            >
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p
                            class="text-xs font-semibold uppercase tracking-wide text-brand-300"
                        >
                            Playlist created
                        </p>
                        <p class="mt-1 text-lg font-bold text-white">
                            {{ createdPlaylist.name }}
                        </p>
                        <p class="text-sm text-ink-300">
                            {{ formatNumber(createdPlaylist.track_count) }} tracks
                            added to your Spotify library
                        </p>
                    </div>

                    <a
                        :href="createdPlaylist.url"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-2 rounded-full bg-brand-500 px-5 py-2.5 text-sm font-bold text-ink-950 shadow-lg shadow-brand-500/20 transition hover:bg-brand-400"
                    >
                        Open in Spotify
                        <svg
                            class="h-4 w-4"
                            viewBox="0 0 20 20"
                            fill="currentColor"
                            aria-hidden="true"
                        >
                            <path
                                d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z"
                            />
                            <path
                                d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 000-2H5z"
                            />
                        </svg>
                    </a>
                </div>
            </section>

            <!-- Not connected -->
            <section v-if="!connected" class="surface-card p-6 sm:p-8">
                <h2 class="text-lg font-bold text-white">Connect Spotify first</h2>
                <p class="mt-2 max-w-2xl text-sm leading-relaxed text-ink-300">
                    Playlists are written to your own Spotify library, so the
                    app needs access before it can create them.
                </p>
                <a
                    :href="route('spotify.redirect')"
                    class="mt-5 inline-flex items-center gap-2 rounded-full bg-brand-500 px-5 py-2.5 text-sm font-bold text-ink-950 shadow-lg shadow-brand-500/20 transition hover:bg-brand-400"
                >
                    Connect Spotify
                </a>
            </section>

            <!-- Missing playlist scope -->
            <section
                v-else-if="!canCreate"
                class="rounded-2xl border border-amber-400/25 bg-amber-400/10 p-5"
            >
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 class="text-sm font-bold text-amber-100">
                            Playlist access not granted yet
                        </h2>
                        <p class="mt-1 max-w-2xl text-sm text-amber-100/80">
                            Your current Spotify token can only read data.
                            Reconnect to approve creating private playlists.
                        </p>
                    </div>
                    <a
                        :href="route('spotify.redirect')"
                        class="shrink-0 rounded-full border border-amber-300/30 bg-amber-300/10 px-4 py-2 text-xs font-semibold text-amber-100 transition hover:bg-amber-300/20"
                    >
                        Reconnect Spotify
                    </a>
                </div>
            </section>

            <!-- Top tracks playlist -->
            <section class="surface-card p-6 sm:p-8">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="max-w-2xl">
                        <p
                            class="text-xs font-semibold uppercase tracking-wide text-ink-400"
                        >
                            From your top tracks
                        </p>
                        <h2 class="mt-2 text-xl font-bold text-white">
                            Your current favorites, as a playlist
                        </h2>
                        <p class="mt-2 text-sm leading-relaxed text-ink-300">
                            Pick a range and the app adds up to
                            {{ topTrackLimit }} of your most played tracks to a
                            new private playlist.
                        </p>
                    </div>
                </div>

                <div
                    class="mt-5 inline-flex flex-wrap rounded-2xl border border-white/10 bg-ink-950/60 p-1"
                >
                    <button
                        v-for="option in rangeOptions"
                        :key="option.value"
                        type="button"
                        class="rounded-xl px-4 py-2 text-sm font-semibold transition"
                        :class="
                            selectedRange === option.value
                                ? 'bg-brand-500 text-ink-950 shadow-lg shadow-brand-500/20'
                                : 'text-ink-300 hover:bg-white/5 hover:text-white'
                        "
                        @click="selectedRange = option.value"
                    >
                        {{ option.label }}
                    </button>
                </div>

                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-full bg-brand-500 px-5 py-2.5 text-sm font-bold text-ink-950 shadow-lg shadow-brand-500/20 transition hover:bg-brand-400 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="!canSubmit"
                        @click="createTopTracks"
                    >
                        {{ submitting === 'top' ? 'Creating…' : 'Create playlist' }}
                    </button>
                    <p class="text-xs text-ink-500">
                        Saved as private in your Spotify library.
                    </p>
                </div>
            </section>

            <!-- Forgotten favorites -->
            <section class="surface-card overflow-hidden">
                <header
                    class="flex flex-wrap items-start justify-between gap-4 border-b border-white/[0.06] px-5 py-5 sm:px-6"
                >
                    <div class="max-w-2xl">
                        <p
                            class="text-xs font-semibold uppercase tracking-wide text-ink-400"
                        >
                            Forgotten favorites
                        </p>
                        <h2 class="mt-2 text-xl font-bold text-white">
                            What you loved, but stopped playing
                        </h2>
                        <p class="mt-2 text-sm leading-relaxed text-ink-300">
                            Tracks played at least {{ forgottenMinPlays }} times
                            that have not come up in {{ forgottenDays }} days,
                            ranked by how often you played them.
                        </p>
                    </div>

                    <button
                        type="button"
                        class="inline-flex shrink-0 items-center gap-2 rounded-full bg-brand-500 px-5 py-2.5 text-sm font-bold text-ink-950 shadow-lg shadow-brand-500/20 transition hover:bg-brand-400 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="!canSubmit || forgotten.length === 0"
                        @click="createForgotten"
                    >
                        {{
                            submitting === 'forgotten'
                                ? 'Creating…'
                                : 'Create playlist'
                        }}
                    </button>
                </header>

                <ol
                    v-if="forgotten.length"
                    class="max-h-96 divide-y divide-white/[0.04] overflow-y-auto"
                >
                    <li
                        v-for="track in forgotten"
                        :key="track.spotify_track_id"
                        class="flex items-center gap-4 px-5 py-3.5"
                    >
                        <img
                            v-if="track.cover_url"
                            :src="track.cover_url"
                            :alt="track.album_name ?? track.name"
                            class="h-12 w-12 rounded-lg object-cover ring-1 ring-white/5"
                            loading="lazy"
                        />
                        <div
                            v-else
                            class="flex h-12 w-12 items-center justify-center rounded-lg bg-ink-800 text-ink-500"
                        >
                            <svg
                                class="h-5 w-5"
                                viewBox="0 0 24 24"
                                fill="currentColor"
                                aria-hidden="true"
                            >
                                <path d="M12 3v10.55A4 4 0 1 0 14 17V7h4V3h-6Z" />
                            </svg>
                        </div>

                        <div class="min-w-0 flex-1">
                            <a
                                v-if="spotifyTrackUrl(track.spotify_track_id)"
                                :href="spotifyTrackUrl(track.spotify_track_id)"
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
                            <p class="truncate text-xs text-ink-400">
                                {{ track.artist_name ?? 'Unknown artist' }}
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            <p class="text-xs font-semibold text-ink-200">
                                {{ formatNumber(track.plays) }} plays
                            </p>
                            <p class="text-xs text-ink-500">
                                Last played {{ formatRelative(track.last_played_at) }}
                            </p>
                        </div>
                    </li>
                </ol>

                <div v-else class="px-5 py-12 text-center">
                    <p class="text-sm font-semibold text-ink-200">
                        Nothing forgotten yet
                    </p>
                    <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-ink-400">
                        Once a track has {{ forgottenMinPlays }}+ plays and goes
                        {{ forgottenDays }} days without a listen, it appears
                        here — ready to be rediscovered.
                    </p>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
