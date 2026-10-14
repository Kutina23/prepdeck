<?php
// Make an account the platform owner (super admin), who manages every school.
//   php bin/platform-owner.php someone@example.com
// Migration 0004 already made the first admin the platform owner; use this to add another or to hand the platform over.
if (PHP_SAPI !== 'cli') exit('CLI only');
require __DIR__ . '/../app/bootstrap.php';
$email = strtolower(trim((string)($argv[1] ?? '')));
$u = $email !== '' ? row('SELECT id,full_name,role_id FROM users WHERE email=?', [$email]) : null;
if (!$u) { fwrite(STDERR, "Usage: php bin/platform-owner.php <email of an existing account>\n"); exit(1); }
q('UPDATE users SET role_id=5 WHERE id=?', [$u['id']]);
Audit::log("made {$email} a platform owner (CLI)", 'user', (int)$u['id']);
echo "{$u['full_name']} ({$email}) is now a platform owner. They sign in as usual and land on /platform/.\n";
