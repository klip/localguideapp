<?php

/**
 * Thrown when the Claude API can't give an answer (not configured, timed
 * out, refused, or replied with something unusable). Deliberately not a
 * `RuntimeException`, so its text never reaches users: every caller falls
 * back to working without the AI step.
 */
class AiException extends Exception
{
}

/**
 * The Claude API (Messages), called with plain curl — no SDK, no Composer.
 *
 * `json()` asks for one JSON object of a given shape through structured
 * output (`output_config.format`), so the reply always parses. Schemas are
 * made strict on the way out: every object gets `additionalProperties:
 * false`, and `maxItems` (unsupported there) is dropped — callers enforce
 * their own limits on the answer. Transient failures (429, 5xx, 529
 * overloaded, network) are retried twice with a short backoff.
 *
 * Each call is logged with its purpose, model, time and token counts —
 * never the prompt or the answer, which carry people's profile text.
 *
 * Needs `ANTHROPIC_API_KEY` in the environment (`localguideapp.env`).
 */
final class Ai
{
    /** Cheap and fast: reading an itinerary. */
    public const MODEL_FAST = 'claude-haiku-5-5';
    /** Ranking and explaining guides. */
    public const MODEL_SMART = 'claude-sonnet-5-5';

    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const RETRIES = 2;

    /** @var string */
    private $apiKey;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = (string) ($apiKey ?? getenv('ANTHROPIC_API_KEY'));
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * One JSON object matching `$schema` (a JSON Schema object), answering
     * `$prompt` under `$system`. `$purpose` names the call in the logs.
     *
     * @throws AiException
     */
    public function json(
        string $model,
        string $system,
        string $prompt,
        array $schema,
        string $purpose,
        int $maxTokens = 1024,
        int $timeoutSeconds = 30
    ): array {
        if (!$this->isConfigured()) {
            throw new AiException('ANTHROPIC_API_KEY is not set.');
        }

        $body = json_encode([
            'model' => $model,
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => self::strict($schema)]],
        ], JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new AiException('The request could not be encoded.');
        }

        $started = microtime(true);
        for ($attempt = 0; ; $attempt++) {
            [$status, $response, $retryAfter, $error] = $this->post($body, $timeoutSeconds);
            $transient = $status === 0 || $status === 429 || $status >= 500;
            if (!$transient || $attempt >= self::RETRIES) {
                break;
            }
            Log::warning('AI call failed; retrying', ['purpose' => $purpose, 'status' => $status, 'error' => $error]);
            sleep(min(10, max($retryAfter, $attempt === 0 ? 1 : 3)));
        }

        $decoded = is_string($response) ? json_decode($response, true) : null;
        $context = [
            'purpose' => $purpose,
            'model' => $model,
            'status' => $status,
            'ms' => (int) ((microtime(true) - $started) * 1000),
            'input_tokens' => (int) ($decoded['usage']['input_tokens'] ?? 0),
            'output_tokens' => (int) ($decoded['usage']['output_tokens'] ?? 0),
        ];

        if ($status !== 200 || !is_array($decoded)) {
            $message = (string) ($decoded['error']['message'] ?? $error ?? 'no response');
            Log::error('AI call failed', $context + ['error' => mb_substr($message, 0, 300)]);
            throw new AiException('The AI service failed: ' . $message);
        }

        $text = '';
        foreach ((array) ($decoded['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }
        $answer = json_decode($text, true);
        $context['stop_reason'] = (string) ($decoded['stop_reason'] ?? '');
        if (!is_array($answer)) {
            Log::error('AI call gave no usable answer', $context);
            throw new AiException('The AI service gave no usable answer.');
        }

        Log::info('AI call', $context);
        return $answer;
    }

    /** `$schema` as structured output accepts it: closed objects, no `maxItems`. */
    private static function strict(array $schema): array
    {
        unset($schema['maxItems']);
        if (($schema['type'] ?? null) === 'object' || isset($schema['properties'])) {
            $schema['additionalProperties'] = false;
        }
        foreach ($schema['properties'] ?? [] as $name => $property) {
            $schema['properties'][$name] = self::strict($property);
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = self::strict($schema['items']);
        }

        return $schema;
    }

    /** @return array{0: int, 1: string|false, 2: int, 3: ?string} status (0 = no response), body, Retry-After seconds, curl error */
    private function post(string $body, int $timeoutSeconds): array
    {
        $retryAfter = 0;
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
                'content-type: application/json',
            ],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$retryAfter): int {
                if (stripos($header, 'retry-after:') === 0) {
                    $retryAfter = (int) trim(substr($header, 12));
                }
                return strlen($header);
            },
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = $response === false ? curl_error($ch) : null;
        curl_close($ch);

        return [$response === false ? 0 : $status, $response, $retryAfter, $error];
    }
}
