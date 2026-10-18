<?php

/**
 * The internal HTTP contract every backend service (`api`, `auth`) speaks
 * with the gateway. Services are never exposed to browsers: only the gateway
 * (`gateway/`) calls them, and it proves it with a shared secret.
 *
 *   POST /                       body: { action, ...payload } as JSON
 *     X-Internal-Token: <INTERNAL_TOKEN>    required, else 403
 *     X-User-Id: <id>                       the caller, when the gateway
 *                                           resolved a session for them
 *     X-Acting-Role: guest|guide            the role the request acts as — one
 *                                           the user holds (the gateway checks)
 *     X-Request-Id: <id>                    for correlating log lines
 *     X-Client-User-Agent: <ua>             the browser's, from the socket handshake
 *   → the status and JSON body the action produced
 *
 *   GET /healthz → 200 {"ok":true}          liveness probe, no token needed
 *
 * The services hold no state between requests — sessions are in Redis,
 * everything else in MySQL — so any number of replicas can sit behind one
 * Kubernetes Service.
 */
final class ServiceHttp
{
    /**
     * Serves one request with `$process(array $input, array $context): [status, body]`,
     * where `$context` is `{ userId: ?int, userAgent: string, requestId: string }`.
     */
    public static function serve(callable $process): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if ($path === '/healthz') {
            self::respond(200, ['ok' => true]);
            return;
        }

        $expected = (string) (getenv('INTERNAL_TOKEN') ?: '');
        if ($expected === '') {
            // Fail closed: without a token anyone who can reach the service
            // could claim to be any user via X-User-Id.
            Log::error('INTERNAL_TOKEN is not configured; refusing requests');
            self::respond(503, ['error' => 'Service unavailable.']);
            return;
        }
        if (!hash_equals($expected, (string) ($_SERVER['HTTP_X_INTERNAL_TOKEN'] ?? ''))) {
            self::respond(403, ['error' => 'Forbidden.']);
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            self::respond(405, ['error' => 'POST only.']);
            return;
        }
        $input = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($input)) {
            self::respond(400, ['error' => 'Expected a JSON object.']);
            return;
        }

        $userId = (int) ($_SERVER['HTTP_X_USER_ID'] ?? 0);
        $actingRole = (string) ($_SERVER['HTTP_X_ACTING_ROLE'] ?? '');
        $context = [
            'userId' => $userId > 0 ? $userId : null,
            // Checked by the gateway against the user's roles before it's sent.
            'actingRole' => $userId > 0 && in_array($actingRole, Roles::APP_ROLES, true) ? $actingRole : null,
            'userAgent' => (string) ($_SERVER['HTTP_X_CLIENT_USER_AGENT'] ?? ''),
            'requestId' => preg_replace('/[^A-Za-z0-9-]/', '', (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? '')),
        ];
        Log::context(array_filter([
            'request_id' => $context['requestId'],
            'action' => (string) ($input['action'] ?? ''),
            'user_id' => $context['userId'],
        ]));

        [$status, $body] = $process($input, $context);
        self::respond($status, $body);
    }

    private static function respond(int $status, array $body): void
    {
        http_response_code($status);
        echo json_encode($body);
    }
}
