<?php
/**
 * Advanced study tools: spaced-repetition review (SM-2, the scheduler behind Anki), daily goal and streak,
 * the activity heatmap, exam readiness, and lecturer study tasks that tick themselves off.
 */
final class Study {
    public const AGAIN = 1, HARD = 2, GOOD = 3, EASY = 4;
    public const RATINGS = [1 => 'Again', 2 => 'Hard', 3 => 'Good', 4 => 'Easy'];
    /** New cards brought into review per day (on top of the cards that are due). */
    public const NEW_PER_DAY = 20;

    /**
     * Next schedule for a card. $p = current [ease, interval_days, reps, lapses] (a new card: 2.5, 0, 0, 0).
     * Returns the new values plus 'minutes' until it is due again. Again = 10 minutes; Hard on a card still being learnt = 6 hours
     * (it stays a learning card); otherwise whole days, always Hard < Good < Easy.
     */
    public static function schedule(array $p, int $rating): array {
        $ease = (float)($p['ease'] ?? 2.5); $iv = (int)($p['interval_days'] ?? 0); $reps = (int)($p['reps'] ?? 0); $lapses = (int)($p['lapses'] ?? 0);
        if ($rating <= self::AGAIN) return ['ease' => max(1.3, round($ease - 0.2, 2)), 'interval_days' => 0, 'reps' => 0, 'lapses' => $lapses + 1, 'minutes' => 10];
        if ($rating === self::HARD && $reps === 0) return ['ease' => max(1.3, round($ease - 0.15, 2)), 'interval_days' => 0, 'reps' => 0, 'lapses' => $lapses, 'minutes' => 360];
        // the three passing answers, worked out together so they never collide
        $good = $reps === 0 ? 1 : ($reps === 1 ? 3 : max($iv + 1, (int)round($iv * $ease)));
        $hard = $reps === 0 ? 0 : max($iv + 1, min($good - 1, (int)round($iv * 1.2)));
        if ($hard >= $good) $good = $hard + 1;
        $easy = max($good + 1, $reps === 0 ? 4 : (int)round(max(1, $iv) * ($ease + 0.15) * 1.3));
        if ($rating === self::HARD) { $ease = max(1.3, $ease - 0.15); $next = $hard; }
        elseif ($rating === self::GOOD) $next = $good;
        else { $ease += 0.15; $next = $easy; }
        $next = min(3650, $next);
        return ['ease' => round($ease, 2), 'interval_days' => $next, 'reps' => $reps + 1, 'lapses' => $lapses, 'minutes' => $next * 1440];
    }
    /** "10 min", "3 d", "2 mo" for the rating buttons. */
    public static function label(int $minutes): string {
        if ($minutes < 60) return $minutes . ' min';
        $d = intdiv($minutes, 1440);
        return $d < 30 ? $d . ' d' : ($d < 365 ? round($d / 30) . ' mo' : round($d / 365, 1) . ' y');
    }

    /** Decks this person may study in the current school (theirs, or shared and active). */
    private static function deckScope(string $d = 'd'): string { return "$d.status='active' AND " . in_school($d) . " AND ($d.owner_id=? OR $d.visibility='shared')"; }

    public static function dueCount(int $uid): int {
        return (int)val('SELECT COUNT(*) FROM card_progress p JOIN cards c ON c.id=p.card_id JOIN decks d ON d.id=c.deck_id WHERE p.user_id=? AND p.due_at<=NOW() AND p.last_reviewed_at IS NOT NULL AND ' . self::deckScope(), [$uid, $uid]);
    }
    public static function newToday(int $uid): int {
        // cards whose very first review was today
        return (int)val('SELECT COUNT(*) FROM (SELECT card_id FROM card_reviews WHERE user_id=? GROUP BY card_id HAVING MIN(reviewed_at)>=CURDATE()) x', [$uid]);
    }
    public static function reviewedToday(int $uid): int { return (int)val('SELECT COUNT(*) FROM card_reviews WHERE user_id=? AND reviewed_at>=CURDATE()', [$uid]); }

    /**
     * Cards for a review session: everything due first (most overdue first), then new cards up to the daily allowance.
     * New cards come from $deck when given, else from decks this person has already started.
     */
    public static function queue(int $uid, int $deck = 0, int $limit = 60, bool $answers = true): array {
        $due = rows('SELECT c.id,c.front,c.back,d.title deck,d.contains_answers,p.ease,p.interval_days,p.reps,p.lapses FROM card_progress p JOIN cards c ON c.id=p.card_id JOIN decks d ON d.id=c.deck_id
            WHERE p.user_id=? AND p.due_at<=NOW() AND p.last_reviewed_at IS NOT NULL AND ' . self::deckScope() . ($deck ? ' AND d.id=' . $deck : '') . ' ORDER BY p.due_at LIMIT ' . $limit, [$uid, $uid]);
        $room = min($limit - count($due), max(0, self::NEW_PER_DAY - self::newToday($uid)));
        $new = [];
        if ($room > 0) {
            $from = $deck ? 'd.id=' . $deck : 'd.id IN (SELECT DISTINCT c2.deck_id FROM card_progress p2 JOIN cards c2 ON c2.id=p2.card_id WHERE p2.user_id=' . $uid . ')';
            $new = rows('SELECT c.id,c.front,c.back,d.title deck,d.contains_answers,2.5 ease,0 interval_days,0 reps,0 lapses FROM cards c JOIN decks d ON d.id=c.deck_id
                WHERE ' . self::deckScope() . " AND $from AND c.id NOT IN (SELECT card_id FROM card_progress WHERE user_id=? AND last_reviewed_at IS NOT NULL) ORDER BY d.id,c.position,c.id LIMIT $room", [$uid, $uid]);
        }
        $out = [];
        foreach (array_merge($due, $new) as $c) {
            $hide = !$answers && (int)$c['contains_answers'] === 1;
            $next = []; foreach (self::RATINGS as $r => $_) $next[$r] = self::label(self::schedule($c, $r)['minutes']);
            $out[] = ['id' => (int)$c['id'], 'front' => $c['front'], 'back' => $hide ? null : $c['back'], 'deck' => $c['deck'], 'new' => (int)$c['reps'] === 0 && (float)$c['interval_days'] === 0.0 && !(int)$c['lapses'], 'next' => $next];
        }
        return $out;
    }

    /** Save one rating. False when the card is not one this person may study. */
    public static function rate(int $uid, int $cardId, int $rating): ?array {
        if ($rating < 1 || $rating > 4) return null;
        if (!val('SELECT COUNT(*) FROM cards c JOIN decks d ON d.id=c.deck_id WHERE c.id=? AND ' . self::deckScope(), [$cardId, $uid])) return null;
        $p = row('SELECT ease,interval_days,reps,lapses FROM card_progress WHERE user_id=? AND card_id=?', [$uid, $cardId]) ?? ['ease' => 2.5, 'interval_days' => 0, 'reps' => 0, 'lapses' => 0];
        $n = self::schedule($p, $rating);
        q('INSERT INTO card_progress(user_id,card_id,known,due_at,ease,interval_days,reps,lapses,last_reviewed_at) VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL ? MINUTE),?,?,?,?,NOW())
           ON DUPLICATE KEY UPDATE known=VALUES(known),due_at=VALUES(due_at),ease=VALUES(ease),interval_days=VALUES(interval_days),reps=VALUES(reps),lapses=VALUES(lapses),last_reviewed_at=NOW()',
           [$uid, $cardId, $rating >= self::GOOD ? 1 : 0, $n['minutes'], $n['ease'], $n['interval_days'], $n['reps'], $n['lapses']]);
        q('INSERT INTO card_reviews(user_id,card_id,rating,interval_days) VALUES (?,?,?,?)', [$uid, $cardId, $rating, $n['interval_days']]);
        return $n;
    }

    /* ---------- Learn and Test modes ---------- */
    /** Decks a student can Learn / Test (4+ cards), the ones they used last first, then their department's. */
    public static function studyDecks(array $u, string $q = '', int $limit = 60): array {
        $uid = (int)$u['id']; $dep = (int)$u['department_id']; $w = self::deckScope() . ' AND d.card_count>=4'; $a = [$uid];
        if ($q !== '') { $w .= ' AND (d.title LIKE ? OR c.code LIKE ?)'; array_push($a, "%$q%", "%$q%"); }
        return rows("SELECT d.id,d.title,d.card_count,d.contains_answers,c.code,
              (SELECT MAX(r.reviewed_at) FROM card_reviews r JOIN cards k ON k.id=r.card_id WHERE k.deck_id=d.id AND r.user_id=$uid) used,
              (SELECT ROUND(100*correct/NULLIF(total,0)) FROM deck_test_attempts t WHERE t.deck_id=d.id AND t.user_id=$uid AND t.mode='test' ORDER BY t.id DESC LIMIT 1) last_test
            FROM decks d LEFT JOIN courses c ON c.id=d.course_id WHERE $w
            ORDER BY used IS NULL, used DESC, (c.department_id=$dep) DESC, d.id DESC LIMIT $limit", $a);
    }
    /** May this person open the deck in Learn / Test? Returns the deck row or null. */
    public static function deck(int $uid, int $id): ?array {
        return $id ? row('SELECT d.*,c.code FROM decks d LEFT JOIN courses c ON c.id=d.course_id WHERE d.id=? AND ' . self::deckScope(), [$id, $uid]) : null;
    }

    /* ---------- activity: streak and heatmap ---------- */
    /** Activity per day (date => count) over the last $days days: card reviews, practice exams, papers opened, focus sessions, lessons read. */
    public static function activity(int $uid, int $days = 112): array {
        $from = gmdate('Y-m-d', time() - ($days - 1) * 86400); $out = [];
        for ($i = $days - 1; $i >= 0; $i--) $out[gmdate('Y-m-d', time() - $i * 86400)] = 0;
        $sql = 'SELECT d, COUNT(*) n FROM (
            SELECT DATE(reviewed_at) d FROM card_reviews WHERE user_id=? AND reviewed_at>=?
            UNION ALL SELECT DATE(created_at) FROM practice_attempts WHERE user_id=? AND created_at>=?
            UNION ALL SELECT DATE(viewed_at) FROM paper_views WHERE user_id=? AND viewed_at>=?
            UNION ALL SELECT DATE(started_at) FROM focus_sessions WHERE user_id=? AND started_at>=?
            UNION ALL SELECT DATE(read_at) FROM lesson_reads WHERE user_id=? AND read_at>=?
            UNION ALL SELECT DATE(created_at) FROM deck_test_attempts WHERE user_id=? AND created_at>=?) x GROUP BY d';
        foreach (rows($sql, array_merge(...array_fill(0, 6, [$uid, $from]))) as $r) if (isset($out[$r['d']])) $out[$r['d']] = (int)$r['n'];
        return $out;
    }
    /** Days in a row with any activity, ending today (or yesterday, so the streak is not lost before today's first study). */
    public static function streak(array $activity): int {
        $s = 0; $dates = array_reverse(array_keys($activity));
        if ($dates && !$activity[$dates[0]]) array_shift($dates);
        foreach ($dates as $d) { if (!$activity[$d]) break; $s++; }
        return $s;
    }
    public static function bestStreak(array $activity): int { $best = $run = 0; foreach ($activity as $n) { $run = $n ? $run + 1 : 0; $best = max($best, $run); } return $best; }

    /** GitHub-style calendar: 7 rows (Mon..Sun), one column per week, darker = more done. Every square has a hover title and the totals are in text. */
    public static function heatmap(array $activity): string {
        $max = max(1, max($activity ?: [0])); $first = array_key_first($activity);
        $pad = ((int)gmdate('N', strtotime($first)) - 1); $cells = array_merge(array_fill(0, $pad, null), array_keys($activity));
        $weeks = (int)ceil(count($cells) / 7); $sz = 13; $gap = 3; $W = $weeks * ($sz + $gap); $H = 7 * ($sz + $gap);
        $o = '<svg class="heat" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Study activity, last ' . count($activity) . ' days">';
        foreach ($cells as $i => $d) {
            if ($d === null) continue;
            $n = $activity[$d]; $lvl = $n ? min(4, 1 + (int)floor(3 * $n / $max)) : 0;
            $o .= '<rect class="h' . $lvl . '" x="' . (intdiv($i, 7) * ($sz + $gap)) . '" y="' . (($i % 7) * ($sz + $gap)) . '" width="' . $sz . '" height="' . $sz . '" rx="3"><title>' . e(gmdate('D j M', strtotime($d))) . ': ' . $n . ' activit' . ($n === 1 ? 'y' : 'ies') . '</title></rect>';
        }
        return $o . '</svg>';
    }

    /* ---------- exam readiness ---------- */
    /**
     * How ready this person is for one course, 0..100, from what they have done: cards mastered in the course's decks,
     * recent practice-exam scores on its papers, and how many of its papers they have opened. Parts with no material are left out.
     * Returns ['score' => int|null, 'parts' => [label => int]].
     */
    public static function readiness(int $uid, int $courseId): array {
        $parts = [];
        $cards = row("SELECT COALESCE(SUM(d.card_count),0) total, (SELECT COUNT(*) FROM card_progress p JOIN cards c ON c.id=p.card_id JOIN decks d2 ON d2.id=c.deck_id WHERE p.user_id=? AND d2.course_id=? AND d2.status='active' AND p.interval_days>=3) known
            FROM decks d WHERE d.course_id=? AND d.status='active' AND (d.visibility='shared' OR d.owner_id=?)", [$uid, $courseId, $courseId, $uid]);
        if ((int)$cards['total']) $parts['Flashcards mastered'] = min(100, (int)round(100 * (int)$cards['known'] / (int)$cards['total']));
        $pr = val("SELECT ROUND(100*SUM(a.correct)/NULLIF(SUM(a.total),0)) FROM practice_attempts a JOIN papers p ON a.source_type='paper' AND p.id=a.source_id WHERE a.user_id=? AND p.course_id=? AND a.created_at>=NOW()-INTERVAL 60 DAY", [$uid, $courseId]);
        $papers = (int)val("SELECT COUNT(*) FROM papers WHERE course_id=? AND status='published'", [$courseId]);
        if ($papers) {
            $parts['Practice score'] = $pr === null || $pr === false ? 0 : (int)$pr;
            $parts['Papers studied'] = min(100, (int)round(100 * (int)val('SELECT COUNT(DISTINCT v.paper_id) FROM paper_views v JOIN papers p ON p.id=v.paper_id WHERE v.user_id=? AND p.course_id=?', [$uid, $courseId]) / $papers));
        }
        $w = ['Flashcards mastered' => 0.35, 'Practice score' => 0.45, 'Papers studied' => 0.2]; $sum = 0; $tw = 0;
        foreach ($parts as $k => $v) { $sum += $v * $w[$k]; $tw += $w[$k]; }
        return ['score' => $tw ? (int)round($sum / $tw) : null, 'parts' => $parts];
    }

    /* ---------- lecturer study tasks ---------- */
    public const TASK_KINDS = ['deck' => 'Review a flashcard deck', 'paper' => 'Practise a past paper', 'lesson' => 'Read a lesson note', 'custom' => 'Something else'];
    /** SQL condition: students who take this course (its department, and its level when the course has one). */
    public static function courseStudentsSql(string $u = 'u'): string {
        return "$u.role_id=1 AND $u.status='active' AND $u.department_id=c.department_id AND (c.level IS NULL OR $u.level=CAST(c.level AS CHAR) COLLATE utf8mb4_unicode_ci)";
    }
    /** Active tasks for a student (their department's courses in the current school), newest due first, with done state. Ticks off any task the work already shows. */
    public static function tasksFor(array $u): array {
        $uid = (int)$u['id'];
        $tasks = rows("SELECT t.*,c.code,c.title course,us.full_name by_name,dn.done_at,dn.auto FROM study_tasks t JOIN courses c ON c.id=t.course_id JOIN users us ON us.id=t.created_by
            LEFT JOIN study_task_done dn ON dn.task_id=t.id AND dn.user_id=? WHERE t.status='active' AND " . in_school('t') . ' AND c.department_id=? AND (c.level IS NULL OR CAST(c.level AS CHAR)=?) AND t.due_on>=CURDATE()-INTERVAL 14 DAY ORDER BY dn.done_at IS NOT NULL, t.due_on, t.id', [$uid, (int)$u['department_id'], (string)$u['level']]);
        foreach ($tasks as &$t) if (!$t['done_at'] && self::taskShowsDone($t, $uid)) {
            q('INSERT IGNORE INTO study_task_done(task_id,user_id,auto) VALUES (?,?,1)', [$t['id'], $uid]); $t['done_at'] = gmdate('Y-m-d H:i:s'); $t['auto'] = 1;
        }
        unset($t);
        return $tasks;
    }
    /** Has the student done the work since the task was set? Deck: reviewed 80% of its cards. Paper: one practice exam. Lesson: read it. */
    public static function taskShowsDone(array $t, int $uid): bool {
        $id = (int)$t['target_id']; $since = $t['created_at'];
        return match ($t['kind']) {
            'deck' => (int)val('SELECT COUNT(DISTINCT r.card_id) FROM card_reviews r JOIN cards c ON c.id=r.card_id WHERE r.user_id=? AND c.deck_id=? AND r.reviewed_at>=?', [$uid, $id, $since]) >= max(1, (int)ceil(0.8 * (int)val('SELECT card_count FROM decks WHERE id=?', [$id]))),
            'paper' => (bool)val("SELECT COUNT(*) FROM practice_attempts WHERE user_id=? AND source_type='paper' AND source_id=? AND created_at>=?", [$uid, $id, $since]),
            'lesson' => (bool)val('SELECT COUNT(*) FROM lesson_reads WHERE user_id=? AND lesson_id=?', [$uid, $id]),
            default => false,
        };
    }
    /** Students who should do this task: [id, full_name, level, done_at, auto]. Ticks off anyone whose work already shows it first. */
    public static function taskStudents(array $t): array {
        $sql = 'SELECT u.id,u.full_name,u.level,dn.done_at,dn.auto FROM users u JOIN courses c ON c.id=? LEFT JOIN study_task_done dn ON dn.task_id=? AND dn.user_id=u.id WHERE u.school_id=? AND ' . self::courseStudentsSql() . ' ORDER BY u.full_name';
        $list = rows($sql, [$t['course_id'], $t['id'], $t['school_id']]);
        if ($t['kind'] !== 'custom') foreach ($list as &$s) if (!$s['done_at'] && self::taskShowsDone($t, (int)$s['id'])) {
            q('INSERT IGNORE INTO study_task_done(task_id,user_id,auto) VALUES (?,?,1)', [$t['id'], $s['id']]); $s['done_at'] = gmdate('Y-m-d H:i:s'); $s['auto'] = 1;
        }
        unset($s);
        return $list;
    }
    /** Where the student goes to do the task. */
    public static function taskLink(array $t): string {
        $id = (int)$t['target_id'];
        return match ($t['kind']) { 'deck' => url('dashboard/review.php?deck=' . $id), 'paper' => url('dashboard/practice.php?type=paper&id=' . $id), 'lesson' => url('dashboard/lessons.php?id=' . $id), default => '' };
    }
}
