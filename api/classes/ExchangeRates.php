<?php

/**
 * Daily exchange rates from GBP — the currency every price is stored and
 * charged in — so the app can show amounts in the viewer's currency
 * (`exchangeRates`, public). Display only: charges stay in GBP.
 *
 * Source: the European Central Bank's reference rates through Frankfurter
 * (free, no key — a development stand-in, see docs/production-memo.md).
 * Cached in Redis for 6 hours (`fx:gbp`), and the last good copy kept for a
 * week (`fx:gbp:last`) so an outage upstream doesn't take conversion down.
 * With nothing at all, GBP alone comes back and the app shows pounds.
 */
class ExchangeRates
{
    private const ENDPOINT = 'https://api.frankfurter.dev/v1/latest?base=GBP';
    private const CACHE_KEY = 'fx:gbp';
    private const LAST_GOOD_KEY = 'fx:gbp:last';
    private const CACHE_SECONDS = 6 * 3600;
    private const LAST_GOOD_SECONDS = 7 * 86400;
    private const TIMEOUT_SECONDS = 5;

    /** @var RedisClient */
    private $redis;

    public function __construct(RedisClient $redis)
    {
        $this->redis = $redis;
    }

    /**
     * @return array{base: string, date: string|null, rates: array<string, float>}
     *   `rates` always includes GBP => 1.
     */
    public function current(): array
    {
        $cached = $this->read(self::CACHE_KEY);
        if ($cached !== null) {
            return $cached;
        }

        $fresh = $this->fetch();
        if ($fresh !== null) {
            $json = json_encode($fresh);
            try {
                $this->redis->command('SET', self::CACHE_KEY, $json, 'EX', (string) self::CACHE_SECONDS);
                $this->redis->command('SET', self::LAST_GOOD_KEY, $json, 'EX', (string) self::LAST_GOOD_SECONDS);
            } catch (RedisException $e) {
                Log::warning('Exchange rates not cached', ['error' => $e->getMessage()]);
            }

            return $fresh;
        }

        return $this->read(self::LAST_GOOD_KEY) ?? ['base' => 'GBP', 'date' => null, 'rates' => ['GBP' => 1.0]];
    }

    private function read(string $key): ?array
    {
        try {
            $value = $this->redis->command('GET', $key);
        } catch (RedisException $e) {
            return null;
        }
        $data = is_string($value) ? json_decode($value, true) : null;

        return is_array($data) && isset($data['rates']) ? $data : null;
    }

    private function fetch(): ?array
    {
        $curl = curl_init(self::ENDPOINT);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        $json = is_string($body) ? json_decode($body, true) : null;
        if ($status !== 200 || !is_array($json) || !is_array($json['rates'] ?? null)) {
            Log::warning('Exchange rates unavailable', ['status' => $status]);

            return null;
        }

        $rates = ['GBP' => 1.0];
        foreach ($json['rates'] as $code => $rate) {
            if (is_string($code) && preg_match('/^[A-Z]{3}$/', $code) && is_numeric($rate) && (float) $rate > 0) {
                $rates[$code] = (float) $rate;
            }
        }

        return ['base' => 'GBP', 'date' => is_string($json['date'] ?? null) ? $json['date'] : null, 'rates' => $rates];
    }
}
