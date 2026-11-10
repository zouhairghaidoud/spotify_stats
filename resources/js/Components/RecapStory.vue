<script setup>
import { Link, router } from '@inertiajs/vue3';
import { spotifyArtistUrl, spotifyTrackUrl } from '@/spotifyLinks';
import { toPng } from 'html-to-image';
import { computed, ref } from 'vue';

const props = defineProps({
    recap: {
        type: Object,
        required: true,
    },
    publicUrl: {
        type: String,
        default: null,
    },
    imageUrl: {
        type: String,
        default: null,
    },
    canManage: {
        type: Boolean,
        default: false,
    },
    shared: {
        type: Boolean,
        default: false,
    },
});

const cover = ref(null);
const exporting = ref(false);
const exportError = ref(null);
const copied = ref(false);
const copyFailed = ref(false);

const hasPlays = computed(() => props.recap.overview.plays > 0);

const topArtist = computed(() => props.recap.top_artist);

const sharePercent = computed(() =>
    Math.round((topArtist.value?.share ?? 0) * 100),
);

const maxGenrePlays = computed(() =>
    Math.max(1, ...props.recap.top_genres.map((genre) => genre.plays)),
);

const peakLabel = computed(() =>
    props.recap.peak_hour
        ? `${String(props.recap.peak_hour.hour).padStart(2, '0')}:00`
        : '—',
);

const periodLabel = computed(() => {
    const from = props.recap.period.from;

    if (!from) {
        return 'Nothing tracked yet';
    }

    return `Tracking since ${new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
    }).format(new Date(from))}`;
});

function formatNumber(value) {
    return new Intl.NumberFormat().format(value ?? 0);
}

function formatDuration(milliseconds) {
    const totalSeconds = Math.round((milliseconds ?? 0) / 1000);

    return `${Math.floor(totalSeconds / 60)}:${String(totalSeconds % 60).padStart(2, '0')}`;
}

async function downloadImage() {
    if (!cover.value || exporting.value) {
        return;
    }

    exporting.value = true;
    exportError.value = null;

    try {
        const dataUrl = await toPng(cover.value, {
            pixelRatio: 2,
            cacheBust: true,
            backgroundColor: '#070908',
        });

        const link = document.createElement('a');
        link.download = 'spotify-stats-recap.png';
        link.href = dataUrl;
        link.click();
    } catch {
        exportError.value =
            'Could not export the image. Your browser may be blocking it.';
    } finally {
        exporting.value = false;
    }
}

async function copyLink() {
    copyFailed.value = false;

    try {
        await navigator.clipboard.writeText(props.publicUrl);
        copied.value = true;

        setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        copyFailed.value = true;
    }
}

function createLink() {
    router.post(route('recap.share'), {}, { preserveScroll: true });
}

function revokeLink() {
    router.delete(route('recap.share'), { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-8">
        <section
            v-if="!hasPlays"
            class="surface-card px-6 py-16 text-center"
        >
            <div
                class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-500/10 text-brand-400"
            >
                <svg class="h-7 w-7" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path
                        fill-rule="evenodd"
                        d="M10 18a8 8 0 100-16 8 8 0 000 16zm.75-11.5a.75.75 0 00-1.5 0v4a.75.75 0 001.5 0v-4zM10 14a1 1 0 100-2 1 1 0 000 2z"
                        clip-rule="evenodd"
                    />
                </svg>
            </div>
            <h2 class="mt-5 text-xl font-bold text-white">No recap yet</h2>
            <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-ink-300">
                Once a few tracks are tracked, your recap fills in with your top
                five tracks, top artist, minutes, genres, and peak listening
                hour.
            </p>
        </section>

        <template v-else>
            <section
                ref="cover"
                class="relative overflow-hidden rounded-3xl border border-white/[0.08] bg-gradient-to-br from-brand-500/20 via-ink-900 to-ink-950 px-6 py-14 shadow-card sm:px-10 sm:py-16"
            >
                <div
                    aria-hidden="true"
                    class="pointer-events-none absolute -right-24 -top-28 h-80 w-80 rounded-full bg-brand-500/20 blur-3xl"
                ></div>

                <p
                    class="relative text-xs font-semibold uppercase tracking-[0.3em] text-brand-300"
                >
                    Spotify Stats &middot; Recap
                </p>

                <div class="relative mt-6 flex flex-wrap items-end gap-4">
                    <p
                        class="text-7xl font-extrabold leading-none tracking-tight text-white sm:text-8xl"
                    >
                        {{ formatNumber(recap.overview.minutes) }}
                    </p>
                    <p class="pb-2 text-lg text-ink-300">minutes listened</p>
                </div>

                <p class="relative mt-2 text-sm text-ink-400">
                    {{ periodLabel }}
                </p>

                <div class="relative mt-10 grid grid-cols-3 gap-4">
                    <div>
                        <p class="text-2xl font-bold text-white">
                            {{ formatNumber(recap.overview.plays) }}
                        </p>
                        <p class="text-xs text-ink-400">plays</p>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-white">
                            {{ formatNumber(recap.overview.unique_artists) }}
                        </p>
                        <p class="text-xs text-ink-400">artists</p>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-white">
                            {{ formatNumber(recap.overview.unique_tracks) }}
                        </p>
                        <p class="text-xs text-ink-400">tracks</p>
                    </div>
                </div>
            </section>

            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-full bg-brand-500 px-5 py-2.5 text-sm font-bold text-ink-950 shadow-lg shadow-brand-500/20 transition hover:bg-brand-400 disabled:opacity-60"
                    :disabled="exporting"
                    @click="downloadImage"
                >
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path
                            d="M10 2a1 1 0 011 1v7.586l2.293-2.293a1 1 0 011.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 111.414-1.414L9 10.586V3a1 1 0 011-1z"
                        />
                        <path d="M4 15a1 1 0 011 1v1h10v-1a1 1 0 112 0v2a1 1 0 01-1 1H4a1 1 0 01-1-1v-2a1 1 0 011-1z" />
                    </svg>
                    {{ exporting ? 'Exporting…' : 'Download image' }}
                </button>
                <p v-if="exportError" class="text-xs font-medium text-red-300">
                    {{ exportError }}
                </p>
            </div>

            <!-- Top artist -->
            <section class="surface-card p-6 sm:p-8">
                <p class="text-xs font-semibold uppercase tracking-wide text-ink-400">
                    Your top artist
                </p>
                <a
                    v-if="topArtist && spotifyArtistUrl(topArtist.artist_id)"
                    :href="spotifyArtistUrl(topArtist.artist_id)"
                    target="_blank"
                    rel="noreferrer"
                    class="mt-3 block text-4xl font-extrabold tracking-tight text-white transition hover:text-brand-300 hover:underline sm:text-5xl"
                    :title="`Open ${topArtist.name} on Spotify`"
                >
                    {{ topArtist.name }}
                </a>
                <p
                    v-else
                    class="mt-3 text-4xl font-extrabold tracking-tight text-white sm:text-5xl"
                >
                    {{ topArtist?.name ?? '—' }}
                </p>
                <p v-if="topArtist" class="mt-3 text-sm text-ink-300">
                    {{ formatNumber(topArtist.plays) }} plays
                    <span class="text-ink-500">&middot;</span>
                    {{ sharePercent }}% of everything you listened to
                </p>
            </section>

            <!-- Top album -->
            <section v-if="recap.top_album" class="surface-card p-6 sm:p-8">
                <p class="text-xs font-semibold uppercase tracking-wide text-ink-400">
                    Your top album
                </p>

                <div class="mt-4 flex flex-col gap-5 sm:flex-row sm:items-center">
                    <img
                        v-if="recap.top_album.cover_url"
                        :src="recap.top_album.cover_url"
                        :alt="recap.top_album.album_name"
                        class="h-28 w-28 shrink-0 rounded-2xl object-cover ring-1 ring-white/10"
                        loading="lazy"
                    />
                    <div
                        v-else
                        class="flex h-28 w-28 shrink-0 items-center justify-center rounded-2xl bg-ink-800 text-ink-500"
                    >
                        <svg
                            class="h-9 w-9"
                            viewBox="0 0 24 24"
                            fill="currentColor"
                            aria-hidden="true"
                        >
                            <path d="M12 3v10.55A4 4 0 1 0 14 17V7h4V3h-6Z" />
                        </svg>
                    </div>

                    <div class="min-w-0">
                        <p class="truncate text-2xl font-extrabold tracking-tight text-white sm:text-3xl">
                            {{ recap.top_album.album_name }}
                        </p>
                        <p class="mt-1 truncate text-sm text-ink-300">
                            {{ recap.top_album.artist_name ?? 'Unknown artist' }}
                        </p>
                        <p class="mt-3 text-sm text-ink-300">
                            {{ formatNumber(recap.top_album.plays) }} plays
                            <span class="text-ink-500">&middot;</span>
                            {{ formatNumber(recap.top_album.minutes) }} minutes
                        </p>
                    </div>
                </div>
            </section>

            <!-- Top tracks -->
            <section class="surface-card overflow-hidden">
                <header class="border-b border-white/[0.06] px-5 py-4">
                    <h2 class="text-base font-bold text-white">Top 5 tracks</h2>
                    <p class="mt-0.5 text-xs text-ink-400">
                        The songs you kept coming back to
                    </p>
                </header>

                <ol v-if="recap.top_tracks.length" class="divide-y divide-white/[0.04]">
                    <li
                        v-for="(track, index) in recap.top_tracks"
                        :key="track.spotify_track_id"
                        class="flex items-center gap-4 px-5 py-4"
                    >
                        <span
                            class="w-6 shrink-0 text-right text-xl font-extrabold tabular-nums text-ink-600"
                        >
                            {{ index + 1 }}
                        </span>

                        <img
                            v-if="track.cover_url"
                            :src="track.cover_url"
                            :alt="track.album_name ?? track.name"
                            class="h-14 w-14 rounded-xl object-cover ring-1 ring-white/5"
                            loading="lazy"
                        />
                        <div
                            v-else
                            class="flex h-14 w-14 items-center justify-center rounded-xl bg-ink-800 text-ink-500"
                        >
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
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
                                <template v-if="track.duration_ms">
                                    &middot; {{ formatDuration(track.duration_ms) }}
                                </template>
                            </p>
                        </div>

                        <span
                            class="shrink-0 rounded-full bg-white/5 px-3 py-1 text-xs font-semibold text-ink-300"
                        >
                            {{ formatNumber(track.plays) }} plays
                        </span>
                    </li>
                </ol>

                <p v-else class="px-5 py-10 text-center text-sm text-ink-400">
                    No track metadata yet.
                </p>
            </section>

            <div class="grid gap-6 sm:grid-cols-2">
                <!-- Genres -->
                <section class="surface-card p-6">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-400">
                        Top genres
                    </p>

                    <ul v-if="recap.top_genres.length" class="mt-4 space-y-3">
                        <li v-for="genre in recap.top_genres" :key="genre.genre">
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="truncate text-sm font-semibold capitalize text-white">
                                    {{ genre.genre }}
                                </span>
                                <span class="text-xs tabular-nums text-ink-400">
                                    {{ formatNumber(genre.plays) }}
                                </span>
                            </div>
                            <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-white/[0.06]">
                                <div
                                    class="h-full rounded-full bg-brand-500"
                                    :style="{
                                        width: `${Math.round((genre.plays / maxGenrePlays) * 100)}%`,
                                    }"
                                ></div>
                            </div>
                        </li>
                    </ul>

                    <p v-else class="mt-3 text-sm text-ink-400">
                        Spotify no longer exposes artist genres to this app.
                    </p>
                </section>

                <!-- Peak hour -->
                <section class="surface-card p-6">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-400">
                        Most active hour
                    </p>
                    <p class="mt-3 text-5xl font-extrabold tracking-tight text-white">
                        {{ peakLabel }}
                    </p>
                    <p v-if="recap.peak_hour" class="mt-2 text-sm text-ink-300">
                        {{ formatNumber(recap.peak_hour.plays) }} plays in this hour
                    </p>
                    <p class="mt-2 text-xs text-ink-500">
                        {{ recap.timezone }}
                    </p>
                </section>
            </div>

            <!-- Share controls -->
            <section v-if="canManage" class="surface-card p-6">
                <h2 class="text-base font-bold text-white">Share your recap</h2>
                <p class="mt-1 text-sm text-ink-400">
                    Anyone with the link can view this recap and its preview
                    image. You can revoke it at any time.
                </p>

                <div v-if="publicUrl" class="mt-4 space-y-3">
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <input
                            :value="publicUrl"
                            readonly
                            class="w-full rounded-xl border border-white/10 bg-ink-950/60 px-3 py-2 text-sm text-ink-200"
                            aria-label="Public recap link"
                        />
                        <button
                            type="button"
                            class="shrink-0 rounded-xl border border-white/10 bg-white/[0.04] px-4 py-2 text-sm font-semibold text-white transition hover:bg-white/[0.08]"
                            @click="copyLink"
                        >
                            {{ copied ? 'Copied' : 'Copy link' }}
                        </button>
                    </div>

                    <div class="flex flex-wrap items-center gap-4">
                        <a
                            v-if="imageUrl"
                            :href="imageUrl"
                            target="_blank"
                            rel="noopener"
                            class="text-xs font-semibold text-brand-300 transition hover:text-brand-200"
                        >
                            Preview share image
                        </a>
                        <button
                            type="button"
                            class="text-xs font-semibold text-red-300 transition hover:text-red-200"
                            @click="revokeLink"
                        >
                            Revoke link
                        </button>
                    </div>

                    <p v-if="copyFailed" class="text-xs text-red-300">
                        Could not copy automatically. Select the link and copy it
                        manually.
                    </p>
                </div>

                <button
                    v-else
                    type="button"
                    class="mt-4 inline-flex items-center gap-2 rounded-full bg-brand-500 px-5 py-2.5 text-sm font-bold text-ink-950 shadow-lg shadow-brand-500/20 transition hover:bg-brand-400"
                    @click="createLink"
                >
                    Create public link
                </button>
            </section>

            <section
                v-if="shared"
                class="rounded-3xl border border-white/[0.06] bg-ink-900/60 p-6 text-center"
            >
                <p class="text-sm text-ink-300">
                    Want a recap of your own listening?
                </p>
                <Link
                    href="/"
                    class="mt-3 inline-flex items-center gap-2 rounded-full bg-brand-500 px-5 py-2.5 text-sm font-bold text-ink-950 transition hover:bg-brand-400"
                >
                    Connect Spotify
                </Link>
            </section>
        </template>
    </div>
</template>
