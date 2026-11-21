<?php

namespace App\Services;

/**
 * Profanity and abusive-language filter backed by dsojevic/profanity-list (en.json).
 * Enforces whole-word boundaries, honors entry exceptions and culinary safe words
 * to avoid false positives (e.g. cumin, shiitake, parsley, cassava, casserole),
 * and checks both raw normalized text and leetspeak-decoded variants.
 */
class ProfanityFilterService {
    private const DICTIONARY_PATH = __DIR__ . '/../../config/profanity-en.json';

    /**
     * Culinary & common Vault terms that must never be flagged as false positives
     * (either directly or inside compound dish phrases).
     */
    private const CULINARY_SAFE_WORDS = [
        'cumin',
        'shiitake',
        'shitake',
        'casserole',
        'casseroles',
        'passata',
        'cassava',
        'spices',
        'spice',
        'cocktail',
        'cocktails',
        'butterscotch',
        'parsley',
        'scallion',
        'scallions',
        'horseradish',
        'coarse',
        'parse',
        'beanery',
        'sesame',
        'chickpea',
        'chickpeas',
        'cornstarch',
        'puttanesca',
        'cock-a-leekie',
        'spotted dick',
        'jerusalem',
        'japanese',
        'jalapeno',
        'molasses',
        'shells',
        'hello',
    ];

    /** @var array|null */
    private static ?array $compiledRules = null;

    /**
     * Loads and compiles config/profanity-en.json once per PHP worker process/request.
     */
    private function getCompiledRules(): array {
        if (self::$compiledRules !== null) {
            return self::$compiledRules;
        }

        if (!is_file(self::DICTIONARY_PATH)) {
            error_log('Profanity dictionary missing at ' . self::DICTIONARY_PATH);
            self::$compiledRules = [];
            return self::$compiledRules;
        }

        $raw = @file_get_contents(self::DICTIONARY_PATH);
        $entries = json_decode((string)$raw, true);
        if (!is_array($entries)) {
            error_log('Profanity dictionary failed to parse as JSON');
            self::$compiledRules = [];
            return self::$compiledRules;
        }

        $compiled = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || empty($entry['match'])) {
                continue;
            }

            $alts = array_values(array_filter(
                array_map('trim', explode('|', (string)$entry['match'])),
                fn($a) => $a !== ''
            ));
            if (empty($alts)) {
                continue;
            }

            $altPatterns = [];
            foreach ($alts as $alt) {
                $altPatterns[] = $this->compileMatchAlternative($alt);
            }

            $regex = '/(?<![a-z0-9])(?:' . implode('|', $altPatterns) . ')(?![a-z0-9])/iu';
            $exceptions = is_array($entry['exceptions'] ?? null)
                ? array_map('strtolower', $entry['exceptions'])
                : [];

            $compiled[] = [
                'id' => (string)($entry['id'] ?? 'profanity'),
                'severity' => (int)($entry['severity'] ?? 1),
                'tags' => is_array($entry['tags'] ?? null) ? $entry['tags'] : [],
                'regex' => $regex,
                'exceptions' => $exceptions,
            ];
        }

        self::$compiledRules = $compiled;
        return self::$compiledRules;
    }

    /**
     * Translates one dsojevic/profanity-list alternative (e.g. "bell*end" or "piss off")
     * into a PCRE fragment. Asterisks allow optional non-alphanumeric separators (e.g. spaces/hyphens),
     * preventing arbitrary letter wildcard expansion across innocent words.
     */
    private function compileMatchAlternative(string $alt): string {
        $segments = explode('*', strtolower($alt));
        $escapedSegments = array_map(function (string $seg): string {
            $quoted = preg_quote($seg, '/');
            // Allow spaces in multi-word phrases to match whitespace or hyphen separators
            return str_replace(' ', '[\s\-]+', $quoted);
        }, $segments);

        return implode('[^a-z0-9]*', $escapedSegments);
    }

    /**
     * Normalizes text for scanning: decodes HTML entities, removes invisible zero-width chars,
     * folds diacritics when intl/iconv is available, and optionally decodes common leetspeak.
     */
    private function normalizeForScan(string $text, bool $decodeLeet = false): string {
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Strip zero-width spaces / soft hyphens commonly used to bypass filters
        $decoded = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00AD}]/u', '', $decoded) ?? $decoded;

        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $decoded);
            if ($ascii !== false && $ascii !== '') {
                $decoded = $ascii;
            }
        }

        $lower = strtolower($decoded);
        if (!$decodeLeet) {
            return $lower;
        }

        return strtr($lower, [
            '@' => 'a',
            '4' => 'a',
            '3' => 'e',
            '1' => 'i',
            '!' => 'i',
            '0' => 'o',
            '$' => 's',
            '5' => 's',
            '7' => 't',
        ]);
    }

    /**
     * Masks known multi-word culinary safe phrases in lowercase scan strings before testing rules.
     */
    private function maskSafePhrases(string $normalized): string {
        foreach (self::CULINARY_SAFE_WORDS as $safe) {
            $pattern = '/(?<![a-z0-9])' . preg_quote($safe, '/') . '(?![a-z0-9])/i';
            $normalized = preg_replace($pattern, ' ', $normalized) ?? $normalized;
        }
        return $normalized;
    }

    /**
     * Extracts the full alphanumeric token surrounding a matched byte range to evaluate rule exceptions.
     */
    private function extractSurroundingToken(string $text, int $offset, int $matchLen): string {
        $start = $offset;
        while ($start > 0 && preg_match('/[a-z0-9]/i', $text[$start - 1])) {
            $start--;
        }
        $end = $offset + $matchLen;
        $len = strlen($text);
        while ($end < $len && preg_match('/[a-z0-9]/i', $text[$end])) {
            $end++;
        }
        return substr($text, $start, $end - $start);
    }

    /**
     * Checks whether a matched occurrence is whitelisted by the entry's `exceptions` or culinary list.
     */
    private function isExceptionMatch(string $matchedText, string $surroundingWord, array $exceptions): bool {
        $cleanMatch = preg_replace('/[^a-z0-9\-]/', '', strtolower($matchedText));
        if (in_array($cleanMatch, self::CULINARY_SAFE_WORDS, true) || in_array($surroundingWord, self::CULINARY_SAFE_WORDS, true)) {
            return true;
        }

        foreach ($exceptions as $exc) {
            $excPattern = strtolower(trim((string)$exc));
            if ($excPattern === '') {
                continue;
            }
            $expanded = str_replace('*', $cleanMatch, $excPattern);
            if ($surroundingWord === $expanded || $cleanMatch === $expanded) {
                return true;
            }
        }

        return false;
    }

    /**
     * Scans input text and returns metadata for the first disallowed match, or null if clean.
     */
    public function findMatch(string $text): ?array {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return null;
        }

        $rules = $this->getCompiledRules();
        if (empty($rules)) {
            return null;
        }

        $variants = [
            $this->maskSafePhrases($this->normalizeForScan($trimmed, false)),
            $this->maskSafePhrases($this->normalizeForScan($trimmed, true)),
        ];

        // Collapse 3+ isolated single letters separated by spaces/punctuation (e.g. "f u c k", "s-h-i-t")
        $collapsed = preg_replace_callback(
            '/(?<![a-z0-9])[a-z](?:[\s\.\-\*\_]+[a-z]){2,}(?![a-z0-9])/i',
            fn($m) => preg_replace('/[^a-z]+/i', '', $m[0]),
            $variants[count($variants) - 1]
        );
        if (is_string($collapsed) && $collapsed !== '' && !in_array($collapsed, $variants, true)) {
            $variants[] = $this->maskSafePhrases($collapsed);
        }

        // Deduplicate identical scan variants
        $variants = array_values(array_unique($variants));

        foreach ($variants as $scanText) {
            foreach ($rules as $rule) {
                $hitCount = @preg_match_all($rule['regex'], $scanText, $matches, PREG_OFFSET_CAPTURE);
                if (!$hitCount) {
                    continue;
                }

                foreach ($matches[0] as $hit) {
                    $matchedStr = (string)$hit[0];
                    $offset = (int)$hit[1];
                    $surrounding = $this->extractSurroundingToken($scanText, $offset, strlen($matchedStr));

                    if ($this->isExceptionMatch($matchedStr, $surrounding, $rule['exceptions'])) {
                        continue;
                    }

                    return [
                        'id' => $rule['id'],
                        'severity' => $rule['severity'],
                        'tags' => $rule['tags'],
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Returns true when text contains profanity or abusive language.
     */
    public function containsProfanity(string $text): bool {
        return $this->findMatch($text) !== null;
    }

    /**
     * Asserts that a single user-supplied field is clean; otherwise sends HTTP 400 and exits.
     */
    public function assertClean(string $text, string $fieldLabel = 'text'): void {
        if ($this->findMatch($text) !== null) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => "Please keep The Veggie Vault welcoming — remove inappropriate language from your {$fieldLabel}.",
            ]);
            exit;
        }
    }

    /**
     * Asserts multiple ['label' => $value] fields are clean, stopping on the first violation.
     */
    public function assertFieldsClean(array $labeledFields): void {
        foreach ($labeledFields as $label => $value) {
            $str = trim((string)$value);
            if ($str !== '') {
                $this->assertClean($str, (string)$label);
            }
        }
    }
}
