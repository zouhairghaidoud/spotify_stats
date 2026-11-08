<script setup>
import Chart from '@/Components/Chart.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import {
    chartColors,
    chartGridColor,
    chartLegend,
    chartTickColor,
    chartTooltip,
} from '@/chartTheme';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    spotifyConnected: {
        type: Boolean,
        default: false,
    },
    timezone: {
        type: String,
        default: 'UTC',
    },
    overview: {
        type: Object,
        required: true,
    },
    topGenres: {
        type: Array,
        default: () => [],
    },
    heatmap: {
        type: Object,
        default: () => ({ counts: [], max: 0 }),
    },
    discovery: {
        type: Object,
        required: true,
    },
    streaks: {
        type: Object,
        required: true,
    },
    trends: {
        type: Object,
        required: true,
    },
    comparison: {
        type: Object,
        required: true,
    },
});

const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const hourLabels = [0, 6, 12, 18];

const hasPlays = computed(() => props.overview.plays > 0);

const maxGenrePlays = computed(() =>
    Math.max(1, ...props.topGenres.map((genre) => genre.plays)),
);

const discoveryPercent = computed(() => Math.round(props.discovery.rate * 100));

const trendChartData = computed(() => ({
    labels: props.trends.weeks.map(formatWeek),
    datasets: props.trends.series.map((artist, index) => ({
        label: artist.name,
        data: artist.plays,
        borderColor: chartColors[index % chartColors.length],
        backgroundColor: chartColors[index % chartColors.length],
        borderWidth: 2,
        tension: 0.35,
        pointRadius: 0,
        pointHoverRadius: 4,
        pointHitRadius: 12,
    })),
}));

const trendChartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: {
            ...chartLegend,
            labels: { ...chartLegend.labels },
        },
        tooltip: {
            ...chartTooltip,
            callbacks: {
                label: (context) =>
                    `${context.dataset.label}: ${context.parsed.y} plays`,
            },
        },
    },
    scales: {
        x: {
            grid: { display: false },
            border: { display: false },
            ticks: {
                color: chartTickColor,
                maxRotation: 0,
                autoSkipPadding: 18,
            },
        },
        y: {
            beginAtZero: true,
            grid: { color: chartGridColor },
            border: { display: false },
            ticks: { color: chartTickColor, precision: 0 },
        },
    },
}));

const comparisonWindows = computed(() => [
    { key: 'recent', title: 'Last 4 weeks', data: props.comparison.recent },
    { key: 'year', title: 'Last year', data: props.comparison.year },
]);

const comparisonLabels = computed(() => {
    const seen = new Set();
    const artists = [];

    [
        ...props.comparison.recent.top_artists,
        ...props.comparison.year.top_artists,
    ].forEach((artist) => {
        if (seen.has(artist.artist_id)) {
            return;
        }

        seen.add(artist.artist_id);
        artists.push({ id: artist.artist_id, name: artist.name });
    });

    return artists.slice(0, 6);
});

function shareFor(window, artistId) {
    return (
        window.top_artists.find((artist) => artist.artist_id === artistId)
            ?.share ?? 0
    );
}

const comparisonChartData = computed(() => ({
    labels: comparisonLabels.value.map((artist) =>
        truncate(artist.name, 22),
    ),
    datasets: [
        {
            label: 'Last 4 weeks',
            data: comparisonLabels.value.map(
                (artist) =>
                    Math.round(shareFor(props.comparison.recent, artist.id) * 1000) / 10,
            ),
            backgroundColor: 'rgba(29, 185, 84, 0.85)',
            borderRadius: 6,
            maxBarThickness: 18,
        },
        {
            label: 'Last year',
            data: comparisonLabels.value.map(
                (artist) =>
                    Math.round(shareFor(props.comparison.year, artist.id) * 1000) / 10,
            ),
            backgroundColor: 'rgba(79, 156, 249, 0.7)',
            borderRadius: 6,
            maxBarThickness: 18,
        },
    ],
}));

const comparisonChartOptions = computed(() => ({
    indexAxis: 'y',
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            ...chartLegend,
            labels: { ...chartLegend.labels },
        },
        tooltip: {
            ...chartTooltip,
            callbacks: {
                label: (context) =>
                    `${context.dataset.label}: ${context.parsed.x}% of plays`,
            },
        },
    },
    scales: {
        x: {
            beginAtZero: true,
            grid: { color: chartGridColor },
            border: { display: false },
            ticks: {
                color: chartTickColor,
                callback: (value) => `${value}%`,
            },
        },
        y: {
            grid: { display: false },
            border: { display: false },
            ticks: { color: chartTickColor },
        },
    },
}));

const stats = computed(() => [
    {
        label: 'Listening time',
        value: formatListeningTime(props.overview.minutes),
        hint: `${formatNumber(props.overview.plays)} plays`,
    },
    {
        label: 'Unique artists',
        value: formatNumber(props.overview.unique_artists),
        hint: `${formatNumber(props.overview.unique_tracks)} unique tracks`,
    },
    {
        label: 'Average track',
        value: formatTrackLength(props.overview.average_track_seconds),
        hint: 'Per play',
    },
]);

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

function formatTrackLength(seconds) {
    if (seconds === null || seconds === undefined) {
        return '—';
    }

    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}

function formatWeek(isoDate) {
    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
    }).format(new Date(`${isoDate}T00:00:00`));
}

function truncate(value, length) {
    return value.length > length ? `${value.slice(0, length - 1)}…` : value;
}

function windowStats(window) {
    return [
        { label: 'Plays', value: formatNumber(window.plays) },
        { label: 'Listening', value: formatListeningTime(window.minutes) },
        { label: 'Artists', value: formatNumber(window.unique_artists) },
    ];
}

function formatMovement(change) {
    if (change === null) {
        return 'New';
    }

    if (change > 0) {
        return `↑ ${change}`;
    }

    if (change < 0) {
        return `↓ ${Math.abs(change)}`;
    }

    return '—';
}

function movementClass(change) {
    if (change === null) {
        return 'border-brand-500/20 bg-brand-500/10 text-brand-300';
    }

    if (change > 0) {
        return 'border-emerald-500/20 bg-emerald-500/10 text-emerald-300';
    }

    if (change < 0) {
        return 'border-red-500/20 bg-red-500/10 text-red-300';
    }

    return 'border-white/10 bg-white/5 text-ink-400';
}

function formatDate(value) {
    if (!value) {
        return null;
    }

    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(
        new Date(value),
    );
}

function cellStyle(count) {
    if (!count || props.heatmap.max === 0) {
        return {};
    }

    const intensity = Math.sqrt(count / props.heatmap.max);

    return {
        backgroundColor: `rgb(var(--brand-500) / ${(0.15 + 0.85 * intensity).toFixed(2)})`,
    };
}

function cellLabel(dayIndex, hour, count) {
    const label = `${weekdays[dayIndex]} ${String(hour).padStart(2, '0')}:00`;
    const plays = `${formatNumber(count)} ${count === 1 ? 'play' : 'plays'}`;

    return `${label} — ${plays}`;
}
</script>

<template>
    <Head title="Analytics" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p
                        class="text-xs font-semibold uppercase tracking-[0.22em] text-brand-400"
                    >
                        Analytics
                    </p>
                    <h1
                        class="mt-2 text-2xl font-bold tracking-tight text-white sm:text-3xl"
                    >
                        Your listening, measured
                    </h1>
                    <p class="mt-1 text-sm text-ink-300">
                        Built from every play we have tracked so far.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <span
                        v-if="overview.first_played_at"
                        class="rounded-full border border-white/10 bg-white/[0.04] px-3 py-1.5 text-xs font-semibold text-ink-300"
                    >
                        Tracking since {{ formatDate(overview.first_played_at) }}
                    </span>
                    <span
                        class="rounded-full border border-white/10 bg-white/[0.04] px-3 py-1.5 text-xs font-semibold text-ink-300"
                        :title="`Heatmap and streaks use the ${timezone} timezone`"
                    >
                        {{ timezone }}
                    </span>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Empty state -->
            <section
                v-if="!hasPlays"
                class="surface-card px-6 py-16 text-center"
            >
                <div
                    class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-500/10 text-brand-400"
                >
                    <svg
                        class="h-7 w-7"
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
                </div>

                <h2 class="mt-5 text-xl font-bold text-white">
                    Nothing tracked yet
                </h2>
                <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-ink-300">
                    Analytics are computed from plays we have already stored.
                    Once you listen to a few tracks, your totals, genres, and
                    heatmap appear here.
                </p>

                <a
                    v-if="!spotifyConnected"
                    :href="route('spotify.redirect')"
                    class="mt-7 inline-flex items-center gap-2 rounded-full bg-brand-500 px-6 py-3 text-sm font-bold text-ink-950 shadow-lg shadow-brand-500/20 transition hover:bg-brand-400"
                >
                    Connect with Spotify
                </a>
                <Link
                    v-else
                    :href="route('dashboard')"
                    class="mt-7 inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.04] px-6 py-3 text-sm font-semibold text-white transition hover:bg-white/[0.08]"
                >
                    Back to dashboard
                </Link>
            </section>

            <template v-else>
                <!-- Headline stats -->
                <div class="grid gap-4 sm:grid-cols-3">
                    <div
                        v-for="stat in stats"
                        :key="stat.label"
                        class="surface-card px-5 py-5"
                    >
                        <p
                            class="text-xs font-semibold uppercase tracking-wide text-ink-400"
                        >
                            {{ stat.label }}
                        </p>
                        <p
                            class="mt-2 text-3xl font-extrabold tracking-tight text-white"
                        >
                            {{ stat.value }}
                        </p>
                        <p class="mt-1 text-xs text-ink-400">{{ stat.hint }}</p>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-2">
                    <!-- Discovery rate -->
                    <section class="surface-card p-5">
                        <header>
                            <h2 class="text-base font-bold text-white">
                                Discovery rate
                            </h2>
                            <p class="mt-0.5 text-xs text-ink-400">
                                Plays that introduced an artist you had not
                                heard before
                            </p>
                        </header>

                        <div class="mt-5 flex items-end gap-4">
                            <p
                                class="text-4xl font-extrabold tracking-tight text-brand-400"
                            >
                                {{ discoveryPercent }}%
                            </p>
                            <p class="pb-1 text-sm text-ink-300">
                                {{ formatNumber(discovery.new_artists) }} new
                                artists across
                                {{ formatNumber(discovery.plays) }} plays
                            </p>
                        </div>

                        <div
                            class="mt-5 h-2 overflow-hidden rounded-full bg-white/[0.06]"
                        >
                            <div
                                class="h-full rounded-full bg-brand-500"
                                :style="{ width: `${discoveryPercent}%` }"
                            ></div>
                        </div>
                    </section>

                    <!-- Streaks -->
                    <section class="surface-card p-5">
                        <header>
                            <h2 class="text-base font-bold text-white">
                                Listening streaks
                            </h2>
                            <p class="mt-0.5 text-xs text-ink-400">
                                Days in a row with at least one play
                            </p>
                        </header>

                        <div class="mt-5 grid grid-cols-2 gap-4">
                            <div
                                class="rounded-xl border border-white/[0.06] bg-white/[0.02] px-4 py-4"
                            >
                                <p class="text-3xl font-extrabold text-white">
                                    {{ streaks.current }}
                                    <span class="text-sm font-semibold text-ink-400">
                                        {{ streaks.current === 1 ? 'day' : 'days' }}
                                    </span>
                                </p>
                                <p class="mt-1 text-xs text-ink-400">
                                    Current streak
                                </p>
                            </div>
                            <div
                                class="rounded-xl border border-white/[0.06] bg-white/[0.02] px-4 py-4"
                            >
                                <p class="text-3xl font-extrabold text-white">
                                    {{ streaks.longest }}
                                    <span class="text-sm font-semibold text-ink-400">
                                        {{ streaks.longest === 1 ? 'day' : 'days' }}
                                    </span>
                                </p>
                                <p class="mt-1 text-xs text-ink-400">
                                    Longest streak
                                </p>
                            </div>
                        </div>
                    </section>
                </div>

                <!-- Artist trends -->
                <section class="surface-card overflow-hidden">
                    <header
                        class="flex flex-wrap items-center justify-between gap-3 border-b border-white/[0.06] px-5 py-4"
                    >
                        <div>
                            <h2 class="text-base font-bold text-white">
                                Artist trends
                            </h2>
                            <p class="mt-0.5 text-xs text-ink-400">
                                Weekly plays for your top artists, last 12 weeks
                            </p>
                        </div>
                        <span
                            v-if="trends.series.length"
                            class="rounded-full bg-white/5 px-2.5 py-1 text-xs font-semibold text-ink-300"
                        >
                            Top {{ trends.series.length }}
                        </span>
                    </header>

                    <template v-if="trends.series.length">
                        <div class="px-5 pb-2 pt-6">
                            <div class="relative h-72">
                                <Chart
                                    type="line"
                                    label="Weekly plays per top artist over the last 12 weeks"
                                    :data="trendChartData"
                                    :options="trendChartOptions"
                                />
                            </div>
                        </div>

                        <div class="border-t border-white/[0.06] px-5 py-5">
                            <div
                                class="flex flex-wrap items-baseline justify-between gap-2"
                            >
                                <h3 class="text-sm font-bold text-white">
                                    Rank movement
                                </h3>
                                <p class="text-xs text-ink-400">
                                    This week vs the previous 7 days
                                </p>
                            </div>

                            <ol class="mt-4 space-y-1">
                                <li
                                    v-for="artist in trends.movement"
                                    :key="artist.artist_id"
                                    class="flex items-center gap-3 rounded-xl px-2 py-2 transition hover:bg-white/[0.03]"
                                >
                                    <span
                                        class="w-5 shrink-0 text-right text-sm font-bold tabular-nums text-ink-500"
                                    >
                                        {{ artist.rank }}
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p
                                            class="truncate text-sm font-semibold text-white"
                                        >
                                            {{ artist.name }}
                                        </p>
                                        <p class="text-xs text-ink-400">
                                            {{ formatNumber(artist.plays) }} plays
                                            this week
                                            &middot;
                                            {{ formatNumber(artist.previous_plays) }}
                                            before
                                        </p>
                                    </div>
                                    <span
                                        class="shrink-0 rounded-full border px-2 py-0.5 text-xs font-bold"
                                        :class="movementClass(artist.change)"
                                        :title="`Rank change for ${artist.name}`"
                                    >
                                        {{ formatMovement(artist.change) }}
                                    </span>
                                </li>
                            </ol>
                        </div>
                    </template>

                    <p v-else class="px-5 py-12 text-center text-sm text-ink-400">
                        No artist plays in the last 12 weeks yet.
                    </p>
                </section>

                <!-- 4 weeks vs 1 year -->
                <section class="surface-card overflow-hidden">
                    <header class="border-b border-white/[0.06] px-5 py-4">
                        <h2 class="text-base font-bold text-white">
                            4 weeks vs 1 year
                        </h2>
                        <p class="mt-0.5 text-xs text-ink-400">
                            How your artist mix shifted, as a share of plays in
                            each window
                        </p>
                    </header>

                    <div class="grid gap-4 px-5 py-5 sm:grid-cols-2">
                        <div
                            v-for="window in comparisonWindows"
                            :key="window.key"
                            class="rounded-xl border border-white/[0.06] bg-white/[0.02] p-4"
                        >
                            <p
                                class="text-xs font-semibold uppercase tracking-wide text-ink-400"
                            >
                                {{ window.title }}
                            </p>
                            <div class="mt-3 grid grid-cols-3 gap-2">
                                <div
                                    v-for="stat in windowStats(window.data)"
                                    :key="stat.label"
                                >
                                    <p class="text-lg font-bold text-white">
                                        {{ stat.value }}
                                    </p>
                                    <p class="text-[11px] text-ink-400">
                                        {{ stat.label }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="comparisonLabels.length" class="px-5 pb-6">
                        <div class="relative h-64">
                            <Chart
                                type="bar"
                                label="Share of plays by artist, last 4 weeks versus last year"
                                :data="comparisonChartData"
                                :options="comparisonChartOptions"
                            />
                        </div>
                    </div>
                    <p
                        v-else
                        class="px-5 pb-8 text-center text-sm text-ink-400"
                    >
                        No plays recorded in these windows yet.
                    </p>
                </section>

                <!-- Top genres -->
                <section class="surface-card overflow-hidden">
                    <header
                        class="flex items-center justify-between border-b border-white/[0.06] px-5 py-4"
                    >
                        <div>
                            <h2 class="text-base font-bold text-white">
                                Top genres
                            </h2>
                            <p class="mt-0.5 text-xs text-ink-400">
                                Weighted by how often you played each artist
                            </p>
                        </div>
                        <span
                            v-if="topGenres.length"
                            class="rounded-full bg-white/5 px-2.5 py-1 text-xs font-semibold text-ink-300"
                        >
                            Top {{ topGenres.length }}
                        </span>
                    </header>

                    <ol v-if="topGenres.length" class="space-y-3 px-5 py-5">
                        <li
                            v-for="genre in topGenres"
                            :key="genre.genre"
                            class="grid grid-cols-[minmax(0,9rem)_1fr_auto] items-center gap-3"
                        >
                            <span
                                class="truncate text-sm font-semibold capitalize text-white"
                            >
                                {{ genre.genre }}
                            </span>
                            <span
                                class="h-2 overflow-hidden rounded-full bg-white/[0.06]"
                            >
                                <span
                                    class="block h-full rounded-full bg-brand-500/80"
                                    :style="{
                                        width: `${Math.round((genre.plays / maxGenrePlays) * 100)}%`,
                                    }"
                                ></span>
                            </span>
                            <span
                                class="text-xs tabular-nums text-ink-400"
                            >
                                {{ formatNumber(genre.plays) }} plays
                            </span>
                        </li>
                    </ol>

                    <p v-else class="px-5 py-10 text-center text-sm text-ink-400">
                        Spotify no longer exposes artist genres to new apps, so
                        genre totals are unavailable. Every other number on this
                        page is unaffected.
                    </p>
                </section>

                <!-- Heatmap -->
                <section class="surface-card overflow-hidden">
                    <header
                        class="flex flex-wrap items-center justify-between gap-3 border-b border-white/[0.06] px-5 py-4"
                    >
                        <div>
                            <h2 class="text-base font-bold text-white">
                                Listening heatmap
                            </h2>
                            <p class="mt-0.5 text-xs text-ink-400">
                                Hour of day by day of week, in {{ timezone }}
                            </p>
                        </div>

                        <div class="flex items-center gap-2 text-xs text-ink-400">
                            <span>Less</span>
                            <span
                                v-for="step in [0.15, 0.35, 0.55, 0.75, 1]"
                                :key="step"
                                class="h-3 w-3 rounded-sm"
                                :style="{
                                    backgroundColor: `rgb(var(--brand-500) / ${step})`,
                                }"
                            ></span>
                            <span>More</span>
                        </div>
                    </header>

                    <div class="overflow-x-auto px-5 py-5">
                        <div class="min-w-[36rem]">
                            <div class="flex items-center gap-2">
                                <span class="w-9 shrink-0"></span>
                                <div class="grid flex-1 grid-cols-24 gap-1">
                                    <span
                                        v-for="hour in 24"
                                        :key="hour"
                                        class="text-center text-[10px] tabular-nums text-ink-500"
                                    >
                                        {{
                                            hourLabels.includes(hour - 1)
                                                ? String(hour - 1).padStart(2, '0')
                                                : ''
                                        }}
                                    </span>
                                </div>
                            </div>

                            <div
                                v-for="(row, dayIndex) in heatmap.counts"
                                :key="dayIndex"
                                class="mt-1 flex items-center gap-2"
                            >
                                <span
                                    class="w-9 shrink-0 text-[11px] font-semibold text-ink-400"
                                >
                                    {{ weekdays[dayIndex] }}
                                </span>
                                <div class="grid flex-1 grid-cols-24 gap-1">
                                    <span
                                        v-for="(count, hour) in row"
                                        :key="hour"
                                        class="h-5 rounded-[3px] bg-white/[0.04] transition hover:ring-1 hover:ring-brand-300"
                                        :style="cellStyle(count)"
                                        :title="cellLabel(dayIndex, hour, count)"
                                    ></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
