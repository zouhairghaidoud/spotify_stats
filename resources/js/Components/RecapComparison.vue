<script setup>
const props = defineProps({
    current: {
        type: Object,
        required: true,
    },
    comparison: {
        type: Object,
        required: true,
    },
    year: {
        type: Number,
        required: true,
    },
    compareYear: {
        type: Number,
        required: true,
    },
});

function formatNumber(value) {
    return new Intl.NumberFormat().format(value ?? 0);
}

function topTrack(recap) {
    return recap?.top_tracks?.[0] ?? null;
}

function topGenre(recap) {
    return recap?.top_genres?.[0] ?? null;
}

function stats(recap) {
    return [
        { label: 'Plays', value: formatNumber(recap.overview.plays) },
        { label: 'Minutes', value: formatNumber(recap.overview.minutes) },
        { label: 'Artists', value: formatNumber(recap.overview.unique_artists) },
        { label: 'Tracks', value: formatNumber(recap.overview.unique_tracks) },
    ];
}

const columns = [
    { year: props.year, recap: props.current },
    { year: props.compareYear, recap: props.comparison },
];
</script>

<template>
    <section class="surface-card overflow-hidden">
        <header class="border-b border-white/[0.06] px-5 py-4">
            <h2 class="text-base font-bold text-white">Year over year</h2>
            <p class="mt-0.5 text-xs text-ink-400">
                {{ year }} compared with {{ compareYear }}
            </p>
        </header>

        <div class="grid divide-y divide-white/[0.06] sm:grid-cols-2 sm:divide-x sm:divide-y-0">
            <div
                v-for="(column, index) in columns"
                :key="column.year"
                class="px-5 py-5"
            >
                <div class="flex items-baseline justify-between">
                    <h3 class="text-lg font-bold text-white">{{ column.year }}</h3>
                    <span
                        class="rounded-full px-2.5 py-1 text-[11px] font-semibold"
                        :class="index === 0 ? 'bg-brand-500/15 text-brand-300' : 'bg-white/5 text-ink-300'"
                    >
                        {{ index === 0 ? 'Selected' : 'Compared' }}
                    </span>
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-3">
                    <div
                        v-for="stat in stats(column.recap)"
                        :key="stat.label"
                        class="rounded-xl bg-white/[0.03] px-3 py-2"
                    >
                        <dt class="text-[11px] font-semibold uppercase tracking-wide text-ink-500">
                            {{ stat.label }}
                        </dt>
                        <dd class="text-lg font-bold tabular-nums text-white">
                            {{ stat.value }}
                        </dd>
                    </div>
                </dl>

                <div class="mt-4 space-y-2 text-sm">
                    <p class="truncate text-ink-300">
                        <span class="font-semibold text-white">Top artist:</span>
                        {{ column.recap.top_artist?.name ?? '—' }}
                    </p>
                    <p class="truncate text-ink-300">
                        <span class="font-semibold text-white">Top album:</span>
                        {{ column.recap.top_album?.album_name ?? '—' }}
                    </p>
                    <p class="truncate text-ink-300">
                        <span class="font-semibold text-white">Top track:</span>
                        {{ topTrack(column.recap)?.name ?? '—' }}
                    </p>
                    <p class="truncate text-ink-300">
                        <span class="font-semibold text-white">Top genre:</span>
                        {{ topGenre(column.recap)?.genre ?? '—' }}
                    </p>
                </div>
            </div>
        </div>
    </section>
</template>
