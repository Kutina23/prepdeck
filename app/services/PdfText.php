<?php
/** Extracts text with poppler's pdftotext (Linux: apt install poppler-utils; Windows: winget install poppler, or set POPPLER_PATH in .env).
 *  Scanned PDFs fall back to ocrmypdf if installed; DocumentProcessor then falls back to reading the PDF with Claude. */
final class PdfText {
    public static function extract(string $pdf, int $maxPages = 80): string {
        if (!self::bin('pdftotext')) return '';
        $t = self::run([self::bin('pdftotext'), '-layout', '-enc', 'UTF-8', '-l', (string)$maxPages, $pdf, '-']);
        if (mb_strlen(trim($t)) < 200 && self::bin('ocrmypdf')) {          // looks scanned -> OCR then retry
            $tmp = tempnam(sys_get_temp_dir(), 'ocr') . '.pdf';
            self::run([self::bin('ocrmypdf'), '--skip-text', '--quiet', $pdf, $tmp]);
            if (is_file($tmp)) { $t = self::run([self::bin('pdftotext'), '-layout', '-enc', 'UTF-8', '-l', (string)$maxPages, $tmp, '-']); unlink($tmp); }
        }
        $t = preg_replace("/[ \t]+\n/", "\n", $t);
        return trim(preg_replace("/\n{3,}/", "\n\n", $t));
    }
    /** Page count via pdfinfo, else by counting page objects in the file; null if it cannot be read (callers must fail closed for limited plans). */
    public static function pages(string $pdf): ?int {
        if (self::bin('pdfinfo') && preg_match('/^Pages:\s+(\d+)/m', self::run([self::bin('pdfinfo'), $pdf]), $m)) return (int)$m[1];
        $raw = @file_get_contents($pdf); if ($raw === false) return null;
        if (preg_match_all('#/Type\s*/Page[^s]#', $raw, $mm) && count($mm[0]) > 0) return count($mm[0]);
        return null;
    }
    public static function has(string $bin): bool { return self::bin($bin) !== null; }

    /** Full path (or bare name) of a poppler/ocr tool, or null when it is not installed. Looks in POPPLER_PATH, PATH and the usual Windows install folders. */
    public static function bin(string $name): ?string {
        static $cache = [];
        if (array_key_exists($name, $cache)) return $cache[$name];
        $win = PHP_OS_FAMILY === 'Windows'; $exe = $name . ($win ? '.exe' : '');
        $dirs = array_filter([env('POPPLER_PATH', '')]);
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $d) if ($d !== '') $dirs[] = $d;
        if ($win) {
            $local = getenv('LOCALAPPDATA') ?: '';
            foreach (array_merge(
                $local ? glob($local . '/Microsoft/WinGet/Packages/*oppler*/*/Library/bin', GLOB_ONLYDIR) ?: [] : [],
                glob('C:/Users/*/AppData/Local/Microsoft/WinGet/Packages/*oppler*/*/Library/bin', GLOB_ONLYDIR) ?: [],
                glob('C:/Program Files/poppler*/Library/bin', GLOB_ONLYDIR) ?: [], glob('C:/poppler*/Library/bin', GLOB_ONLYDIR) ?: [],
                ['C:/Program Files/Git/mingw64/bin', 'C:/xampp/poppler/bin']) as $d) $dirs[] = $d;
        } else array_push($dirs, '/usr/bin', '/usr/local/bin', '/opt/homebrew/bin');
        foreach ($dirs as $d) { $p = rtrim($d, '/\\') . DIRECTORY_SEPARATOR . $exe; if (is_file($p)) return $cache[$name] = $p; }
        return $cache[$name] = null;
    }
    private static function run(array $cmd): string {      // array form = no shell, no injection through file names
        $p = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) return '';
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
        return (string)$out;
    }
    /** Split on paragraph boundaries into ~$size character chunks so long documents fit the model. */
    public static function chunks(string $text, int $size = 12000): array {
        $out = []; $cur = '';
        foreach (preg_split("/\n\n/", $text) as $para) {
            if (mb_strlen($cur) + mb_strlen($para) > $size && $cur !== '') { $out[] = $cur; $cur = ''; }
            while (mb_strlen($para) > $size) { $out[] = mb_substr($para, 0, $size); $para = mb_substr($para, $size); }
            $cur .= $para . "\n\n";
        }
        if (trim($cur) !== '') $out[] = $cur;
        return $out;
    }
}
