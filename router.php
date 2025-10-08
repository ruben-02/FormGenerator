<?php
// router.php for PHP built-in server
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($uri === '/' || $uri === '' || $uri === '/index.php') {
    header('Location: login.php');
    exit;
}
if (file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false; // serve the requested file as-is
}
// fallback: show 404
http_response_code(404);
echo '404 Not Found';
