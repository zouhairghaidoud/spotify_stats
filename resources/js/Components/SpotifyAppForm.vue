<script setup>
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    spotifyApp: {
        type: Object,
        default: () => ({
            configured: false,
            client_id: null,
            redirect_uri: '',
            env_configured: false,
        }),
    },
});

const form = useForm({
    spotify_client_id: props.spotifyApp.client_id ?? '',
    spotify_client_secret: '',
});

const copied = ref(false);

watch(
    () => props.spotifyApp.client_id,
    (value) => {
        form.spotify_client_id = value ?? '';
    },
);

function save() {
    form.post(route('profile.spotify-app.update'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('spotify_client_secret');
        },
    });
}

function remove() {
    if (
        !window.confirm(
            'Remove your Spotify app credentials? Spotify will disconnect until you reconnect.',
        )
    ) {
        return;
    }

    router.delete(route('profile.spotify-app.destroy'), {
        preserveScroll: true,
    });
}

async function copyRedirectUri() {
    try {
        await navigator.clipboard.writeText(props.spotifyApp.redirect_uri);
        copied.value = true;

        window.setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        copied.value = false;
    }
}
</script>

<template>
    <div>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-bold text-white">
                    Use your own Spotify app
                </h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-ink-300">
                    Spotify's Development Mode only allows five allowlisted
                    accounts per app. Connect your own free Spotify developer
                    app to skip that limit and unlock live stats, now playing,
                    playlists, and scheduled sync.
                </p>
            </div>

            <span
                class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold"
                :class="
                    spotifyApp.configured
                        ? 'border-brand-500/20 bg-brand-500/10 text-brand-300'
                        : 'border-white/10 bg-white/[0.04] text-ink-400'
                "
            >
                <span
                    class="inline-block h-1.5 w-1.5 rounded-full"
                    :class="
                        spotifyApp.configured ? 'bg-brand-400' : 'bg-ink-500'
                    "
                ></span>
                {{ spotifyApp.configured ? 'Custom app active' : 'Using app default' }}
            </span>
        </div>

        <details class="group mt-4 rounded-xl border border-white/[0.06] bg-white/[0.02] px-4 py-3">
            <summary class="cursor-pointer text-sm font-semibold text-white">
                How to create a Spotify app (3 steps)
            </summary>

            <ol class="mt-3 space-y-3 text-sm leading-relaxed text-ink-300">
                <li>
                    <span class="font-semibold text-white">1.</span>
                    Open the
                    <a
                        href="https://developer.spotify.com/dashboard"
                        target="_blank"
                        rel="noreferrer"
                        class="font-semibold text-brand-300 underline decoration-brand-500/40 underline-offset-2 hover:text-brand-200"
                    >
                        Spotify Developer Dashboard
                    </a>
                    and create an app. Any name and description will do.
                </li>
                <li>
                    <span class="font-semibold text-white">2.</span>
                    In the app settings, add this exact Redirect URI:
                    <button
                        type="button"
                        class="mt-2 flex w-full items-center justify-between gap-2 rounded-lg border border-white/10 bg-ink-950/60 px-3 py-2 text-left font-mono text-xs text-ink-200 transition hover:border-brand-500/40"
                        @click="copyRedirectUri"
                    >
                        <span class="truncate">{{ spotifyApp.redirect_uri }}</span>
                        <span class="shrink-0 text-[11px] font-semibold text-brand-300">
                            {{ copied ? 'Copied' : 'Copy' }}
                        </span>
                    </button>
                </li>
                <li>
                    <span class="font-semibold text-white">3.</span>
                    Copy the app's Client ID and Client Secret below. Secrets
                    are stored encrypted and never shown again.
                </li>
            </ol>
        </details>

        <form class="mt-5 grid gap-4 sm:grid-cols-2" @submit.prevent="save">
            <div>
                <label class="text-xs font-semibold uppercase tracking-wide text-ink-400">
                    Client ID
                </label>
                <TextInput
                    v-model="form.spotify_client_id"
                    class="mt-1.5 w-full"
                    autocomplete="off"
                    placeholder="Your app's Client ID"
                />
                <InputError class="mt-1.5" :message="form.errors.spotify_client_id" />
            </div>

            <div>
                <label class="text-xs font-semibold uppercase tracking-wide text-ink-400">
                    Client Secret
                </label>
                <TextInput
                    v-model="form.spotify_client_secret"
                    type="password"
                    class="mt-1.5 w-full"
                    autocomplete="new-password"
                    :placeholder="
                        spotifyApp.configured
                            ? 'Leave blank to keep the current secret'
                            : `Your app's Client Secret`
                    "
                />
                <InputError class="mt-1.5" :message="form.errors.spotify_client_secret" />
            </div>

            <div class="flex flex-wrap items-center gap-3 sm:col-span-2">
                <PrimaryButton :disabled="form.processing">
                    {{ form.processing ? 'Saving…' : 'Save app credentials' }}
                </PrimaryButton>

                <button
                    v-if="spotifyApp.configured"
                    type="button"
                    class="text-xs font-semibold text-red-300 transition hover:text-red-200"
                    @click="remove"
                >
                    Remove custom app
                </button>

                <span class="text-xs text-ink-500">
                    Changing the app disconnects your current Spotify session.
                </span>
            </div>
        </form>
    </div>
</template>
