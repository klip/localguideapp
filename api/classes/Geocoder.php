<?php

/**
 * Place names ↔ coordinates for the place search (a guide's base, the deck's
 * "Near: …" point, later the travel planner's stops). One server-side proxy
 * so the client never talks to the provider and results are cached.
 *
 * **Development stand-in** (docs/production-memo.md): Photon's public server
 * (photon.komoot.io — OpenStreetMap data, free, fair use, built for
 * search-as-you-type). For production point `ENDPOINT` at a self-hosted
 * Photon or swap this class for a paid geocoder; the place shape stays.
 *
 * A place: `{ label, name, countryCode, latitude, longitude, kind }` —
 * `label` is "Lisbon, Portugal", `kind` the provider's type (city, state…).
 */
final class Geocoder
{
    private const ENDPOINT = 'https://photon.komoot.io';
    private const USER_AGENT = 'BeLikeLocal-dev/0.1 (+https://belikelocal.club)';
    private const TIMEOUT_SECONDS = 6;
    private const CACHE_SECONDS = 7 * 86400;
    /** Towns, cities and areas — not streets or houses: a base or a destination is a place, never an address. */
    private const LAYERS = ['city', 'locality', 'district', 'county', 'state', 'country'];
    private const TOWN_KINDS = ['city', 'locality'];
    /** Photon names places in these; other languages get local names. */
    private const LANGUAGES = ['en', 'de', 'fr'];
    /** Requests per second to the public server, across every service replica — fair use. */
    private const MAX_PER_SECOND = 5;

    /** @var RedisClient */
    private $redis;

    public function __construct(RedisClient $redis)
    {
        $this->redis = $redis;
    }

    /**
     * Places matching `$query`, best first (at most `$limit`). `$near`
     * ({latitude, longitude}) favours results close to it.
     *
     * @return array<int, array>
     */
    public function search(string $query, string $language, ?array $near = null, int $limit = 6): array
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query));
        if (mb_strlen($query) < 2) {
            return [];
        }
        if (mb_strlen($query) > 120) {
            throw new RuntimeException('That place name is too long.');
        }

        $params = ['q' => $query, 'limit' => $limit + 4] + $this->languageParam($language);
        if ($near) {
            $params['lat'] = round($near['latitude'], 2);
            $params['lon'] = round($near['longitude'], 2);
        }
        $features = $this->fetch('/api/', $params, self::LAYERS);

        $places = [];
        foreach ($features as $feature) {
            $place = $this->toPlace($feature);
            // The same town often comes back as its city and its county, under
            // one label: keep the town's point, not the county's middle.
            if ($place && (!isset($places[$place['label']])
                || in_array($place['kind'], self::TOWN_KINDS, true) && !in_array($places[$place['label']]['kind'], self::TOWN_KINDS, true))) {
                $places[$place['label']] = $place;
            }
        }

        return array_slice(array_values($places), 0, $limit);
    }

    /** The town or area at a point (for "use my location"), or null when there's nothing there. */
    public function reverse(float $latitude, float $longitude, string $language): ?array
    {
        $features = $this->fetch('/reverse', [
            // A ~1 km grid: enough to name the town, and the cache works.
            'lat' => round($latitude, 2),
            'lon' => round($longitude, 2),
            'limit' => 1,
        ] + $this->languageParam($language));

        if (!$features) {
            return null;
        }
        // The nearest thing is usually a street or a square; name the town
        // it's in instead ("Lisboa, Portugal"), at the point asked about.
        $properties = (array) ($features[0]['properties'] ?? []);
        $isTown = in_array($properties['type'] ?? '', ['city', 'county', 'state', 'country'], true);
        $town = $isTown ? null : ($properties['city'] ?? $properties['county'] ?? $properties['state'] ?? null);
        if ($town !== null) {
            $features[0]['properties'] = ['name' => $town, 'type' => 'city'] + array_diff_key($properties, ['city' => 1, 'name' => 1]);
        }
        $place = $this->toPlace($features[0]);
        if ($place) {
            $place['latitude'] = round($latitude, 2);
            $place['longitude'] = round($longitude, 2);
        }

        return $place;
    }

    private function languageParam(string $language): array
    {
        return in_array($language, self::LANGUAGES, true) ? ['lang' => $language] : [];
    }

    /** @return array<int, array> the response's GeoJSON features */
    private function fetch(string $path, array $params, array $layers = []): array
    {
        $url = self::ENDPOINT . $path . '?' . http_build_query($params)
            . implode('', array_map(fn($layer) => '&layer=' . $layer, $layers));
        $cacheKey = 'geocode:' . sha1($url);

        $cached = $this->cacheGet($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        $this->throttle();

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        $json = is_string($body) ? json_decode($body, true) : null;
        if ($status !== 200 || !is_array($json)) {
            Log::warning('Geocoder failed', ['status' => $status, 'error' => $error, 'path' => $path]);
            throw new RuntimeException('Place search isn’t available right now. Please try again.');
        }

        $features = array_values(array_filter((array) ($json['features'] ?? []), 'is_array'));
        $this->cacheSet($cacheKey, $features);

        return $features;
    }

    private function toPlace(array $feature): ?array
    {
        $coordinates = $feature['geometry']['coordinates'] ?? null;
        $properties = (array) ($feature['properties'] ?? []);
        $name = trim((string) ($properties['name'] ?? $properties['city'] ?? ''));
        if (!is_array($coordinates) || count($coordinates) < 2 || $name === '') {
            return null;
        }

        $parts = [$name];
        foreach (['city', 'state', 'country'] as $field) {
            $value = trim((string) ($properties[$field] ?? ''));
            if ($value !== '' && !in_array($value, $parts, true)) {
                $parts[] = $value;
            }
        }
        // "Palace Gully, Gibraltar, Gibraltar" → at most name, region, country.
        if (count($parts) > 3) {
            $parts = [$parts[0], $parts[count($parts) - 2], $parts[count($parts) - 1]];
        }
        $countryCode = strtoupper(trim((string) ($properties['countrycode'] ?? '')));

        return [
            'label' => implode(', ', $parts),
            'name' => $name,
            'countryCode' => preg_match('/^[A-Z]{2}$/', $countryCode) ? $countryCode : null,
            'latitude' => round((float) $coordinates[1], 5),
            'longitude' => round((float) $coordinates[0], 5),
            'kind' => (string) ($properties['type'] ?? ''),
        ];
    }

    private function throttle(): void
    {
        try {
            $key = 'geocode:rate:' . time();
            $count = (int) $this->redis->command('INCR', $key);
            if ($count === 1) {
                $this->redis->command('EXPIRE', $key, '2');
            }
        } catch (RedisException $e) {
            return; // No Redis, no shared count — let the request through.
        }
        if ($count > self::MAX_PER_SECOND) {
            throw new RuntimeException('Place search is busy. Please try again in a moment.');
        }
    }

    private function cacheGet(string $key): ?array
    {
        try {
            $value = $this->redis->command('GET', $key);
        } catch (RedisException $e) {
            return null;
        }
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    private function cacheSet(string $key, array $features): void
    {
        try {
            $this->redis->command('SET', $key, json_encode($features), 'EX', (string) self::CACHE_SECONDS);
        } catch (RedisException $e) {
            // Uncached is fine.
        }
    }
}
