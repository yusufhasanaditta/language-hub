<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_teacher();

$uid = current_user()['id'];

if (empty($_GET['material_id'])) {
    redirect(APP_URL . '/teacher/dashboard.php');
}

$material_id = (int)$_GET['material_id'];
$mat = db_row("SELECT * FROM classroom_materials WHERE id=?", [$material_id]);
if (!$mat || ($mat['kind'] !== 'exam' && $mat['kind'] !== 'cq_exam')) {
    die("Invalid exam material.");
}

$is_cq = ($mat['kind'] === 'cq_exam');
$table = $is_cq ? 'cq_exams' : 'exams';
$exam_id = (int)$mat['reference_id'];

$exam = db_row("SELECT * FROM {$table} WHERE id=?", [$exam_id]);
if (!$exam) {
    die("Exam not found.");
}

// Handle AJAX toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_leaderboard') {
    $new_status = empty($_POST['show']) ? 0 : 1;
    db_query("UPDATE {$table} SET show_leaderboard=? WHERE id=?", [$new_status, $exam_id]);
    header('Content-Type: application/json');
    echo json_encode(['success'=>true, 'new_status'=>$new_status]);
    exit;
}

// Fetch stats and takers
if ($is_cq) {
    $total_students = db_row("SELECT COUNT(DISTINCT user_id) as c FROM cq_submissions WHERE exam_id=?", [$exam_id])['c'];
    $avg_score = db_row("SELECT AVG(score_total) as a FROM cq_submissions WHERE exam_id=?", [$exam_id])['a'];
    $takers = db_query("SELECT cs.id as submission_id, cs.user_id, cs.score_total as score, cs.submitted_at, u.name, u.avatar, u.email FROM cq_submissions cs JOIN users u ON cs.user_id = u.id WHERE cs.exam_id=? ORDER BY cs.score_total DESC, cs.submitted_at ASC", [$exam_id]);
} else {
    $total_students = db_row("SELECT COUNT(DISTINCT user_id) as c FROM exam_attempts WHERE exam_id=? AND status IN ('submitted','graded')", [$exam_id])['c'];
    $avg_score = db_row("SELECT AVG(score) as a FROM exam_attempts WHERE exam_id=? AND status IN ('submitted','graded')", [$exam_id])['a'];
    $takers = db_query("SELECT ea.id as submission_id, ea.user_id, ea.score, ea.submitted_at, ea.time_taken, u.name, u.avatar, u.email FROM exam_attempts ea JOIN users u ON ea.user_id = u.id WHERE ea.exam_id=? AND ea.status IN ('submitted','graded') ORDER BY ea.score DESC, ea.time_taken ASC", [$exam_id]);
}

$avg_score = round($avg_score ?? 0, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Exam Info - <?= e($mat['title']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f7f9fc; color: #333; margin:0; padding:0; display:flex; min-height:100vh; }
        .sidebar { width: 260px; background: #2c2f50; color: #fff; display: flex; flex-direction: column; flex-shrink:0; }
        .sidebar-header { padding: 20px; display: flex; align-items: center; gap: 12px; font-weight: 800; font-size: 1.2rem; }
        .sidebar-header .logo-icon { width: 36px; height: 36px; background: #5B21B6; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
        .main-content { flex: 1; padding: 40px; display: flex; flex-direction: column; max-height:100vh; overflow-y:auto; }
        
        .header-top { display:flex; align-items:center; justify-content:space-between; margin-bottom: 30px; }
        .header-top h1 { margin:0; font-size: 28px; font-weight:800; color:#1a1a2e; }
        
        .stats-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom:40px; }
        .stat-card { background:#fff; border-radius:16px; padding:24px; box-shadow:0 4px 12px rgba(0,0,0,0.03); display:flex; align-items:center; gap:16px; }
        .stat-icon { width:56px; height:56px; border-radius:16px; background:rgba(91, 33, 182,0.1); color:#5B21B6; display:flex; align-items:center; justify-content:center; }
        .stat-icon .material-icons-round { font-size: 28px; }
        .stat-info h4 { margin:0; font-size:14px; color:#666; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; }
        .stat-info .val { margin:4px 0 0; font-size:24px; font-weight:800; color:#1a1a2e; }

        .leaderboard-section { background:#fff; border-radius:16px; box-shadow:0 4px 12px rgba(0,0,0,0.03); padding:30px; }
        .leaderboard-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; }
        .leaderboard-header h2 { margin:0; font-size:20px; font-weight:700; color:#1a1a2e; display:flex; align-items:center; gap:8px; }
        
        .toggle-switch { position: relative; display: inline-block; width: 50px; height: 26px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .3s; border-radius: 34px; }
        .slider:before { position: absolute; content: ""; height: 20px; width: 20px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; }
        input:checked + .slider { background-color: #5B21B6; }
        input:checked + .slider:before { transform: translateX(24px); }
        .toggle-container { display:flex; align-items:center; gap:12px; font-weight:600; font-size:14px; color:#444; }

        table.takers-table { width: 100%; border-collapse: collapse; }
        .takers-table th { text-align:left; padding:16px; font-size:13px; font-weight:700; color:#888; text-transform:uppercase; border-bottom:2px solid #eee; }
        .takers-table td { padding:16px; font-size:15px; font-weight:500; border-bottom:1px solid #eee; vertical-align:middle; }
        .takers-table tbody tr:hover { background: #fafafa; }
        
        .student-cell { display:flex; align-items:center; gap:12px; cursor:pointer; text-decoration:none; color:inherit; }
        .student-cell:hover .name { color: #5B21B6; }
        .avatar { width:40px; height:40px; border-radius:50%; object-fit:cover; }
        .name { font-weight:700; color:#1a1a2e; margin:0; transition:color 0.2s; }
        .email { font-size:12px; color:#888; margin:2px 0 0; }
        
        .score-badge { display:inline-flex; align-items:center; padding:4px 12px; background:rgba(91, 33, 182,0.1); color:#5B21B6; border-radius:20px; font-weight:700; font-size:14px; }
        .btn-action { display:inline-flex; align-items:center; justify-content:center; padding:8px 16px; background:#5B21B6; color:#fff; text-decoration:none; border-radius:8px; font-weight:600; font-size:13px; transition:0.2s; }
        .btn-action:hover { background:#4b32c3; }
        .btn-back { display:inline-flex; align-items:center; gap:6px; color:#666; text-decoration:none; font-weight:600; font-size:14px; transition:0.2s; }
        .btn-back:hover { color:#1a1a2e; }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="sidebar-header" style="display:flex;align-items:center;gap:10px;">
            <img src="<?= APP_URL ?>/image/language-hub-logo.png" alt="Logo" style="height:28px; width:auto; object-fit:contain;">
            <span style="font-weight:800; font-size:1.1rem; color:#fff;">Language Hub</span>
        </div>
        <!-- Simplified sidebar for focus -->
    </aside>

    <main class="main-content">
        <div style="margin-bottom:20px;">
            <a href="<?= APP_URL ?>/teacher/manage_classroom.php?id=<?= $mat['classroom_id'] ?>" class="btn-back">
                <span class="material-icons-round">arrow_back</span> Back to Classroom
            </a>
        </div>
        
        <div class="header-top">
            <div>
                <div style="color:#5B21B6; font-weight:700; font-size:14px; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px;">
                    <?= $is_cq ? 'CQ Exam' : 'MCQ Exam' ?> Info
                </div>
                <h1><?= e($mat['title']) ?></h1>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><span class="material-icons-round">people</span></div>
                <div class="stat-info">
                    <h4>Total Students</h4>
                    <div class="val"><?= number_format($total_students) ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:rgba(255,152,0,0.1); color:#FF9800;"><span class="material-icons-round">analytics</span></div>
                <div class="stat-info">
                    <h4>Average Marks</h4>
                    <div class="val"><?= number_format($avg_score, 1) ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:rgba(76,175,80,0.1); color:#4CAF50;"><span class="material-icons-round">emoji_events</span></div>
                <div class="stat-info">
                    <h4>Total Marks</h4>
                    <div class="val"><?= number_format($exam['total_marks']) ?></div>
                </div>
            </div>
        </div>

        <div class="leaderboard-section">
            <div class="leaderboard-header">
                <h2><span class="material-icons-round" style="color:#5B21B6;">format_list_numbered</span> Exam Takers (Leaderboard)</h2>
                
                <div class="toggle-container">
                    <span>Show Leaderboard to Students:</span>
                    <label class="toggle-switch">
                        <input type="checkbox" id="leaderboardToggle" <?= $exam['show_leaderboard'] ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>
            </div>

            <?php if (empty($takers)): ?>
                <div style="text-align:center; padding:40px; color:#888;">
                    <span class="material-icons-round" style="font-size:48px; opacity:0.5; margin-bottom:16px; display:block;">inbox</span>
                    No students have taken this exam yet.
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="takers-table">
                        <thead>
                            <tr>
                                <th style="width:50px;">Rank</th>
                                <th>Student</th>
                                <th>Score</th>
                                <th>Submitted At</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $rank = 1; foreach ($takers as $t): 
                                $avatar = $t['avatar'] ? APP_URL.'/'.ltrim($t['avatar'], '/') : 'https://ui-avatars.com/api/?name='.urlencode($t['name']).'&background=random';
                                
                                // Determine grading link
                                if ($is_cq) {
                                    $review_link = APP_URL . "/teacher/cq_grade.php?id=" . $t['submission_id'];
                                } else {
                                    // Normally MCQ grading isn't manually done in a separate UI, but maybe a review page exists.
                                    // If we don't have an MCQ review page for teacher, we'll just link to the student result view.
                                    $review_link = APP_URL . "/exam-result.php?attempt=" . $t['submission_id'];
                                }
                            ?>
                                <tr>
                                    <td>
                                        <div style="width:30px; height:30px; border-radius:50%; background:#f0f0f0; display:flex; align-items:center; justify-content:center; font-weight:800; color:#555; font-size:13px;">
                                            <?= $rank++ ?>
                                        </div>
                                    </td>
                                    <td>
                                        <a href="<?= e($review_link) ?>" class="student-cell" target="_blank">
                                            <img src="<?= e($avatar) ?>" alt="Avatar" class="avatar">
                                            <div>
                                                <p class="name"><?= e($t['name']) ?></p>
                                                <p class="email"><?= e($t['email']) ?></p>
                                            </div>
                                        </a>
                                    </td>
                                    <td>
                                        <div class="score-badge"><?= number_format($t['score'], 2) ?></div>
                                    </td>
                                    <td style="color:#888; font-size:14px;">
                                        <?= date('M d, Y h:i A', strtotime($t['submitted_at'])) ?>
                                    </td>
                                    <td style="text-align:right;">
                                        <a href="<?= e($review_link) ?>" target="_blank" class="btn-action">
                                            <?= $is_cq ? 'Grade / Review' : 'View Paper' ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        document.getElementById('leaderboardToggle').addEventListener('change', function() {
            const isChecked = this.checked;
            const formData = new FormData();
            formData.append('action', 'toggle_leaderboard');
            formData.append('show', isChecked ? '1' : '0');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    // Optional: show a small toast notification
                    console.log('Leaderboard visibility updated');
                }
            })
            .catch(err => {
                console.error(err);
                alert('Error updating leaderboard setting.');
                this.checked = !isChecked; // revert
            });
        });
    </script>
</body>
</html>
