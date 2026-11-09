const SPOTIFY_WEB_URL = 'https://open.spotify.com';

/**
 * Locally generated ids (standard history imports) have no Spotify page.
 */
function isLocalId(id) {
    return !id || String(id).startsWith('local_');
}

export function spotifyTrackUrl(id) {
    return isLocalId(id) ? null : `${SPOTIFY_WEB_URL}/track/${id}`;
}

export function spotifyArtistUrl(id) {
    return isLocalId(id) ? null : `${SPOTIFY_WEB_URL}/artist/${id}`;
}

export function spotifyItemUrl(item) {
    return item?.external_urls?.spotify ?? null;
}
