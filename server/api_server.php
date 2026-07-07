<?php
// server/api_server.php
require_once __DIR__ . '/../config/security.php';
initSecureSession();
header('Content-Type: application/json');

if (!file_exists(__DIR__ . '/../config/database.php')) {
    echo json_encode(['success' => false, 'message' => 'Install first']);
    exit;
}
require_once __DIR__ . '/../config/database.php';
verifyActiveSession($conn);

if (!isset($_SESSION['id_user'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

requirePostMethod();
requireCsrfToken();

echo json_encode(['success' => true, 'redirect' => '../msg/']);
exit;
?>
