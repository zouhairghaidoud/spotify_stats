<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    password: '',
});

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Confirm Password" />

        <div class="mb-6">
            <h1 class="text-xl font-bold tracking-tight text-white">
                Confirm your password
            </h1>
            <p class="mt-1 text-sm leading-relaxed text-ink-400">
                This is a secure area of the application. Please confirm your
                password before continuing.
            </p>
        </div>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="password" value="Password" />

                <TextInput
                    id="password"
                    type="password"
                    class="mt-1.5 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                    autofocus
                />

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <PrimaryButton
                class="mt-6 w-full"
                :class="{ 'opacity-40': form.processing }"
                :disabled="form.processing"
            >
                Confirm
            </PrimaryButton>
        </form>
    </GuestLayout>
</template>
