<?php

/**
 * The Redis wire protocol (RESP2): encoding commands and incrementally
 * parsing replies, shared by the blocking `RedisClient` and the gateway's
 * non-blocking `RedisSubscriber`. No extension needed.
 */
final class Resp
{
    /** One command as a RESP array of bulk strings. */
    public static function encode(array $args): string
    {
        $out = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $arg = (string) $arg;
            $out .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }

        return $out;
    }

    /**
     * Parses one reply starting at `$offset`. Returns `[value, nextOffset]`,
     * or `null` when `$buffer` doesn't hold a whole reply yet. Error replies
     * come back as `RespError` values, a null bulk/array as `null`.
     *
     * @return array{0: mixed, 1: int}|null
     */
    public static function parse(string $buffer, int $offset = 0): ?array
    {
        $end = strpos($buffer, "\r\n", $offset);
        if ($end === false) {
            return null;
        }
        $type = $buffer[$offset] ?? '';
        $line = substr($buffer, $offset + 1, $end - $offset - 1);
        $next = $end + 2;

        switch ($type) {
            case '+':
                return [$line, $next];
            case '-':
                return [new RespError($line), $next];
            case ':':
                return [(int) $line, $next];
            case '$':
                $length = (int) $line;
                if ($length < 0) {
                    return [null, $next];
                }
                if (strlen($buffer) < $next + $length + 2) {
                    return null;
                }

                return [substr($buffer, $next, $length), $next + $length + 2];
            case '*':
                $count = (int) $line;
                if ($count < 0) {
                    return [null, $next];
                }
                $items = [];
                for ($i = 0; $i < $count; $i++) {
                    $item = self::parse($buffer, $next);
                    if ($item === null) {
                        return null;
                    }
                    [$items[], $next] = $item;
                }

                return [$items, $next];
        }

        throw new RedisException('Unexpected reply from Redis.');
    }
}

/** An error reply (`-ERR …`), as a value: in a pipeline one failed command shouldn't hide the others' replies. */
final class RespError
{
    /** @var string */
    public $message;

    public function __construct(string $message)
    {
        $this->message = $message;
    }
}

/**
 * Redis is unreachable or answered with an error. Deliberately not a
 * `RuntimeException`: the request processors show those messages to the
 * user, and this one belongs in the log, behind a generic 500.
 */
class RedisException extends Exception
{
}
