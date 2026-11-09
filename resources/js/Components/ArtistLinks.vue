<script setup>
import { spotifyArtistUrl, spotifyItemUrl } from '@/spotifyLinks';

defineProps({
    artists: {
        type: Array,
        default: () => [],
    },
});

function urlFor(artist) {
    return spotifyItemUrl(artist) ?? spotifyArtistUrl(artist?.id);
}
</script>

<template>
    <span>
        <template
            v-for="(artist, index) in artists"
            :key="artist.id ?? index"
        >
            <a
                v-if="urlFor(artist)"
                :href="urlFor(artist)"
                target="_blank"
                rel="noreferrer"
                class="transition hover:text-brand-300 hover:underline"
                :title="`Open ${artist.name} on Spotify`"
            >
                {{ artist.name }}
            </a>
            <span v-else>{{ artist.name }}</span>
            <span v-if="index < artists.length - 1">, </span>
        </template>
    </span>
</template>
