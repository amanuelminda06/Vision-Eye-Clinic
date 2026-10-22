<?php

declare(strict_types=1);

namespace App\Support\PublicSite;

/**
 * The Google Maps embed on the public site (footer + contact page).
 *
 * The clinic runs on a plain host: no Google Cloud project, no API key and
 * no billing account. The embed therefore uses the KEYLESS Google Maps form:
 *
 *     https://www.google.com/maps?q=<lat>,<lng>&z=<zoom>&output=embed
 *
 * Google serves the framed document from its own host and sends its OWN CSP
 * header, so the site only needs one CSP exception — `frame-src
 * https://www.google.com`. No API key, no third-party script, no `img-src`
 * exception and no third-party cookie: the map is a plain <iframe>.
 *
 * The position comes from the CMS (`map_lat`, `map_lng`, `map_zoom`).
 */
final class MapEmbed
{
    /** The host that serves the embeddable map. */
    public const HOST = 'https://www.google.com';

    /** Google Maps zoom levels: 1 = the world, 21 = a single building. */
    public const MIN_ZOOM = 1;

    public const MAX_ZOOM = 21;

    /** The zoom used when the CMS leaves `map_zoom` blank. */
    public const DEFAULT_ZOOM = 15;

    /**
     * True when the coordinates point at a real place.
     *
     * BOTH coordinates must be set: a blank CMS value reads as 0, and a
     * half-set position (0 for the latitude, 38.77 for the longitude) would
     * drop the pin in the ocean instead of at the clinic.
     */
    public static function hasPosition(float $lat, float $lng): bool
    {
        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return false;
        }

        return $lat !== 0.0 && $lng !== 0.0;
    }

    /**
     * The Google Maps embed URL for a position, or null when there is no
     * position to show.
     *
     * @param  float  $lat  The latitude from the CMS.
     * @param  float  $lng  The longitude from the CMS.
     * @param  int  $zoom  The zoom from the CMS (clamped to Google's range).
     */
    public static function embedUrl(float $lat, float $lng, int $zoom = self::DEFAULT_ZOOM): ?string
    {
        if (! self::hasPosition($lat, $lng)) {
            return null;
        }

        $zoom = max(self::MIN_ZOOM, min(self::MAX_ZOOM, $zoom));

        $query = self::coordinate($lat).','.self::coordinate($lng);

        return self::HOST.'/maps?q='.$query.'&z='.$zoom.'&output=embed';
    }

    /**
     * A coordinate with the trailing zeros removed — six decimals is about a
     * centimetre, far more than the map needs.
     */
    private static function coordinate(float $value): string
    {
        $text = number_format($value, 6, '.', '');

        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return $text === '' || $text === '-' ? '0' : $text;
    }
}
