<?php
// auth/api_heartbeat.php
require_once __DIR__ . '/../config/security.php';
initSecureSession();
header('Content-Type: application/json');

if (!file_exists(__DIR__ . '/../config/database.php')) {
    echo json_encode(['success' => false, 'message' => 'Database not found']);
    exit;
}
require_once __DIR__ . '/../config/database.php';

// If active session is invalid, verifyActiveSession will destroy it and exit with JSON
verifyActiveSession($conn);

if (!isset($_SESSION['id_user'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

requirePostMethod();
requireCsrfToken();

// Session is active and valid — update online status based on focus state
$status = $_POST['status'] ?? 'online';
try {
    if ($status === 'offline') {
        $stmt = $conn->prepare("UPDATE users SET is_online = 0 WHERE id_user = ?");
        $stmt->execute([$_SESSION['id_user']]);
    } else {
        $stmt = $conn->prepare("UPDATE users SET last_online = NOW(), is_online = 1 WHERE id_user = ?");
        $stmt->execute([$_SESSION['id_user']]);
    }
} catch (Exception $e) {}

// Fetch the user's current role from the database (may have been changed by an admin)
$currentRole = $_SESSION['role'] ?? 'user';
try {
    $stmt_role = $conn->prepare("SELECT role FROM users WHERE id_user = ? LIMIT 1");
    $stmt_role->execute([$_SESSION['id_user']]);
    $dbRole = $stmt_role->fetchColumn();
    if ($dbRole !== false) {
        $currentRole = $dbRole;
        // Sync session role with database role so server-side checks stay current
        if ($_SESSION['role'] !== $dbRole) {
            $_SESSION['role'] = $dbRole;
        }
    }
} catch (Exception $e) {}

echo json_encode(['success' => true, 'role' => $currentRole]);
exit;
?>
