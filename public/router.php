<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/admin') { require __DIR__.'/admin.php'; return true; }
if ($path === '/api/planning') { require __DIR__.'/api.php'; return true; }
if ($path === '/') { require __DIR__.'/index.php'; return true; }
return false;
