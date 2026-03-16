<?php
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);
$file = __DIR__ . $path;

// Se il file esiste, servilo direttamente
if ($path !== '/' && file_exists($file) && !is_dir($file)) {
    return false;
}

// Altrimenti servi index.html
include __DIR__ . '/index.html';
