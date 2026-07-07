<?php
// auth/logout.php
require_once __DIR__ . '/../config/security.php';
initSecureSession();

$base_url = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$base_url = preg_replace('/(\/(auth|dashboard|notes|msg|drive|calendar|server|admin|installer))?$/i', '', $base_url);
if ($base_url === '/') $base_url = '';

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
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();

header("Location: " . $base_url . "/auth/login");
exit;
?>
