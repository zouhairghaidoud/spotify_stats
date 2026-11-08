<script setup>
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    status: {
        type: String,
    },
});

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(
    () => props.status === 'verification-link-sent',
);
</script>

<template>
    <GuestLayout>
        <Head title="Email Verification" />

        <div class="mb-6">
            <h1 class="text-xl font-bold tracking-tight text-white">
                Verify your email
            </h1>
            <p class="mt-1 text-sm leading-relaxed text-ink-400">
                Thanks for signing up. Before getting started, please verify
                your email address by clicking the link we just emailed you. If
                you did not receive it, we can send another.
            </p>
        </div>

        <div
            v-if="verificationLinkSent"
            class="mb-4 rounded-xl border border-brand-500/20 bg-brand-500/10 px-3 py-2 text-sm font-medium text-brand-300"
        >
            A new verification link has been sent to the email address you
            provided during registration.
        </div>

        <form @submit.prevent="submit">
            <PrimaryButton
                class="w-full"
                :class="{ 'opacity-40': form.processing }"
                :disabled="form.processing"
            >
                Resend verification email
            </PrimaryButton>

            <div class="mt-6 text-center">
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="rounded-md text-sm font-medium text-ink-400 underline decoration-white/20 underline-offset-4 transition hover:text-white"
                >
                    Log out
                </Link>
            </div>
        </form>
    </GuestLayout>
</template>
