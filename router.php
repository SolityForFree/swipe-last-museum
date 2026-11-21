<?php
// Router for PHP's built-in server (development):  php -S 0.0.0.0:8000 router.php
// Blocks the database folder and build scripts; serves everything else as static files.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
if (preg_match('#(^|/)(data|\.[^/]*)(/|$)#', $path) || preg_match('#\.(py|sqlite|sqlite-wal|sqlite-shm|sh)$#', $path) || $path === '/lib.php') {
    http_response_code(404);
    exit('Not found');
}
if ($path === '/') { header('Location: /swipe.html'); exit; }
return false; // let the built-in server handle the file (including api.php)
