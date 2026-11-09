<script setup>
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    summary: {
        type: Object,
        default: () => ({
            imported_plays: 0,
            total_plays: 0,
            last_imported_at: null,
        }),
    },
});

const form = useForm({ file: null });
const fileInput = ref(null);

const status = computed(() => {
    const imported = props.summary.imported_plays ?? 0;
    const total = props.summary.total_plays ?? 0;

    if (total === 0) {
        return 'No listening history stored yet.';
    }

    return `${formatNumber(imported)} imported plays · ${formatNumber(total)} total plays`;
});

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

            <PrimaryButton :disabled="!form.file || form.processing">
                {{ form.processing ? 'Importing…' : 'Import history' }}
            </PrimaryButton>
        </form>

        <InputError class="mt-2" :message="form.errors.file" />

        <p class="mt-3 text-xs leading-relaxed text-ink-500">
            Re-uploading the same export is safe: plays are deduplicated by
            track and timestamp. Imported entries count exactly the time you
            actually listened.
        </p>
    </div>
</template>
