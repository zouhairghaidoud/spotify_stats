<?php

namespace App\Services;

use GdImage;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Renders the public recap Open Graph card (1200×630 PNG) with GD.
 *
 * Text uses the first available TrueType font (a bundled font, then common
 * system fonts). When none can be found it falls back to GD's built-in bitmap
 * font so a valid image is still produced.
 */
class RecapImageService
{
    private const WIDTH = 1200;

    private const HEIGHT = 630;

    /**
     * @param  array<string, mixed>  $recap
     */
    public function render(array $recap): string
    {
        if (! extension_loaded('gd') || ! function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('The GD extension is required to render recap images.');
        }

        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $font = $this->fontPath();

        if ($image === false) {
            throw new RuntimeException('Could not create the recap image canvas.');
        }

        $background = imagecolorallocate($image, 7, 9, 8);
        imagefilledrectangle($image, 0, 0, self::WIDTH, self::HEIGHT, $background);

        $greenGlow = imagecolorallocatealpha($image, 29, 185, 84, 100);
        imagefilledellipse($image, 1120, 10, 720, 520, $greenGlow);

        $blueGlow = imagecolorallocatealpha($image, 79, 156, 249, 118);
        imagefilledellipse($image, 60, 700, 700, 440, $blueGlow);

        $white = imagecolorallocate($image, 244, 246, 245);
        $muted = imagecolorallocate($image, 160, 172, 167);
        $brand = imagecolorallocate($image, 29, 185, 84);

        $overview = $recap['overview'] ?? [];
        $topArtist = $recap['top_artist']['name'] ?? 'No plays yet';
        $topTrack = $recap['top_tracks'][0] ?? null;
        $trackName = $topTrack['name'] ?? 'No plays yet';
        $trackArtist = $topTrack['artist_name'] ?? '';
        $peakHour = $recap['peak_hour'] ?? null;
        $peak = $peakHour === null ? '—' : sprintf('%02d:00', $peakHour['hour']);
        $genres = implode(' · ', array_column($recap['top_genres'] ?? [], 'genre'));

        if ($font === null) {
            $this->renderBitmapFallback(
                $image,
                (int) ($overview['minutes'] ?? 0),
                (int) ($overview['plays'] ?? 0),
                $topArtist,
                $trackName,
                $peak,
                $white,
                $muted,
            );
        } else {
            $this->drawText($image, $font, 20, 72, 104, $brand, 'SPOTIFY STATS · RECAP');

            $this->drawText($image, $font, 140, 66, 304, $white, number_format((int) ($overview['minutes'] ?? 0)));
            $this->drawText($image, $font, 32, 72, 356, $muted, 'minutes listened');

            $this->drawText(
                $image,
                $font,
                24,
                72,
                424,
                $muted,
                number_format((int) ($overview['plays'] ?? 0)).' plays · '
                    .number_format((int) ($overview['unique_artists'] ?? 0)).' artists · '
                    .number_format((int) ($overview['unique_tracks'] ?? 0)).' tracks',
            );

            if ($genres !== '') {
                $this->drawText($image, $font, 24, 72, 486, $muted, 'Top genres: '.$this->truncate($genres, 38));
            }

            $since = $recap['period']['from'] ?? null;

            if (filled($since)) {
                $this->drawText(
                    $image,
                    $font,
                    20,
                    72,
                    542,
                    $muted,
                    'Tracking since '.Carbon::parse((string) $since)->format('j M Y'),
                );
            }

            $this->drawText($image, $font, 18, 72, 592, $muted, 'Made with Spotify Stats');

            $this->drawText($image, $font, 20, 704, 150, $muted, 'TOP ARTIST');
            $this->drawText($image, $font, 46, 704, 208, $white, $this->truncate($topArtist, 17));

            $this->drawText($image, $font, 20, 704, 296, $muted, 'TOP TRACK');
            $this->drawText($image, $font, 34, 704, 348, $white, $this->truncate((string) $trackName, 22));

            if ($trackArtist !== '') {
                $this->drawText($image, $font, 22, 704, 386, $muted, $this->truncate((string) $trackArtist, 30));
            }

            $this->drawText($image, $font, 20, 704, 476, $muted, 'MOST ACTIVE HOUR');
            $this->drawText($image, $font, 40, 704, 532, $white, $peak);
        }

        ob_start();
        imagepng($image);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        return $contents;
    }

    /**
     * A compact, text-only layout for hosts without a TrueType font.
     */
    private function renderBitmapFallback(
        GdImage $image,
        int $minutes,
        int $plays,
        string $topArtist,
        string $topTrack,
        string $peak,
        int $white,
        int $muted,
    ): void {
        $lines = [
            'SPOTIFY STATS RECAP',
            number_format($minutes).' MINUTES LISTENED',
            number_format($plays).' PLAYS',
            'TOP ARTIST: '.$topArtist,
            'TOP TRACK: '.$topTrack,
            'MOST ACTIVE HOUR: '.$peak,
        ];

        $y = 120;

        foreach ($lines as $index => $line) {
            imagestring($image, 5, 72, $y, $this->truncate($line, 70), $index === 0 ? $muted : $white);
            $y += 60;
        }
    }

    private function drawText(GdImage $image, string $font, float $size, int $x, int $baseline, int $color, string $text): void
    {
        imagettftext($image, $size, 0, $x, $baseline, $color, $font, $text);
    }

    private function truncate(string $value, int $length): string
    {
        return mb_strlen($value) > $length
            ? mb_substr($value, 0, $length - 1).'…'
            : $value;
    }

    private function fontPath(): ?string
    {
        $candidates = [
            resource_path('fonts/Figtree-Bold.ttf'),
            'C:\\Windows\\Fonts\\arialbd.ttf',
            'C:\\Windows\\Fonts\\arial.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
