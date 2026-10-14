<?php
// Database migrations. Runs every file in database/migrations/ (in name order) that has not run yet, and records it in schema_migrations.
//   php bin/migrate.php            apply pending migrations
//   php bin/migrate.php --status   list applied / pending
// Each migration is a PHP file returning function (PDO $db): void. Write them so they are safe to run twice, and only ADD things
// (new tables, nullable columns, new indexes) so the release that is still live keeps working while the new one deploys.
// Remove old columns in a later release, after no deployed code uses them.
if (PHP_SAPI !== 'cli') exit('CLI only');
require __DIR__ . '/../app/bootstrap.php';

/** Helpers available to migration files. */
function mig_has_table(string $t): bool { return (bool)val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$t]); }
function mig_has_column(string $t, string $c): bool { return (bool)val('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?', [$t, $c]); }
function mig_has_index(string $t, string $i): bool { return (bool)val('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?', [$t, $i]); }
function mig_add_column(string $t, string $c, string $def): void { if (mig_has_table($t) && !mig_has_column($t, $c)) db()->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def"); }

$db = db();
$db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name varchar(190) NOT NULL PRIMARY KEY, applied_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$done = array_flip(array_column(rows('SELECT name FROM schema_migrations'), 'name'));
$files = glob(ROOT . '/database/migrations/*.php') ?: []; sort($files);

if (in_array('--status', $argv, true)) {
    foreach ($files as $f) echo (isset($done[basename($f)]) ? '[x] ' : '[ ] ') . basename($f) . PHP_EOL;
    exit(0);
}
$n = 0;
foreach ($files as $f) {
    $name = basename($f); if (isset($done[$name])) continue;
    $fn = require $f;
    if (!is_callable($fn)) { fwrite(STDERR, "$name does not return a function\n"); exit(1); }
    echo "Applying $name ... ";
    try { $fn($db); }                                   // MySQL DDL commits on its own, so no wrapping transaction
    catch (Throwable $e) { fwrite(STDERR, "FAILED\n" . $e->getMessage() . PHP_EOL); exit(1); }
    q('INSERT INTO schema_migrations(name) VALUES (?)', [$name]); $n++;
    echo "ok\n";
}
echo $n ? "$n migration(s) applied.\n" : "Database is up to date.\n";
