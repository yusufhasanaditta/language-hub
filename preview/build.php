<?php
// Builds the static preview shown on Vercel while no database is connected.
// Run on the local XAMPP site:  php preview/build.php
// Each page is fetched from http://localhost/language-hub and saved with
// site-relative links, keyed the same way api/index.php looks them up.
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/key.php';

$base = 'http://localhost/language-hub';
$pages = ['/', '/courses', '/courses?type=live', '/courses?type=recorded', '/courses?free=1', '/login', '/teachers', '/about', '/faq', '/terms', '/privacy', '/refund', '/contact'];
$pdo = new PDO('mysql:host=localhost;dbname=language_hub;charset=utf8mb4', 'root', '');
foreach ($pdo->query("SELECT slug FROM courses WHERE status='published'") as $r) $pages[] = '/course/' . $r['slug'];
foreach ($pdo->query("SELECT slug FROM categories") as $r) $pages[] = '/category/' . $r['slug'];

foreach ($pages as $p) {
    $html = @file_get_contents($base . $p);
    if ($html === false) { echo "FAIL $p\n"; continue; }
    $html = str_replace([$base, str_replace('/', '\/', $base)], '', $html);
    // Drop the dev-only error beacon script
    $html = preg_replace('#<script>\s*// Dev-only error reporting.*?</script>#s', '', $html);
    $u = parse_url($p);
    $file = __DIR__ . '/pages/' . lh_preview_key($u['path'] ?? '/', $u['query'] ?? '') . '.html';
    @mkdir(dirname($file), 0755, true);
    file_put_contents($file, $html);
    echo 'ok  ', $p, "\n";
}
