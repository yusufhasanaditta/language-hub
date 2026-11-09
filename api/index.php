<?php
// Vercel front controller (vercel-php runtime). Every non-static request lands
// here; it does what .htaccess does on Apache: clean URLs, blocking private
// folders, and running the matching PHP page. Not used on XAMPP/cPanel.
if (getenv('VERCEL') !== '1') { http_response_code(404); exit; }

$root = dirname(__DIR__);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

function lh_not_found(): never {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Not found</title>'
       . '<p style="font:16px system-ui;padding:40px;text-align:center">Page not found. <a href="/">Go home</a></p>';
    exit;
}

// Clean URLs (same as .htaccess)
$extraGet = [];
if (preg_match('#^/course/([A-Za-z0-9-]+)/?$#', $path, $m))        { $file = 'course.php';          $extraGet = ['slug' => $m[1]]; }
elseif (preg_match('#^/t/([A-Za-z0-9._-]+)/?$#', $path, $m))       { $file = 'teacher-profile.php'; $extraGet = ['slug' => $m[1]]; }
elseif (preg_match('#^/category/([A-Za-z0-9-]+)/?$#', $path, $m))  { $file = 'courses.php';         $extraGet = ['category' => $m[1]]; }
else {
    $file = trim($path, '/');
    if ($file === '' || is_dir($root . '/' . $file)) $file = ltrim($file . '/index.php', '/');
    elseif (!str_ends_with($file, '.php')) $file .= '.php';
}

// Private areas and leftover debug scripts are never reachable
if (preg_match('#(^|/)\.|^(config|includes|cache|uploads|vendor)/|^api/index\.php$#i', $file)
    || preg_match('#(^|/)(test_[^/]*|diagnos[^/]*|fix_db|get_schema|patch_db|add_col|cpanel_db_sync|setup|debug|db_update|alter_db|migrate_[^/]*|run_cmd|fix_paths|get_users)\.php$#i', $file)) {
    lh_not_found();
}

$real = realpath($root . '/' . $file);
if ($real === false || !is_file($real) || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) lh_not_found();

foreach ($extraGet as $k => $v) { $_GET[$k] = $v; $_REQUEST[$k] = $v; }
$_SERVER['DOCUMENT_ROOT']   = $root;
$_SERVER['SCRIPT_FILENAME'] = $real;
$_SERVER['SCRIPT_NAME']     = '/' . $file;
$_SERVER['PHP_SELF']        = '/' . $file;

chdir(dirname($real));
require $real;
