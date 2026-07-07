<?php
// notes/api_notes.php
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
$isSuperAdmin = isSuperAdminRole();
$canCreateGlobal = canCreateGlobalAgenda();
$userId = (int)$_SESSION['id_user'];

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $stmt = $conn->prepare("INSERT INTO notes (id_user, judul, konten) VALUES (?, 'Catatan Baru', '')");
        $stmt->execute([$_SESSION['id_user']]);
        $new_id = $conn->lastInsertId();
        $_SESSION['active_note_id'] = $new_id;
        echo json_encode(['success' => true, 'id_note' => $new_id]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal membuat catatan.')]);
    }
    exit;
}

if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_note = isset($_POST['id_note']) ? (int)$_POST['id_note'] : null;
    if (!$id_note) {
        echo json_encode(['success' => false, 'message' => 'Missing ID']);
        exit;
    }
    try {
        $stmt = $conn->prepare("SELECT * FROM notes WHERE id_note = ? AND id_user = ? LIMIT 1");
        $stmt->execute([$id_note, $_SESSION['id_user']]);
        $note = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$note) {
            echo json_encode(['success' => false, 'message' => 'Catatan tidak ditemukan']);
            exit;
        }
        $_SESSION['active_note_id'] = $note['id_note'];
        echo json_encode(['success' => true, 'note' => $note]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal mengambil catatan.')]);
    }
    exit;
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_note = isset($_POST['id_note']) ? (int)$_POST['id_note'] : null;
    $judul = trim($_POST['judul'] ?? '');
    $konten = $_POST['konten'] ?? '';
    $tanggal_kegiatan = !empty($_POST['tanggal_kegiatan']) ? $_POST['tanggal_kegiatan'] : null;
    $warna = $_POST['warna'] ?? '#58a6ff';
    $is_global = array_key_exists('is_global', $_POST) ? ((int)$_POST['is_global'] === 1 ? 1 : 0) : 0;
    
    if (!$id_note) {
        echo json_encode(['success' => false, 'message' => 'Missing ID']);
        exit;
    }
    
    // Sanitize input to prevent Stored XSS
    $judul = sanitizeOutput($judul);
    $konten = sanitizeHtmlContent($konten);
    
    try {
        $check = $conn->prepare("SELECT id_user, is_global FROM notes WHERE id_note = ? LIMIT 1");
        $check->execute([$id_note]);
        $current = $check->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            echo json_encode(['success' => false, 'message' => 'Catatan tidak ditemukan']);
            exit;
        }

        $isOwner = (int)$current['id_user'] === $userId;

        if (!$isSuperAdmin) {
            if ($is_global == 1 && !$canCreateGlobal) {
                echo json_encode(['success' => false, 'message' => 'Mahasiswa tidak dapat membuat agenda global']);
                exit;
            }
            if (!$isOwner) {
                if ((int)$current['is_global'] === 1) {
                    echo json_encode(['success' => false, 'message' => 'Anda tidak diizinkan untuk mengedit agenda global ini']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki hak untuk mengedit catatan ini']);
                }
                exit;
            }
        }

        if ($isSuperAdmin) {
            $stmt = $conn->prepare("UPDATE notes SET judul = ?, konten = ?, tanggal_kegiatan = ?, warna = ?, is_global = ? WHERE id_note = ?");
            $stmt->execute([$judul, $konten, $tanggal_kegiatan, $warna, $is_global, $id_note]);
        } else {
            $stmt = $conn->prepare("UPDATE notes SET judul = ?, konten = ?, tanggal_kegiatan = ?, warna = ?, is_global = ? WHERE id_note = ? AND id_user = ?");
            $stmt->execute([$judul, $konten, $tanggal_kegiatan, $warna, $is_global, $id_note, $userId]);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal mengupdate catatan.')]);
    }
    exit;
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_note = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : null);
    if (!$id_note) {
        echo json_encode(['success' => false, 'message' => 'Missing ID']);
        exit;
    }
    
    try {
        $check = $conn->prepare("SELECT id_user, is_global FROM notes WHERE id_note = ? LIMIT 1");
        $check->execute([$id_note]);
        $current = $check->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            echo json_encode(['success' => false, 'message' => 'Catatan tidak ditemukan']);
            exit;
        }

        $isOwner = (int)$current['id_user'] === $userId;

        if (!$isSuperAdmin && !$isOwner) {
            if ((int)$current['is_global'] === 1) {
                echo json_encode(['success' => false, 'message' => 'Anda tidak diizinkan untuk menghapus agenda global ini']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki hak untuk menghapus catatan ini']);
            }
            exit;
        }

        if ($isSuperAdmin) {
            $stmt = $conn->prepare("DELETE FROM notes WHERE id_note = ?");
            $stmt->execute([$id_note]);
        } else {
            $stmt = $conn->prepare("DELETE FROM notes WHERE id_note = ? AND id_user = ?");
            $stmt->execute([$id_note, $userId]);
        }
        if (isset($_SESSION['active_note_id']) && $_SESSION['active_note_id'] == $id_note) {
            unset($_SESSION['active_note_id']);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal menghapus catatan.')]);
    }
    exit;
}

if ($action === 'list_user_notes') {
    if (($_SESSION['role'] ?? '') !== 'superadmin') {
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }
    $uid = (int)($_POST['id_user'] ?? $_GET['id_user'] ?? 0);
    try {
        $stmt = $conn->prepare("SELECT id_note, judul, updated_at FROM notes WHERE id_user = ? ORDER BY updated_at DESC");
        $stmt->execute([$uid]);
        echo json_encode(['success' => true, 'notes' => $stmt->fetchAll()]);
    } catch(Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal mengambil daftar catatan.')]);
    }
    exit;
}

if ($action === 'get_note_detail') {
    if (($_SESSION['role'] ?? '') !== 'superadmin') {
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }
    $nid = (int)($_POST['id_note'] ?? $_GET['id_note'] ?? 0);
    try {
        $stmt = $conn->prepare("SELECT id_note, judul, konten, updated_at FROM notes WHERE id_note = ? LIMIT 1");
        $stmt->execute([$nid]);
        
        $note = $stmt->fetch();
        if ($note) {
            // Keep note content safe
            $note['konten'] = sanitizeHtmlContent($note['konten']);
        }
        
        echo json_encode(['success' => true, 'note' => $note]);
    } catch(Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal memuat detail catatan.')]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
?>
