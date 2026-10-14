<?php

/**
 * Something with sockets of its own that `WebSocketServer` should wait on in
 * its one `stream_select()` loop — the gateway's outgoing HTTP requests
 * (`HttpClient`) and its Redis subscription (`RedisSubscriber`) — so nothing
 * in the gateway ever blocks.
 */
interface StreamWatcher
{
    /** @return resource[] sockets to wake on when readable */
    public function readStreams(): array;

    /** @return resource[] sockets to wake on when writable */
    public function writeStreams(): array;

    /** @param resource $stream */
    public function onReadable($stream): void;

    /** @param resource $stream */
    public function onWritable($stream): void;

    /** About once a second: timeouts, reconnects. */
    public function tick(): void;
}
