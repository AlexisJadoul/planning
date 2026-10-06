<?php
require __DIR__.'/../src/bootstrap.php';
session_write_close();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(activePlanning(readState()), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
