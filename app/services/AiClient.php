<?php
/** Thin server-side client for the Anthropic Messages API. The key never reaches the browser. */
final class AiClient {
    public int $in = 0, $out = 0;
    private string $model;
    public function __construct(string $model = '') { $this->model = $model !== '' ? $model : self::defaultModel(); }

    /** Platform settings win, then AI_MODEL in .env. */
    public static function defaultModel(): string { return (string)(Settings::get('ai_model', '') ?: env('AI_MODEL', 'claude-sonnet-5-5')); }
    public static function configured(): bool { return (string)env('AI_API_KEY', '') !== ''; }

    public function text(string $system, string $user, int $maxTokens = 4000): string {
        return $this->request($system, [['role' => 'user', 'content' => $user]], $maxTokens, 180, false);
    }

    /** Multi-turn chat: $messages = [['role'=>'user'|'assistant','content'=>'...'], ...] */
    public function chat(string $system, array $messages, int $maxTokens = 1500): string {
        try { return $this->request($system, $messages, $maxTokens, 120, false); }
        catch (RuntimeException $e) {
            $m = $e->getMessage();
            if (str_contains($m, 'not set')) throw new RuntimeException('PrepDeck AI is not connected yet. Ask your admin to add the AI key.');
            if (stripos($m, 'credit') !== false) throw new RuntimeException('PrepDeck AI is paused: the AI account is out of credit. Please tell your admin.');
            throw new RuntimeException(str_contains($m, 'busy') ? 'PrepDeck AI is busy. Try again in a moment.' : 'PrepDeck AI could not answer right now.');
        }
    }

    /** Read a PDF directly (works for scanned pages too) and return its text. Claude accepts PDFs up to 32 MB / 100 pages. */
    public function pdfText(string $path, int $maxPages = 80): string {
        $data = @file_get_contents($path);
        if ($data === false) throw new RuntimeException('The uploaded file could not be read.');
        if (strlen($data) > 30 * 1048576) throw new RuntimeException('This PDF is too large for AI reading (30 MB max). Upload a smaller file.');
        $content = [['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($data)]],
            ['type' => 'text', 'text' => "Transcribe all of the readable text in this PDF (at most the first $maxPages pages) as plain text, page by page, keeping question numbers, headings and lists. "
                . 'Describe any diagram or table briefly in [square brackets]. Output only the transcription.']];
        return $this->request('You transcribe documents accurately.', [['role' => 'user', 'content' => $content]], 16000, 300, false);
    }

    /** Ask for JSON, parse it, retry once with a correction if the model wrapped it in prose or broke it. */
    public function json(string $system, string $user, int $maxTokens = 4000): array {
        for ($try = 0; $try < 2; $try++) {
            $t = $this->text($system . "\nReturn ONLY valid JSON. No markdown fences, no commentary.", $user, $maxTokens);
            $t = trim(preg_replace('/^```(?:json)?|```$/m', '', $t));
            $t = self::extractJson($t);
            $j = json_decode($t, true);
            if (is_array($j)) return $j;
            $user .= "\n\n(Your previous reply was not valid JSON. Reply again with JSON only.)";
        }
        throw new RuntimeException('AI returned unreadable output.');
    }

    /** Admin connection test: [ok, message]. */
    public static function test(): array {
        if (!self::configured()) return [false, 'AI_API_KEY is empty in .env.'];
        try { $c = new self(); $c->text('Reply with the single word OK.', 'Connection test', 10); return [true, 'Connected. Model ' . $c->model . ' answered.']; }
        catch (Throwable $e) { return [false, JobQueue::friendlyError($e->getMessage()) . ' (' . $e->getMessage() . ')']; }
    }

    private function request(string $system, array $messages, int $maxTokens, int $timeout, bool $_ = false): string {
        $key = (string)env('AI_API_KEY', ''); if ($key === '') throw new RuntimeException('AI_API_KEY is not set in .env');
        $ch = curl_init(env('AI_API_URL', '') ?: 'https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['content-type: application/json', "x-api-key: $key", 'anthropic-version: 2023-06-01'],
            CURLOPT_POSTFIELDS => json_encode(['model' => $this->model, 'max_tokens' => $maxTokens, 'system' => $system, 'messages' => $messages])]);
        $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch);
        if ($raw === false) throw new RuntimeException('Could not reach the AI service (' . $cerr . '), will retry');
        $j = json_decode((string)$raw, true);
        if ($code === 429 || $code === 529 || $code >= 500) { sleep(5); throw new RuntimeException("AI busy ($code), will retry"); }
        $text = ''; foreach ($j['content'] ?? [] as $b) if (($b['type'] ?? '') === 'text') $text .= $b['text'];
        if ($code !== 200 || $text === '') throw new RuntimeException('AI error: ' . ($j['error']['message'] ?? "HTTP $code"));
        $this->in += (int)($j['usage']['input_tokens'] ?? 0); $this->out += (int)($j['usage']['output_tokens'] ?? 0);
        return $text;
    }

    private static function extractJson(string $t): string {
        $s = strcspn($t, '{['); $e = max((int)strrpos($t, '}'), (int)strrpos($t, ']'));
        return ($s < strlen($t) && $e > $s) ? substr($t, $s, $e - $s + 1) : $t;
    }
}
