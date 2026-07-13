<?php
// auth/logout.php
require_once __DIR__ . '/../config/security.php';
initSecureSession();
setSecurityHeaders();

$base_url = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$base_url = preg_replace('/(\/(auth|dashboard|notes|msg|drive|calendar|server|admin|installer))?$/i', '', $base_url);
if ($base_url === '/') $base_url = '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $base_url . (isset($_SESSION['id_user']) ? '/dashboard/' : '/auth/login'), true, 303);
    exit;
}

if (!validateCsrfToken()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Token keamanan tidak valid. Muat ulang halaman dan coba lagi.';
    exit;
}

if (isset($_SESSION['id_user'])) {
    if (file_exists(__DIR__ . '/../config/database.php')) {
        require_once __DIR__ . '/../config/database.php';
        try {
            $conn->prepare("UPDATE users SET is_online = 0 WHERE id_user = ?")->execute([$_SESSION['id_user']]);
        } catch (Exception $e) {}
    }
}

// Clear session variables
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Strict',
    ]);
}

// Destroy session
session_destroy();

header('Location: ' . $base_url . '/auth/login', true, 303);
exit;
?>
