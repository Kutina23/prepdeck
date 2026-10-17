<?php
// PrepDeck Community API (dashboard/community.php + community.js). Every action except state / join / unlock needs the
// community to be unlocked with the account password in this session (Community::unlocked), and an unbanned profile.
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(true); $me = (int)$u['id']; $a = $_GET['a'] ?? '';
$reason = Community::blockedReason($u); $prof = Community::profile($me);

if ($a === 'state') Response::ok(['joined' => (bool)$prof, 'unlocked' => $prof && Community::unlocked($me), 'reason' => $reason]);
if ($reason) Response::error($reason, 403);

if ($a === 'join' || $a === 'unlock') {
    require_post(); $b = Response::body();
    if ($a === 'join') {
        if ($prof) Response::error('You already joined. Unlock the community with your password.', 409);
        $name = Validator::str($b['display_name'] ?? '', 2, 60); $bio = mb_substr(trim((string)($b['bio'] ?? '')), 0, 280);
        if (!$name) Response::error('Choose a display name of 2 to 60 characters.');
        if (empty($b['agree'])) Response::error('Please agree to the community guidelines.');
    }
    $r = Community::checkPassword($u, (string)($b['password'] ?? ''));
    if ($r === 'blocked') Response::error('Too many wrong passwords. Wait 15 minutes and try again.', 429);
    if ($r === 'bad') Response::error('That is not your PrepDeck password.', 401);
    if ($a === 'join') {
        q('INSERT INTO community_profiles(user_id,display_name,bio,discoverable) VALUES (?,?,?,?)', [$me, $name, $bio !== '' ? $bio : null, empty($b['hidden']) ? 1 : 0]);
        SecurityLog::record('community_join', 'info', $u); Audit::log('joined the community', 'user', $me);
    } else SecurityLog::record('community_unlock', 'info', $u);
    session_regenerate_id(true);
    Community::touch($me);
    Response::ok(['ok' => true, 'esid' => Community::esid()]);   // the browser tags its unlocked encryption keys with this session
}
if (!$prof) Response::error('Join the community first.', 403);
if (!Community::unlocked($me)) Response::error('The community is locked. Enter your password to open it again.', 423);
Community::touch($me);

$id = (int)($_GET['id'] ?? $_GET['with'] ?? 0);
/** The other person must be a community member who has not blocked you (and you have not blocked). */
function other_or_fail(int $me, int $id): array {
    $p = $id && $id !== $me ? Community::person($me, $id) : null;
    if (!$p) Response::error('That person is not available.', 404);
    return $p;
}

/* ---------- reading ---------- */
if ($a === 'me') {
    Response::ok(['me' => Community::card(row(Community::PEOPLE . ' WHERE p.user_id=?', [$me])) + ['discoverable' => (bool)$prof['discoverable']]]);
}
if ($a === 'inbox') {
    $friends = Community::friendIds($me); $threads = [];
    if ($friends) {
        $in = implode(',', $friends);
        $people = rows(Community::PEOPLE . " WHERE p.user_id IN ($in) AND p.banned_at IS NULL AND " . Community::notBlocked('p.user_id', $me));
        $last = []; foreach (rows("SELECT m.* FROM messages m JOIN (SELECT IF(sender_id=?,recipient_id,sender_id) o, MAX(id) mx FROM messages WHERE ((sender_id=? AND hidden_for_sender=0) OR (recipient_id=? AND hidden_for_recipient=0)) GROUP BY o) t ON t.mx=m.id", [$me, $me, $me]) as $m) $last[(int)($m['sender_id'] == $me ? $m['recipient_id'] : $m['sender_id'])] = $m;
        $unread = []; foreach (rows('SELECT sender_id,COUNT(*) n FROM messages WHERE recipient_id=? AND read_at IS NULL AND hidden_for_recipient=0 GROUP BY sender_id', [$me]) as $r) $unread[(int)$r['sender_id']] = (int)$r['n'];
        foreach ($people as $p) { $c = Community::card($p); $m = $last[$c['id']] ?? null;
            $threads[] = $c + ['last' => $m ? ['body' => $m['kind'] === 'file' ? 'Encrypted file' : mb_strimwidth($m['body'], 0, 90, '…'), 'file' => $m['kind'] === 'file', 'mine' => (int)$m['sender_id'] === $me, 'at' => $m['created_at'], 'ago' => ago($m['created_at'])] : null, 'unread' => $unread[$c['id']] ?? 0, 'sort' => $m ? (int)$m['id'] : 0]; }
        usort($threads, fn($x, $y) => $y['sort'] <=> $x['sort'] ?: strcmp($x['name'], $y['name']));
    }
    Response::ok(['threads' => $threads, 'requests' => (int)val("SELECT COUNT(*) FROM friendships f JOIN community_profiles p ON p.user_id=f.requester_id AND p.banned_at IS NULL WHERE f.addressee_id=? AND f.status='pending' AND " . Community::notBlocked('f.requester_id', $me), [$me])]);
}
if ($a === 'requests') {
    $in = rows(Community::PEOPLE . " JOIN friendships f ON f.requester_id=p.user_id AND f.addressee_id=? AND f.status='pending' WHERE p.banned_at IS NULL AND " . Community::notBlocked('p.user_id', $me) . ' ORDER BY f.id DESC', [$me]);
    $out = rows(Community::PEOPLE . " JOIN friendships f ON f.addressee_id=p.user_id AND f.requester_id=? AND f.status='pending' WHERE p.banned_at IS NULL ORDER BY f.id DESC", [$me]);
    Response::ok(['incoming' => array_map([Community::class, 'card'], $in), 'outgoing' => array_map([Community::class, 'card'], $out)]);
}
if ($a === 'search') {
    $q = trim((string)($_GET['q'] ?? '')); $scope = (string)($_GET['scope'] ?? 'all');
    $w = ['p.discoverable=1', 'p.banned_at IS NULL', 'p.user_id<>' . $me, Community::notBlocked('p.user_id', $me)]; $args = [];
    if ($q !== '') { $w[] = 'p.display_name LIKE ?'; $args[] = '%' . addcslashes($q, '%_\\') . '%'; }
    if ($scope === 'school') { $w[] = 'u.school_id=?'; $args[] = (int)$u['school_id']; }
    elseif ($scope === 'others') { $w[] = '(u.school_id IS NULL OR u.school_id<>?)'; $args[] = (int)$u['school_id']; }
    elseif ($scope === 'dept') { $w[] = 'd.name=(SELECT name FROM departments WHERE id=?)'; $args[] = (int)$u['department_id']; }   // same field of study, any school
    $list = rows(Community::PEOPLE . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY p.last_seen_at IS NULL, p.last_seen_at DESC LIMIT 30', $args);
    Response::ok(['people' => array_map(fn($r) => Community::card($r) + ['relation' => Community::relation($me, (int)$r['user_id'])], $list)]);
}
if ($a === 'person') Response::ok(['person' => other_or_fail($me, $id)]);
if ($a === 'blocked') {
    Response::ok(['people' => array_map([Community::class, 'card'], rows(Community::PEOPLE . ' JOIN user_blocks b ON b.blocked_id=p.user_id AND b.user_id=? ORDER BY b.created_at DESC', [$me]))]);
}
if ($a === 'messages') {
    $p = other_or_fail($me, $id); $after = (int)($_GET['after'] ?? 0); $before = (int)($_GET['before'] ?? 0);
    $pair = '((m.sender_id=? AND m.recipient_id=? AND m.hidden_for_sender=0) OR (m.sender_id=? AND m.recipient_id=? AND m.hidden_for_recipient=0))'; $args = [$me, $id, $id, $me];
    if ($after) $list = rows("SELECT m.* FROM messages m WHERE $pair AND m.id>? ORDER BY m.id LIMIT 200", array_merge($args, [$after]));
    else $list = array_reverse(rows("SELECT m.* FROM messages m WHERE $pair" . ($before ? ' AND m.id<' . $before : '') . ' ORDER BY m.id DESC LIMIT 50', $args));
    q('UPDATE messages SET read_at=NOW() WHERE sender_id=? AND recipient_id=? AND read_at IS NULL', [$id, $me]);
    $seen = val('SELECT MAX(id) FROM messages WHERE sender_id=? AND recipient_id=? AND read_at IS NOT NULL', [$me, $id]);
    [$out, $keys] = Community::messagesOut($list, $me);
    Response::ok(['person' => $p, 'messages' => $out, 'keys' => $keys, 'seen_upto' => (int)$seen, 'more' => !$after && count($list) === 50]);
}
if ($a === 'unread') {   // light poll for the nav badge
    Response::ok(['messages' => (int)val('SELECT COUNT(*) FROM messages WHERE recipient_id=? AND read_at IS NULL AND hidden_for_recipient=0 AND ' . Community::notBlocked('sender_id', $me), [$me]),
        'requests' => (int)val("SELECT COUNT(*) FROM friendships WHERE addressee_id=? AND status='pending' AND " . Community::notBlocked('requester_id', $me), [$me])]);
}

/* ---------- end-to-end encryption: keys and files (all the cryptography happens in the browser) ---------- */
if ($a === 'keys') {   // my own key pairs: public key + private key wrapped with my password, for this browser to unwrap
    Response::ok(['keys' => array_map(fn($k) => ['id' => (int)$k['id'], 'public_key' => $k['public_key'], 'wrapped' => $k['wrapped_private'], 'salt' => $k['salt'], 'iterations' => (int)$k['iterations'], 'active' => (bool)$k['active']],
        rows('SELECT * FROM community_keys WHERE user_id=? ORDER BY id DESC LIMIT 20', [$me]))]);
}
if ($a === 'peerkey') {   // a friend's current public key, to encrypt a file for them
    other_or_fail($me, $id);
    $k = row('SELECT id,public_key FROM community_keys WHERE user_id=? AND active=1 ORDER BY id DESC LIMIT 1', [$id]);
    Response::ok(['key' => $k ? ['id' => (int)$k['id'], 'public_key' => $k['public_key']] : null]);
}
if ($a === 'file') {   // the encrypted bytes of a shared file, only for its sender and recipient. The server cannot read them.
    $f = row('SELECT f.*,m.hidden_for_sender,m.hidden_for_recipient FROM community_files f JOIN messages m ON m.file_id=f.id WHERE f.id=? AND (f.sender_id=? OR f.recipient_id=?)', [(int)($_GET['id'] ?? 0), $me, $me]);
    $path = $f ? Community::filePath($f['storage_name']) : '';
    if (!$f || ((int)$f['sender_id'] === $me ? $f['hidden_for_sender'] : $f['hidden_for_recipient']) || !is_file($path)) Response::error('File not found.', 404);
    if (Throttle::over("cm-dl:$me", 300, 10)) Response::error('Too many downloads. Wait a few minutes.', 429);
    header('Content-Type: application/octet-stream'); header('Content-Length: ' . filesize($path)); header('Content-Disposition: attachment; filename="encrypted.bin"');
    header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
    readfile($path); exit;
}

/* ---------- changes ---------- */
require_post(); $b = Response::body(); $id = (int)($b['id'] ?? $b['to'] ?? 0);
if ($a === 'keys_new') {   // a new key pair made in this browser (first time, or after choosing "start new keys")
    $k = Community::checkKeyFields($b, true); if (is_string($k)) Response::error($k);
    if (Throttle::over("cm-keys:$me", 10, 60)) Response::error('Too many key changes. Try again later.', 429);
    q('UPDATE community_keys SET active=0 WHERE user_id=?', [$me]);
    q('INSERT INTO community_keys(user_id,public_key,wrapped_private,salt,iterations) VALUES (?,?,?,?,?)', [$me, $k['public_key'], $k['wrapped'], $k['salt'], $k['iterations']]);
    $kid = (int)db()->lastInsertId();
    SecurityLog::record('community_keys', 'info', $u, null, 'new encryption keys made in the browser');
    Response::ok(['id' => $kid]);
}
if ($a === 'keys_rewrap') {   // after a password change: the same private keys, wrapped again with the new password
    $mine = array_map('intval', array_column(rows('SELECT id FROM community_keys WHERE user_id=?', [$me]), 'id')); $n = 0;
    foreach (array_slice((array)($b['keys'] ?? []), 0, 20) as $k) {
        $kid = (int)($k['id'] ?? 0); $c = Community::checkKeyFields((array)$k, false);
        if (!in_array($kid, $mine, true) || is_string($c)) continue;
        q('UPDATE community_keys SET wrapped_private=?,salt=?,iterations=? WHERE id=? AND user_id=?', [$c['wrapped'], $c['salt'], $c['iterations'], $kid, $me]); $n++;
    }
    Response::ok(['updated' => $n]);
}
if ($a === 'upload') {   // an already-encrypted file from the browser, for one friend
    other_or_fail($me, $id);
    if (!Community::areFriends($me, $id)) Response::error('You can share files with friends only.', 403);
    $up = $_FILES['file'] ?? null;
    if (!$up || $up['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'])) Response::error(($up['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($up['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE ? 'That file is too big.' : 'The upload did not arrive. Try again.');
    $size = (int)$up['size'];
    if ($size < 16 || $size > Community::MAX_FILE + 32) Response::error('Files can be up to ' . (Community::MAX_FILE >> 20) . ' MB.');
    if ((int)val('SELECT COALESCE(SUM(size),0) FROM community_files WHERE sender_id=? AND created_at>NOW()-INTERVAL 1 DAY', [$me]) + $size > Community::DAILY_BYTES) Response::error('You have shared a lot today. Try again tomorrow.', 429);
    if (Throttle::over("cm-up:$me", 30, 10)) Response::error('You are sharing files very fast. Wait a moment.', 429);
    $meta = (string)($b['meta'] ?? ''); $iv = (string)($b['file_iv'] ?? ''); $wk = (string)($b['wrapped_key'] ?? ''); $sha = strtolower((string)($b['sha256'] ?? ''));
    $sk = (int)($b['sender_key_id'] ?? 0); $rk = (int)($b['recipient_key_id'] ?? 0);
    if (!Community::b64($meta, 30, 2400) || !Community::b64($iv, 16, 16) || !Community::b64($wk, 60, 100) || !preg_match('/^[0-9a-f]{64}$/', $sha)) Response::error('The encrypted file was not packed correctly. Reload the page and try again.');
    if (!val('SELECT COUNT(*) FROM community_keys WHERE id=? AND user_id=? AND active=1', [$sk, $me]) || !val('SELECT COUNT(*) FROM community_keys WHERE id=? AND user_id=?', [$rk, $id])) Response::error('Encryption keys changed. Reload the page and try again.', 409);
    if (!hash_equals($sha, hash_file('sha256', $up['tmp_name']))) Response::error('The file was damaged on the way. Try again.');
    $name = bin2hex(random_bytes(20)); $dest = Community::filePath($name);
    if (!is_dir(dirname($dest))) @mkdir(dirname($dest), 0775, true);
    if (!move_uploaded_file($up['tmp_name'], $dest)) Response::error('Could not store the file. Try again.', 500);
    q('INSERT INTO community_files(sender_id,recipient_id,storage_name,size,meta,file_iv,wrapped_key,sender_key_id,recipient_key_id,sha256) VALUES (?,?,?,?,?,?,?,?,?,?)', [$me, $id, $name, $size, $meta, $iv, $wk, $sk, $rk, $sha]);
    $fid = (int)db()->lastInsertId();
    q("INSERT INTO messages(sender_id,recipient_id,body,kind,file_id) VALUES (?,?,'','file',?)", [$me, $id, $fid]); $mid = (int)db()->lastInsertId();
    [$out, $keys] = Community::messagesOut([row('SELECT * FROM messages WHERE id=?', [$mid])], $me);
    Response::ok(['message' => $out[0], 'keys' => $keys]);
}
if ($a === 'lock') { Community::lock(); Response::ok(); }
if ($a === 'profile') {
    $name = Validator::str($b['display_name'] ?? '', 2, 60); if (!$name) Response::error('Choose a display name of 2 to 60 characters.');
    q('UPDATE community_profiles SET display_name=?, bio=?, discoverable=? WHERE user_id=?', [$name, mb_substr(trim((string)($b['bio'] ?? '')), 0, 280) ?: null, empty($b['discoverable']) ? 0 : 1, $me]);
    Response::ok(['message' => 'Profile saved.']);
}
if ($a === 'request') {
    $p = other_or_fail($me, $id);
    if (Throttle::over("friend-req:$me", 40, 60)) Response::error('You have sent a lot of requests. Try again in an hour.', 429);
    if ($p['relation'] === 'received') { q("UPDATE friendships SET status='accepted',responded_at=NOW() WHERE requester_id=? AND addressee_id=?", [$id, $me]); Response::ok(['relation' => 'friends']); }
    if ($p['relation'] !== 'none') Response::ok(['relation' => $p['relation']]);
    // a declined request may be sent again after 7 days
    $old = row('SELECT id,status,responded_at FROM friendships WHERE requester_id=? AND addressee_id=?', [$me, $id]);
    if ($old && $old['status'] === 'declined' && strtotime($old['responded_at'] . ' UTC') > time() - 7 * 86400) Response::ok(['relation' => 'sent']);   // looks sent; they said no recently
    if ($old) q("UPDATE friendships SET status='pending',created_at=NOW(),responded_at=NULL WHERE id=?", [$old['id']]);
    else q('INSERT INTO friendships(requester_id,addressee_id) VALUES (?,?)', [$me, $id]);
    Response::ok(['relation' => 'sent']);
}
if ($a === 'respond') {
    $ok = !empty($b['accept']);
    $n = q("UPDATE friendships SET status=?,responded_at=NOW() WHERE requester_id=? AND addressee_id=? AND status='pending'", [$ok ? 'accepted' : 'declined', $id, $me])->rowCount();
    if (!$n) Response::error('That request is no longer waiting.', 404);
    Response::ok(['relation' => $ok ? 'friends' : 'none']);
}
if ($a === 'cancel') { q("DELETE FROM friendships WHERE requester_id=? AND addressee_id=? AND status='pending'", [$me, $id]); Response::ok(['relation' => 'none']); }
if ($a === 'unfriend') { q("DELETE FROM friendships WHERE ((requester_id=? AND addressee_id=?) OR (requester_id=? AND addressee_id=?)) AND status='accepted'", [$me, $id, $id, $me]); Response::ok(['relation' => 'none']); }
if ($a === 'block') {
    if (!$id || $id === $me || !Community::profile($id)) Response::error('That person is not available.', 404);
    q('INSERT IGNORE INTO user_blocks(user_id,blocked_id) VALUES (?,?)', [$me, $id]);
    q('DELETE FROM friendships WHERE (requester_id=? AND addressee_id=?) OR (requester_id=? AND addressee_id=?)', [$me, $id, $id, $me]);
    Response::ok(['message' => 'Blocked. You will not see each other in the community.']);
}
if ($a === 'unblock') { q('DELETE FROM user_blocks WHERE user_id=? AND blocked_id=?', [$me, $id]); Response::ok(['message' => 'Unblocked.']); }
if ($a === 'send') {
    other_or_fail($me, $id);
    if (!Community::areFriends($me, $id)) Response::error('You can message people once they accept your friend request.', 403);
    $body = trim(str_replace("\r\n", "\n", (string)($b['body'] ?? '')));
    if ($body === '') Response::error('Write a message first.');
    if (mb_strlen($body) > Community::MAX_MESSAGE) Response::error('Messages can be up to ' . Community::MAX_MESSAGE . ' characters.');
    if (Throttle::over("msg:$me", 30, 1)) Response::error('You are sending messages very fast. Wait a moment.', 429);
    q('INSERT INTO messages(sender_id,recipient_id,body) VALUES (?,?,?)', [$me, $id, $body]); $mid = (int)db()->lastInsertId();
    Response::ok(['message' => ['id' => $mid, 'mine' => true, 'body' => $body, 'at' => gmdate('Y-m-d\TH:i:s\Z')]]);
}
if ($a === 'hide') {   // delete a message for yourself only
    $mid = (int)($b['message_id'] ?? 0);
    q('UPDATE messages SET hidden_for_sender=IF(sender_id=?,1,hidden_for_sender), hidden_for_recipient=IF(recipient_id=?,1,hidden_for_recipient) WHERE id=? AND (sender_id=? OR recipient_id=?)', [$me, $me, $mid, $me, $me]);
    Response::ok();
}
if ($a === 'report') {
    if (!$id || $id === $me || !Community::profile($id)) Response::error('That person is not available.', 404);
    $why = Validator::str($b['reason'] ?? '', 5, 500); if (!$why) Response::error('Tell us briefly what happened (5 characters or more).');
    $mid = (int)($b['message_id'] ?? 0) ?: null;
    if ($mid && !val('SELECT COUNT(*) FROM messages WHERE id=? AND ((sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?))', [$mid, $id, $me, $me, $id])) $mid = null;
    if (Throttle::over("report:$me", 10, 60)) Response::error('Too many reports. Try again later.', 429);
    q('INSERT INTO community_reports(reporter_id,target_id,message_id,reason) VALUES (?,?,?,?)', [$me, $id, $mid, $why]);
    if (!empty($b['block'])) { q('INSERT IGNORE INTO user_blocks(user_id,blocked_id) VALUES (?,?)', [$me, $id]); q('DELETE FROM friendships WHERE (requester_id=? AND addressee_id=?) OR (requester_id=? AND addressee_id=?)', [$me, $id, $id, $me]); }
    Response::ok(['message' => 'Thank you. The PrepDeck team will look at this.' . (!empty($b['block']) ? ' You also blocked this person.' : '')]);
}
if ($a === 'leave') {
    if (Community::checkPassword($u, (string)($b['password'] ?? '')) !== 'ok') Response::error('That is not your PrepDeck password.', 401);
    q('DELETE FROM friendships WHERE requester_id=? OR addressee_id=?', [$me, $me]);
    q('UPDATE messages SET hidden_for_sender=1 WHERE sender_id=?', [$me]); q('UPDATE messages SET hidden_for_recipient=1 WHERE recipient_id=?', [$me]);
    q('DELETE FROM community_profiles WHERE user_id=?', [$me]); Community::lock();
    Audit::log('left the community', 'user', $me);
    Response::ok(['redirect' => url('dashboard/community.php')]);
}
Response::error('Unknown action.', 404);
