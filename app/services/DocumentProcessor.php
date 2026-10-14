<?php
/**
 * PDF / lecture audio -> everything a student needs, reported live to the uploader (JobQueue::progress -> api/jobs.php?a=stream).
 *   past_question : read -> find questions -> worked solutions -> flashcards -> revision guide -> games
 *   lecture_notes : read -> digest -> study guide -> predicted exam questions with answers -> key-term flashcards -> games
 * Generated rows are 'draft' unless site setting ai_autopublish=1, because AI answers can be wrong (a student's own notes are always theirs to use).
 */
final class DocumentProcessor {
    private AiClient $ai; private array $job; private bool $publish; private int $id; private string $type; private int $sid;

    public function run(array $job): void {
        $this->job = $job; $this->id = $id = (int)$job['id'];
        $this->ai = new AiClient();
        $this->publish = Settings::get('ai_autopublish', '0') === '1';
        $this->type = $type = $job['source_type']; $this->sid = $sid = (int)$job['source_id'];
        if (!AiClient::configured()) throw new RuntimeException('AI_API_KEY is not set in .env');
        JobQueue::setSourceStatus($type, $sid, 'running');
        q('UPDATE processing_jobs SET stats=? WHERE id=?', ['{}', $id]);

        if ($type === 'recording') {
            $text = $this->transcript($job, $sid);                                   // 1. audio -> text (skipped if a good transcript already exists)
        } else {                                                                     // 1. read the PDF
            $this->step('reading', 6, 'Opening the PDF and reading every page');
            $path = upload_path($type === 'paper' ? 'papers' : 'notes', $job['file_path']);
            $ownerPlus = is_plus(['id' => $job['owner_id']]);
            $pageCap = $ownerPlus || $type === 'paper' ? (int)Settings::get('ai_max_pages', 80) : (int)Settings::get('free_upload_pages', 1);
            $pages = PdfText::pages($path);
            $text = PdfText::extract($path, $pageCap);
            $read = $pages ? min($pages, $pageCap) : 1;
            if (($pages ?? 0) <= 100 && (mb_strlen($text) < 200 || str_word_count($text) / $read < 25)) {    // scanned pages (little text per page) or no poppler here: let Claude read the PDF itself
                $this->step('reading', 9, PdfText::has('pdftotext') ? 'Most pages are scanned images, so PrepDeck AI is reading them like a person would' : 'Reading the PDF with PrepDeck AI');
                $ai = trim($this->ai->pdfText($path, $pageCap));
                if (mb_strlen($ai) > mb_strlen($text)) $text = $ai;
            }
            if (mb_strlen($text) < 200) throw new RuntimeException('No readable text found. The scan may be too poor; upload a clearer PDF.');
            $words = str_word_count(mb_substr($text, 0, 200000));
            $this->step('reading', 12, 'Read ' . ($pages ? min($pages, $pageCap) . ' page' . ($pages === 1 ? '' : 's') . ', ' : '') . number_format($words) . ' words', ['pages' => $pages ? min($pages, $pageCap) : null, 'words' => $words]);
        }
        $text = mb_substr($text, 0, (int)Settings::get('ai_max_chars', 60000));
        $chunks = PdfText::chunks($text);

        // 2. what is it?
        $kind = $type === 'recording' ? 'lecture_notes' : $job['requested_kind'];
        if ($kind === 'auto') {
            $this->step('classifying', 15, 'Working out what kind of document this is');
            $c = $this->ai->json(Prompts::CLASSIFY, mb_substr($text, 0, 4000), 300);
            $kind = Validator::in($c['type'] ?? '', ['past_question', 'lecture_notes']) ?? 'lecture_notes';
        }
        db()->prepare('UPDATE processing_jobs SET detected_kind=? WHERE id=?')->execute([$kind, $id]);
        $this->step('classifying', 17, $kind === 'past_question' ? 'This is an exam paper. Next: find every question and solve it' : 'These are teaching notes. Next: summarise them, write a guide and predict exam questions', ['kind' => $kind]);

        $this->clearPrevious($type, $sid);                                           // a re-run replaces the old AI output instead of duplicating it
        $kind === 'past_question' ? $this->pastQuestion($chunks) : $this->lectureNotes($chunks);
        $this->games();

        $st = json_decode((string)val('SELECT stats FROM processing_jobs WHERE id=?', [$id]), true) ?: [];
        $sum = array_filter([($st['answered'] ?? $st['predicted'] ?? 0) ? ($st['answered'] ?? $st['predicted']) . ' questions with answers' : '', ($st['cards'] ?? 0) ? $st['cards'] . ' flashcards' : '', ($st['guides'] ?? 0) ? 'a study guide' : '', ($st['games'] ?? 0) ? $st['games'] . ' games' : '']);
        $done = ($this->publish || $type === 'material' ? 'All done. Ready to study: ' : 'All done. Waiting for a reviewer to publish: ') . implode(', ', $sum);
        db()->prepare("UPDATE processing_jobs SET status='done', stage='done', progress=100, message=?, finished_at=NOW(), tokens_in=?, tokens_out=? WHERE id=?")
            ->execute([mb_substr($done, 0, 250), $this->ai->in, $this->ai->out, $id]);
        JobQueue::event($id, 'done', $done, ['tokens' => $this->ai->in + $this->ai->out], [], 'success');
        JobQueue::setSourceStatus($type, $sid, 'done');
        if ($type === 'material') q('UPDATE materials SET question_count=(SELECT COUNT(*) FROM exam_questions WHERE source_type="material" AND source_id=?), page_count=? WHERE id=?', [$sid, (int)(PdfText::pages(upload_path('notes', $job['file_path'])) ?? 0), $sid]);
        db()->prepare('INSERT INTO ai_usage(school_id,user_id,kind,tokens) VALUES (?,?,?,?)')->execute([$job['school_id'] ?? null, $job['owner_id'], 'guide', $this->ai->in + $this->ai->out]);
        Audit::log("AI processed $type $sid as $kind", $type, $sid);
    }

    private function step(string $stage, int $pct, string $msg, array $stats = [], array $items = []): void { JobQueue::progress($this->id, $stage, $pct, $msg, array_filter($stats, fn($v) => $v !== null), $items); }

    private function pastQuestion(array $chunks): void {
        $qs = []; $n = count($chunks);
        foreach ($chunks as $i => $ch) {
            $this->step('extracting', 20 + (int)(18 * $i / $n), 'Looking for questions' . ($n > 1 ? ' in part ' . ($i + 1) . ' of ' . $n : ''));
            $found = array_values(array_filter($this->ai->json(Prompts::EXTRACT_QUESTIONS, $ch, 3500)['questions'] ?? [], fn($q) => !empty($q['question'])));
            $qs = array_merge($qs, $found);
            $this->step('extracting', 20 + (int)(18 * ($i + 1) / $n), 'Found ' . count($found) . ' question' . (count($found) === 1 ? '' : 's') . ($n > 1 ? ' in part ' . ($i + 1) : ''), ['questions' => count($qs)],
                array_map(fn($q) => trim(($q['number'] ?? '') . ' ' . $q['question']), $found));
        }
        if (!$qs) throw new RuntimeException('No questions were found in this document.');
        $qs = array_slice($qs, 0, 80);

        $solved = []; $batches = array_chunk($qs, 4);                              // small batches give better answers
        foreach ($batches as $i => $b) {
            $from = $i * 4 + 1; $to = min(($i + 1) * 4, count($qs));
            $this->step('solving', 40 + (int)(35 * $i / count($batches)), "Writing model answers for questions $from-$to of " . count($qs));
            $r = $this->ai->json(Prompts::SOLVE, json_encode($b, JSON_UNESCAPED_UNICODE), 4500)['answers'] ?? [];
            $items = [];
            foreach ($b as $k => $q) { $a = $q + ['answer' => $r[$k]['answer'] ?? '', 'confidence' => $r[$k]['confidence'] ?? 'low']; $solved[] = $a; $items[] = 'Q' . ($q['number'] ?? '') . ' · ' . $a['confidence'] . ' confidence'; }
            $this->step('solving', 40 + (int)(35 * ($i + 1) / count($batches)), "Answered questions $from-$to", ['answered' => count($solved), 'low' => count(array_filter($solved, fn($s) => $s['confidence'] === 'low'))], $items);
        }
        $st = db()->prepare('INSERT INTO exam_questions(source_type,source_id,kind,number,question,answer,topic,marks,confidence,status) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ($solved as $q)
            $st->execute([$this->type, $this->sid, 'past', $q['number'] ?? null, $q['question'], $q['answer'], $q['topic'] ?? null, $q['marks'] ?? null,
                Validator::in($q['confidence'], ['high', 'medium', 'low']) ?? 'low', $this->publish && ($q['confidence'] ?? '') !== 'low' ? 'published' : 'draft']);
        if ($this->type === 'paper' && $this->publish) q('UPDATE papers SET has_answers=1 WHERE id=?', [$this->sid]);

        $this->step('flashcards', 78, 'Turning the solved questions into flashcards');
        $cards = array_map(fn($q) => [$q['question'], $q['answer']], array_filter($solved, fn($q) => $q['answer'] && ($q['confidence'] ?? '') !== 'low'));
        $this->saveDeck('Past paper cards', $cards, true);
        $this->step('flashcards', 82, 'Made ' . count($cards) . ' flashcards (low-confidence answers left out)', ['cards' => count($cards)]);

        $this->step('guide', 85, 'Writing a revision guide: which topics come up and what to learn first');
        $guide = $this->ai->json(Prompts::PAPER_GUIDE, json_encode(array_map(fn($q) => [$q['topic'] ?? '', $q['question']], $qs), JSON_UNESCAPED_UNICODE), 3500);
        $this->saveGuide($guide);
    }

    private function lectureNotes(array $chunks): void {
        $digests = []; $n = count($chunks); $terms = [];
        foreach ($chunks as $i => $ch) {
            $this->step('digesting', 20 + (int)(25 * $i / $n), 'Summarising the notes' . ($n > 1 ? ' (part ' . ($i + 1) . ' of ' . $n . ')' : ''));
            $d = $this->ai->json(Prompts::NOTES_DIGEST, $ch, 3000); $digests[] = $d;
            foreach ($d['terms'] ?? [] as $t) if (!empty($t['term']) && !empty($t['definition'])) $terms[] = [$t['term'], $t['definition']];
            $this->step('digesting', 20 + (int)(25 * ($i + 1) / $n), 'Found topics: ' . (implode(', ', array_slice($d['topics'] ?? [], 0, 5)) ?: 'general'), ['terms' => count($terms), 'topics' => array_sum(array_map(fn($x) => count($x['topics'] ?? []), $digests))],
                array_map(fn($t) => $t['term'], array_slice($d['terms'] ?? [], 0, 6)));
        }
        $this->step('guide', 48, 'Writing the study guide');
        $this->saveGuide($this->ai->json(Prompts::GUIDE, json_encode($digests, JSON_UNESCAPED_UNICODE), 5000));

        $count = (int)Settings::get('ai_predicted_count', 12);
        $this->step('predicting', 62, "Predicting $count likely exam questions and writing model answers");
        $pred = array_values(array_filter($this->ai->json(str_replace('{N}', (string)$count, Prompts::PREDICT),
            json_encode(array_map(fn($d) => ['summary' => $d['summary'] ?? '', 'terms' => $d['terms'] ?? []], $digests), JSON_UNESCAPED_UNICODE), 7000)['questions'] ?? [], fn($q) => !empty($q['question']) && !empty($q['answer'])));
        $st = db()->prepare('INSERT INTO exam_questions(source_type,source_id,kind,question,answer,topic,marks,difficulty,confidence,status) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ($pred as $q)
            $st->execute([$this->type, $this->sid, 'predicted', $q['question'], $q['answer'], $q['topic'] ?? null, $q['marks'] ?? null,
                Validator::in($q['difficulty'] ?? '', ['easy', 'medium', 'hard']), 'medium', $this->publish || $this->type === 'material' ? 'published' : 'draft']);
        $this->step('predicting', 80, 'Wrote ' . count($pred) . ' likely exam questions with model answers', ['predicted' => count($pred)], array_map(fn($q) => $q['question'], $pred));

        $this->step('flashcards', 84, 'Making flashcards from the key terms');
        $this->saveDeck('Key terms', array_slice($terms, 0, 60));
        $this->step('flashcards', 88, 'Made ' . min(60, count($terms)) . ' flashcards', ['cards' => min(60, count($terms))], array_map(fn($t) => $t[0], array_slice($terms, 0, 6)));
    }

    /** Games run on any deck with 3+ cards, so this step just reports which games are now playable. */
    private function games(): void {
        $this->step('games', 92, 'Setting up games with the new flashcards');
        $col = JobQueue::col($this->type);
        $deck = row("SELECT id,card_count FROM decks WHERE $col=? AND ai_generated=1 ORDER BY card_count DESC LIMIT 1", [$this->sid]);
        $games = $deck && $deck['card_count'] >= 3 ? array_column(rows('SELECT name FROM games WHERE is_enabled=1 ORDER BY id'), 'name') : [];
        $this->step('games', 96, $games ? count($games) . ' game' . (count($games) === 1 ? '' : 's') . ' ready to play' : 'Not enough flashcards for games (3 or more are needed)', ['games' => count($games), 'deck' => $deck['id'] ?? null], $games);
    }

    /** Audio -> transcript. Reuses an existing (possibly lecturer-edited) transcript unless it was cleared for re-transcription. */
    private function transcript(array $job, int $sid): string {
        $r = row('SELECT transcript,stt_status FROM recordings WHERE id=?', [$sid]);
        if ($r && $r['stt_status'] === 'ready' && mb_strlen((string)$r['transcript']) > 200) { $this->step('transcribing', 12, 'Using the saved transcript (' . number_format(str_word_count($r['transcript'])) . ' words)', ['words' => str_word_count($r['transcript'])]); return $r['transcript']; }
        $this->step('transcribing', 6, 'Listening to the lecture and writing it down');
        db()->prepare("UPDATE recordings SET stt_status='running', error=NULL WHERE id=?")->execute([$sid]);
        $t = Transcriber::transcribe(upload_path('audio', $job['file_path']), Settings::get('stt_language', 'auto'));
        if (mb_strlen($t['text']) < 200) { db()->prepare("UPDATE recordings SET stt_status='failed', error=? WHERE id=?")->execute(['Too little speech found. Try a clearer recording.', $sid]); throw new RuntimeException('Too little speech found in the audio.'); }
        db()->prepare("UPDATE recordings SET transcript=?, word_count=?, seconds=GREATEST(seconds,?), stt_status='ready', stt_confidence=? WHERE id=?")
            ->execute([$t['text'], str_word_count($t['text']), $t['seconds'], $t['confidence'], $sid]);
        $this->step('transcribing', 14, 'Transcript ready: ' . number_format(str_word_count($t['text'])) . ' words', ['words' => str_word_count($t['text'])]);
        return $t['text'];
    }
    private function saveDeck(string $title, array $pairs, bool $answers = false): void {
        if (!$pairs) return; $o = (int)$this->job['owner_id']; $type = $this->type; $sid = $this->sid; $col = JobQueue::col($type);
        $vis = $type === 'material' ? 'private' : 'shared';                            // student's own notes stay private
        $pdo = db(); $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO decks(school_id,owner_id,title,source,visibility,status,ai_generated,contains_answers,$col) VALUES (?,?,?,?,?,?,1,?,?)")
            ->execute([$this->job['school_id'] ?? null, $o, $title, $type === 'paper' ? 'paper' : 'notes', $vis, $this->publish || $type === 'material' ? 'active' : 'hidden', (int)$answers, $sid]);
        $deck = (int)$pdo->lastInsertId(); $st = $pdo->prepare('INSERT INTO cards(deck_id,front,back,position) VALUES (?,?,?,?)');
        foreach (array_values($pairs) as $i => $p) $st->execute([$deck, $p[0], $p[1], $i]);
        $courseId = $type === 'paper' ? val('SELECT course_id FROM papers WHERE id=?', [$sid]) : ($type === 'recording' ? val('SELECT course_id FROM recordings WHERE id=?', [$sid]) : null);
        q('UPDATE decks SET card_count=?, course_id=? WHERE id=?', [count($pairs), $courseId ?: null, $deck]);
        $pdo->commit();
    }
    private function saveGuide(array $g): void {
        if (empty($g['body'])) { $this->step('guide', 90, 'The study guide came back empty, so it was skipped'); return; }
        $type = $this->type; $sid = $this->sid;
        db()->prepare('INSERT INTO study_guides(school_id,owner_id,paper_id,material_id,recording_id,title,body,status,ai_generated) VALUES (?,?,?,?,?,?,?,?,1)')
            ->execute([$this->job['school_id'] ?? null, (int)$this->job['owner_id'], $type === 'paper' ? $sid : null, $type === 'material' ? $sid : null, $type === 'recording' ? $sid : null, mb_substr($g['title'] ?? 'Study guide', 0, 160),
                $g['body'], $this->publish || $type === 'material' ? 'active' : 'hidden']);
        preg_match_all('/^#{1,3}\s+(.+)$/m', (string)$g['body'], $h);
        $this->step('guide', $this->type === 'paper' ? 90 : 58, 'Study guide ready: "' . mb_strimwidth($g['title'] ?? 'Study guide', 0, 70, '…') . '" with ' . count($h[1]) . ' sections', ['guides' => 1], array_slice($h[1], 0, 6));
    }
    private function clearPrevious(string $type, int $sid): void {
        $pdo = db(); $col = JobQueue::col($type);
        $pdo->prepare('DELETE FROM exam_questions WHERE source_type=? AND source_id=?')->execute([$type, $sid]);
        $pdo->prepare("DELETE FROM decks WHERE $col=? AND ai_generated=1")->execute([$sid]);
        $pdo->prepare("DELETE FROM study_guides WHERE $col=? AND ai_generated=1")->execute([$sid]);
    }
}
