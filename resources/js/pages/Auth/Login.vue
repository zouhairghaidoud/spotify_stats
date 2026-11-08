<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Log in" />

        <div class="mb-6">
            <h1 class="text-xl font-bold tracking-tight text-white">
                Welcome back
            </h1>
            <p class="mt-1 text-sm text-ink-400">
                Log in to continue to your listening stats.
            </p>
        </div>

        <div
            v-if="status"
            class="mb-4 rounded-xl border border-brand-500/20 bg-brand-500/10 px-3 py-2 text-sm font-medium text-brand-300"
        >
            {{ status }}
        </div>

        <div
            v-if="$page.props.flash?.error"
            class="mb-4 rounded-xl border border-red-500/20 bg-red-500/10 px-3 py-2 text-sm font-medium text-red-300"
        >
            {{ $page.props.flash.error }}
        </div>

        <a
            :href="route('spotify.redirect')"
            class="flex w-full items-center justify-center gap-2 rounded-xl bg-brand-500 px-4 py-3 text-sm font-bold text-ink-950 shadow-lg shadow-brand-500/20 transition hover:bg-brand-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:ring-offset-2 focus-visible:ring-offset-ink-900"
        >
            <svg
                class="h-5 w-5"
                viewBox="0 0 24 24"
                fill="currentColor"
                aria-hidden="true"
            >
                <path
                    d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0Zm5.5 17.28a.75.75 0 0 1-1.03.25c-2.82-1.72-6.37-2.11-10.55-1.16a.75.75 0 0 1-.33-1.46c4.57-1.05 8.5-.6 11.66 1.34.35.21.46.67.25 1.03Zm1.47-3.27a.94.94 0 0 1-1.29.31c-3.23-1.98-8.15-2.56-11.97-1.4a.94.94 0 1 1-.54-1.8c4.36-1.32 9.78-.68 13.49 1.6.44.27.58.85.31 1.29Zm.13-3.4C15.24 8.32 8.94 8.11 5.26 9.23a1.12 1.12 0 1 1-.65-2.15c4.22-1.28 11.18-1.03 15.58 1.58a1.12 1.12 0 1 1-1.15 1.92Z"
                />
            </svg>
            Log in with Spotify
        </a>

        <div class="relative my-6">
            <div class="absolute inset-0 flex items-center">
                <div class="w-full border-t border-white/10"></div>
            </div>
            <div class="relative flex justify-center text-[11px] uppercase">
                <span class="bg-ink-900 px-3 font-semibold tracking-wider text-ink-500">
                    Or continue with email
                </span>
            </div>
        </div>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="email" value="Email" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1.5 block w-full"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div class="mt-4">
                <InputLabel for="password" value="Password" />

                <TextInput
                    id="password"
                    type="password"
                    class="mt-1.5 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                />

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="mt-4 flex items-center justify-between">
                <label class="flex cursor-pointer items-center">
                    <Checkbox name="remember" v-model:checked="form.remember" />
                    <span class="ms-2 text-sm text-ink-300">Remember me</span>
                </label>

                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="rounded-md text-sm font-medium text-ink-300 underline decoration-white/20 underline-offset-4 transition hover:text-white"
                >
                    Forgot password?
                </Link>
            </div>

            <PrimaryButton
                class="mt-6 w-full"
                :class="{ 'opacity-40': form.processing }"
                :disabled="form.processing"
            >
                Log in
            </PrimaryButton>
        </form>

        <p class="mt-6 text-center text-sm text-ink-400">
            Don't have an account?
            <Link
                :href="route('register')"
                class="font-semibold text-brand-400 transition hover:text-brand-300"
            >
                Sign up
            </Link>
        </p>
    </GuestLayout>
</template>
