<?php
// Maps a URL (path + query) to a preview file name. Shared by build.php and api/index.php.
function lh_preview_key(string $path, string $query): string {
    $path = trim(preg_replace('#\.php$#', '', $path), '/');
    if ($path === '' || $path === 'index') $path = 'index';
    if ($path === 'courses' && preg_match('#^category=([a-z-]+)$#', $query, $m)) { $path = 'category/' . $m[1]; $query = ''; }
    parse_str($query, $q);
    $q = array_intersect_key($q, ['type' => 1, 'free' => 1]);   // only filters that have a saved page
    ksort($q);
    $key = $path . ($q ? '--' . http_build_query($q) : '');
    return preg_replace('/[^A-Za-z0-9_-]+/', '_', $key);
}
