<script setup>
import { computed, ref } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const showingNavigationDropdown = ref(false);

const user = computed(() => page.props.auth.user);
const flash = computed(() => page.props.flash ?? {});

const initials = computed(() => {
    const name = user.value?.name ?? '';

    return (
        name
            .split(' ')
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part.charAt(0).toUpperCase())
            .join('') || 'S'
    );
});
</script>

<template>
    <div class="relative min-h-screen bg-ink-950">
        <!-- Ambient background -->
        <div
            aria-hidden="true"
            class="pointer-events-none fixed inset-x-0 top-0 -z-10 h-96 bg-[radial-gradient(ellipse_at_top,rgba(29,185,84,0.13),transparent_65%)]"
        ></div>

        <nav
            class="sticky top-0 z-40 border-b border-white/[0.06] bg-ink-950/80 backdrop-blur-xl"
        >
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 items-center justify-between gap-4">
                    <div class="flex items-center gap-2">
                        <Link
                            href="/"
                            class="flex items-center gap-2.5 rounded-xl px-1 py-1 transition hover:opacity-90"
                        >
                            <ApplicationLogo class="h-8 w-8" />
                            <span
                                class="hidden text-sm font-bold tracking-tight text-white sm:block"
                            >
                                Spotify
                                <span class="text-brand-400">Stats</span>
                            </span>
                        </Link>

                        <div class="ms-2 hidden items-center gap-1 sm:flex">
                            <NavLink
                                :href="route('dashboard')"
                                :active="route().current('dashboard')"
                            >
                                Dashboard
                            </NavLink>
                            <NavLink
                                :href="route('profile.edit')"
                                :active="route().current('profile.edit')"
                            >
                                Profile
                            </NavLink>
                        </div>
                    </div>

                    <!-- Desktop user menu -->
                    <div class="hidden items-center gap-3 sm:flex">
                        <span
                            class="hidden rounded-full border border-white/[0.08] bg-white/[0.03] px-3 py-1.5 text-xs font-medium text-ink-300 lg:inline-flex"
                        >
                            <span
                                class="me-2 inline-block h-1.5 w-1.5 self-center rounded-full bg-brand-400"
                            ></span>
                            Personal analytics
                        </span>

                        <Dropdown align="right" width="48">
                            <template #trigger>
                                <button
                                    type="button"
                                    class="flex items-center gap-2.5 rounded-full border border-white/[0.08] bg-white/[0.03] py-1.5 pe-3 ps-1.5 text-sm font-medium text-ink-100 transition hover:bg-white/[0.07] focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400/40"
                                >
                                    <img
                                        v-if="user.spotify_avatar"
                                        :src="user.spotify_avatar"
                                        :alt="user.name"
                                        class="h-7 w-7 rounded-full object-cover ring-1 ring-white/10"
                                    />
                                    <span
                                        v-else
                                        class="flex h-7 w-7 items-center justify-center rounded-full bg-brand-500 text-xs font-bold text-ink-950"
                                    >
                                        {{ initials }}
                                    </span>
                                    <span class="max-w-32 truncate">
                                        {{ user.name }}
                                    </span>
                                    <svg
                                        class="h-4 w-4 text-ink-400"
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 20 20"
                                        fill="currentColor"
                                        aria-hidden="true"
                                    >
                                        <path
                                            fill-rule="evenodd"
                                            d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                            clip-rule="evenodd"
                                        />
                                    </svg>
                                </button>
                            </template>

                            <template #content>
                                <div
                                    class="border-b border-white/[0.06] px-4 py-3"
                                >
                                    <p
                                        class="truncate text-sm font-semibold text-white"
                                    >
                                        {{ user.name }}
                                    </p>
                                    <p
                                        class="truncate text-xs text-ink-400"
                                    >
                                        {{ user.email }}
                                    </p>
                                </div>
                                <DropdownLink :href="route('profile.edit')">
                                    Profile
                                </DropdownLink>
                                <DropdownLink
                                    :href="route('logout')"
                                    method="post"
                                    as="button"
                                >
                                    Log out
                                </DropdownLink>
                            </template>
                        </Dropdown>
                    </div>

                    <!-- Mobile menu button -->
                    <div class="-me-2 flex items-center sm:hidden">
                        <button
                            type="button"
                            @click="
                                showingNavigationDropdown =
                                    !showingNavigationDropdown
                            "
                            class="inline-flex items-center justify-center rounded-xl p-2 text-ink-300 transition hover:bg-white/5 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400/40"
                            :aria-expanded="showingNavigationDropdown"
                            aria-label="Toggle navigation"
                        >
                            <svg
                                class="h-6 w-6"
                                stroke="currentColor"
                                fill="none"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    :class="{
                                        hidden: showingNavigationDropdown,
                                        'inline-flex':
                                            !showingNavigationDropdown,
                                    }"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M4 6h16M4 12h16M4 18h16"
                                />
                                <path
                                    :class="{
                                        hidden: !showingNavigationDropdown,
                                        'inline-flex': showingNavigationDropdown,
                                    }"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12"
                                />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Mobile navigation -->
            <div
                :class="{
                    block: showingNavigationDropdown,
                    hidden: !showingNavigationDropdown,
                }"
                class="border-t border-white/[0.06] bg-ink-950/95 sm:hidden"
            >
                <div class="space-y-1 px-4 py-4">
                    <ResponsiveNavLink
                        :href="route('dashboard')"
                        :active="route().current('dashboard')"
                        @click="showingNavigationDropdown = false"
                    >
                        Dashboard
                    </ResponsiveNavLink>
                    <ResponsiveNavLink
                        :href="route('profile.edit')"
                        :active="route().current('profile.edit')"
                        @click="showingNavigationDropdown = false"
                    >
                        Profile
                    </ResponsiveNavLink>
                </div>

                <div class="border-t border-white/[0.06] px-4 py-4">
                    <div class="flex items-center gap-3 px-1 pb-3">
                        <img
                            v-if="user.spotify_avatar"
                            :src="user.spotify_avatar"
                            :alt="user.name"
                            class="h-9 w-9 rounded-full object-cover ring-1 ring-white/10"
                        />
                        <span
                            v-else
                            class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-500 text-xs font-bold text-ink-950"
                        >
                            {{ initials }}
                        </span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-white">
                                {{ user.name }}
                            </p>
                            <p class="truncate text-xs text-ink-400">
                                {{ user.email }}
                            </p>
                        </div>
                    </div>

                    <div class="space-y-1">
                        <ResponsiveNavLink
                            :href="route('profile.edit')"
                            @click="showingNavigationDropdown = false"
                        >
                            Profile
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            :href="route('logout')"
                            method="post"
                            as="button"
                            @click="showingNavigationDropdown = false"
                        >
                            Log out
                        </ResponsiveNavLink>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Page heading -->
        <header
            v-if="$slots.header"
            class="border-b border-white/[0.06] bg-ink-900/30"
        >
            <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <slot name="header" />
            </div>
        </header>

        <!-- Page content -->
        <main class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <div
                v-if="flash.success"
                class="mb-6 flex items-start gap-3 rounded-xl border border-brand-500/20 bg-brand-500/10 px-4 py-3 text-sm font-medium text-brand-200"
            >
                <svg
                    class="mt-0.5 h-4 w-4 shrink-0"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    aria-hidden="true"
                >
                    <path
                        fill-rule="evenodd"
                        d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                        clip-rule="evenodd"
                    />
                </svg>
                <span>{{ flash.success }}</span>
            </div>

            <div
                v-if="flash.error"
                class="mb-6 flex items-start gap-3 rounded-xl border border-red-500/20 bg-red-500/10 px-4 py-3 text-sm font-medium text-red-300"
            >
                <svg
                    class="mt-0.5 h-4 w-4 shrink-0"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    aria-hidden="true"
                >
                    <path
                        fill-rule="evenodd"
                        d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                        clip-rule="evenodd"
                    />
                </svg>
                <span>{{ flash.error }}</span>
            </div>

            <slot />
        </main>

        <footer class="border-t border-white/[0.06] py-6">
            <p class="text-center text-xs text-ink-500">
                Spotify Stats &middot; Personal listening analytics
            </p>
        </footer>
    </div>
</template>
