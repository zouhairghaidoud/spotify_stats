<script setup>
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    summary: {
        type: Object,
        default: () => ({
            imported_plays: 0,
            total_plays: 0,
            last_imported_at: null,
        }),
    },
    imports: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({ file: null });
const fileInput = ref(null);
let pollTimer = null;

const activeImports = computed(() =>
    props.imports.filter((item) =>
        ['pending', 'running'].includes(item.status),
    ),
);

const isImporting = computed(() => activeImports.value.length > 0);

const status = computed(() => {
    const imported = props.summary.imported_plays ?? 0;
    const total = props.summary.total_plays ?? 0;

    if (total === 0) {
        return 'No listening history stored yet.';
    }

    return `${formatNumber(imported)} imported plays · ${formatNumber(total)} total plays`;
});

watch(
    isImporting,
    (active) => {
        if (active) {
            startPolling();
        } else {
            stopPolling();
        }
    },
    { immediate: true },
);

onBeforeUnmount(stopPolling);

function startPolling() {
    if (pollTimer) {
        return;
    }

    pollTimer = window.setInterval(() => {
        router.reload({
            only: ['imports', 'importSummary'],
            preserveScroll: true,
            preserveState: true,
        });
    }, 3000);
}

function stopPolling() {
    if (pollTimer) {
        window.clearInterval(pollTimer);
        pollTimer = null;
    }
}

function chooseFile(event) {
    form.file = event.target.files[0] ?? null;
}

function submit() {
    form.post(route('import.store'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset('file');

            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
}

function formatNumber(value) {
    return new Intl.NumberFormat().format(value ?? 0);
}

function statusLabel(status) {
    return {
        pending: 'Queued',
        running: 'Importing…',
        completed: 'Completed',
        failed: 'Failed',
    }[status] ?? status;
}

function statusClass(status) {
    return {
        pending: 'border-amber-400/20 bg-amber-400/10 text-amber-300',
        running: 'border-brand-500/20 bg-brand-500/10 text-brand-300',
        completed: 'border-emerald-400/20 bg-emerald-400/10 text-emerald-300',
        failed: 'border-rose-400/20 bg-rose-400/10 text-rose-300',
    }[status] ?? 'border-white/10 bg-white/[0.04] text-ink-300';
}

function formatDate(value) {
    if (!value) {
        return null;
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function deleteImported() {
    if (
        !window.confirm(
            'Delete every play that came from an uploaded export? Spotify-synced plays and shared track metadata are kept.',
        )
    ) {
        return;
    }

    router.delete(route('history.destroy-imported'), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-bold text-white">
                    Import your Spotify history
                </h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-ink-300">
                    Upload your Spotify
                    <span class="font-semibold text-white">Extended streaming history</span>
                    or
                    <span class="font-semibold text-white">Account data</span>
                    export (<span class="font-mono text-xs">.json</span> or
                    <span class="font-mono text-xs">.zip</span>) to unlock exact
                    lifetime play counts without connecting Spotify.
                </p>
                <p class="mt-1.5 text-xs text-ink-500">
                    {{ status }}
                </p>
            </div>

            <span
                class="rounded-full border border-white/10 bg-white/[0.04] px-2.5 py-1 text-xs font-semibold text-ink-300"
            >
                30s+ plays only
            </span>
        </div>

        <form class="mt-4 flex flex-wrap items-center gap-3" @submit.prevent="submit">
            <input
                ref="fileInput"
                type="file"
                accept=".json,.zip,application/json,application/zip"
                class="block w-full max-w-md cursor-pointer rounded-xl border border-white/10 bg-white/[0.04] px-3 py-2 text-xs text-ink-300 file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-white/10 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white hover:border-brand-500/40"
                @change="chooseFile"
            />

            <PrimaryButton :disabled="!form.file || form.processing || isImporting">
                {{ form.processing ? 'Uploading…' : 'Import history' }}
            </PrimaryButton>
        </form>

        <InputError class="mt-2" :message="form.errors.file" />

        <div v-if="isImporting" class="mt-4">
            <div class="h-1.5 overflow-hidden rounded-full bg-white/[0.06]">
                <div class="h-full w-1/3 animate-pulse rounded-full bg-brand-500"></div>
            </div>
            <p class="mt-2 text-xs font-medium text-brand-300">
                Your export is importing in the background. You can keep
                browsing — this panel updates automatically.
            </p>
        </div>

        <div v-if="imports.length" class="mt-5 space-y-2">
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-400">
                Recent imports
            </p>

            <ul class="space-y-2">
                <li
                    v-for="item in imports"
                    :key="item.id"
                    class="rounded-xl border border-white/[0.06] bg-white/[0.02] px-3.5 py-3"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="min-w-0 truncate text-sm font-semibold text-white">
                            {{ item.original_name }}
                        </p>
                        <span
                            class="shrink-0 rounded-full border px-2.5 py-0.5 text-[11px] font-semibold"
                            :class="statusClass(item.status)"
                        >
                            {{ statusLabel(item.status) }}
                        </span>
                    </div>

                    <p
                        v-if="item.status === 'completed'"
                        class="mt-1 text-xs text-ink-400"
                    >
                        {{ formatNumber(item.imported) }} plays from
                        {{ formatNumber(item.files) }} file(s)
                        <span v-if="item.skipped > 0">
                            · {{ formatNumber(item.skipped) }} skipped
                        </span>
                    </p>

                    <p
                        v-else-if="item.status === 'failed'"
                        class="mt-1 text-xs text-rose-300"
                    >
                        {{ item.error ?? 'The import could not be completed.' }}
                    </p>

                    <p
                        v-else
                        class="mt-1 text-xs text-ink-400"
                    >
                        {{ formatNumber(item.imported) }} plays imported so far
                    </p>

                    <p
                        v-if="formatDate(item.created_at)"
                        class="mt-1 text-[11px] text-ink-500"
                    >
                        {{ formatDate(item.created_at) }}
                    </p>
                </li>
            </ul>
        </div>

        <div
            v-if="summary.imported_plays > 0"
            class="mt-5 flex flex-wrap items-center gap-2 border-t border-white/[0.06] pt-4"
        >
            <a
                :href="route('history.export')"
                class="rounded-full border border-white/10 bg-white/[0.04] px-3.5 py-2 text-xs font-semibold text-ink-200 transition hover:bg-white/[0.08]"
            >
                Export CSV
            </a>

            <button
                type="button"
                class="rounded-full border border-rose-400/20 bg-rose-400/10 px-3.5 py-2 text-xs font-semibold text-rose-300 transition hover:bg-rose-400/20"
                @click="deleteImported"
            >
                Delete imported history
            </button>
        </div>

        <p class="mt-3 text-xs leading-relaxed text-ink-500">
            Re-uploading the same export is safe: plays are deduplicated by
            track and timestamp. Imported entries count exactly the time you
            actually listened.
        </p>
    </div>
</template>
