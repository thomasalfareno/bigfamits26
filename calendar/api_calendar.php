<?php
// calendar/api_calendar.php
require_once __DIR__ . '/../config/security.php';
initSecureSession();
header('Content-Type: application/json');

if (!file_exists(__DIR__ . '/../config/database.php')) { echo json_encode(['success'=>false,'message'=>'Install first']); exit; }
require_once __DIR__ . '/../config/database.php';
verifyActiveSession($conn);

if (!isset($_SESSION['id_user'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// CSRF check
requirePostMethod();
requireCsrfToken();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'create_note_date' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $tanggal = $_POST['tanggal'] ?? '';
    if (empty($tanggal) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        echo json_encode(['success' => false, 'message' => 'Format tanggal tidak valid (harus YYYY-MM-DD)']);
        exit;
    }
    
    try {
        $is_global = isset($_POST['is_global']) ? (int)$_POST['is_global'] : 0;
        if ($is_global == 1 && !canCreateGlobalAgenda()) {
            echo json_encode(['success' => false, 'message' => 'Mahasiswa tidak dapat membuat agenda global']);
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO notes (id_user, judul, konten, tanggal_kegiatan, warna, is_global) VALUES (?, 'Agenda Baru', '', ?, '#58a6ff', ?)");
        $stmt->execute([$_SESSION['id_user'], $tanggal, $is_global]);
        $new_id = $conn->lastInsertId();
        echo json_encode(['success' => true, 'id_note' => $new_id]);
    } catch(Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal membuat agenda baru.')]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
?>
