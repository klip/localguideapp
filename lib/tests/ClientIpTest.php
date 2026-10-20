<?php

// Plain-PHP checks for `ClientIp`:
//   docker exec lemp-php php /var/www/localguideapp/lib/tests/ClientIpTest.php

require_once __DIR__ . '/../ClientIp.php';

putenv('TRUSTED_PROXIES'); // the default: loopback + private ranges
$failures = 0;
function same(string $name, string $expected, string $actual): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        echo "FAIL: {$name} — expected '{$expected}', got '{$actual}'\n";
    }
}

same('a direct public peer is the client', '203.0.113.7', ClientIp::resolve('203.0.113.7'));
same('a public peer can\'t claim another address', '203.0.113.7', ClientIp::resolve('203.0.113.7', '198.51.100.1'));
same('behind our proxy, the forwarded address', '198.51.100.1', ClientIp::resolve('172.18.0.4', '198.51.100.1'));
same('a made-up left-most hop is ignored', '198.51.100.1', ClientIp::resolve('172.18.0.4', '1.2.3.4, 198.51.100.1'));
same('two proxies of ours', '198.51.100.1', ClientIp::resolve('172.18.0.4', '198.51.100.1, 172.18.0.6'));
same('all private (development): the farthest hop', '192.168.65.1', ClientIp::resolve('172.18.0.4', '192.168.65.1, 172.18.0.6'));
same('no header: the peer', '172.18.0.4', ClientIp::resolve('172.18.0.4'));
same('ports and IPv4-mapped forms are cleaned', '198.51.100.1', ClientIp::resolve('::ffff:172.18.0.4', '198.51.100.1:5555'));
same('IPv6 client', '2001:db8::1', ClientIp::resolve('10.0.0.2', '[2001:db8::1]:443'));
same('garbage is dropped', '172.18.0.4', ClientIp::resolve('172.18.0.4', 'not-an-ip'));

putenv('TRUSTED_PROXIES=35.191.0.0/16,130.211.0.0/22');
same('a configured load balancer is trusted', '198.51.100.1', ClientIp::resolve('35.191.4.4', '198.51.100.1'));
same('…and a private peer no longer is', '10.0.0.2', ClientIp::resolve('10.0.0.2', '198.51.100.1'));
putenv('TRUSTED_PROXIES');

$checks = [ClientIp::isPrivate('10.1.2.3'), ClientIp::isPrivate('127.0.0.1'), !ClientIp::isPrivate('8.8.8.8')];
if (in_array(false, $checks, true)) {
    $failures++;
    echo "FAIL: isPrivate\n";
}

if ($failures > 0) {
    echo "{$failures} check(s) failed\n";
    exit(1);
}
echo "All ClientIp checks passed\n";
