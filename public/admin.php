<?php
require __DIR__.'/../src/bootstrap.php';
require __DIR__.'/../src/Excel.php';
header('Cache-Control: no-store');
$error = $message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf']) || !is_string($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) { http_response_code(400); exit('Requête expirée. Rechargez la page.'); }
    $action = $_POST['action'] ?? '';
    if ($action === 'login') {
        $lock = fopen(DATA_DIR.'/login.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $file = DATA_DIR.'/login-attempts.json';
            $attempts = is_file($file) ? json_decode(file_get_contents($file), true) : [];
            $attempts = array_map(fn($times)=>array_values(array_filter($times, fn($t)=>$t > time()-300)), $attempts);
            $attempts = array_filter($attempts);
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $recent = $attempts[$ip] ?? [];
            if (count($recent) >= 10) $error = 'Trop de tentatives. Réessayez dans 5 minutes.';
            elseif (is_string($_POST['password'] ?? null) && hash_equals(adminPassword(), $_POST['password'])) {
                session_regenerate_id(true);
                $_SESSION['admin_until'] = time()+8*3600;
                unset($attempts[$ip]);
            } else { $recent[] = time(); $attempts[$ip] = $recent; $error = 'Mot de passe incorrect.'; }
            file_put_contents($file, json_encode($attempts), LOCK_EX);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        if (!$error) { header('Location: admin.php'); exit; }
    } elseif (empty($_SESSION['admin_until'])) { header('Location: admin.php'); exit; }
    elseif ($action === 'logout') { $_SESSION = []; session_destroy(); header('Location: admin.php'); exit; }
    elseif ($action === 'upload') {
        try {
            $upload = $_FILES['file'] ?? null;
            if (!$upload || $upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > 8*1024*1024 || strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'xlsx' || !is_uploaded_file($upload['tmp_name'])) throw new RuntimeException('Import invalide.');
            $weeks = parseExcel($upload['tmp_name']);
            updateState(function ($value) use ($weeks) {
                $value['weeks'] = $weeks;
                $value['revision'] = bin2hex(random_bytes(8));
                $value['updated'] = date(DATE_ATOM);
                if (!in_array($value['selected'], array_column($weeks, 'id'), true)) { $value['mode'] = 'auto'; $value['selected'] = null; }
                return $value;
            });
            $message = count($weeks).' semaines importées. Les écrans se mettent à jour sous 5 secondes.';
        } catch (Throwable $exception) { $error = 'Import refusé : fichier invalide, trop volumineux ou structure incompatible. Le planning précédent est conservé.'; }
    } elseif ($action === 'select') {
        $selection = $_POST['week'] ?? '';
        updateState(function ($value) use ($selection) {
            if ($selection !== 'auto' && !in_array($selection, array_column($value['weeks'], 'id'), true)) throw new RuntimeException('Semaine inconnue.');
            $value['mode'] = $selection === 'auto' ? 'auto' : 'manual';
            $value['selected'] = $selection === 'auto' ? null : $selection;
            return $value;
        });
        $message = 'Affichage mis à jour.';
    }
}
$data = readState();
$authenticated = !empty($_SESSION['admin_until']);
require __DIR__.'/../views/admin.php';
