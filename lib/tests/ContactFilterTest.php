<?php

// Plain-PHP checks for `ContactFilter` (there's no PHP test runner yet):
//   docker exec lemp-php php /var/www/localguideapp/lib/tests/ContactFilterTest.php
// Exits non-zero on the first failure count > 0.

require_once __DIR__ . '/../Log.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ContactFilter.php';

// Quiet: hits log a warning each.
Log::install('test');
ini_set('error_log', '/dev/null');

$blocked = [
    // phones
    'Call me on 56002731',
    '+350 56002731',
    '+44 (0) 7700 900 123',
    '077-009-001-23',
    '0 7 7 0 0 9 0 0 1 2 3',
    'five six zero zero two seven three one',
    'cinco seis cero cero dos siete tres uno',
    '５６００２７３１',
    '٥٦٠٠٢٧٣١',
    '5six0zero2seven31',
    // emails
    'john.smith@gmail.com',
    'john.smith @ gmail.com',
    'john at gmail dot com',
    'john(at)gmail(dot)com',
    'john [at] example [dot] org',
    'reach john at hotmail',
    // links
    'see https://example.org/me',
    'www.myguiding.es',
    'book me on myguiding.com',
    'wa.me/35056002731',
    't.me/johnny',
    // handles
    'follow @johnny_tours',
    'IG: johnny_tours',
    'Instagram is johnny.tours',
    'telegram id: johnny',
    // invitations
    'add me on whatsapp',
    'Text me via telegram please',
    'my number is below',
    'My Instagram: see above',
    // bypasses found in review
    'insta johnsmith_gib',
    'Telegram john.smith',
    '56,00,27,31',
    '5 6 0 0 , 2 7 3 1',
    '560O2731',
    'Spanish mobile 600.123.456',
    'Signal: @gina',
    'line id: gina_99',
    'john at outlook.com',
];

$allowed = [
    '',
    'Hi! I love history, hiking and Instagram photography.',
    'I have guided since 2015-2024 and speak 4 languages.',
    'Tours from 09:00 to 17:00, £50 per person, groups up to 12.',
    'Born 1990-05-21, lived at 221 Baker Street.',
    'We met at the station. Then went for lunch.',
    'Meet me at noon. See you!',
    'Price: €1,500,000 is not a phone number',
    'One of the best tours, two thumbs up!',
    'Meet at https://maps.google.com/?q=36.14,-5.35',
    'Meet here: https://maps.apple.com/?ll=36.14,-5.35',
    'https://maps.app.goo.gl/abc123',
    'e.g. the Rock, i.e. the top',
    'Line up at the cable car.',
    'Snapchat filters are fun',
    'Party of 4 adults and 2 kids, 3 hours',
    'Main St. 12, 2nd floor',
    'We meet at 36.1408, -5.3536 by the gate',
    // false positives found in review
    'pickup at hotel. At 9',
    'We arrived at Gibraltar. In the morning',
    'I grew up at sea. It was wonderful',
    'I work at live music venues',
    'I studied at uni. Co-founder of a tour company',
    'I love it.In summer we swim',
    'ASP.NET developer turned guide',
    'Opening hours 9 - 13 / 16 - 20',
    'Price £1500-2000 for groups',
    'Tours cost 1500 € per week',
    'In 2019 2020 2021 I guided 300 groups',
    'Over 1.500.000 visitors a year',
    'Signal: strong across the Rock',
    'line: queue at the cable car',
    'Threads of history everywhere',
    'Instagram photography tours.',
];

$failures = 0;
foreach ($blocked as $text) {
    if (!ContactFilter::kindsIn($text)) {
        echo "MISSED:  {$text}\n";
        $failures++;
    }
}
foreach ($allowed as $text) {
    $kinds = ContactFilter::kindsIn($text);
    if ($kinds) {
        echo "FALSE +: {$text}  (" . implode(',', $kinds) . ")\n";
        $failures++;
    }
}

$masked = [
    'Great guide! Call 56002731 or mail john@gmail.com.' => 'Great guide! Call [•••] or mail [•••].',
    'Meet at the café, WhatsApp: johnny99' => 'Meet at the café, [•••]',
    'Nothing to hide here.' => 'Nothing to hide here.',
    'Café «Ñandú» — tel. 5 6 0 0 2 7 3 1 ok' => 'Café «Ñandú» — tel. [•••] ok',
];
foreach ($masked as $in => $expected) {
    $out = ContactFilter::mask($in, 'test');
    if ($out !== $expected) {
        echo "MASK:    {$in}\n   got: {$out}\n  want: {$expected}\n";
        $failures++;
    }
}

try {
    ContactFilter::assertCleanTogether(['attribute:1' => '560', 'attribute:2' => '027', 'attribute:3' => '31']);
    echo "assertCleanTogether missed a split number\n";
    $failures++;
} catch (ContactDetailsException $e) {
    if ($e->getField() !== 'attribute:1') {
        echo 'assertCleanTogether named ' . $e->getField() . "\n";
        $failures++;
    }
}
try {
    ContactFilter::assertCleanTogether(['headline' => 'History walks', 'bio' => 'Born 1990-05-21, groups up to 12']);
} catch (ContactDetailsException $e) {
    echo "assertCleanTogether false positive\n";
    $failures++;
}
if (ContactFilter::kindsIn("bad \xff utf8") !== ['unreadable']) {
    echo "invalid UTF-8 not treated as unreadable\n";
    $failures++;
}

try {
    ContactFilter::assertClean('my email is a@b.co', 'bio');
    echo "assertClean did not throw\n";
    $failures++;
} catch (ContactDetailsException $e) {
    if ($e->toBody()['code'] !== 'contact_details' || $e->getField() !== 'bio') {
        echo "assertClean body wrong\n";
        $failures++;
    }
}

$total = count($blocked) + count($allowed) + count($masked) + 4;
echo $failures ? "{$failures} of {$total} checks failed\n" : "All {$total} checks passed\n";
exit($failures ? 1 : 0);
