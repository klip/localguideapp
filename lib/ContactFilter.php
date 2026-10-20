<?php

/**
 * Keeps contact details — phone numbers, emails, links, social handles —
 * out of everything people write for each other. BeLikeLocal never shares
 * contact details: matched people plan their trip in `Messages` (chat with
 * location sharing), so a bio or message saying "WhatsApp me on 56002731"
 * would take the trip — and its protection — off the platform.
 *
 * Two ways to use it:
 *   - `assertClean()` — for text a person writes about themselves (profile
 *     fields, their name, a new interest or category): the save is refused
 *     with a `ContactDetailsException`, which both processors turn into a 400
 *     with `code: "contact_details"` so the client can say why in the
 *     viewer's language.
 *   - `mask()` — for text written *to* someone (reviews, a meeting place,
 *     a decline or cancellation reason): each match becomes `[•••]` and the
 *     rest is kept.
 *
 * Every hit is logged and, once `configure()` has been called for the
 * request, recorded in `contact_filter_hits` (028) — so repeat attempts show
 * up for moderation.
 *
 * Deliberately conservative about false positives: a phone number needs 7 or
 * more digits in a run (so prices, street numbers and party sizes pass), dates
 * and year ranges are ignored, and a messenger's name alone ("I love
 * Instagram photography") is fine — only a name followed by an id, or an
 * invitation to move there, counts. Map links are allowed: a meeting point is
 * not a contact detail.
 */
final class ContactFilter
{
    public const MASK = '[•••]';

    /** Spelled-out digits in the languages people most often write them in. */
    private const NUMBER_WORDS = [
        'zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'oh',
        'cero', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve',
        'zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'sept', 'huit', 'neuf',
        'null', 'eins', 'zwei', 'drei', 'vier', 'fünf', 'sechs', 'sieben', 'acht', 'neun',
        'due', 'tre', 'quattro', 'cinque', 'sei', 'sette', 'otto', 'nove',
        'um', 'dois', 'três', 'sete', 'oito',
    ];

    /**
     * Messengers whose name, followed by a separator and an id, is a handle.
     * `WEAK_MESSENGERS` are also ordinary words ("Signal: strong across the
     * Rock"), so they only count when the id looks like one (a digit, `_`,
     * an inner `.`, or a leading `@`).
     */
    private const MESSENGERS = 'whats\s*app|telegram|viber|we\s*chat|insta(?:gram)?|ig|snap(?:chat)?|face\s*book|fb|messenger|tik\s*tok|skype|discord|kakao(?:\s*talk)?|twitter';
    private const WEAK_MESSENGERS = 'signal|threads|line';

    /**
     * Top-level domains a bare `name.tld` is recognised by. Matched in
     * lowercase only (`ASP.NET`, "it.In summer" aren't links), and without the
     * ones that are everyday English words after a missing space (in, it, at,
     * us, be, is).
     */
    private const TLDS = 'com|net|org|edu|gov|info|biz|io|co|me|app|link|ly|gl|gg|tv|xyz|online|site|page|uk|es|fr|de|pt|gi|ru|ua|gr|tr|cn|jp|kr|th|ae|sa|eu|ca|au|nl|ch|pl|ie|br|mx|ar';

    /** Mail providers that give an address away even without a domain ("john at hotmail"). */
    private const EMAIL_PROVIDERS = 'gmail|googlemail|hotmail|yahoo|icloud|proton(?:mail)?|aol|yandex|gmx|msn';

    /** Map links stay: sharing where to meet is the point of a meeting place (and of the chat's location button). */
    private const ALLOWED_LINK = '~^(?:https?://)?(?:www\.)?(?:maps\.google\.[a-z.]+|google\.[a-z.]+/maps|maps\.app\.goo\.gl|goo\.gl/maps|maps\.apple\.com)(?:[/?#]|$)~i';

    /**
     * Number-like text that isn't a phone number, blanked out before the
     * phone pattern runs: dates, clock times, year lists and ranges, money
     * ("£1500-2000", "1500 €"), and thousands written with `.`/`,`/non-breaking
     * spaces whose first group has 1–2 digits ("1.500.000" — while
     * "600.123.456", a Spanish mobile, is still a phone).
     */
    private const NOT_PHONES = [
        // Coordinates ("36.1408, -5.3536"): a place, not a person.
        '/-?\p{Nd}{1,3}\.\p{Nd}{3,}\s*,\s*-?\p{Nd}{1,3}\.\p{Nd}{3,}/u',
        '/(?<!\p{Nd})(?:\p{Nd}{4}[-\/.]\p{Nd}{1,2}[-\/.]\p{Nd}{1,2}|\p{Nd}{1,2}[-\/.]\p{Nd}{1,2}[-\/.]\p{Nd}{2,4})(?!\p{Nd})/u',
        '/(?<!\p{Nd})\p{Nd}{1,2}[:h]\p{Nd}{2}(?!\p{Nd})/u',
        '/(?<!\p{Nd})(?:1[89]|20)\p{Nd}{2}(?:(?:\s*[-–—,\/&]\s*|\s+(?:and|y|et|und|e|to|a|bis)\s+|\s+)(?:(?:1[89]|20)\p{Nd}{2}|\p{Nd}{2}))+(?!\p{Nd})/u',
        '/[£€$]\s?\p{Nd}[\p{Nd}.,]*(?:\s?[-–—]\s?[£€$]?\s?\p{Nd}[\p{Nd}.,]*)?/u',
        '/(?<![\p{Nd}.,])\p{Nd}[\p{Nd}.,]*(?:\s?[-–—]\s?\p{Nd}[\p{Nd}.,]*)?\s?(?:€|£|\$|eur(?:os?)?\b|gbp\b|usd\b|pounds?\b|dollars?\b)/iu',
        '/(?<![\p{Nd}.,])\p{Nd}{1,2}(?:[.,\x{00A0}\x{202F}]\p{Nd}{3}){2,}(?!\p{Nd})/u',
    ];

    /** @var db|null */
    private static $db = null;
    /** @var int|null */
    private static $userId = null;

    /** Lets hits be recorded against the caller for the rest of this request. */
    public static function configure(?db $db, ?int $userId): void
    {
        self::$db = $db;
        self::$userId = $userId;
    }

    /**
     * Throws `ContactDetailsException` if `$text` holds contact details.
     * `$field` names the input it came from (`bio`, `name`, `attribute`, …)
     * so the client can point at it.
     */
    public static function assertClean(?string $text, string $field): void
    {
        $kinds = self::kindsIn((string) $text);
        if ($kinds) {
            self::record($field, $kinds, 'blocked');
            throw new ContactDetailsException($field);
        }
    }

    /**
     * `assertClean()` for several fields shown together, so a number can't be
     * split across them ("560" as one interest and "02731" as the next, or
     * half in the headline, half in the bio). `$texts` is `field => text`; the
     * error names the field the match starts in.
     */
    public static function assertCleanTogether(array $texts): void
    {
        $joined = '';
        $starts = [];
        foreach ($texts as $field => $text) {
            $text = (string) $text;
            if ($text === '') {
                continue;
            }
            $starts[(string) $field] = strlen($joined);
            $joined .= $text . "\n";
        }
        $spans = self::find($joined);
        if (!$spans) {
            return;
        }
        $field = (string) array_key_first($starts);
        foreach ($starts as $name => $start) {
            if ($start <= $spans[0][0]) {
                $field = $name;
            }
        }
        self::record($field, array_values(array_unique(array_column($spans, 2))), 'blocked');
        throw new ContactDetailsException($field);
    }

    /** `$text` with every contact detail replaced by `MASK`. */
    public static function mask(string $text, string $field): string
    {
        $spans = self::find($text);
        if (!$spans) {
            return $text;
        }
        self::record($field, array_values(array_unique(array_column($spans, 2))), 'masked');

        // Right to left, so earlier offsets stay valid.
        foreach (array_reverse($spans) as [$offset, $length]) {
            $text = substr_replace($text, self::MASK, $offset, $length);
        }

        return $text;
    }

    /** @return string[] the kinds of contact detail found (`phone`, `email`, `link`, `handle`, `invitation`, `unreadable`) */
    public static function kindsIn(string $text): array
    {
        return array_values(array_unique(array_column(self::find($text), 2)));
    }

    /**
     * Every contact detail in `$text`, as `[byteOffset, byteLength, kind]`,
     * sorted and with overlaps merged. Fails closed: text that isn't valid
     * UTF-8, or that a pattern can't finish matching, is one `unreadable` span
     * covering all of it.
     *
     * @return array<int, array{0: int, 1: int, 2: string}>
     */
    public static function find(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $everything = [[0, strlen($text), 'unreadable']];
        if (!mb_check_encoding($text, 'UTF-8')) {
            return $everything;
        }

        // Blank out allowed map links and number-like text that isn't a phone
        // (same byte length, so offsets still line up with `$text`).
        $work = preg_replace_callback(
            '~(?:https?://|www\.)[^\s<>"]+~i',
            static fn(array $m): string => preg_match(self::ALLOWED_LINK, $m[0]) ? str_repeat('x', strlen($m[0])) : $m[0],
            $text
        );
        if ($work === null) {
            return $everything;
        }
        foreach (self::NOT_PHONES as $pattern) {
            $work = preg_replace_callback($pattern, static fn(array $m): string => str_repeat('x', strlen($m[0])), $work);
            if ($work === null) {
                return $everything;
            }
        }

        $spans = [];
        foreach (self::patterns() as $kind => $pattern) {
            $found = preg_match_all($pattern, $work, $matches, PREG_OFFSET_CAPTURE);
            if ($found === false) {
                return $everything;
            }
            foreach ($found ? $matches[0] : [] as [$match, $offset]) {
                if ($kind === 'phone' && !self::isPhoneRun($match)) {
                    continue;
                }
                if ($kind === 'link' && preg_match(self::ALLOWED_LINK, $match)) {
                    continue;
                }
                $trimmed = ltrim($match);
                $offset += strlen($match) - strlen($trimmed);
                $match = rtrim($trimmed, " \t\n.,;:!?)(-");
                if ($match !== '') {
                    $spans[] = [$offset, strlen($match), $kind];
                }
            }
        }

        usort($spans, static fn(array $a, array $b): int => $a[0] <=> $b[0] ?: $b[1] <=> $a[1]);
        $merged = [];
        foreach ($spans as $span) {
            $last = count($merged) - 1;
            if ($last >= 0 && $span[0] <= $merged[$last][0] + $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $span[0] + $span[1] - $merged[$last][0]);
                continue;
            }
            $merged[] = $span;
        }

        return $merged;
    }

    /**
     * A run counts as a phone number with 7+ digits — but not across a spaced
     * dash or slash, which separates two numbers ("9 - 13 / 16 - 20") rather
     * than the parts of one.
     */
    private static function isPhoneRun(string $match): bool
    {
        $digit = '/\p{Nd}|(?<=\p{Nd})[oO]|[oO](?=\p{Nd})|' . self::numberWordPattern() . '/iu';
        foreach (preg_split('/\s[-–—\/]\s/u', $match) ?: [] as $piece) {
            if (preg_match_all($digit, $piece) >= 7) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> kind => regex, most specific first */
    private static function patterns(): array
    {
        static $patterns = null;
        if ($patterns !== null) {
            return $patterns;
        }

        $local = '[\p{L}\p{N}_%+\-](?:[\p{L}\p{N}_.%+\-]*[\p{L}\p{N}_%+\-])?';
        $label = '[\p{L}\p{N}](?:[\p{L}\p{N}\-]*[\p{L}\p{N}])?';
        // Obfuscated "at"/"dot": bracketed or spelled out. A plain ". " is not
        // a dot — "pickup at hotel. At 9" is a sentence, not an address.
        $at = '(?:\s*[(\[{<]\s*(?:at|arroba)\s*[)\]}>]\s*|\s+(?:at|arroba)\s+|\s*[@＠]\s+|\s+[@＠]\s*)';
        $dot = '(?:\s*[(\[{<]\s*(?:dot|punto|point|punkt)\s*[)\]}>]\s*|\s+(?:dot|punto|point|punkt)\s+|\.)';
        $tld = '(?-i:' . self::TLDS . ')(?![\p{L}\p{N}])';
        // An "O" next to a digit stands in for a zero ("560O2731").
        $token = '(?:\p{Nd}|(?<=\p{Nd})[oO]|[oO](?=\p{Nd})|' . self::numberWordPattern() . ')';
        // An id, as opposed to a word: a digit, `_`, or an inner `.`.
        $idLike = '@?(?=[\p{L}\p{N}_.]*(?:[_\p{Nd}]|\.[\p{L}\p{N}]))[\p{L}\p{N}_.+\-]{3,}';

        return $patterns = [
            'email' => '/(?<![\p{L}\p{N}_.%+\-])' . $local . '[@＠]' . $label . '(?:\.' . $label . ')+'
                . '|(?<![\p{L}\p{N}_.%+\-])' . $local . $at . '(?:' . self::EMAIL_PROVIDERS . ')(?!\p{L})(?:' . $dot . $label . ')*'
                . '|(?<![\p{L}\p{N}_.%+\-])' . $local . $at . $label . '(?:' . $dot . $label . ')*' . $dot . $tld . '/iu',
            'link' => '/(?:https?:\/\/|www\.)[^\s<>"]+'
                . '|(?<![\p{L}\p{N}@.\-])(?:' . $label . '\.)+' . $tld . '(?:\/[^\s<>"]*)?'
                . '|(?<!\p{L})(?:wa\.me|t\.me|m\.me|ig\.me)\/[^\s<>"]*/iu',
            'invitation' => '/\b(?:add|find|follow|message|msg|text|txt|call|ring|phone|contact|dm|ping|reach|write|hit)\s+me\s+(?:up\s+)?(?:on|at|via|through|in|by)\s+(?:' . self::MESSENGERS . '|' . self::WEAK_MESSENGERS . '|e-?mail|mail|sms|my\s+(?:phone|number|cell|mobile|email))\b'
                . '|\bmy\s+(?:phone(?:\s+number)?|number|cell(?:\s+phone)?|mobile(?:\s+number)?|' . self::MESSENGERS . '|e-?mail(?:\s+address)?)\s*(?:is\b|:)/iu',
            'handle' => '/(?<![\p{L}\p{N}_.@])@[\p{L}\p{N}_.]{3,}'
                . '|\b(?:' . self::MESSENGERS . ')\s*(?:id|handle|user(?:name)?|number|no\.?|num|nr|#)?\s*(?:[:=]|->|=>|\bis\b)\s*@?[\p{L}\p{N}_.+\-]{3,}'
                . '|\b(?:' . self::MESSENGERS . ')\s+' . $idLike
                . '|\b(?:' . self::WEAK_MESSENGERS . ')\s*(?:id|handle|user(?:name)?)?\s*(?:[:=]|->|=>|\bis\b)?\s*' . $idLike . '/iu',
            // Seven or more digits (or spelled-out digits), with only the
            // separators people put inside phone numbers between them.
            'phone' => '/(?<![\p{L}\p{N}])\+?\s*\(?\s*(?>' . $token . '(?:[\s.,\-–—_()\/\\\\*~|·•]{0,3}))+/iu',
        ];
    }

    private static function numberWordPattern(): string
    {
        static $pattern = null;

        // Russian, Greek, Arabic, … spelled-out digits aren't covered.
        return $pattern ??= '(?<!\p{L})(?:' . implode('|', array_map(
            static fn(string $w): string => preg_quote($w, '/'),
            self::NUMBER_WORDS
        )) . ')(?!\p{L})';
    }

    private static function record(string $field, array $kinds, string $outcome): void
    {
        Log::warning('Contact details ' . $outcome, ['field' => $field, 'kinds' => implode(',', $kinds)]);
        if (!self::$db || !self::$userId) {
            return;
        }
        // Recording is for moderation; it must never fail the save itself.
        $ok = self::$db->db_Insert('contact_filter_hits', [
            'user_id' => self::$userId,
            'field' => mb_substr($field, 0, 40),
            'kinds' => implode(',', $kinds),
            'outcome' => $outcome,
        ]);
        if (!$ok) {
            Log::warning('Could not record a contact filter hit', ['field' => $field]);
        }
    }
}

/**
 * Text that would publish contact details (see `ContactFilter::assertClean()`).
 * A `RuntimeException`, so anything that doesn't know about it still answers
 * a plain 400; the processors add `code`/`field` to the body.
 */
final class ContactDetailsException extends RuntimeException
{
    /** @var string */
    private $field;

    public function __construct(string $field)
    {
        parent::__construct('Contact details (phone numbers, emails, links or social media handles) can’t be shared on BeLikeLocal — matched people plan their trip in Messages.');
        $this->field = $field;
    }

    public function getField(): string
    {
        return $this->field;
    }

    /** The 400 body both processors send. */
    public function toBody(): array
    {
        return ['error' => $this->getMessage(), 'code' => 'contact_details', 'field' => $this->field];
    }
}
