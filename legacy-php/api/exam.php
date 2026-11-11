<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();
header('Content-Type: application/json');

if (!is_logged_in()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

// CSRF check (header or POST field)
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    echo json_encode(['success'=>false,'message'=>'CSRF token mismatch']); exit;
}

$raw   = file_get_contents('php://input');
$body  = $raw ? (json_decode($raw, true) ?? []) : [];
$input = array_merge($_GET, $_POST, $body);

$action    = sanitize_string($input['action'] ?? '', 20);
$attemptId = sanitize_int($input['attempt_id'] ?? 0);
$userId    = (int)$_SESSION['user_id'];

function jok(array $data = []): void  { echo json_encode(['success'=>true]  + $data); exit; }
function jerr(string $msg, int $code = 400): void { http_response_code($code); echo json_encode(['success'=>false,'message'=>$msg]); exit; }

// ── autosave ──────────────────────────────────────────────────────────────────
if ($action === 'autosave' && $attemptId) {
    $attempt = db_row("SELECT id, status FROM exam_attempts WHERE id=? AND user_id=?", [$attemptId, $userId]);
    if (!$attempt || $attempt['status'] !== 'in_progress') jerr('Invalid attempt');

    $answers = $input['answers'] ?? [];
    if (is_array($answers)) {
        foreach ($answers as $qid => $val) {
            $qid = (int)$qid;
            $val = strtolower(sanitize_string((string)$val, 1));
            if (in_array($val, ['a','b','c','d'])) {
                $map = ['a'=>'1', 'b'=>'2', 'c'=>'3', 'd'=>'4'];
                $val = $map[$val];
            }
            if (!$qid || !in_array($val, ['1','2','3','4'])) continue;
            db_exec(
                "INSERT INTO exam_attempt_answers (attempt_id, question_id, chosen, is_correct)
                 VALUES (?,?,?,0) ON DUPLICATE KEY UPDATE chosen=VALUES(chosen)",
                [$attemptId, $qid, $val]
            );
        }
        db_exec("UPDATE exam_attempts SET updated_at=NOW() WHERE id=?", [$attemptId]);
    }
    jok(['saved' => true]);
}

// ── start ─────────────────────────────────────────────────────────────────────
if ($action === 'start') {
    $examId = sanitize_int($input['exam_id'] ?? 0);
    if (!$examId) jerr('exam_id required');

    $exam = db_row("SELECT * FROM exams WHERE id=? AND status='published'", [$examId]);
    if (!$exam) jerr('Exam not available', 404);

    $existing = db_row(
        "SELECT id FROM exam_attempts WHERE user_id=? AND exam_id=? AND status='in_progress'",
        [$userId, $examId]
    );
    if ($existing) jok(['attempt_id' => (int)$existing['id'], 'resumed' => true]);

    $maxAttempts = (int)($exam['max_attempts'] ?? 0);
    if ($maxAttempts > 0) {
        $done = (int)db_val(
            "SELECT COUNT(*) FROM exam_attempts WHERE user_id=? AND exam_id=? AND status IN ('submitted','graded')",
            [$userId, $examId]
        );
        if ($done >= $maxAttempts) jerr('Maximum attempts reached', 403);
    }

    $newId = db_insert(
        "INSERT INTO exam_attempts (user_id, exam_id, status, started_at, created_at, updated_at)
         VALUES (?,?,'in_progress',NOW(),NOW(),NOW())",
        [$userId, $examId]
    );
    jok(['attempt_id' => (int)$newId, 'resumed' => false]);
}

// ── submit ────────────────────────────────────────────────────────────────────
if ($action === 'submit' && $attemptId) {
    $attempt = db_row("SELECT * FROM exam_attempts WHERE id=? AND user_id=?", [$attemptId, $userId]);
    if (!$attempt) jerr('Attempt not found', 404);
    if (in_array($attempt['status'], ['submitted','graded'])) {
        jok(['result_url' => APP_URL . '/exam-result.php?attempt=' . $attemptId, 'already' => true]);
    }

    $exam = db_row("SELECT * FROM exams WHERE id=?", [(int)$attempt['exam_id']]);
    if (!$exam) jerr('Exam not found', 404);

    $questions = db_query(
        "SELECT id, correct_option, marks FROM exam_questions WHERE exam_id=? ORDER BY sort_order",
        [(int)$exam['id']]
    );

    $answers  = $input['answers'] ?? [];
    if (!is_array($answers)) $answers = [];

    $correct  = 0;
    $wrong    = 0;
    $skipped  = 0;
    $negMark  = (float)($exam['negative_mark'] ?? 0);
    $score    = 0.0;
    $startedAt = strtotime($attempt['started_at']);

    foreach ($questions as $q) {
        $qid    = (string)$q['id'];
        $chosen = isset($answers[$qid]) ? strtolower(sanitize_string((string)$answers[$qid], 1)) : null;
        if (in_array($chosen, ['a','b','c','d'])) {
            $map = ['a'=>'1', 'b'=>'2', 'c'=>'3', 'd'=>'4'];
            $chosen = $map[$chosen];
        }
        $chosen = in_array($chosen, ['1','2','3','4']) ? $chosen : null;

        if ($chosen === null) {
            $skipped++;
            $isCorrect = 0;
        } elseif ($chosen === strtolower((string)$q['correct_option'])) {
            $correct++;
            $score += (float)$q['marks'];
            $isCorrect = 1;
        } else {
            $wrong++;
            $score -= $negMark;
            $isCorrect = 0;
        }

        db_exec(
            "INSERT INTO exam_attempt_answers (attempt_id, question_id, chosen, is_correct)
             VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE chosen=VALUES(chosen), is_correct=VALUES(is_correct)",
            [$attemptId, (int)$q['id'], $chosen, $isCorrect]
        );
    }

    $score     = max(0.0, round($score, 2));
    $timeTaken = max(0, (int)($input['time_taken'] ?? (time() - $startedAt)));

    db_exec(
        "UPDATE exam_attempts SET status='submitted', score=?, total_correct=?, total_wrong=?,
         total_skipped=?, time_taken=?, submitted_at=NOW(), updated_at=NOW() WHERE id=?",
        [$score, $correct, $wrong, $skipped, $timeTaken, $attemptId]
    );

    // Rank
    $rank = 1 + (int)db_val(
        "SELECT COUNT(DISTINCT user_id) FROM exam_attempts
         WHERE exam_id=? AND score>? AND status IN ('submitted','graded') AND id!=?",
        [(int)$exam['id'], $score, $attemptId]
    );
    db_exec("UPDATE exam_attempts SET rank=? WHERE id=?", [$rank, $attemptId]);

    // Leaderboard: only the student's FIRST attempt counts as their official result.
    record_exam_leaderboard_result((int)$exam['id'], $userId, $attemptId, $score);

    jok(['result_url' => APP_URL . '/exam-result.php?attempt=' . $attemptId, 'score' => $score,
         'correct' => $correct, 'wrong' => $wrong, 'skipped' => $skipped, 'rank' => $rank]);
}

jerr('Unknown action');
