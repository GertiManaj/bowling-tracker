<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;

// Serve il service worker con l'header speciale
if ($path === '/service-worker.js' && file_exists($file)) {
    header('Service-Worker-Allowed: /');
    header('Content-Type: application/javascript');
    readfile($file);
    exit;
}

// Redirect root → welcome.html
if ($path === '/' || $path === '') {
    header('Location: /welcome.html');
    exit;
}

// Serve tutti gli altri file statici
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