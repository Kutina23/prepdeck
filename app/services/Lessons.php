<?php
/**
 * Lecturer tools: a course outline PDF becomes a list of topics, and each topic becomes a lesson note (PrepDeck Plus).
 * Rules are in site settings (Admin > Lecturer tools): lesson_tools_on, lesson_quota (notes a lecturer may generate per month, 0 = no limit),
 * outline_max_topics, outline_max_pages, lesson_autopublish (notes go straight to students), lesson_students_plus (students need Plus to read them).
 */
final class Lessons {
    const DEPTH = ['concise' => 'Concise (about 800 words)', 'standard' => 'Standard (about 1,500 words)', 'detailed' => 'Detailed (about 2,500 words)'];
    const WORDS = ['concise' => 800, 'standard' => 1500, 'detailed' => 2500];

    public static function enabled(): bool { return Settings::get('lesson_tools_on', '1') === '1'; }
    public static function quota(): int { return max(0, (int)Settings::get('lesson_quota', 60)); }
    /** Notes this person had PrepDeck AI write since the 1st of this month (a rewrite counts once per month). */
    public static function usedThisMonth(int $uid): int {
        return (int)val("SELECT COUNT(*) FROM lesson_notes WHERE user_id=? AND generated_at>=DATE_FORMAT(NOW(),'%Y-%m-01')", [$uid]);
    }
    /** Notes already waiting in the queue for this person, so a second click cannot go over the quota. */
    public static function queued(int $uid): int {
        return (int)val("SELECT COUNT(*) FROM outline_topics t JOIN course_outlines o ON o.id=t.outline_id WHERE o.user_id=? AND t.note_status IN ('queued','running')", [$uid]);
    }
    /** How many more notes this person may generate this month; null = no limit. */
    public static function left(int $uid): ?int {
        $q = self::quota(); return $q === 0 ? null : max(0, $q - self::usedThisMonth($uid) - self::queued($uid));
    }
    /** [allowed, reason]. Server-side gate used by every page and endpoint that starts AI work for a lecturer. */
    public static function canGenerate(array $u): array {
        if (!self::enabled()) return [false, 'Lesson note generation is switched off by the admin.'];
        if (!is_plus($u)) return [false, 'Generating lesson notes from a course outline is part of PrepDeck Plus.'];
        if (!AiClient::configured()) return [false, 'PrepDeck AI is not connected yet. Ask the admin to add the AI key.'];
        return [true, ''];
    }
    /** Course ids the person can use (lecturer: assigned courses; admin: all). */
    public static function courseOk(int $courseId, ?array $ids): bool { return $ids === null || in_array($courseId, $ids, true); }

    /** Queue a job that writes notes for $topicIds. A new outline is read first; $all = also write notes for its first $all topics. Returns the job id. */
    public static function enqueue(array $outline, array $topicIds, int $owner, int $all = 0): int {
        $topicIds = array_values(array_unique(array_map('intval', $topicIds)));
        if ($topicIds) q('UPDATE outline_topics SET note_status=\'queued\' WHERE outline_id=? AND id IN (' . implode(',', $topicIds) . ')', [$outline['id']]);
        db()->prepare('INSERT INTO processing_jobs(school_id,source_type,source_id,owner_id,file_path,requested_kind,events,stats,options) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$outline['school_id'] ?? sid(), 'outline', $outline['id'], $owner, $outline['file_path'], 'lecture_notes',
                json_encode([['t' => time(), 'stage' => 'queued', 'msg' => $topicIds ? 'Queued ' . count($topicIds) . ' lesson note' . (count($topicIds) === 1 ? '' : 's') : 'Outline received. Waiting for PrepDeck AI to start']]), '{}',
                json_encode(['topics' => $topicIds, 'all' => $all])]);
        $id = (int)db()->lastInsertId();
        JobQueue::setSourceStatus('outline', (int)$outline['id'], 'queued');
        JobQueue::kick();
        return $id;
    }

    /** Remove an outline, its file, its topics and (optionally) the notes made from it. */
    public static function deleteOutline(int $id, bool $withNotes): void {
        $o = row('SELECT file_path FROM course_outlines WHERE id=?', [$id]); if (!$o) return;
        if ($withNotes) q('DELETE FROM lesson_notes WHERE outline_id=?', [$id]);
        q("DELETE FROM processing_jobs WHERE source_type='outline' AND source_id=?", [$id]);
        q('DELETE FROM course_outlines WHERE id=?', [$id]);
        @unlink(upload_path('outlines', $o['file_path']));
    }

    public static function publish(int $id, string $status): void {
        q("UPDATE lesson_notes SET status=?, published_at=IF(?='published', COALESCE(published_at,NOW()), published_at) WHERE id=?", [$status, $status, $id]);
    }
    /** Students may read published notes; admins can make it a Plus perk for students. */
    public static function studentsCanRead(array $u): bool { return Settings::get('lesson_students_plus', '0') !== '1' || is_plus($u); }
}

/** Runs an 'outline' job: read the PDF -> list topics (first run only) -> write one lesson note per requested topic, reported live. */
final class OutlineProcessor {
    private AiClient $ai; private int $id;

    public function run(array $job): void {
        $this->id = $id = (int)$job['id']; $sid = (int)$job['source_id'];
        $o = row('SELECT o.*,c.code,c.title course,c.level FROM course_outlines o JOIN courses c ON c.id=o.course_id WHERE o.id=?', [$sid]);
        if (!$o) throw new RuntimeException('The course outline was deleted.');
        if (!AiClient::configured()) throw new RuntimeException('AI_API_KEY is not set in .env');
        $this->ai = new AiClient();
        JobQueue::setSourceStatus('outline', $sid, 'running');
        q('UPDATE processing_jobs SET stats=? WHERE id=?', ['{}', $id]);
        $opt = json_decode((string)($job['options'] ?? ''), true) ?: [];
        if (!(int)val('SELECT COUNT(*) FROM outline_topics WHERE outline_id=?', [$sid])) {
            $this->readOutline($o, $job);
            if (!empty($opt['all'])) q("UPDATE outline_topics SET note_status='queued' WHERE outline_id=? ORDER BY position LIMIT " . max(0, (int)$opt['all']), [$sid]);
        }
        // every topic still waiting on this outline (also picks up the rest after a retry)
        $topics = rows("SELECT * FROM outline_topics WHERE outline_id=? AND note_status IN ('queued','running') ORDER BY position", [$sid]);
        $this->write($o, $topics, (int)$job['owner_id']);

        $st = json_decode((string)val('SELECT stats FROM processing_jobs WHERE id=?', [$id]), true) ?: [];
        $done = 'All done. ' . (int)($st['topics'] ?? val('SELECT COUNT(*) FROM outline_topics WHERE outline_id=?', [$sid])) . ' topics'
            . (!empty($st['lessons']) ? ', ' . $st['lessons'] . ' lesson note' . ($st['lessons'] == 1 ? '' : 's') . (Settings::get('lesson_autopublish', '0') === '1' ? ' shared with students' : ' saved as drafts for you to check') : '');
        db()->prepare("UPDATE processing_jobs SET status='done', stage='done', progress=100, message=?, finished_at=NOW(), tokens_in=?, tokens_out=? WHERE id=?")
            ->execute([mb_substr($done, 0, 250), $this->ai->in, $this->ai->out, $id]);
        JobQueue::event($id, 'done', $done, ['tokens' => $this->ai->in + $this->ai->out], [], 'success');
        JobQueue::setSourceStatus('outline', $sid, 'done');
        Audit::log("AI processed course outline $sid", 'outline', $sid);
    }

    private function step(string $stage, int $pct, string $msg, array $stats = [], array $items = []): void { JobQueue::progress($this->id, $stage, $pct, $msg, $stats, $items); }

    private function readOutline(array $o, array $job): void {
        $this->step('reading', 6, 'Opening the course outline');
        $path = upload_path('outlines', $job['file_path']);
        $cap = max(1, (int)Settings::get('outline_max_pages', 30));
        $pages = PdfText::pages($path); $text = PdfText::extract($path, $cap);
        if (($pages ?? 0) <= 100 && mb_strlen($text) < 200) { $this->step('reading', 9, 'The outline looks scanned, so PrepDeck AI is reading it directly'); $ai = trim($this->ai->pdfText($path, $cap)); if (mb_strlen($ai) > mb_strlen($text)) $text = $ai; }
        if (mb_strlen($text) < 80) throw new RuntimeException('No readable text found in the outline. Upload a clearer PDF.');
        if ($pages) q('UPDATE course_outlines SET page_count=? WHERE id=?', [min($pages, 65535), $o['id']]);
        $this->step('reading', 12, 'Read ' . ($pages ? min($pages, $cap) . ' page' . ($pages === 1 ? '' : 's') . ', ' : '') . number_format(str_word_count($text)) . ' words', ['pages' => $pages ? min($pages, $cap) : null, 'words' => str_word_count($text)]);

        $this->step('outlining', 16, 'Finding the topics, weeks and learning objectives');
        $r = $this->ai->json(Prompts::OUTLINE_TOPICS, 'Course: ' . $o['code'] . ' ' . $o['course'] . "\n\n" . mb_substr($text, 0, 40000), 6000);
        $max = max(1, (int)Settings::get('outline_max_topics', 30));
        $topics = array_slice(array_values(array_filter($r['topics'] ?? [], fn($t) => is_array($t) && trim((string)($t['title'] ?? '')) !== '')), 0, $max);
        if (!$topics) throw new RuntimeException('No teaching topics were found in this outline.');
        $st = db()->prepare('INSERT INTO outline_topics(outline_id,position,week,title,subtopics,objectives) VALUES (?,?,?,?,?,?)');
        foreach ($topics as $i => $t)
            $st->execute([$o['id'], $i + 1, mb_substr(trim((string)($t['week'] ?? '')), 0, 40) ?: null, mb_substr(trim((string)$t['title']), 0, 190),
                implode("\n", array_map('strval', array_filter((array)($t['subtopics'] ?? []), 'is_scalar'))) ?: null,
                implode("\n", array_map('strval', array_filter((array)($t['objectives'] ?? []), 'is_scalar'))) ?: null]);
        if (!empty($r['summary'])) q('UPDATE course_outlines SET summary=? WHERE id=?', [mb_substr((string)$r['summary'], 0, 2000), $o['id']]);
        $this->step('outlining', 24, 'Found ' . count($topics) . ' topics', ['topics' => count($topics)], array_map(fn($t) => trim(($t['week'] ?? '') . ' ' . $t['title']), $topics));
    }

    private function write(array $o, array $topics, int $owner): void {
        $n = count($topics); if (!$n) return;
        $publish = Settings::get('lesson_autopublish', '0') === '1';
        $level = $o['level'] ? $o['level'] . '-level undergraduate' : 'undergraduate';
        $all = array_column(rows('SELECT title FROM outline_topics WHERE outline_id=? ORDER BY position', [$o['id']]), 'title');
        $made = 0;
        foreach ($topics as $i => $t) {
            $tid = (int)$t['id'];
            $this->step('writing', 26 + (int)(70 * $i / $n), 'Writing lesson ' . ($i + 1) . ' of ' . $n . ': ' . mb_strimwidth($t['title'], 0, 80, '…'));
            q("UPDATE outline_topics SET note_status='running' WHERE id=?", [$tid]);
            $before = $this->ai->in + $this->ai->out;
            try {
                $brief = "Course: {$o['code']} {$o['course']} ({$level})\nCourse topics in order: " . implode('; ', $all)
                    . "\n\nWrite the lesson note for: " . ($t['week'] ? $t['week'] . ' - ' : '') . $t['title']
                    . ($t['subtopics'] ? "\nSub-topics:\n- " . str_replace("\n", "\n- ", $t['subtopics']) : '')
                    . ($t['objectives'] ? "\nObjectives from the outline:\n- " . str_replace("\n", "\n- ", $t['objectives']) : '')
                    . "\nLength: about " . Lessons::WORDS[$o['depth']] . ' words.'
                    . ($o['instructions'] ? "\nThe lecturer's instructions: " . $o['instructions'] : '');
                $g = $this->ai->json(Prompts::LESSON_NOTE, $brief, $o['depth'] === 'detailed' ? 8000 : 5500);
                if (empty($g['body'])) throw new RuntimeException('The note came back empty.');
                $title = mb_substr(trim((string)($g['title'] ?? '')) ?: $t['title'], 0, 190); $body = (string)$g['body']; $words = str_word_count($body); $tok = $this->ai->in + $this->ai->out - $before;
                q('INSERT INTO lesson_notes(school_id,user_id,course_id,outline_id,topic_id,title,week,body,word_count,status,ai_generated,tokens,generated_at,published_at) VALUES (?,?,?,?,?,?,?,?,?,?,1,?,NOW(),?)
                   ON DUPLICATE KEY UPDATE title=VALUES(title), week=VALUES(week), body=VALUES(body), word_count=VALUES(word_count), tokens=tokens+VALUES(tokens), generated_at=NOW()',
                    [$o['school_id'], $owner, $o['course_id'], $o['id'], $tid, $title, $t['week'], $body, $words, $publish ? 'published' : 'draft', $tok, $publish ? gmdate('Y-m-d H:i:s') : null]);
                q("UPDATE outline_topics SET note_status='done' WHERE id=?", [$tid]); $made++;
                preg_match_all('/^##\s+(.+)$/m', $body, $h);
                $this->step('writing', 26 + (int)(70 * ($i + 1) / $n), 'Lesson ready: "' . mb_strimwidth($title, 0, 70, '…') . '" (' . number_format($words) . ' words)', ['lessons' => $made], array_slice($h[1], 0, 6));
            } catch (Throwable $e) {
                q("UPDATE outline_topics SET note_status='failed' WHERE id=?", [$tid]);
                if (str_contains($e->getMessage(), 'retry') || stripos($e->getMessage(), 'credit') !== false || str_contains($e->getMessage(), 'not set')) {   // the AI service itself is down: stop and let the job retry
                    q("UPDATE outline_topics SET note_status='queued' WHERE outline_id=? AND note_status IN ('queued','running','failed') AND id IN (" . implode(',', array_column(array_slice($topics, $i), 'id')) . ')', [$o['id']]);
                    throw $e;
                }
                JobQueue::event($this->id, 'writing', 'Could not write "' . mb_strimwidth($t['title'], 0, 60, '…') . '": ' . JobQueue::friendlyError($e->getMessage()) . '. You can try this topic again.', [], [], 'error');
            }
        }
        if (!$made) throw new RuntimeException('No lesson notes could be written. Try again in a few minutes.');
    }
}
