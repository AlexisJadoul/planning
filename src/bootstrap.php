<?php
declare(strict_types=1);
date_default_timezone_set('Europe/Paris');
$config = is_file(__DIR__.'/../config.php') ? require __DIR__.'/../config.php' : [];
define('DATA_DIR', getenv('DATA_DIR') ?: ($config['data_dir'] ?? __DIR__.'/../data'));
if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0700, true)) throw new RuntimeException('Dossier data non accessible.');
if (PHP_SAPI !== 'cli') {
    ini_set('session.save_path', DATA_DIR);
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(['lifetime'=>0, 'httponly'=>true, 'samesite'=>'Strict', 'secure'=>getenv('COOKIE_SECURE') === '1' || ($config['cookie_secure'] ?? false)]);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
    if (isset($_SESSION['admin_until']) && $_SESSION['admin_until'] < time()) unset($_SESSION['admin_until']);
}
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function readState(): array {
    $file = DATA_DIR.'/planning.json';
    return is_file($file) ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : ['weeks'=>[], 'mode'=>'auto', 'selected'=>null, 'revision'=>null];
}
function updateState(callable $change): array {
    $lock = fopen(DATA_DIR.'/planning.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Verrouillage impossible.');
    $temp = null;
    try {
        $value = $change(readState());
        $temp = tempnam(DATA_DIR, 'planning-');
        if (!$temp || file_put_contents($temp, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false || !rename($temp, DATA_DIR.'/planning.json')) throw new RuntimeException('Enregistrement impossible.');
        return $value;
    } finally { if ($temp && is_file($temp)) unlink($temp); flock($lock, LOCK_UN); fclose($lock); }
}
function adminPassword(): string {
    global $config;
    $password = getenv('ADMIN_PASSWORD') ?: ($config['admin_password'] ?? '');
    if ($password !== '') return $password;
    $file = DATA_DIR.'/admin-password.txt';
    $handle = fopen($file, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) throw new RuntimeException('Mot de passe inaccessible.');
    try {
        $password = trim(stream_get_contents($handle));
        if ($password === '') { $password = bin2hex(random_bytes(18)); fwrite($handle, $password); chmod($file, 0600); }
        return $password;
    } finally { flock($handle, LOCK_UN); fclose($handle); }
}
function activePlanning(array $state, ?DateTimeImmutable $today = null): array {
    $today ??= new DateTimeImmutable();
    $selected = $state['mode'] === 'manual' ? $state['selected'] : $today->format('o-\WW');
    $week = null;
    foreach ($state['weeks'] as $candidate) if ($candidate['id'] === $selected) $week = $candidate;
    return ['week'=>$week, 'revision'=>$state['revision'] ?? null, 'updated'=>$state['updated'] ?? null, 'today'=>$today->format('Y-m-d'), 'mode'=>$state['mode']];
}
