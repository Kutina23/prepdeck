<?php
// XML sitemap of the public pages, for search engines.
require __DIR__ . '/../app/bootstrap.php';
$host = rtrim((string)env('APP_URL', ''), '/') ?: ((!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$urls = [[url(), 'weekly', '1.0'], [url('register.php'), 'monthly', '0.8'], [url('login.php'), 'monthly', '0.5']];
foreach (rows('SELECT slug,updated_at FROM site_pages ORDER BY slug') as $p) $urls[] = [url('page.php?p=' . $p['slug']), 'yearly', '0.3', $p['updated_at']];
// each active, listed school's landing page (?school=N) shows that school's library
foreach (rows('SELECT id,updated_at FROM schools WHERE is_active=1 AND is_listed=1 ORDER BY name') as $s) $urls[] = [url('?school=' . (int)$s['id']), 'weekly', 0.6, $s['updated_at'] ?? null];
header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) echo '  <url><loc>' . htmlspecialchars($host . $u[0], ENT_XML1) . '</loc>' . (!empty($u[3]) ? '<lastmod>' . gmdate('Y-m-d', strtotime($u[3])) . '</lastmod>' : '') . '<changefreq>' . $u[1] . '</changefreq><priority>' . $u[2] . "</priority></url>\n";
echo "</urlset>\n";
