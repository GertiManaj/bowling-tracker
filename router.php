<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;

if ($path !== '/' && file_exists($file) && !is_dir($file)) {
    return false;
}

$index = __DIR__ . '/welcome.html';
if (file_exists($index)) {
    include $index;
} else {
    http_response_code(404);
    echo '404 Not Found';
}
