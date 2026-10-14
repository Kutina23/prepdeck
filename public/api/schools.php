<?php
// Public school directory: search (landing page + register autocomplete), a school's departments, logos,
// "my school isn't listed" requests, and picking a school to browse as a visitor.
require __DIR__ . '/../../app/bootstrap.php';
$act = $_GET['a'] ?? '';
$label = fn(array $s) => ['id' => (int)$s['id'], 'name' => $s['name'], 'short' => $s['short_name'], 'region' => $s['region'], 'city' => $s['city'], 'type' => Tenant::TYPES[$s['type']] ?? $s['type'], 'slug' => $s['slug']];

if ($act === 'search') {
    $q = trim((string)($_GET['q'] ?? '')); $region = (string)($_GET['region'] ?? ''); $type = (string)($_GET['type'] ?? '');
    $w = ['is_active=1', 'is_listed=1']; $p = [];
    if ($q !== '') { $w[] = '(name LIKE ? OR short_name LIKE ? OR city LIKE ?)'; array_push($p, "%$q%", "$q%", "$q%"); }
    if (in_array($region, Tenant::REGIONS, true)) { $w[] = 'region=?'; $p[] = $region; }
    if (isset(Tenant::TYPES[$type])) { $w[] = 'type=?'; $p[] = $type; }
    $order = $q !== '' ? 'ORDER BY (short_name=?) DESC, (name LIKE ?) DESC, name' : 'ORDER BY name';
    if ($q !== '') array_push($p, $q, "$q%");
    $limit = min(200, max(1, (int)($_GET['limit'] ?? 30)));
    Response::ok(['schools' => array_map($label, rows('SELECT id,slug,name,short_name,type,region,city FROM schools WHERE ' . implode(' AND ', $w) . " $order LIMIT $limit", $p))]);
}
if ($act === 'departments') {
    $s = (int)($_GET['school'] ?? 0);
    if (!val('SELECT COUNT(*) FROM schools WHERE id=? AND is_active=1', [$s])) Response::error('Choose your school first.', 404);
    Response::ok(['departments' => rows('SELECT id,name FROM departments WHERE school_id=? AND is_active=1 ORDER BY name', [$s])]);
}
if ($act === 'logo') {
    $s = row('SELECT logo FROM schools WHERE id=?', [(int)($_GET['id'] ?? 0)]);
    $f = $s && $s['logo'] ? upload_path('logos', $s['logo']) : '';
    if (!$f || !is_file($f)) { http_response_code(404); exit; }
    $type = (new finfo(FILEINFO_MIME_TYPE))->file($f);
    if (!in_array($type, ['image/png', 'image/jpeg', 'image/webp'], true)) { http_response_code(404); exit; }   // never serve SVG/HTML from uploads
    header('Content-Type: ' . $type); header('Cache-Control: public, max-age=86400'); header('X-Content-Type-Options: nosniff');
    readfile($f); exit;
}

require_post();
$b = Response::body();
if ($act === 'visit') {             // a visitor picked a school on the landing page: show that school's library
    if (!Tenant::visit((int)($b['school_id'] ?? 0))) Response::error('That school is not available.', 404);
    Response::ok(['redirect' => url('?school=' . (int)$b['school_id'] . '#library')]);
}
if ($act === 'request') {           // "my school isn't listed"
    if (Settings::global('school_requests_open', '1') !== '1') Response::error('School requests are closed right now. Please contact us instead.', 403);
    $_SESSION['sreq'] = array_values(array_filter($_SESSION['sreq'] ?? [], fn($t) => $t > time() - 3600));
    if (count($_SESSION['sreq']) >= 3 || Throttle::over('school-request-ip:' . client_ip(), 5, 60)) Response::error('You have sent a few requests already. We will be in touch.', 429);
    $name = Validator::str($b['name'] ?? '', 3, 160); $email = strtolower(trim((string)($b['email'] ?? '')));
    if (!$name || !Validator::email($email)) Response::error('Enter the school name and your email address.');
    if (val('SELECT COUNT(*) FROM schools WHERE name=? AND is_active=1', [$name])) Response::error('That school is already listed. Search for it by name.');
    if (val("SELECT COUNT(*) FROM school_requests WHERE name=? AND status='pending'", [$name])) Response::ok(['message' => 'Someone already asked for this school. We will add it soon.']);
    q('INSERT INTO school_requests(name,type,region,city,requester_name,requester_email,message) VALUES (?,?,?,?,?,?,?)', [
        $name, isset(Tenant::TYPES[$b['type'] ?? '']) ? $b['type'] : null, in_array($b['region'] ?? '', Tenant::REGIONS, true) ? $b['region'] : null,
        mb_substr(trim((string)($b['city'] ?? '')), 0, 80) ?: null, mb_substr(trim((string)($b['requester'] ?? '')), 0, 120) ?: null, $email, mb_substr(trim((string)($b['message'] ?? '')), 0, 600) ?: null]);
    $_SESSION['sreq'][] = time();
    Response::ok(['message' => 'Thanks. We will add ' . $name . ' and email you at ' . $email . ' when it is ready.']);
}
Response::error('Unknown action.', 404);
