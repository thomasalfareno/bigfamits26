<?php
// msg/api_msg.php
require_once __DIR__ . '/../config/security.php';
initSecureSession();
header('Content-Type: application/json');

// Disable displaying errors to output to ensure we always return valid JSON
ini_set('display_errors', '0');
error_reporting(0);

// Check if request is POST but $_FILES is empty (typically happens when post_max_size is exceeded)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'Ukuran file melebihi batas upload server. Silakan upload file yang lebih kecil.']);
    exit;
}

if (!file_exists(__DIR__ . '/../config/database.php')) { echo json_encode(['success'=>false,'message'=>'Install first']); exit; }
require_once __DIR__ . '/../config/database.php';
verifyActiveSession($conn);

if (!isset($_SESSION['id_user'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// CSRF validation
requirePostMethod();
requireCsrfToken();

// Safe migrations running individually (highly robust)
try {
    // 1. Create polls if not exists
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `polls` (
          `id_poll` int(11) NOT NULL AUTO_INCREMENT,
          `pertanyaan` text NOT NULL,
          `tipe` enum('single','multiple') NOT NULL DEFAULT 'single',
          `id_user` int(11) NOT NULL,
          `media_path` varchar(255) DEFAULT NULL,
          `media_type` varchar(50) DEFAULT NULL,
          `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id_poll`),
          KEY `id_user` (`id_user`),
          CONSTRAINT `fk_poll_user` FOREIGN KEY (`id_user`) REFERENCES `users` (`id_user`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
    
    // 2. Create poll_options if not exists
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `poll_options` (
          `id_option` int(11) NOT NULL AUTO_INCREMENT,
          `id_poll` int(11) NOT NULL,
          `teks` varchar(255) DEFAULT NULL,
          `gambar` varchar(255) DEFAULT NULL,
          PRIMARY KEY (`id_option`),
          KEY `id_poll` (`id_poll`),
          CONSTRAINT `fk_option_poll` FOREIGN KEY (`id_poll`) REFERENCES `polls` (`id_poll`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");

    // 3. Create poll_votes if not exists
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `poll_votes` (
          `id_vote` int(11) NOT NULL AUTO_INCREMENT,
          `id_poll` int(11) NOT NULL,
          `id_option` int(11) NOT NULL,
          `id_user` int(11) NOT NULL,
          PRIMARY KEY (`id_vote`),
          UNIQUE KEY `unique_vote` (`id_poll`,`id_option`,`id_user`),
          CONSTRAINT `fk_vote_poll` FOREIGN KEY (`id_poll`) REFERENCES `polls` (`id_poll`) ON DELETE CASCADE,
          CONSTRAINT `fk_vote_option` FOREIGN KEY (`id_option`) REFERENCES `poll_options` (`id_option`) ON DELETE CASCADE,
          CONSTRAINT `fk_vote_user` FOREIGN KEY (`id_user`) REFERENCES `users` (`id_user`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");

    // 4. Create msg if not exists
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `msg` (
          `id_msg` int(11) NOT NULL AUTO_INCREMENT,
          `id_user` int(11) NOT NULL,
          `pesan` text DEFAULT NULL,
          `tipe` enum('text','image','file','poll') NOT NULL DEFAULT 'text',
          `file_path` varchar(255) DEFAULT NULL,
          `file_name` varchar(255) DEFAULT NULL,
          `pinned` tinyint(1) NOT NULL DEFAULT 0,
          `reply_to` int(11) DEFAULT NULL,
          `id_poll` int(11) DEFAULT NULL,
          `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id_msg`),
          KEY `id_user` (`id_user`),
          KEY `id_poll` (`id_poll`),
          CONSTRAINT `fk_msg_user` FOREIGN KEY (`id_user`) REFERENCES `users` (`id_user`) ON DELETE CASCADE,
          CONSTRAINT `fk_msg_poll` FOREIGN KEY (`id_poll`) REFERENCES `polls` (`id_poll`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");

    // 5. Create msg_deleted if not exists
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `msg_deleted` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `id_msg` int(11) NOT NULL,
          `id_user` int(11) NOT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_del` (`id_msg`,`id_user`),
          CONSTRAINT `fk_del_msg` FOREIGN KEY (`id_msg`) REFERENCES `msg` (`id_msg`) ON DELETE CASCADE,
          CONSTRAINT `fk_del_user` FOREIGN KEY (`id_user`) REFERENCES `users` (`id_user`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");

    // 6. Create msg_reads if not exists
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `msg_reads` (
          `id_read` int(11) NOT NULL AUTO_INCREMENT,
          `id_msg` int(11) NOT NULL,
          `id_user` int(11) NOT NULL,
          `read_at` datetime DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id_read`),
          UNIQUE KEY `unique_msg_user` (`id_msg`,`id_user`),
          CONSTRAINT `fk_reads_msg` FOREIGN KEY (`id_msg`) REFERENCES `msg` (`id_msg`) ON DELETE CASCADE,
          CONSTRAINT `fk_reads_user` FOREIGN KEY (`id_user`) REFERENCES `users` (`id_user`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");

    // 7. Add edited and edited_at column to msg if not exists
    try {
        $conn->query("SELECT edited FROM msg LIMIT 1");
    } catch (Exception $e_col) {
        $conn->exec("ALTER TABLE msg ADD COLUMN edited tinyint(1) NOT NULL DEFAULT 0, ADD COLUMN edited_at datetime DEFAULT NULL");
    }

    // 8. Make sure polls has tipe enum single/multiple
    try {
        $conn->query("SELECT tipe FROM polls LIMIT 1");
    } catch (Exception $e_col) {
        $conn->exec("ALTER TABLE polls ADD COLUMN tipe enum('single','multiple') NOT NULL DEFAULT 'single'");
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Database update error.')]);
    exit;
}

// Read action from POST first, then GET fallback (InfinityFree blocks GET query strings)
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$req_id = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
$isAdminOrOperator = in_array($_SESSION['role'] ?? '', ['admin', 'operator', 'superadmin']);
$isSuperAdmin = isSuperAdminRole();

function deleteMsgAttachmentFile($filePath) {
    if (empty($filePath)) return;
    $paths = [
        __DIR__ . '/../uploads/msg/' . $filePath,
    ];
    foreach ($paths as $path) {
        if (file_exists($path)) @unlink($path);
    }
}

function deletePollMediaFile($mediaPath) {
    if (empty($mediaPath)) return;
    $path = __DIR__ . '/../uploads/msg/' . $mediaPath;
    if (file_exists($path)) @unlink($path);
}

function permanentlyDeleteMessage($conn, $msgId) {
    $stmt = $conn->prepare("SELECT tipe, file_path, id_poll FROM msg WHERE id_msg = ? LIMIT 1");
    $stmt->execute([$msgId]);
    $msg = $stmt->fetch();
    if (!$msg) return false;

    if (in_array($msg['tipe'], ['image', 'file']) && !empty($msg['file_path'])) {
        deleteMsgAttachmentFile($msg['file_path']);
    }

    if ($msg['tipe'] === 'poll' && !empty($msg['id_poll'])) {
        $stmt_poll = $conn->prepare("SELECT media_path FROM polls WHERE id_poll = ? LIMIT 1");
        $stmt_poll->execute([$msg['id_poll']]);
        $poll = $stmt_poll->fetch();
        if ($poll && !empty($poll['media_path'])) {
            deletePollMediaFile($poll['media_path']);
        }
        $conn->prepare("DELETE FROM polls WHERE id_poll = ?")->execute([$msg['id_poll']]);
    }

    $conn->prepare("DELETE FROM msg_reads WHERE id_msg = ?")->execute([$msgId]);
    $conn->prepare("DELETE FROM msg_deleted WHERE id_msg = ?")->execute([$msgId]);
    $conn->prepare("DELETE FROM msg WHERE id_msg = ?")->execute([$msgId]);
    return true;
}

function softDeleteMessageRow($conn, $msg) {
    if (in_array($msg['tipe'], ['image', 'file']) && !empty($msg['file_path'])) {
        deleteMsgAttachmentFile($msg['file_path']);
    }
    if ($msg['tipe'] === 'poll' && !empty($msg['id_poll'])) {
        $conn->prepare("DELETE FROM polls WHERE id_poll = ?")->execute([$msg['id_poll']]);
    }
    $conn->prepare("UPDATE msg SET pesan = '🚫 Pesan ini telah dihapus', tipe = 'text', file_path = NULL, file_name = NULL, id_poll = NULL, pinned = 0 WHERE id_msg = ?")
        ->execute([$msg['id_msg']]);
}

if ($action === 'get') {
    $last_id = isset($_POST['last_id']) ? (int)$_POST['last_id'] : (isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0);
    try {
        // Fetch total active users for read receipt calculation
        $stmt_tot = $conn->query("SELECT COUNT(*) FROM users WHERE role != 'superadmin'");
        $total_users = (int)$stmt_tot->fetchColumn();

        $stmt = $conn->prepare("
            SELECT c.*, u.nama, u.role, u.foto_profil,
                   r.nama as reply_sender, r_c.pesan as reply_preview,
                   (SELECT COUNT(*) FROM msg_reads cr WHERE cr.id_msg = c.id_msg AND cr.id_user != c.id_user) as read_count,
                   (SELECT GROUP_CONCAT(us.nama ORDER BY cr.read_at ASC SEPARATOR ', ') FROM msg_reads cr JOIN users us ON cr.id_user = us.id_user WHERE cr.id_msg = c.id_msg AND cr.id_user != c.id_user) as read_by
            FROM msg c
            JOIN users u ON c.id_user = u.id_user
            LEFT JOIN msg r_c ON c.reply_to = r_c.id_msg
            LEFT JOIN users r ON r_c.id_user = r.id_user
            WHERE (
              c.id_msg > ?
              OR c.id_msg IN (
                  SELECT id_msg FROM (
                      SELECT id_msg FROM msg 
                      ORDER BY id_msg DESC LIMIT 50
                  ) as tmp
              )
            ) AND c.id_msg NOT IN (SELECT id_msg FROM msg_deleted WHERE id_user = ?)
            ORDER BY c.id_msg ASC
        ");
        $stmt->execute([$last_id, $_SESSION['id_user']]);
        $messages = $stmt->fetchAll();
        
        foreach ($messages as &$msg) {
            if ($msg['tipe'] === 'poll' && !empty($msg['id_poll'])) {
                // Fetch poll type
                $stmt_p = $conn->prepare("SELECT tipe, media_path, media_type FROM polls WHERE id_poll = ? LIMIT 1");
                $stmt_p->execute([$msg['id_poll']]);
                $poll_info = $stmt_p->fetch();
                $msg['poll_type'] = $poll_info['tipe'] ?? 'single';
                $msg['poll_media_path'] = $poll_info['media_path'] ?? null;
                $msg['poll_media_type'] = $poll_info['media_type'] ?? null;

                $stmt_opt = $conn->prepare("
                    SELECT po.*, COUNT(pv.id_vote) as votes_count,
                           (SELECT GROUP_CONCAT(us.nama SEPARATOR ', ') FROM poll_votes pv2 JOIN users us ON pv2.id_user = us.id_user WHERE pv2.id_option = po.id_option) as voter_names
                    FROM poll_options po 
                    LEFT JOIN poll_votes pv ON po.id_option = pv.id_option 
                    WHERE po.id_poll = ? 
                    GROUP BY po.id_option
                ");
                $stmt_opt->execute([$msg['id_poll']]);
                $msg['poll_options'] = $stmt_opt->fetchAll();

                $stmt_my = $conn->prepare("SELECT id_option FROM poll_votes WHERE id_poll = ? AND id_user = ?");
                $stmt_my->execute([$msg['id_poll'], $_SESSION['id_user']]);
                $msg['my_votes'] = $stmt_my->fetchAll(PDO::FETCH_COLUMN);
            }
        }
        
        echo json_encode(['success' => true, 'messages' => $messages, 'total_users' => $total_users]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal memuat pesan.')]);
    }
    exit;
}

if ($action === 'mark_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $last_id = isset($_POST['last_id']) ? (int)$_POST['last_id'] : 0;
    if ($last_id > 0) {
        try {
            $stmt = $conn->prepare("
                INSERT IGNORE INTO msg_reads (id_msg, id_user)
                SELECT id_msg, ? FROM msg 
                WHERE id_msg <= ? AND id_user != ?
            ");
            $stmt->execute([$_SESSION['id_user'], $last_id, $_SESSION['id_user']]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal menandai pesan dibaca.')]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid last_id']);
    }
    exit;
}

if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pesan = trim($_POST['pesan'] ?? '');
    $reply_to = !empty($_POST['reply_to']) ? (int)$_POST['reply_to'] : null;
    
    if (empty($pesan)) {
        echo json_encode(['success' => false, 'message' => 'Pesan kosong']);
        exit;
    }
    
    // Sanitize input to prevent Stored XSS
    $pesan = sanitizeOutput($pesan);
    
    try {
        $stmt = $conn->prepare("INSERT INTO msg (id_user, pesan, tipe, reply_to) VALUES (?, ?, 'text', ?)");
        $stmt->execute([$_SESSION['id_user'], $pesan, $reply_to]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal mengirim pesan.')]);
    }
    exit;
}

if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pesan = trim($_POST['pesan'] ?? '');
    if (!$req_id || empty($pesan)) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        exit;
    }
    
    // Sanitize input to prevent Stored XSS
    $pesan = sanitizeOutput($pesan);
    
    try {
        $stmt_chk = $conn->prepare("SELECT id_user, tipe FROM msg WHERE id_msg = ? LIMIT 1");
        $stmt_chk->execute([$req_id]);
        $msg = $stmt_chk->fetch();
        if (!$msg) {
            echo json_encode(['success' => false, 'message' => 'Pesan tidak ditemukan']);
            exit;
        }
        if ($msg['id_user'] != $_SESSION['id_user'] && !$isAdminOrOperator) {
            echo json_encode(['success' => false, 'message' => 'Forbidden']);
            exit;
        }
        if ($msg['tipe'] !== 'text') {
            echo json_encode(['success' => false, 'message' => 'Hanya pesan teks yang dapat diedit']);
            exit;
        }
        $stmt_upd = $conn->prepare("UPDATE msg SET pesan = ?, edited = 1, edited_at = CURRENT_TIMESTAMP WHERE id_msg = ?");
        $stmt_upd->execute([$pesan, $req_id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal mengedit pesan.')]);
    }
    exit;
}

if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $reply_to = !empty($_POST['reply_to']) ? (int)$_POST['reply_to'] : null;
    
    if (!isset($_FILES['msg_file']) || $_FILES['msg_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'File upload error']);
        exit;
    }
    
    $file = $_FILES['msg_file'];
    
    // Sanitize filename & extension checks
    $original_name = sanitizeFilename($file['name']);
    if (!isAllowedFileType($original_name, 'msg')) {
        echo json_encode(['success' => false, 'message' => 'Tipe file tidak diizinkan untuk alasan keamanan.']);
        exit;
    }
    
    // No file size limit for uploads as requested by user
    // Server-side MIME validation
    if (!validateFileMimeType($file['tmp_name'], 'msg')) {
        echo json_encode(['success' => false, 'message' => 'Konten file tidak valid atau berbahaya.']);
        exit;
    }
    
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    $is_image = in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp']);
    $tipe = $is_image ? 'image' : 'file';
    
    $new_name = generateSafeFilename($original_name, 'msg');
    $target_dir = __DIR__ . '/../uploads/msg/';
    
    if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
    
    if (move_uploaded_file($file['tmp_name'], $target_dir . $new_name)) {
        try {
            $caption = isset($_POST['pesan']) ? trim($_POST['pesan']) : '';
            if (empty($caption) && !$is_image) {
                $caption = $original_name;
            }
            $caption = sanitizeOutput($caption);
            
            $stmt = $conn->prepare("INSERT INTO msg (id_user, pesan, tipe, file_path, file_name, reply_to) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_SESSION['id_user'], 
                $caption, 
                $tipe, 
                $new_name, 
                $original_name, 
                $reply_to
            ]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            // Cleanup on database failure
            @unlink($target_dir . $new_name);
            echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal mengirim file ke database.')]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed moving file']);
    }
    exit;
}

if ($action === 'pin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdminOrOperator) {
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }
    try {
        $conn->prepare("UPDATE msg SET pinned = 1 WHERE id_msg = ?")->execute([$req_id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal menyematkan pesan.')]);
    }
    exit;
}

if ($action === 'unpin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdminOrOperator) {
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }
    try {
        $conn->prepare("UPDATE msg SET pinned = 0 WHERE id_msg = ?")->execute([$req_id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal melepas sematan pesan.')]);
    }
    exit;
}

if ($action === 'delete_for_me' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $stmt = $conn->prepare("INSERT IGNORE INTO msg_deleted (id_msg, id_user) VALUES (?, ?)");
        $stmt->execute([$req_id, $_SESSION['id_user']]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal menyembunyikan pesan.')]);
    }
    exit;
}

if ($action === 'delete_for_everyone' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $stmt_chk = $conn->prepare("SELECT id_user, tipe, file_path, id_poll FROM msg WHERE id_msg = ? LIMIT 1");
        $stmt_chk->execute([$req_id]);
        $msg = $stmt_chk->fetch();
        
        if (!$msg) {
            echo json_encode(['success' => false, 'message' => 'Pesan tidak ditemukan']);
            exit;
        }
        
        $isSuperAdmin = isSuperAdminRole();
        if ($msg['id_user'] != $_SESSION['id_user'] && !$isSuperAdmin) {
            echo json_encode(['success' => false, 'message' => 'Forbidden']);
            exit;
        }
        
        if (in_array($msg['tipe'], ['image', 'file']) && !empty($msg['file_path'])) {
            deleteMsgAttachmentFile($msg['file_path']);
        }

        // If it's a poll, cascade delete it from polls table
        if ($msg['tipe'] === 'poll' && !empty($msg['id_poll'])) {
            $conn->prepare("DELETE FROM polls WHERE id_poll = ?")->execute([$msg['id_poll']]);
        }
        
        $conn->prepare("UPDATE msg SET pesan = '🚫 Pesan ini telah dihapus', tipe = 'text', file_path = NULL, file_name = NULL, id_poll = NULL, pinned = 0 WHERE id_msg = ?")->execute([$req_id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal menghapus pesan.')]);
    }
    exit;
}

if ($action === 'delete_permanent' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isSuperAdmin) {
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }
    if (!$req_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid msg ID']);
        exit;
    }
    try {
        if (!permanentlyDeleteMessage($conn, $req_id)) {
            echo json_encode(['success' => false, 'message' => 'Pesan tidak ditemukan']);
            exit;
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal menghapus pesan permanen.')]);
    }
    exit;
}

if ($action === 'clear_group_chat' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isSuperAdmin) {
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }
    $mode = $_POST['mode'] ?? 'soft';
    if (!in_array($mode, ['soft', 'permanent'])) {
        echo json_encode(['success' => false, 'message' => 'Mode tidak valid']);
        exit;
    }
    try {
        $conn->beginTransaction();

        if ($mode === 'permanent') {
            $rows = $conn->query("SELECT id_msg, tipe, file_path, id_poll FROM msg")->fetchAll();
            foreach ($rows as $row) {
                if (in_array($row['tipe'], ['image', 'file']) && !empty($row['file_path'])) {
                    deleteMsgAttachmentFile($row['file_path']);
                }
            }
            $polls = $conn->query("SELECT id_poll, media_path FROM polls")->fetchAll();
            foreach ($polls as $poll) {
                if (!empty($poll['media_path'])) {
                    deletePollMediaFile($poll['media_path']);
                }
            }
            $conn->exec("DELETE FROM msg_reads");
            $conn->exec("DELETE FROM msg_deleted");
            $conn->exec("DELETE FROM polls");
            $conn->exec("DELETE FROM msg");
        } else {
            $rows = $conn->query("SELECT id_msg, tipe, file_path, id_poll, pesan FROM msg WHERE pesan NOT LIKE '%Pesan ini telah dihapus%'")->fetchAll();
            foreach ($rows as $row) {
                softDeleteMessageRow($conn, $row);
            }
        }

        $conn->commit();
        echo json_encode(['success' => true, 'message' => $mode === 'permanent' ? 'Semua pesan grup dihapus permanen.' : 'Semua pesan grup ditandai sebagai dihapus.']);
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal membersihkan pesan grup.')]);
    }
    exit;
}

if ($action === 'send_poll' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pertanyaan = trim($_POST['pertanyaan'] ?? '');
    $opsi = $_POST['opsi'] ?? [];
    $tipe = $_POST['tipe'] ?? 'single';
    if (!in_array($tipe, ['single', 'multiple'])) $tipe = 'single';
    
    if (empty($pertanyaan) || count($opsi) < 2) {
        echo json_encode(['success' => false, 'message' => 'Pertanyaan dan minimal 2 opsi wajib diisi']);
        exit;
    }
    
    $media_path = null;
    $media_type = null;
    
    if (isset($_FILES['poll_media']) && $_FILES['poll_media']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['poll_media'];
        $original_name = sanitizeFilename($file['name']);
        if (!isAllowedFileType($original_name, 'msg')) {
            echo json_encode(['success' => false, 'message' => 'Tipe file tidak diizinkan untuk polling.']);
            exit;
        }
        // No size limit as requested by user
        if (!validateFileMimeType($file['tmp_name'], 'msg')) {
            echo json_encode(['success' => false, 'message' => 'Konten file tidak valid atau berbahaya.']);
            exit;
        }
        
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'])) {
            $media_type = 'image';
        } elseif (in_array($ext, ['mp4', 'webm', 'mov'])) {
            $media_type = 'video';
        } else {
            echo json_encode(['success' => false, 'message' => 'Polling hanya mendukung lampiran Gambar, GIF, atau Video.']);
            exit;
        }
        
        $new_name = generateSafeFilename($original_name, 'msg');
        $target_dir = __DIR__ . '/../uploads/msg/';
        if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
        
        if (move_uploaded_file($file['tmp_name'], $target_dir . $new_name)) {
            $media_path = $new_name;
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal mengupload lampiran media polling.']);
            exit;
        }
    }
    
    try {
        $conn->beginTransaction();
        
        $stmt_p = $conn->prepare("INSERT INTO polls (pertanyaan, tipe, id_user, media_path, media_type) VALUES (?, ?, ?, ?, ?)");
        $stmt_p->execute([$pertanyaan, $tipe, $_SESSION['id_user'], $media_path, $media_type]);
        $new_poll_id = $conn->lastInsertId();
        
        $stmt_opt = $conn->prepare("INSERT INTO poll_options (id_poll, teks, gambar) VALUES (?, ?, ?)");
        foreach ($opsi as $index => $txt) {
            $txt = trim($txt);
            if (!empty($txt)) {
                $opt_gambar = null;
                $file_key = 'opsi_gambar_' . $index;
                if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES[$file_key];
                    $original_name = sanitizeFilename($file['name']);
                    if (isAllowedFileType($original_name, 'msg') && validateFileMimeType($file['tmp_name'], 'msg')) {
                        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                        if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'])) {
                            $new_name = generateSafeFilename($original_name, 'msg');
                            $target_dir = __DIR__ . '/../uploads/msg/';
                            if (move_uploaded_file($file['tmp_name'], $target_dir . $new_name)) {
                                $opt_gambar = $new_name;
                            }
                        }
                    }
                }
                $stmt_opt->execute([$new_poll_id, $txt, $opt_gambar]);
            }
        }
        
        $stmt_c = $conn->prepare("INSERT INTO msg (id_user, pesan, tipe, id_poll) VALUES (?, ?, 'poll', ?)");
        $stmt_c->execute([$_SESSION['id_user'], $pertanyaan, $new_poll_id]);
        
        $conn->commit();
        echo json_encode(['success' => true]);
    } catch(Exception $e) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal membuat polling.')]);
    }
    exit;
}

if ($action === 'vote_poll' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_poll = (int)($_POST['id_poll'] ?? 0);
    $id_option = (int)($_POST['id_option'] ?? 0);
    
    if (!$id_poll || !$id_option) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        exit;
    }
    
    try {
        $stmt_p = $conn->prepare("SELECT tipe FROM polls WHERE id_poll = ? LIMIT 1");
        $stmt_p->execute([$id_poll]);
        $poll = $stmt_p->fetch();
        if (!$poll) {
            echo json_encode(['success' => false, 'message' => 'Polling tidak ditemukan']);
            exit;
        }

        if ($poll['tipe'] === 'single') {
            // Check if user already voted for this option (cancel toggle)
            $stmt_chk = $conn->prepare("SELECT 1 FROM poll_votes WHERE id_poll = ? AND id_option = ? AND id_user = ? LIMIT 1");
            $stmt_chk->execute([$id_poll, $id_option, $_SESSION['id_user']]);
            if ($stmt_chk->fetch()) {
                // Clicked the same option again: remove vote
                $stmt_del = $conn->prepare("DELETE FROM poll_votes WHERE id_poll = ? AND id_user = ?");
                $stmt_del->execute([$id_poll, $_SESSION['id_user']]);
            } else {
                // Clicked a different option: remove old vote and save new
                $stmt_del = $conn->prepare("DELETE FROM poll_votes WHERE id_poll = ? AND id_user = ?");
                $stmt_del->execute([$id_poll, $_SESSION['id_user']]);
                
                $stmt_ins = $conn->prepare("INSERT INTO poll_votes (id_poll, id_option, id_user) VALUES (?, ?, ?)");
                $stmt_ins->execute([$id_poll, $id_option, $_SESSION['id_user']]);
            }
        } else {
            // Multiple choice - toggle vote
            $stmt_chk = $conn->prepare("SELECT 1 FROM poll_votes WHERE id_poll = ? AND id_option = ? AND id_user = ? LIMIT 1");
            $stmt_chk->execute([$id_poll, $id_option, $_SESSION['id_user']]);
            if ($stmt_chk->fetch()) {
                $stmt_del = $conn->prepare("DELETE FROM poll_votes WHERE id_poll = ? AND id_option = ? AND id_user = ?");
                $stmt_del->execute([$id_poll, $id_option, $_SESSION['id_user']]);
            } else {
                $stmt_ins = $conn->prepare("INSERT INTO poll_votes (id_poll, id_option, id_user) VALUES (?, ?, ?)");
                $stmt_ins->execute([$id_poll, $id_option, $_SESSION['id_user']]);
            }
        }
        
        echo json_encode(['success' => true]);
    } catch(Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal merekam pilihan Anda.')]);
    }
    exit;
}

if ($action === 'get_poll_details' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_poll = (int)($_POST['id_poll'] ?? 0);
    if (!$id_poll) {
        echo json_encode(['success' => false, 'message' => 'Invalid poll ID']);
        exit;
    }
    try {
        $stmt_opt = $conn->prepare("SELECT id_option, teks FROM poll_options WHERE id_poll = ? ORDER BY id_option ASC");
        $stmt_opt->execute([$id_poll]);
        $options = $stmt_opt->fetchAll();
        
        $stmt_votes = $conn->prepare("
            SELECT pv.id_option, u.nama, u.foto_profil 
            FROM poll_votes pv 
            JOIN users u ON pv.id_user = u.id_user 
            WHERE pv.id_poll = ?
        ");
        $stmt_votes->execute([$id_poll]);
        $votes = $stmt_votes->fetchAll();
        
        $grouped_votes = [];
        foreach ($options as $opt) {
            $grouped_votes[$opt['id_option']] = [
                'teks' => $opt['teks'],
                'voters' => []
            ];
        }
        
        foreach ($votes as $v) {
            if (isset($grouped_votes[$v['id_option']])) {
                $grouped_votes[$v['id_option']]['voters'][] = [
                    'nama' => $v['nama'],
                    'foto_profil' => $v['foto_profil']
                ];
            }
        }
        
        echo json_encode(['success' => true, 'options' => array_values($grouped_votes)]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal memuat detail polling.')]);
    }
    exit;
}

if ($action === 'get_read_details' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$req_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid msg ID']);
        exit;
    }
    try {
        $stmt = $conn->prepare("
            SELECT cr.read_at, u.nama, u.foto_profil 
            FROM msg_reads cr 
            JOIN users u ON cr.id_user = u.id_user 
            WHERE cr.id_msg = ? 
            ORDER BY cr.read_at DESC
        ");
        $stmt->execute([$req_id]);
        $readers = $stmt->fetchAll();
        echo json_encode(['success' => true, 'readers' => $readers]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal mengambil detail pembaca.')]);
    }
    exit;
}

if ($action === 'get_users' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $stmt = $conn->query("SELECT id_user, nama, foto_profil, role FROM users WHERE role != 'superadmin' ORDER BY nama ASC");
        $users = $stmt->fetchAll();
        echo json_encode(['success' => true, 'users' => $users]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal mengambil daftar pengguna.')]);
    }
    exit;
}

// Fetch pinned messages for real-time pin bar updates
if ($action === 'get_pinned') {
    try {
        $stmt = $conn->prepare("
            SELECT c.id_msg, c.pesan, c.tipe, c.file_name, u.nama
            FROM msg c
            JOIN users u ON c.id_user = u.id_user
            WHERE c.pinned = 1
            ORDER BY c.id_msg DESC
        ");
        $stmt->execute();
        $pinned = $stmt->fetchAll();
        echo json_encode(['success' => true, 'pinned' => $pinned]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal memuat pesan disematkan.')]);
    }
    exit;
}

// Fetch user profile for mention popup
if ($action === 'get_user_profile' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $target_name = $_POST['nama'] ?? '';
    if (empty($target_name)) {
        echo json_encode(['success' => false, 'message' => 'Nama required']);
        exit;
    }
    try {
        $stmt = $conn->prepare("SELECT id_user, nama, role, foto_profil, created_at FROM users WHERE nama = ? LIMIT 1");
        $stmt->execute([$target_name]);
        $user = $stmt->fetch();
        if ($user) {
            echo json_encode(['success' => true, 'user' => $user]);
        } else {
            echo json_encode(['success' => false, 'message' => 'User not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal memuat profil pengguna.')]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);

