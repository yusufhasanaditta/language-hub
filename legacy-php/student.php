<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();

$roll = sanitize_string($_GET['roll'] ?? '', 20);

if (!$roll || !preg_match('/^BA\d{2}[A-Z]\d{5}$/', $roll)) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Not Found</title></head><body style="font-family:sans-serif;text-align:center;padding:60px"><h2>Student Not Found</h2><p>The roll number is invalid or incorrect.</p><a href="' . APP_URL . '/">Back to Home</a></body></html>';
    exit;
}

$student = db_row(
    "SELECT id, name, avatar, roll_number, created_at FROM users WHERE roll_number=? AND role_id=?",
    [$roll, ROLE_STUDENT]
);

if (!$student) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Not Found</title></head><body style="font-family:sans-serif;text-align:center;padding:60px"><h2>Student Not Found</h2><p>No student exists with this roll number.</p><a href="' . APP_URL . '/">Back to Home</a></body></html>';
    exit;
}

$sid = (int)$student['id'];

$enrolledCount = (int)db_val(
    "SELECT COUNT(*) FROM enrollments WHERE user_id=? AND status='active'",
    [$sid]
);

$examStats = db_row(
    "SELECT COUNT(*) as total,
            COALESCE(AVG(CASE WHEN ex.total_marks>0 THEN ea.score/ex.total_marks*100 END),0) as avg_score,
            MAX(CASE WHEN ex.total_marks>0 THEN ea.score/ex.total_marks*100 END) as best_score
     FROM exam_attempts ea
     JOIN exams ex ON ex.id = ea.exam_id
     WHERE ea.user_id=? AND ea.status IN ('submitted','graded')",
    [$sid]
);

$joinYear = date('Y', strtotime($student['created_at']));
$avatarSrc = avatar_url($student['avatar'], $student['name']);
$publicUrl = APP_URL . '/student.php?roll=' . urlencode($roll);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1e1b4b">
    <title><?= e($student['name']) ?> — Language Hub Student Card</title>
    <meta property="og:title" content="<?= e($student['name']) ?> — Language Hub">
    <meta property="og:description" content="Roll: <?= e($roll) ?> · Courses: <?= $enrolledCount ?> · Exams: <?= (int)$examStats['total'] ?>">
    <meta property="og:image" content="<?= e($avatarSrc) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;600;700&family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --c-primary: #5B21B6;
            --c-ink: #1a1a2e;
            --c-ink-light: #64648a;
            --c-border: #e5e7eb;
            --font-bn: 'Hind Siliguri', sans-serif;
            --font-la: 'Inter', sans-serif;
        }
        body {
            font-family: var(--font-bn);
            background: linear-gradient(135deg, #0f0c29 0%, #1e1b4b 50%, #302b63 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }
        .card {
            background: #fff;
            border-radius: 24px;
            width: 100%;
            max-width: 400px;
            overflow: hidden;
            box-shadow: 0 32px 80px rgba(0,0,0,.5);
        }
        .card-header {
            background: linear-gradient(135deg, #1e1b4b 0%, #5B21B6 100%);
            padding: 32px 28px 24px;
            text-align: center;
            position: relative;
        }
        .card-header::after {
            content: '';
            position: absolute;
            bottom: -1px; left: 0; right: 0;
            height: 24px;
            background: #fff;
            border-radius: 24px 24px 0 0;
        }
        .org-label {
            font-family: var(--font-la);
            font-size: 11px;
            font-weight: 700;
            color: rgba(255,255,255,.5);
            letter-spacing: .15em;
            text-transform: uppercase;
            margin-bottom: 20px;
        }
        .avatar {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            border: 4px solid rgba(255,255,255,.3);
            object-fit: cover;
            display: block;
            margin: 0 auto 14px;
        }
        .name {
            font-size: 22px;
            font-weight: 700;
            color: #fff;
            margin-bottom: 6px;
        }
        .roll-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.22);
            border-radius: 999px;
            padding: 4px 16px;
            font-family: var(--font-la);
            font-size: 13px;
            font-weight: 800;
            color: rgba(255,255,255,.9);
            letter-spacing: .06em;
        }
        .card-body {
            padding: 28px 28px 24px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 24px;
        }
        .stat {
            text-align: center;
            background: #f5f3ff;
            border-radius: 14px;
            padding: 16px 8px;
        }
        .stat-val {
            font-family: var(--font-la);
            font-size: 22px;
            font-weight: 900;
            color: var(--c-primary);
            display: block;
        }
        .stat-lab {
            font-size: 11px;
            color: var(--c-ink-light);
            font-weight: 600;
            margin-top: 3px;
        }
        .info-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 0;
            border-bottom: 1px solid var(--c-border);
            font-size: 14px;
        }
        .info-row:last-child { border-bottom: none; }
        .info-row .mi { font-size: 18px; color: var(--c-primary); }
        .info-label { color: var(--c-ink-light); min-width: 80px; font-weight: 600; }
        .info-val { font-weight: 700; color: var(--c-ink); }
        .qr-section {
            background: #f9f9ff;
            border-top: 1px solid var(--c-border);
            padding: 20px 28px;
            text-align: center;
        }
        .qr-wrap {
            display: inline-block;
            background: #fff;
            border-radius: 12px;
            padding: 10px;
            border: 1px solid var(--c-border);
            margin-bottom: 10px;
        }
        .qr-hint {
            font-size: 11px;
            color: var(--c-ink-light);
            letter-spacing: .03em;
        }
        .footer-link {
            display: block;
            text-align: center;
            padding: 14px;
            background: var(--c-primary);
            color: #fff;
            font-weight: 700;
            font-size: 14px;
            text-decoration: none;
        }
        .footer-link:hover { background: #4a2ee0; }
    </style>
</head>
<body>
<div class="card">
    <div class="card-header">
        <div class="org-label">Language Hub — Student ID Card</div>
        <img src="<?= e($avatarSrc) ?>" alt="<?= e($student['name']) ?>" class="avatar">
        <div class="name"><?= e($student['name']) ?></div>
        <span class="roll-badge">
            <span class="material-icons-round" style="font-size:13px">badge</span>
            <?= e($roll) ?>
        </span>
    </div>

    <div class="card-body">
        <div class="stats-grid">
            <div class="stat">
                <span class="stat-val"><?= $enrolledCount ?></span>
                <span class="stat-lab">Courses</span>
            </div>
            <div class="stat">
                <span class="stat-val"><?= (int)$examStats['total'] ?></span>
                <span class="stat-lab">Exams</span>
            </div>
            <div class="stat">
                <span class="stat-val"><?= $examStats['total'] > 0 ? round((float)$examStats['avg_score']) . '%' : '—' ?></span>
                <span class="stat-lab">Average Score</span>
            </div>
        </div>

        <div>
            <div class="info-row">
                <span class="material-icons-round mi">school</span>
                <span class="info-label">Joined</span>
                <span class="info-val"><?= e($joinYear) ?></span>
            </div>
            <div class="info-row">
                <span class="material-icons-round mi">stars</span>
                <span class="info-label">Best Score</span>
                <span class="info-val"><?= $examStats['total'] > 0 ? round((float)$examStats['best_score']) . '%' : '—' ?></span>
            </div>
            <div class="info-row">
                <span class="material-icons-round mi">verified</span>
                <span class="info-label">Roll Number</span>
                <span class="info-val" style="font-family:var(--font-la);letter-spacing:.05em"><?= e($roll) ?></span>
            </div>
        </div>
    </div>

    <div class="qr-section">
        <div class="qr-wrap">
            <div id="miniQR"></div>
        </div>
        <div class="qr-hint" style="margin-bottom:14px">Scan this QR code to view profile</div>
        <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
            <button onclick="copyLink()" id="copyBtn"
                style="display:inline-flex;align-items:center;gap:5px;background:#f0edff;border:1px solid #c4b5fd;border-radius:8px;padding:7px 14px;color:#5B21B6;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit">
                <span class="material-icons-round" style="font-size:14px">content_copy</span>
                Copy Link
            </button>
            <button onclick="downloadQR()"
                style="display:inline-flex;align-items:center;gap:5px;background:#f0edff;border:1px solid #c4b5fd;border-radius:8px;padding:7px 14px;color:#5B21B6;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit">
                <span class="material-icons-round" style="font-size:14px">download</span>
                Download QR
            </button>
            <button id="shareBtn" onclick="shareCard()"
                style="display:none;align-items:center;gap:5px;background:#f0edff;border:1px solid #c4b5fd;border-radius:8px;padding:7px 14px;color:#5B21B6;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit">
                <span class="material-icons-round" style="font-size:14px">share</span>
                Share
            </button>
        </div>
    </div>

    <a href="<?= APP_URL ?>/" class="footer-link">
        <span class="material-icons-round" style="font-size:16px;vertical-align:middle;margin-right:4px">school</span>
        Join Language Hub
    </a>
</div>

<script src="<?= APP_URL ?>/assets/js/qrcode.min.js"></script>
<script>
const PUBLIC_URL = <?= json_encode($publicUrl) ?>;
const STUDENT_NAME = <?= json_encode($student['name']) ?>;
const ROLL = <?= json_encode($roll) ?>;

if (typeof QRCode !== 'undefined') {
    new QRCode(document.getElementById('miniQR'), {
        text: PUBLIC_URL,
        width: 100,
        height: 100,
        colorDark: '#1e1b4b',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.H
    });
} else {
    console.error('QRCode library failed to load');
}

if (navigator.share) {
    document.getElementById('shareBtn').style.display = 'inline-flex';
}

function copyLink() {
    navigator.clipboard.writeText(PUBLIC_URL).then(() => {
        const btn = document.getElementById('copyBtn');
        const orig = btn.innerHTML;
        btn.innerHTML = '<span class="material-icons-round" style="font-size:14px">check</span> Copied!';
        btn.style.background = '#d1fae5';
        btn.style.borderColor = '#6ee7b7';
        btn.style.color = '#065f46';
        setTimeout(() => { btn.innerHTML = orig; btn.style.cssText = ''; btn.setAttribute('style', 'display:inline-flex;align-items:center;gap:5px;background:#f0edff;border:1px solid #c4b5fd;border-radius:8px;padding:7px 14px;color:#5B21B6;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit'); }, 2000);
    });
}

function downloadQR() {
    const el = document.getElementById('miniQR');
    const img = el.querySelector('img') || el.querySelector('canvas');
    if (!img) return;
    const a = document.createElement('a');
    a.href = img.tagName === 'CANVAS' ? img.toDataURL('image/png') : img.src;
    a.download = 'QR-' + ROLL + '.png';
    a.click();
}

function shareCard() {
    if (navigator.share) {
        navigator.share({ title: STUDENT_NAME + ' — Language Hub', text: 'Roll: ' + ROLL, url: PUBLIC_URL });
    }
}
</script>
</body>
</html>
