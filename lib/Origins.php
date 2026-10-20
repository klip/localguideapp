<?php

/**
 * The browser origins the web apps are served from — checked by the gateway
 * on the WebSocket handshake and by the auth service's cookie endpoints
 * (`AuthHttp`): belikelocal.club (+ www, admin, api) and the Vite dev server,
 * plus `WS_EXTRA_ORIGINS` (comma-separated, exact match), e.g. a second dev
 * server on another port.
 */
final class Origins
{
    public static function isAllowed(string $origin): bool
    {
        $extra = array_filter(array_map(
            static fn(string $value): string => strtolower(trim($value)),
            explode(',', (string) (getenv('WS_EXTRA_ORIGINS') ?: ''))
        ));

        return in_array(strtolower($origin), $extra, true)
            || (bool) preg_match('#^https?://((www|admin|api)\.)?(belikelocal\.club|localhost:5173)$#i', $origin);
    }
}
