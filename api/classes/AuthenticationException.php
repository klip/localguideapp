<?php

/**
 * Thrown when a request's session hash is missing, unknown, or expired.
 * `RequestProcessor::handle()` maps this to HTTP 401 (distinct from the
 * generic 400 an ordinary `RuntimeException` gets), so the client can tell
 * "you're logged out" apart from a plain validation error and react to it —
 * see `plugins/api.ts`'s `call()`, which clears the local session on any
 * 401 response.
 */
class AuthenticationException extends RuntimeException
{
}
