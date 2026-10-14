<?php
/**
 * Speech-to-text through any OpenAI-compatible endpoint (POST {STT_API_URL}/audio/transcriptions).
 * Works with OpenAI Whisper, Groq, a self-hosted faster-whisper server, etc.  .env: STT_API_URL, STT_API_KEY, STT_MODEL
 * Large files are cut into 10-minute pieces with ffmpeg (apt install ffmpeg) because providers cap upload size.
 */
final class Transcriber {
    /** @return array{text:string,confidence:?int,seconds:int} */
    public static function transcribe(string $file, string $language = 'auto'): array {
        $pieces = [$file]; $tmp = null;
        if (filesize($file) > 20 * 1048576 && PdfText::has('ffmpeg')) {
            $tmp = sys_get_temp_dir() . '/pd_' . bin2hex(random_bytes(4)); mkdir($tmp);
            self::run(['ffmpeg', '-v', 'error', '-i', $file, '-f', 'segment', '-segment_time', '600', '-ac', '1', '-ar', '16000', '-b:a', '48k', $tmp . '/p%03d.mp3']);
            $pieces = glob($tmp . '/p*.mp3') ?: [$file];
        }
        $text = '';
        foreach ($pieces as $p) $text .= trim(self::call($p, $language)) . "\n\n";
        if ($tmp) { array_map('unlink', glob($tmp . '/*')); rmdir($tmp); }
        return ['text' => trim($text), 'confidence' => null, 'seconds' => self::seconds($file)];
    }
    private static function call(string $file, string $lang): string {
        $url = rtrim((string)env('STT_API_URL', ''), '/'); $key = env('STT_API_KEY');
        if (!$url || !$key) throw new RuntimeException('Speech-to-text is not configured (STT_API_URL / STT_API_KEY in .env).');
        $post = ['file' => new CURLFile($file), 'model' => env('STT_MODEL', 'whisper-1'), 'response_format' => 'json'];
        if ($lang !== 'auto') $post['language'] = $lang;
        $ch = curl_init("$url/audio/transcriptions");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 600,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $key"], CURLOPT_POSTFIELDS => $post]);
        $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $j = json_decode((string)$raw, true);
        if ($code === 429 || $code >= 500) throw new RuntimeException("Speech service busy ($code), will retry");
        if ($code !== 200 || !isset($j['text'])) throw new RuntimeException('Transcription failed: ' . ($j['error']['message'] ?? "HTTP $code"));
        return $j['text'];
    }
    public static function seconds(string $file): int {
        if (!PdfText::has('ffprobe')) return 0;
        $o = self::run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=nw=1:nk=1', $file]);
        return (int)round((float)trim($o));
    }
    private static function run(array $cmd): string {
        $p = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes); if (!is_resource($p)) return '';
        $o = stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p); return (string)$o;
    }
}
