<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});
</script>

<template>
    <Head title="Profile" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <p
                    class="text-xs font-semibold uppercase tracking-[0.22em] text-brand-400"
                >
                    Account
                </p>
                <h1
                    class="mt-2 text-2xl font-bold tracking-tight text-white sm:text-3xl"
                >
                    Profile
                </h1>
                <p class="mt-1 text-sm text-ink-300">
                    Manage your account details, password, and data.
                </p>
            </div>
        </template>

        <div class="space-y-6">
            <section class="surface-card p-6 sm:p-8">
                <UpdateProfileInformationForm
                    :must-verify-email="mustVerifyEmail"
                    :status="status"
                    class="max-w-xl"
                />
            </section>

            <section class="surface-card p-6 sm:p-8">
                <UpdatePasswordForm class="max-w-xl" />
            </section>

            <section
                class="rounded-2xl border border-red-500/20 bg-red-500/[0.03] p-6 shadow-card sm:p-8"
            >
                <DeleteUserForm class="max-w-xl" />
            </section>
        </div>
    </AuthenticatedLayout>
</template>
