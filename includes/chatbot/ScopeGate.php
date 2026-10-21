<?php

declare(strict_types=1);

/**
 * TUGON AI scope gate: refuse-by-default.
 *
 * ONE shared implementation used by every chat path (AiAssistantService::respond() is the only
 * place a chat message becomes a reply, and it calls this before anything else). The word lists
 * and the refusal text live in config/chatbot_scope.php.
 *
 * An off-topic message must never reach topic detection, knowledge search, caches, tools or the AI.
 */
final class TugonScopeGate
{
    private static ?array $cfg = null;
    private static bool $logTableReady = false;

    private const FIL_MARKERS = ['paano', 'pano', 'ano', 'anong', 'magluto', 'ng', 'mga', 'po', 'ba', 'kayo', 'ako', 'ko', 'mo', 'naman', 'nga', 'bakit', 'saan', 'kailan', 'magkano', 'gusto', 'pwede', 'puwede', 'ito', 'iyan', 'hindi', 'para', 'kumusta', 'salamat', 'sinigang'];
    private const CEB_MARKERS = ['unsaon', 'pagluto', 'ug', 'og', 'unsa', 'kanus', 'asa', 'nganong', 'naa', 'dili', 'kinsa', 'pila', 'tagpila', 'palihug', 'tinola', 'nako', 'kaayo', 'daghang', 'kabalo', 'ngano', 'karon'];

    public static function config(): array
    {
        if (self::$cfg === null) {
            self::$cfg = require __DIR__ . '/../../config/chatbot_scope.php';
        }
        return self::$cfg;
    }

    public static function version(): string
    {
        return (string) self::config()['version'];
    }

    /** Lowercase, accents removed, punctuation -> spaces (hyphens kept for reference numbers). */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(mb_substr($text, 0, 1000), 'UTF-8');
        if (class_exists('Normalizer')) {
            $decomposed = Normalizer::normalize($text, Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $text = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
            }
        } else {
            $text = strtr($text, ['ñ' => 'n', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
        }
        $text = (string) preg_replace("/['\x{2019}`]/u", '', $text);
        $text = (string) preg_replace('/[^a-z0-9\s-]+/u', ' ', $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** @return string[] */
    private static function tokens(string $norm): array
    {
        return $norm === '' ? [] : array_values(array_filter(preg_split('/[\s-]+/', $norm) ?: [], static fn($t) => $t !== ''));
    }

    public static function detectLanguage(string $norm): string
    {
        $fil = 0;
        $ceb = 0;
        foreach (self::tokens($norm) as $t) {
            if (in_array($t, self::FIL_MARKERS, true)) {
                $fil++;
            }
            if (in_array($t, self::CEB_MARKERS, true)) {
                $ceb++;
            }
        }
        if ($ceb > $fil && $ceb > 0) {
            return 'ceb';
        }
        return $fil > 0 ? 'fil' : 'en';
    }

    public static function refusal(string $lang): string
    {
        $m = self::config()['refusal'];
        return $m[$lang] ?? $m['en'];
    }

    public static function clarify(string $lang): string
    {
        $m = self::config()['clarify'];
        return $m[$lang] ?? $m['en'];
    }

    public static function isRefusal(string $text): bool
    {
        $needle = self::normalize($text);
        if ($needle === '') {
            return false;
        }
        foreach (self::config()['refusal'] as $msg) {
            if ($needle === self::normalize($msg) || str_starts_with($needle, mb_substr(self::normalize($msg), 0, 40))) {
                return true;
            }
        }
        if (str_contains($needle, 'only respond to tugon parish') || str_contains($needle, 'isnt related to this parish') || str_contains($needle, 'tungkol lamang sa tugon') || str_contains($needle, 'mga pangutana lang nga may kalabotan')) {
            return true;
        }
        return false;
    }

    /**
     * @param array<int,array<string,mixed>> $history previous turns: ['role'=>'user|assistant','content'=>string] (or ['in_scope'=>bool])
     * @return array{in_scope:bool,reason:string,language:string,normalized:string}
     */
    public static function check(string $message, array $history = []): array
    {
        return self::evaluate($message, $history, true);
    }

    public static function inScope(string $message, array $history = []): bool
    {
        return self::evaluate($message, $history, true)['in_scope'];
    }

    private static function evaluate(string $message, array $history, bool $allowFollowup): array
    {
        $cfg = self::config();
        $norm = self::normalize($message);
        $lang = self::detectLanguage($norm);
        $result = static fn(bool $ok, string $reason): array => ['in_scope' => $ok, 'reason' => $reason, 'language' => $lang, 'normalized' => $norm];

        // Empty / only punctuation / single character
        if (preg_match_all('/[a-z]/', $norm) < 2) {
            return $result(false, 'empty_or_meaningless');
        }

        $tokens = self::tokens($norm);
        $padded = ' ' . $norm . ' ';

        // 1. Blockers always win (even if a parish word is present)
        foreach ($cfg['block_phrases'] as $phrase) {
            if (str_contains($padded, ' ' . $phrase . ' ')) {
                return $result(false, 'blocked_phrase:' . $phrase);
            }
        }
        foreach ($tokens as $t) {
            if (in_array($t, $cfg['block_words'], true)) {
                return $result(false, 'blocked_word:' . $t);
            }
        }
        // Plain arithmetic like "25 x 4" or "12+7"
        if (preg_match('/\d+\s*[x*\/+]\s*\d+/', mb_strtolower($message, 'UTF-8'))) {
            return $result(false, 'arithmetic');
        }

        // 2. Reference numbers such as TUGON-2026-C3F31447 (must contain a digit)
        if (preg_match('/\b[a-z]{3,6}(?:-[a-z0-9]+){1,3}\b/', $norm, $m) && preg_match('/\d/', $m[0])) {
            return $result(true, 'reference_number');
        }

        // 3. Allowlist
        foreach ($cfg['allow'] as $kw) {
            if (str_contains($kw, ' ')) {
                if (str_contains($padded, ' ' . $kw . ' ')) {
                    return $result(true, 'allow:' . $kw);
                }
                continue;
            }
            foreach ($tokens as $t) {
                if ($t === $kw || (strlen($kw) >= 6 && str_starts_with($t, $kw))) {
                    return $result(true, 'allow:' . $kw);
                }
            }
        }

        // 4. Short greeting / thanks (every word must be a greeting word)
        if (count($tokens) <= 8 && $tokens !== [] && array_diff($tokens, $cfg['greeting_words']) === []) {
            return $result(true, 'greeting');
        }

        // 5. Short follow-up right after an in-scope turn
        if ($allowFollowup && strlen($norm) <= (int) $cfg['followup_max_chars'] && $history !== []
            && array_intersect($tokens, $cfg['followup_words']) !== []
            && self::lastWasInScope($history, $message)) {
            return $result(true, 'followup');
        }

        return $result(false, 'no_parish_keyword');
    }

    private static function lastWasInScope(array $history, string $current): bool
    {
        $h = array_values(array_filter($history, 'is_array'));
        $n = count($h);
        // The widget already appends the current message to the history it sends; drop it.
        if ($n > 0 && ($h[$n - 1]['role'] ?? 'user') === 'user' && trim((string) ($h[$n - 1]['content'] ?? '')) === trim($current)) {
            array_pop($h);
        }
        if ($h === []) {
            return false;
        }
        $last = $h[count($h) - 1];
        if (isset($last['in_scope'])) {
            return (bool) $last['in_scope'];
        }
        $lastText = (string) ($last['content'] ?? '');
        if (self::isRefusal($lastText)) {
            return false;
        }
        if (($last['role'] ?? '') === 'assistant') {
            for ($i = count($h) - 2; $i >= 0; $i--) {
                if (($h[$i]['role'] ?? '') === 'user') {
                    return self::evaluate((string) ($h[$i]['content'] ?? ''), [], false)['in_scope'];
                }
            }
            return false; // only the welcome message so far
        }
        return self::evaluate($lastText, [], false)['in_scope'];
    }

    /** Request-id trace, enabled with the environment variable TUGON_AI_TRACE=1 (off by default). */
    public static function trace(string $requestId, string $step, array $data = []): void
    {
        if (getenv('TUGON_AI_TRACE') === '1') {
            error_log('[TUGON-TRACE rid=' . $requestId . '] ' . $step . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    /** Review log: message, in_scope, topic, reply_source, created_at. Never breaks the chat if the table is unavailable. */
    public static function log(mysqli $db, string $requestId, int $userId, string $message, bool $inScope, string $topic, string $replySource): void
    {
        try {
            if (!self::$logTableReady) {
                $db->query("CREATE TABLE IF NOT EXISTS ai_scope_log (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    request_id VARCHAR(64) NOT NULL,
                    user_id INT NULL,
                    message VARCHAR(500) NOT NULL,
                    in_scope TINYINT(1) NOT NULL,
                    topic VARCHAR(40) NOT NULL,
                    reply_source VARCHAR(80) NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_scope_created (created_at),
                    KEY idx_scope_in (in_scope)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                self::$logTableReady = true;
            }
            $msg = function_exists('tugonRedactSensitive') ? tugonRedactSensitive($message) : $message;
            $msg = mb_substr($msg, 0, 500);
            $uid = $userId > 0 ? $userId : null;
            $flag = $inScope ? 1 : 0;
            $stmt = $db->prepare('INSERT INTO ai_scope_log(request_id, user_id, message, in_scope, topic, reply_source) VALUES(?,?,?,?,?,?)');
            if ($stmt) {
                $stmt->bind_param('sisiss', $requestId, $uid, $msg, $flag, $topic, $replySource);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('[TUGON AI scope log warning] ' . $e->getMessage());
        }
    }
}
