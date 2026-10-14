<?php
// Fast checks that need no database:  php tests/unit.php   (exit code 1 on any failure)
if (PHP_SAPI !== 'cli') exit('CLI only');
define('ROOT', dirname(__DIR__));
$GLOBALS['cfg'] = require ROOT . '/app/config/config.php';
foreach (['Validator', 'Settings'] as $c) require_once ROOT . "/app/core/$c.php";
foreach (['PdfText', 'AiClient', 'JobQueue', 'Mailer'] as $c) require_once ROOT . "/app/services/$c.php";
require_once ROOT . '/app/helpers.php';

$fail = 0; $pass = 0;
function check(string $name, bool $ok, string $detail = ''): void { global $fail, $pass; if ($ok) { $pass++; return; } $fail++; echo "FAIL  $name" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }

// --- email addresses: every valid form is accepted, broken ones are not
foreach (['user@bücher.de' => 'user@xn--bcher-kva.de', 'a@münchen.de' => 'a@xn--mnchen-3ya.de', 'x@例え.jp' => 'x@xn--r8jz45g.jp', 'x@пример.рф' => 'x@xn--e1afmkfd.xn--p1ai',
          'first.last+tag@sub.example.co.uk' => 'first.last+tag@sub.example.co.uk', 'me@school.education' => 'me@school.education', "o'brien@ex.com" => "o'brien@ex.com", '  Ama@Example.COM ' => 'Ama@example.com'] as $in => $want)
    check("emailAscii($in)", Validator::emailAscii($in) === $want, var_export(Validator::emailAscii($in), true));
foreach (['plain', 'a@b', 'a@@b.com', 'a@-x.com', 'a@x..com', '.a@x.com', 'a@x.c0m', 'a b@x.com', str_repeat('a', 65) . '@x.com', 'a@' . str_repeat('x', 250) . '.com'] as $bad)
    check("rejects $bad", !Validator::email($bad));
check('typo gmial', Validator::emailTypo('john@gmial.com') === 'john@gmail.com');
check('typo transposed', Validator::emailTypo('john@gmail.cmo') === 'john@gmail.com');
check('no typo for real domain', Validator::emailTypo('john@gmail.com') === null && Validator::emailTypo('john@ug.edu.gh') === null);

// --- email rendering
$html = Mailer::layout('Reset your password', ['Hi Ama,', 'Line <b>two</b> & more'], ['Choose a new password', 'https://x.test/r?t=1&u=2']);
check('layout escapes text', str_contains($html, 'Line &lt;b&gt;two&lt;/b&gt; &amp; more'));
check('layout has button link', str_contains($html, 'href="https://x.test/r?t=1&amp;u=2"'));
$text = Mailer::toText($html);
check('text part starts cleanly', str_starts_with($text, 'PrepDeck'), substr($text, 0, 40));
check('text part keeps link', str_contains($text, 'Choose a new password [https://x.test/r?t=1&u=2]'));
check('text part has no template markup', !preg_match('/<(table|td|tr|a |p |div|span|h1)/i', $text));

// --- AI pipeline helpers
check('credit error is explained', str_contains(JobQueue::friendlyError('AI error: Your credit balance is too low to access the Anthropic API.'), 'out of credit'));
check('missing key is explained', str_contains(JobQueue::friendlyError('AI_API_KEY is not set in .env'), 'not connected'));
$chunks = PdfText::chunks(str_repeat("para one\n\n", 4000), 12000);
check('chunks stay under the limit', max(array_map('mb_strlen', $chunks)) <= 12000 + 20 && count($chunks) > 1);

echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
