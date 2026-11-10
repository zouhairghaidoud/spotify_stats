<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    album: {
        type: Object,
        required: true,
    },
    timezone: {
        type: String,
        default: 'UTC',
    },
});

const maxTimelinePlays = computed(() =>
    Math.max(1, ...props.album.timeline.map((point) => point.plays)),
);

function formatNumber(value) {
    return new Intl.NumberFormat().format(value ?? 0);
}

function formatListeningTime(minutes) {
    const total = minutes ?? 0;

    if (total < 60) {
        return `${formatNumber(total)} min`;
    }

    const hours = Math.floor(total / 60);
    const remainder = total % 60;

    return remainder
        ? `${formatNumber(hours)}h ${remainder}m`
        : `${formatNumber(hours)}h`;
}

function formatDate(value) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
    }).format(new Date(value));
}

function formatMonth(value) {
    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        year: '2-digit',
    }).format(new Date(`${value}-01T00:00:00`));
}

function trackUrl(id) {
    return id && !String(id).startsWith('local_')
        ? `https://open.spotify.com/track/${id}`
        : null;
}
</script>

<template>
    <Head :title="album.album_name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="min-w-0">
                    <Link
                        :href="route('analytics')"
                        class="text-xs font-semibold uppercase tracking-[0.22em] text-brand-400 transition hover:text-brand-300"
                    >
                        &larr; Analytics
                    </Link>
                    <h1 class="mt-2 truncate text-2xl font-bold tracking-tight text-white sm:text-3xl">
                        {{ album.album_name }}
                    </h1>
                    <p class="mt-1 text-sm text-ink-300">
                        {{ album.artist_name ?? 'Unknown artist' }}
                    </p>
                </div>

                <span
                    class="rounded-full border border-white/10 bg-white/[0.04] px-3 py-1.5 text-xs font-semibold text-ink-300"
                    :title="`Monthly timeline uses the ${timezone} timezone`"
                >
                    {{ timezone }}
                </span>
            </div>
        </template>

        <div class="space-y-6">
            <section class="surface-card overflow-hidden">
                <div class="flex flex-col gap-6 p-6 sm:flex-row sm:items-center">
                    <img
                        v-if="album.cover_url"
                        :src="album.cover_url"
                        :alt="album.album_name"
                        class="h-36 w-36 shrink-0 rounded-2xl object-cover ring-1 ring-white/10"
                        loading="lazy"
                    />
                    <div
                        v-else
                        class="flex h-36 w-36 shrink-0 items-center justify-center rounded-2xl bg-ink-800 text-ink-500"
                    >
                        <svg class="h-10 w-10" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12 3v10.55A4 4 0 1 0 14 17V7h4V3h-6Z" />
                        </svg>
                    </div>

                    <div class="grid flex-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-ink-400">Plays</p>
                            <p class="mt-1 text-2xl font-extrabold text-white">{{ formatNumber(album.overview.plays) }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-ink-400">Listening time</p>
                            <p class="mt-1 text-2xl font-extrabold text-white">{{ formatListeningTime(album.overview.minutes) }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-ink-400">Tracks played</p>
                            <p class="mt-1 text-2xl font-extrabold text-white">{{ formatNumber(album.overview.unique_tracks) }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-ink-400">Last played</p>
                            <p class="mt-1 text-sm font-semibold text-white">{{ formatDate(album.overview.last_played_at) }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="surface-card overflow-hidden">
                <header class="border-b border-white/[0.06] px-5 py-4">
                    <h2 class="text-base font-bold text-white">Monthly plays</h2>
                    <p class="mt-0.5 text-xs text-ink-400">Your listening history for this album</p>
                </header>

                <div v-if="album.timeline.length" class="flex h-48 items-end gap-2 px-5 py-6">
                    <div
                        v-for="point in album.timeline"
                        :key="point.month"
                        class="flex flex-1 flex-col items-center justify-end gap-2"
                    >
                        <span class="text-[11px] font-semibold text-ink-300">{{ point.plays }}</span>
                        <div
                            class="w-full rounded-t-md bg-brand-500/70"
                            :style="{ height: `${Math.max(6, (point.plays / maxTimelinePlays) * 100)}%` }"
                        ></div>
                        <span class="text-[10px] uppercase tracking-wide text-ink-500">{{ formatMonth(point.month) }}</span>
                    </div>
                </div>

                <p v-else class="px-5 py-10 text-center text-sm text-ink-400">No plays recorded yet.</p>
            </section>

            <section class="surface-card overflow-hidden">
                <header class="border-b border-white/[0.06] px-5 py-4">
                    <h2 class="text-base font-bold text-white">Tracks on this album</h2>
                </header>

                <ol class="divide-y divide-white/[0.06]">
                    <li
                        v-for="(track, index) in album.tracks"
                        :key="track.spotify_track_id"
                        class="flex items-center gap-4 px-5 py-3"
                    >
                        <span class="w-6 text-sm font-bold text-ink-500">{{ index + 1 }}</span>
                        <div class="min-w-0 flex-1">
                            <a
                                v-if="trackUrl(track.spotify_track_id)"
                                :href="trackUrl(track.spotify_track_id)"
                                target="_blank"
                                rel="noreferrer"
                                class="truncate text-sm font-semibold text-white transition hover:text-brand-300"
                            >
                                {{ track.name }}
                            </a>
                            <p v-else class="truncate text-sm font-semibold text-white">{{ track.name }}</p>
                        </div>
                        <span class="text-xs tabular-nums text-ink-400">{{ formatNumber(track.plays) }} plays</span>
                        <span class="w-20 text-right text-xs tabular-nums text-ink-400">{{ formatListeningTime(track.minutes) }}</span>
                    </li>
                </ol>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
