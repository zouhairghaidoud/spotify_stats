<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Forgot Password" />

        <div class="mb-6">
            <h1 class="text-xl font-bold tracking-tight text-white">
                Reset your password
            </h1>
            <p class="mt-1 text-sm leading-relaxed text-ink-400">
                Enter the email address you registered with and we will send
                you a link to choose a new password.
            </p>
        </div>

        <div
            v-if="status"
            class="mb-4 rounded-xl border border-brand-500/20 bg-brand-500/10 px-3 py-2 text-sm font-medium text-brand-300"
        >
            {{ status }}
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

            <PrimaryButton
                class="mt-6 w-full"
                :class="{ 'opacity-40': form.processing }"
                :disabled="form.processing"
            >
                Email password reset link
            </PrimaryButton>
        </form>

        <p class="mt-6 text-center text-sm text-ink-400">
            Remembered it?
            <Link
                :href="route('login')"
                class="font-semibold text-brand-400 transition hover:text-brand-300"
            >
                Back to log in
            </Link>
        </p>
    </GuestLayout>
</template>
