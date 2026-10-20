<?php

/**
 * Where an IP address is, roughly (country, town) — for the devices list
 * and, later, risk scoring. **Off unless `GEO_LOOKUP_URL` is set**: a lookup
 * sends the address to whoever answers it, so nothing leaves the server by
 * default. The URL is a template with `{ip}` and must answer JSON with any of
 * `country_code`/`countryCode`, `city`, `region`/`regionName` (ipapi.co and
 * ip-api.com both do). Production: MaxMind GeoLite2 on our own servers
 * instead (docs/production-memo.md).
 *
 * Private and reserved addresses (development, the docker network) are never
 * looked up. Answers are cached in Redis for 30 days; a failure is `null`.
 */
final class GeoIp
{
    private const CACHE_SECONDS = 30 * 86400;
    private const TIMEOUT_SECONDS = 2;

    /** @var RedisClient */
    private $redis;

    public function __construct(RedisClient $redis)
    {
        $this->redis = $redis;
    }

    /** @return array{country: ?string, place: ?string}|null */
    public function lookup(string $ip): ?array
    {
        $template = (string) (getenv('GEO_LOOKUP_URL') ?: '');
        if ($template === '' || $ip === '' || ClientIp::isPrivate($ip)) {
            return null;
        }

        $key = 'geo:ip:' . sha1($ip);
        try {
            $cached = $this->redis->command('GET', $key);
            if (is_string($cached)) {
                $hit = json_decode($cached, true);
                return is_array($hit) ? $hit : null;
            }
        } catch (Throwable $e) {
            // No cache — look it up anyway.
        }

        $raw = @file_get_contents(str_replace('{ip}', rawurlencode($ip), $template), false, stream_context_create([
            'http' => ['timeout' => self::TIMEOUT_SECONDS, 'header' => "Accept: application/json\r\n"],
        ]));
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return null;
        }

        $country = strtoupper((string) ($data['country_code'] ?? $data['countryCode'] ?? ''));
        $city = trim((string) ($data['city'] ?? ''));
        $region = trim((string) ($data['region'] ?? $data['regionName'] ?? ''));
        $result = [
            'country' => preg_match('/^[A-Z]{2}$/', $country) ? $country : null,
            'place' => mb_substr(implode(', ', array_filter([$city, $region !== $city ? $region : ''])), 0, 120) ?: null,
        ];
        try {
            $this->redis->command('SET', $key, (string) json_encode($result), 'EX', (string) self::CACHE_SECONDS);
        } catch (Throwable $e) {
            // Uncached is fine.
        }

        return $result;
    }
}
