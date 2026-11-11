<script setup>
import RecapComparison from '@/Components/RecapComparison.vue';
import RecapStory from '@/Components/RecapStory.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    shared: {
        type: Boolean,
        default: false,
    },
    recap: {
        type: Object,
        required: true,
    },
    year: {
        type: Number,
        default: null,
    },
    years: {
        type: Array,
        default: () => [],
    },
    compareYear: {
        type: Number,
        default: null,
    },
    comparison: {
        type: Object,
        default: null,
    },
    publicUrl: {
        type: String,
        default: null,
    },
    imageUrl: {
        type: String,
        default: null,
    },
});

const compareYears = computed(() =>
    props.years.filter((option) => option !== props.year),
);

function selectYear(year) {
    router.get(
        route('recap'),
        year === null ? {} : { year },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

function selectCompare(year) {
    router.get(
        route('recap'),
        {
            year: props.year,
            ...(year === null ? {} : { compare: year }),
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}
</script>

<template>
    <Head title="Listening recap" />

    <AuthenticatedLayout v-if="!shared">
        <template #header>
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p
                        class="text-xs font-semibold uppercase tracking-[0.22em] text-brand-400"
                    >
                        Recap
                    </p>
                    <h1
                        class="mt-2 text-2xl font-bold tracking-tight text-white sm:text-3xl"
                    >
                        Your listening story
                    </h1>
                    <p class="mt-1 text-sm text-ink-300">
                        {{
                            year
                                ? `A snapshot of everything you played in ${year}.`
                                : 'A snapshot of everything tracked so far.'
                        }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <label
                        v-if="years.length"
                        class="flex items-center gap-2 text-xs font-semibold text-ink-300"
                    >
                        <span class="sr-only">Recap year</span>
                        <select
                            :value="year ?? ''"
                            class="rounded-full border border-white/10 bg-ink-900 px-3 py-2 text-xs font-semibold text-white transition focus:border-brand-500/60 focus:ring-2 focus:ring-brand-500/30"
                            @change="selectYear($event.target.value === '' ? null : Number($event.target.value))"
                        >
                            <option value="">All time</option>
                            <option v-for="option in years" :key="option" :value="option">
                                {{ option }}
                            </option>
                        </select>
                    </label>

                    <label
                        v-if="year !== null && compareYears.length"
                        class="flex items-center gap-2 text-xs font-semibold text-ink-300"
                    >
                        <span class="text-ink-500">Compare</span>
                        <select
                            :value="compareYear ?? ''"
                            class="rounded-full border border-white/10 bg-ink-900 px-3 py-2 text-xs font-semibold text-white transition focus:border-brand-500/60 focus:ring-2 focus:ring-brand-500/30"
                            @change="selectCompare($event.target.value === '' ? null : Number($event.target.value))"
                        >
                            <option value="">Off</option>
                            <option v-for="option in compareYears" :key="option" :value="option">
                                {{ option }}
                            </option>
                        </select>
                    </label>

                    <Link
                        :href="route('analytics')"
                        class="rounded-full border border-white/10 bg-white/[0.04] px-4 py-2 text-xs font-semibold text-ink-200 transition hover:bg-white/[0.08]"
                    >
                        View analytics
                    </Link>
                </div>
            </div>
        </template>

        <RecapComparison
            v-if="comparison && year !== null && compareYear !== null"
            :current="recap"
            :comparison="comparison"
            :year="year"
            :compare-year="compareYear"
            class="mb-8"
        />

        <RecapStory
            :recap="recap"
            :public-url="publicUrl"
            :image-url="imageUrl"
            can-manage
        />
    </AuthenticatedLayout>

    <div v-else class="relative min-h-screen bg-ink-950">
        <div
            aria-hidden="true"
            class="pointer-events-none fixed inset-x-0 top-0 -z-10 h-96 bg-[radial-gradient(ellipse_at_top,rgba(29,185,84,0.13),transparent_65%)]"
        ></div>

        <header
            class="mx-auto flex w-full max-w-4xl items-center justify-between px-4 py-6 sm:px-6"
        >
            <Link href="/" class="flex items-center gap-2 text-sm font-bold text-white">
                Spotify <span class="text-brand-400">Stats</span>
            </Link>
            <Link
                href="/"
                class="rounded-full border border-white/10 bg-white/[0.04] px-4 py-2 text-xs font-semibold text-ink-200 transition hover:bg-white/[0.08]"
            >
                Make your own
            </Link>
        </header>

        <main class="mx-auto w-full max-w-4xl px-4 pb-16 sm:px-6">
            <RecapStory
                :recap="recap"
                :public-url="publicUrl"
                :image-url="imageUrl"
                shared
            />
        </main>
    </div>
</template>
