<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_login();

$attemptId = sanitize_int($_GET['attempt'] ?? 0);
$userId    = (int)$_SESSION['user_id'];

if (!$attemptId) { flash('error', 'Result not found.'); redirect(APP_URL . '/dashboard.php'); }

$tp = db_row("SELECT id FROM teacher_profiles WHERE user_id=?", [$userId]);
$tpId = $tp ? (int)$tp['id'] : 0;

$attempt = db_row(
    "SELECT ea.*, ex.title as exam_title, ex.duration, ex.total_marks, ex.pass_marks,
            ex.negative_mark, ex.course_id, ex.id as exam_id, ex.show_leaderboard,
            u.name as user_name, u.avatar as user_avatar, u.roll_number
     FROM exam_attempts ea
     JOIN exams ex ON ex.id = ea.exam_id
     JOIN users u  ON u.id  = ea.user_id
     WHERE ea.id=? AND (ea.user_id=? OR ex.course_id IN (SELECT id FROM courses WHERE teacher_id=?)) AND ea.status IN ('submitted','completed','graded')",
    [$attemptId, $userId, $tpId]
);

if (!$attempt) {
    flash('error', 'Result not found or not submitted yet.');
    redirect(APP_URL . '/dashboard.php');
}

$eid       = (int)$attempt['exam_id'];
$questions = db_query(
    "SELECT id, body, option_a, option_b, option_c, option_d, correct_option, marks, explanation
     FROM exam_questions WHERE exam_id=? ORDER BY sort_order ASC, id ASC",
    [$eid]
);

// Load my answers
$myAnswers = [];
$ansRows = db_query("SELECT question_id, chosen, is_correct FROM exam_attempt_answers WHERE attempt_id=?", [$attemptId]);
foreach ($ansRows as $r) { $myAnswers[(int)$r['question_id']] = $r; }

$totalQ   = count($questions);
$correct  = (int)$attempt['total_correct'];
$wrong    = (int)$attempt['total_wrong'];
$skipped  = (int)$attempt['total_skipped'];
$score    = (float)$attempt['score'];
$maxScore = (float)$attempt['total_marks'];
$pct      = $maxScore > 0 ? round($score / $maxScore * 100) : 0;
$passed   = $attempt['pass_marks'] ? $score >= (float)$attempt['pass_marks'] : null;
$timeTaken = (int)$attempt['time_taken'];

// Leaderboard (only if teacher published it)
$showLeaderboard = (int)($attempt['show_leaderboard'] ?? 0);
$leaderboard = [];
if ($showLeaderboard) {
    $leaderboard = db_query(
        "SELECT el.score, el.rank, u.name, u.avatar, u.roll_number,
                CASE WHEN u.id=? THEN 1 ELSE 0 END as is_me
         FROM exam_leaderboard el
         JOIN users u ON u.id=el.user_id
         WHERE el.exam_id=?
         ORDER BY el.score DESC, el.updated_at ASC
         LIMIT 20",
        [$userId, $eid]
    );
    // Fallback from exam_attempts if leaderboard table empty
    if (empty($leaderboard)) {
        $leaderboard = db_query(
            "SELECT ea.score, ea.rank, u.name, u.avatar, u.roll_number,
                    CASE WHEN u.id=? THEN 1 ELSE 0 END as is_me
             FROM exam_attempts ea
             JOIN users u ON u.id=ea.user_id
             WHERE ea.exam_id=? AND ea.status IN ('submitted','graded')
             ORDER BY ea.score DESC, ea.submitted_at ASC
             LIMIT 20",
            [$userId, $eid]
        );
    }
}

$OPT_LABELS = ['a'=>'A','b'=>'B','c'=>'C','d'=>'D'];

$page_title = 'Result — ' . $attempt['exam_title'];
include __DIR__ . '/includes/header.php';
?>

<div class="container" style="max-width:980px;padding:var(--sp-8) var(--sp-4) var(--sp-16)">

    <!-- ── Score hero ── -->
    <div style="background:linear-gradient(135deg,#5B21B6 0%,#7B5FF5 100%);border-radius:var(--r-2xl);padding:var(--sp-8) var(--sp-6);color:#fff;text-align:center;margin-bottom:var(--sp-8);position:relative;overflow:hidden">
        <div style="position:absolute;top:-60px;right:-60px;width:300px;height:300px;background:rgba(255,255,255,.05);border-radius:50%"></div>

        <div style="font-size:var(--text-sm);opacity:.75;margin-bottom:var(--sp-3)"><?= e($attempt['exam_title']) ?></div>

        <!-- Score ring -->
        <div style="display:inline-block;position:relative;margin-bottom:var(--sp-5)">
            <svg width="130" height="130" style="transform:rotate(-90deg)">
                <circle cx="65" cy="65" r="55" fill="none" stroke="rgba(255,255,255,.2)" stroke-width="10"/>
                <circle cx="65" cy="65" r="55" fill="none" stroke="#fff" stroke-width="10"
                        stroke-dasharray="<?= round(345.4 * $pct / 100) ?> 345.4"
                        stroke-linecap="round"/>
            </svg>
            <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center">
                <div style="font-size:var(--text-3xl);font-weight:800;font-family:var(--font-latin)"><?= round($score, 1) ?></div>
                <div style="font-size:var(--text-xs);opacity:.7">/ <?= round($maxScore) ?></div>
            </div>
        </div>

        <div style="font-size:var(--text-4xl);font-weight:800;margin-bottom:var(--sp-2)"><?= $pct ?>%</div>

        <?php if ($passed !== null): ?>
        <div style="display:inline-flex;align-items:center;gap:8px;background:<?= $passed?'rgba(21,190,139,.25)':'rgba(239,68,68,.25)' ?>;padding:8px 24px;border-radius:999px;font-weight:700;margin-bottom:var(--sp-4)">
            <span class="material-icons-round"><?= $passed?'emoji_events':'cancel' ?></span>
            <?= $passed ? 'Passed 🎉' : 'Failed' ?>
        </div>
        <?php endif; ?>

        <!-- Stats row -->
        <div style="display:flex;justify-content:center;gap:var(--sp-6);flex-wrap:wrap;margin-top:var(--sp-5)">
            <?php foreach ([
                ['Correct', $correct, '#4ade80'],
                ['Wrong', $wrong, '#f87171'],
                ['Skipped', $skipped, 'rgba(255,255,255,.55)'],
                ['Rank', $attempt['rank'] ? '#'.($attempt['rank']) : '—', '#fff'],
                ['Time', $timeTaken ? gmdate('i:s', $timeTaken) : '—', '#fff'],
            ] as [$lab, $val, $color]): ?>
            <div style="text-align:center">
                <div style="font-size:var(--text-2xl);font-weight:800;font-family:var(--font-latin);color:<?= $color ?>"><?= $val ?></div>
                <div style="font-size:var(--text-xs);opacity:.75"><?= $lab ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 320px;gap:var(--sp-6)" class="result-grid">

        <!-- ── Q&A Review ── -->
        <div>
            <h2 style="font-size:var(--text-xl);margin-bottom:var(--sp-5);display:flex;align-items:center;gap:8px">
                <span class="material-icons-round" style="color:var(--c-primary)">rate_review</span>
                Question & Answer Review
            </h2>

            <?php foreach ($questions as $qi => $q):
                $qid        = (int)$q['id'];
                $ansRow     = $myAnswers[$qid] ?? null;
                $myChosen   = ($ansRow && $ansRow['chosen']) ? strtolower((string)$ansRow['chosen']) : null;
                if (in_array($myChosen, ['1','2','3','4'])) {
                    $map = ['1'=>'a', '2'=>'b', '3'=>'c', '4'=>'d'];
                    $myChosen = $map[$myChosen];
                }
                $correct_k  = strtolower((string)$q['correct_option']);
                if (in_array($correct_k, ['1','2','3','4'])) {
                    $map = ['1'=>'a', '2'=>'b', '3'=>'c', '4'=>'d'];
                    $correct_k = $map[$correct_k];
                }
                $isCorrect  = $myChosen && $myChosen === $correct_k;
                $isWrong    = $myChosen && $myChosen !== $correct_k;
                $isSkipped  = !$myChosen;
                $borderColor = $isCorrect ? 'var(--c-success)' : ($isWrong ? 'var(--c-error)' : 'var(--c-border)');
                $bgColor     = $isCorrect ? 'rgba(21,190,139,.04)' : ($isWrong ? 'rgba(239,68,68,.04)' : 'var(--c-surface)');
            ?>
            <div style="background:<?= $bgColor ?>;border-radius:var(--r-xl);padding:var(--sp-5) var(--sp-6);margin-bottom:var(--sp-4);border-left:4px solid <?= $borderColor ?>;border:1px solid var(--c-border);border-left:4px solid <?= $borderColor ?>">

                <div style="display:flex;align-items:flex-start;gap:var(--sp-3);margin-bottom:var(--sp-4)">
                    <span style="flex-shrink:0;width:30px;height:30px;background:<?= $isCorrect?'var(--c-success)':($isWrong?'var(--c-error)':'var(--c-border)') ?>;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:var(--text-xs);font-weight:800"><?= $qi+1 ?></span>
                    <div style="font-weight:600;line-height:1.75;flex:1"><?= nl2br(e($q['body'] ?? '')) ?></div>
                    <span style="flex-shrink:0;font-size:var(--text-xs);color:var(--c-ink-muted);background:var(--c-surface-2);padding:2px 8px;border-radius:999px"><?= (float)$q['marks'] ?> Marks</span>
                </div>

                <div style="display:flex;flex-direction:column;gap:8px">
                    <?php foreach (['a','b','c','d'] as $k):
                        $optText = $q['option_'.$k] ?? '';
                        if (!$optText) continue;
                        $isCorrectOpt = $k === $correct_k;
                        $isMyOpt      = $k === $myChosen;
                        if ($isCorrectOpt) {
                            $bg = 'rgba(21,190,139,.12)'; $bd = 'var(--c-success)';
                        } elseif ($isMyOpt) {
                            $bg = 'rgba(239,68,68,.1)'; $bd = 'var(--c-error)';
                        } else {
                            $bg = 'var(--c-surface-2)'; $bd = 'transparent';
                        }
                    ?>
                    <div style="display:flex;align-items:center;gap:var(--sp-3);padding:10px 14px;border-radius:var(--r-md);background:<?= $bg ?>;border:1.5px solid <?= $bd ?>">
                        <span style="flex-shrink:0;width:28px;height:28px;border-radius:50%;background:<?= $isCorrectOpt?'var(--c-success)':($isMyOpt?'var(--c-error)':'var(--c-border)') ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:var(--text-xs);font-weight:700">
                            <?= $OPT_LABELS[$k] ?>
                        </span>
                        <span style="font-size:var(--text-sm);flex:1"><?= e($optText) ?></span>
                        <?php if ($isCorrectOpt): ?>
                        <span class="material-icons-round" style="color:var(--c-success);font-size:18px">check_circle</span>
                        <?php elseif ($isMyOpt): ?>
                        <span class="material-icons-round" style="color:var(--c-error);font-size:18px">cancel</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($isSkipped): ?>
                <div style="margin-top:var(--sp-3);font-size:var(--text-xs);color:var(--c-ink-muted);padding:6px 10px;background:var(--c-surface-2);border-radius:var(--r-md)">
                    <span class="material-icons-round" style="font-size:14px;vertical-align:middle">remove_circle_outline</span>
                    You skipped this question. Correct answer: <strong><?= $OPT_LABELS[$correct_k] ?? $correct_k ?></strong>
                </div>
                <?php endif; ?>

                <?php if (!empty($q['explanation'])): ?>
                <div style="margin-top:var(--sp-4);padding:var(--sp-3) var(--sp-4);background:rgba(91, 33, 182,.06);border-radius:var(--r-md);font-size:var(--text-sm);border-left:3px solid var(--c-primary)">
                    <strong style="color:var(--c-primary)">Explanation:</strong> <?= nl2br(e($q['explanation'])) ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- ── Sidebar ── -->
        <div>
            <!-- Leaderboard -->
            <div class="card" style="padding:var(--sp-5);margin-bottom:var(--sp-5)">
                <div style="display:flex;align-items:center;gap:var(--sp-2);margin-bottom:var(--sp-4)">
                    <span class="material-icons-round" style="color:#F59E0B;font-size:22px">emoji_events</span>
                    <h3 style="margin:0">Leaderboard</h3>
                </div>

                <?php if (!$showLeaderboard): ?>
                <div style="text-align:center;padding:var(--sp-6);color:var(--c-ink-muted)">
                    <span class="material-icons-round" style="font-size:36px;display:block;margin-bottom:8px;color:var(--c-ink-faint)">lock</span>
                    <div style="font-size:var(--text-sm)">Your teacher has not published the leaderboard yet.</div>
                </div>
                <?php elseif (empty($leaderboard)): ?>
                <p style="color:var(--c-ink-muted);font-size:var(--text-sm);text-align:center">No results yet.</p>
                <?php else: ?>
                <!-- Top 3 podium -->
                <?php if (count($leaderboard) >= 3): ?>
                <div style="display:flex;align-items:flex-end;justify-content:center;gap:var(--sp-2);margin-bottom:var(--sp-4)">
                    <?php $podium = [1 => $leaderboard[1], 0 => $leaderboard[0], 2 => $leaderboard[2]];
                          $hs = [1=>48, 0=>64, 2=>36];
                          $medals = [0=>'🥇', 1=>'🥈', 2=>'🥉'];
                          $bcs = [0=>'#F59E0B', 1=>'#9CA3AF', 2=>'#CD7C2F'];
                          foreach ($podium as $pos => $pl): ?>
                    <div style="flex:1;text-align:center">
                        <img src="<?= e(avatar_url($pl['avatar'],$pl['name'])) ?>"
                             style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2.5px solid <?= $bcs[$pos] ?>;margin:0 auto 4px">
                        <div style="font-size:11px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e(mb_substr($pl['name'],0,10,'UTF-8')) ?></div>
                        <div style="font-family:var(--font-latin);font-size:12px;font-weight:800"><?= round($pl['score'],0) ?></div>
                        <div style="background:<?= $bcs[$pos] ?>;height:<?= $hs[$pos] ?>px;border-radius:4px 4px 0 0;display:flex;align-items:center;justify-content:center;font-size:18px;margin-top:6px">
                            <?= $medals[$pos] ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php foreach ($leaderboard as $ri => $lb): ?>
                <div style="display:flex;align-items:center;gap:var(--sp-2);padding:var(--sp-2) var(--sp-3);border-radius:var(--r-md);<?= $lb['is_me']?'background:var(--c-primary-light);border:1.5px solid rgba(91, 33, 182,.2)':'' ?>;margin-bottom:3px">
                    <div style="width:26px;text-align:center;font-weight:800;font-family:var(--font-latin);font-size:var(--text-sm);color:<?= $ri===0?'#F59E0B':($ri===1?'#9CA3AF':($ri===2?'#CD7C2F':'var(--c-ink-muted)')) ?>">
                        <?= $ri < 3 ? ['🥇','🥈','🥉'][$ri] : '#'.($ri+1) ?>
                    </div>
                    <img src="<?= e(avatar_url($lb['avatar'],$lb['name'])) ?>"
                         style="width:26px;height:26px;border-radius:50%;object-fit:cover;flex-shrink:0">
                    <div style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:var(--text-sm);font-weight:<?= $lb['is_me']?'700':'600' ?>;color:<?= $lb['is_me']?'var(--c-primary)':'var(--c-ink)' ?>">
                        <?= e($lb['name']) ?><?= $lb['is_me'] ? ' (You) 🙋' : '' ?>
                    </div>
                    <div style="font-family:var(--font-latin);font-weight:800;font-size:var(--text-sm);flex-shrink:0"><?= round($lb['score'],0) ?></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Next steps -->
            <div class="card" style="padding:var(--sp-5)">
                <h4 style="margin-bottom:var(--sp-4)">Next Steps</h4>
                <?php if (!empty($attempt['course_id'])): ?>
                <a href="<?= APP_URL ?>/learn.php?course=<?= (int)$attempt['course_id'] ?>"
                   class="btn btn-primary" style="width:100%;margin-bottom:var(--sp-3);justify-content:center">
                    <span class="material-icons-round">play_circle</span> Back to Course
                </a>
                <?php endif; ?>
                <a href="<?= APP_URL ?>/exams.php?exam=<?= $eid ?>"
                   class="btn btn-ghost" style="width:100%;margin-bottom:var(--sp-3);justify-content:center">
                    <span class="material-icons-round">quiz</span> Go to Exam Page
                </a>
                <a href="<?= APP_URL ?>/dashboard.php" class="btn btn-ghost" style="width:100%;justify-content:center">
                    <span class="material-icons-round">dashboard</span> Dashboard
                </a>
            </div>
        </div>
    </div>
</div>

<style>
@media(max-width:900px){.result-grid{grid-template-columns:1fr !important}}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
