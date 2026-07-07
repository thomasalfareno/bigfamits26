<?php
// admin/api_admin.php
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

if (!in_array($_SESSION['role'] ?? '', ['admin', 'operator', 'superadmin'])) {
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

// CSRF validation
requirePostMethod();
requireCsrfToken();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'delete_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
    
    if ($id === (int)$_SESSION['id_user']) {
        echo json_encode(['success' => false, 'message' => 'Anda tidak dapat menghapus akun Anda sendiri']);
        exit;
    }
    
    try {
        $stmt_chk_role = $conn->prepare("SELECT role FROM users WHERE id_user = ?");
        $stmt_chk_role->execute([$id]);
        $target_user_role = $stmt_chk_role->fetchColumn();

        if ($target_user_role === 'superadmin' && ($_SESSION['role'] ?? '') !== 'superadmin') {
            echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki hak untuk menghapus Super Admin.']);
            exit;
        }

        $isSuperAdmin = ($_SESSION['role'] ?? '') === 'superadmin';
        if (!$isSuperAdmin) {
            $confirm_password = trim($_POST['target_password'] ?? '');
            if ($confirm_password === '') {
                echo json_encode(['success' => false, 'message' => 'Password konfirmasi wajib diisi.']);
                exit;
            }

            if (!verifyDeleteConfirmationPassword($conn, $id, $confirm_password)) {
                echo json_encode(['success' => false, 'message' => 'Password konfirmasi salah. Masukkan password dari akun yang hendak Anda hapus.']);
                exit;
            }
        }

        $conn->beginTransaction();
        
        // 1. Fetch all user's files and physically delete them
        $stmt_files = $conn->prepare("SELECT file_path FROM files WHERE id_user = ?");
        $stmt_files->execute([$id]);
        $files = $stmt_files->fetchAll();
        foreach ($files as $f) {
            $path = __DIR__ . '/../uploads/drive/' . $f['file_path'];
            if (file_exists($path)) @unlink($path);
        }
        
        // 2. Fetch all user's messages files and physically delete them
        $stmt_msgs = $conn->prepare("SELECT file_path FROM msg WHERE id_user = ? AND tipe IN ('image', 'file')");
        $stmt_msgs->execute([$id]);
        $msgs = $stmt_msgs->fetchAll();
        foreach ($msgs as $m) {
            $path_msg = __DIR__ . '/../uploads/msg/' . $m['file_path'];
            if (file_exists($path_msg)) @unlink($path_msg);
        }
        
        // 3. Delete user's profile image if exists
        $stmt_prof = $conn->prepare("SELECT foto_profil FROM users WHERE id_user = ? LIMIT 1");
        $stmt_prof->execute([$id]);
        $prof = $stmt_prof->fetch();
        if ($prof && !empty($prof['foto_profil'])) {
            $path = __DIR__ . '/../uploads/profil/' . $prof['foto_profil'];
            if (file_exists($path)) @unlink($path);
        }
        
        // 4. Delete database records (Foreign keys CASCADE will handle notes, msg, folders, files, calendar_events, poll_votes)
        $conn->prepare("DELETE FROM users WHERE id_user = ?")->execute([$id]);
        
        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal menghapus pengguna.')]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
?>
