<?php
// drive/api_drive.php
require_once __DIR__ . '/../config/security.php';
initSecureSession();
header('Content-Type: application/json');

// Disable displaying errors to output to ensure we always return valid JSON
ini_set('display_errors', '0');
error_reporting(0);

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
$isSuperAdmin = ($_SESSION['role'] ?? '') === 'superadmin';
$isAdminOrOperator = in_array($_SESSION['role'] ?? '', ['admin', 'operator', 'superadmin']);

if ($action === 'create_folder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama = trim($_POST['nama'] ?? '');
    $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
    
    if (empty($nama)) {
        echo json_encode(['success' => false, 'message' => 'Nama folder wajib diisi']);
        exit;
    }
    
    // Sanitize folder name
    $nama = sanitizeOutput($nama);
    
    try {
        $stmt = $conn->prepare("INSERT INTO folders (nama, parent_id, id_user) VALUES (?, ?, ?)");
        $stmt->execute([$nama, $parent_id, $_SESSION['id_user']]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal membuat folder.')]);
    }
    exit;
}

if ($action === 'upload_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_folder = !empty($_POST['id_folder']) ? (int)$_POST['id_folder'] : null;
    
    // Check if $_FILES is empty (typically happens when post_max_size is exceeded)
    if (empty($_FILES) && isset($_SERVER['CONTENT_LENGTH'])) {
        echo json_encode(['success' => false, 'message' => 'Ukuran file melebihi batas upload server. Silakan upload file yang lebih kecil.']);
        exit;
    }
    
    if (!isset($_FILES['drive_file']) || $_FILES['drive_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Gagal mengupload file']);
        exit;
    }
    
    $file = $_FILES['drive_file'];
    
    // Sanitize filename & extension checks
    $original_name = sanitizeFilename($file['name']);
    if (!isAllowedFileType($original_name, 'drive')) {
        echo json_encode(['success' => false, 'message' => 'Tipe file tidak diizinkan untuk alasan keamanan.']);
        exit;
    }
    
    // Server-side MIME validation
    if (!validateFileMimeType($file['tmp_name'], 'drive')) {
        echo json_encode(['success' => false, 'message' => 'Konten file tidak valid atau berbahaya.']);
        exit;
    }
    
    $new_name = generateSafeFilename($original_name, 'drive');
    $target_dir = __DIR__ . '/../uploads/drive/';
    
    if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
    
    if (move_uploaded_file($file['tmp_name'], $target_dir . $new_name)) {
        try {
            $stmt = $conn->prepare("INSERT INTO files (nama, tipe, ukuran, file_path, id_folder, id_user) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $original_name,
                $file['type'] ?: strtolower(pathinfo($original_name, PATHINFO_EXTENSION)),
                $file['size'],
                $new_name,
                $id_folder,
                $_SESSION['id_user']
            ]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            // Cleanup uploaded file on DB failure
            @unlink($target_dir . $new_name);
            echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal menyimpan info file ke database.')]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal memindahkan file ke penyimpanan']);
    }
    exit;
}

if ($action === 'delete_folder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
    try {
        // Fetch folder to check owner
        $stmt_chk = $conn->prepare("SELECT id_user FROM folders WHERE id_folder = ? LIMIT 1");
        $stmt_chk->execute([$id]);
        $folder = $stmt_chk->fetch();
        
        if (!$folder) {
            echo json_encode(['success' => false, 'message' => 'Folder tidak ditemukan']);
            exit;
        }
        
        // Owner or Super Admin can delete
        if ($folder['id_user'] != $_SESSION['id_user'] && !$isSuperAdmin) {
            echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki hak menghapus folder ini']);
            exit;
        }

        deleteFolderRecursive($id, $conn);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal menghapus folder.')]);
    }
    exit;
}

if ($action === 'delete_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
    try {
        $stmt_chk = $conn->prepare("SELECT id_user, file_path FROM files WHERE id_file = ? LIMIT 1");
        $stmt_chk->execute([$id]);
        $file = $stmt_chk->fetch();
        
        if (!$file) {
            echo json_encode(['success' => false, 'message' => 'File tidak ditemukan']);
            exit;
        }
        
        // Owner or Super Admin can delete
        if ($file['id_user'] != $_SESSION['id_user'] && !$isSuperAdmin) {
            echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki hak menghapus file ini']);
            exit;
        }
        
        $path = __DIR__ . '/../uploads/drive/' . $file['file_path'];
        if (file_exists($path)) @unlink($path);
        
        $conn->prepare("DELETE FROM files WHERE id_file = ?")->execute([$id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal menghapus file.')]);
    }
    exit;
}

if ($action === 'rename_folder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $nama = trim($_POST['nama'] ?? '');
    
    if (!$id || empty($nama)) {
        echo json_encode(['success' => false, 'message' => 'ID folder dan nama baru wajib diisi']);
        exit;
    }
    
    // Sanitize folder name
    $nama = sanitizeOutput($nama);
    
    try {
        $stmt_chk = $conn->prepare("SELECT id_user FROM folders WHERE id_folder = ? LIMIT 1");
        $stmt_chk->execute([$id]);
        $folder = $stmt_chk->fetch();
        
        if (!$folder) {
            echo json_encode(['success' => false, 'message' => 'Folder tidak ditemukan']);
            exit;
        }
        
        // Owner or Super Admin can rename
        if ($folder['id_user'] != $_SESSION['id_user'] && !$isSuperAdmin) {
            echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki hak untuk mengubah nama folder ini']);
            exit;
        }
        
        $stmt = $conn->prepare("UPDATE folders SET nama = ? WHERE id_folder = ?");
        $stmt->execute([$nama, $id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal mengubah nama folder.')]);
    }
    exit;
}

if ($action === 'rename_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $nama = trim($_POST['nama'] ?? '');
    
    if (!$id || empty($nama)) {
        echo json_encode(['success' => false, 'message' => 'ID file dan nama baru wajib diisi']);
        exit;
    }
    
    // Sanitize filename format
    $nama = sanitizeFilename($nama);
    
    try {
        $stmt_chk = $conn->prepare("SELECT id_user, nama FROM files WHERE id_file = ? LIMIT 1");
        $stmt_chk->execute([$id]);
        $file = $stmt_chk->fetch();
        
        if (!$file) {
            echo json_encode(['success' => false, 'message' => 'File tidak ditemukan']);
            exit;
        }
        
        // Owner or Super Admin can rename
        if ($file['id_user'] != $_SESSION['id_user'] && !$isSuperAdmin) {
            echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki hak untuk mengubah nama file ini']);
            exit;
        }
        
        // Preserve original extension
        $old_ext = pathinfo($file['nama'], PATHINFO_EXTENSION);
        $new_name_without_ext = pathinfo($nama, PATHINFO_FILENAME);
        $final_nama = $new_name_without_ext . ($old_ext ? '.' . $old_ext : '');

        if (!isAllowedFileType($final_nama, 'drive')) {
            echo json_encode(['success' => false, 'message' => 'Nama file mengandung ekstensi yang tidak diizinkan.']);
            exit;
        }
        
        $stmt = $conn->prepare("UPDATE files SET nama = ? WHERE id_file = ?");
        $stmt->execute([$final_nama, $id]);
        echo json_encode(['success' => true, 'new_name' => $final_nama]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal mengubah nama file.')]);
    }
    exit;
}

function isFolderDescendant(PDO $conn, $folderId, $potentialAncestorId) {
    if (!$folderId || !$potentialAncestorId) {
        return false;
    }

    $currentId = $folderId;
    $depth = 0;
    while ($currentId && $depth < 50) {
        if ((int)$currentId === (int)$potentialAncestorId) {
            return true;
        }

        $stmt = $conn->prepare("SELECT parent_id FROM folders WHERE id_folder = ? LIMIT 1");
        $stmt->execute([$currentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            break;
        }

        $currentId = $row['parent_id'];
        $depth++;
    }

    return false;
}

function resolveDriveTargetFolderId($rawValue) {
    if ($rawValue === null || $rawValue === '' || $rawValue === 'root' || $rawValue === '0') {
        return null;
    }

    return (int)$rawValue;
}

if ($action === 'move_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $targetFolderId = resolveDriveTargetFolderId($_POST['id_folder'] ?? null);

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'File tidak ditemukan']);
        exit;
    }

    try {
        $stmt = $conn->prepare("SELECT id_user, id_folder FROM files WHERE id_file = ? LIMIT 1");
        $stmt->execute([$id]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$file) {
            echo json_encode(['success' => false, 'message' => 'File tidak ditemukan']);
            exit;
        }

        if ((int)$file['id_user'] !== (int)$_SESSION['id_user'] && !$isSuperAdmin) {
            echo json_encode(['success' => false, 'message' => 'Hanya pengunggah file yang dapat memindahkan file ini']);
            exit;
        }

        if ($targetFolderId !== null) {
            $stmtFolder = $conn->prepare("SELECT id_folder FROM folders WHERE id_folder = ? LIMIT 1");
            $stmtFolder->execute([$targetFolderId]);
            if (!$stmtFolder->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Folder tujuan tidak ditemukan']);
                exit;
            }
        }

        if ((int)$file['id_folder'] === (int)$targetFolderId) {
            echo json_encode(['success' => true, 'message' => 'File sudah berada di lokasi tersebut']);
            exit;
        }

        $stmtUpdate = $conn->prepare("UPDATE files SET id_folder = ? WHERE id_file = ?");
        $stmtUpdate->execute([$targetFolderId, $id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal memindahkan file.')]);
    }
    exit;
}

if ($action === 'move_folder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $targetParentId = resolveDriveTargetFolderId($_POST['parent_id'] ?? null);

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Folder tidak ditemukan']);
        exit;
    }

    try {
        $stmt = $conn->prepare("SELECT id_user, parent_id FROM folders WHERE id_folder = ? LIMIT 1");
        $stmt->execute([$id]);
        $folder = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$folder) {
            echo json_encode(['success' => false, 'message' => 'Folder tidak ditemukan']);
            exit;
        }

        if ((int)$folder['id_user'] !== (int)$_SESSION['id_user'] && !$isSuperAdmin) {
            echo json_encode(['success' => false, 'message' => 'Hanya pembuat folder yang dapat memindahkan folder ini']);
            exit;
        }

        if ($targetParentId === $id) {
            echo json_encode(['success' => false, 'message' => 'Folder tidak dapat dipindahkan ke dirinya sendiri']);
            exit;
        }

        if ($targetParentId !== null) {
            $stmtParent = $conn->prepare("SELECT id_folder FROM folders WHERE id_folder = ? LIMIT 1");
            $stmtParent->execute([$targetParentId]);
            if (!$stmtParent->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Folder tujuan tidak ditemukan']);
                exit;
            }

            if (isFolderDescendant($conn, $targetParentId, $id)) {
                echo json_encode(['success' => false, 'message' => 'Folder tidak dapat dipindahkan ke subfoldernya sendiri']);
                exit;
            }
        }

        if ((int)$folder['parent_id'] === (int)$targetParentId) {
            echo json_encode(['success' => true, 'message' => 'Folder sudah berada di lokasi tersebut']);
            exit;
        }

        $stmtUpdate = $conn->prepare("UPDATE folders SET parent_id = ? WHERE id_folder = ?");
        $stmtUpdate->execute([$targetParentId, $id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal memindahkan folder.')]);
    }
    exit;
}

if ($action === 'import_note_from_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_file = isset($_POST['id_file']) ? (int)$_POST['id_file'] : 0;

    if (!$id_file) {
        echo json_encode(['success' => false, 'message' => 'File tidak ditemukan']);
        exit;
    }

    try {
        $stmt = $conn->prepare("SELECT nama, file_path FROM files WHERE id_file = ? LIMIT 1");
        $stmt->execute([$id_file]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$file) {
            echo json_encode(['success' => false, 'message' => 'File tidak ditemukan']);
            exit;
        }

        $ext = strtolower(pathinfo($file['nama'], PATHINFO_EXTENSION));
        if (!canImportDriveFileToNotes($file['nama'])) {
            if (isDriveWordExtension($ext) && !isDriveDocxExtension($ext)) {
                echo json_encode(['success' => false, 'message' => 'Format .doc tidak dapat disalin ke catatan. Simpan sebagai .docx terlebih dahulu.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Hanya file HTML atau Word (.docx) yang dapat disalin ke catatan']);
            }
            exit;
        }

        $path = __DIR__ . '/../uploads/drive/' . basename($file['file_path']);
        if (!is_file($path)) {
            echo json_encode(['success' => false, 'message' => 'File fisik tidak ditemukan']);
            exit;
        }

        $parsed = parseDriveFileNoteImport($path, $file['nama']);
        if ($parsed === null) {
            echo json_encode(['success' => false, 'message' => 'Gagal membaca konten file']);
            exit;
        }

        $judul = trim($parsed['judul'] ?? '');
        $konten = $parsed['konten'] ?? '';

        if ($judul === '') {
            $base = pathinfo($file['nama'], PATHINFO_FILENAME);
            $base = preg_replace('/^\[Catatan\]\s*/i', '', $base);
            $judul = trim($base) !== '' ? trim($base) : 'Catatan Impor';
        }

        if ($judul === '' && trim(strip_tags($konten)) === '') {
            echo json_encode(['success' => false, 'message' => 'File tidak memiliki judul atau konten yang valid']);
            exit;
        }

        if (trim(strip_tags($konten)) === '') {
            $konten = '<p><br></p>';
        }

        $stmtIns = $conn->prepare("INSERT INTO notes (id_user, judul, konten) VALUES (?, ?, ?)");
        $stmtIns->execute([
            $_SESSION['id_user'],
            $judul,
            $konten
        ]);

        echo json_encode([
            'success' => true,
            'id_note' => (int)$conn->lastInsertId(),
            'message' => 'Berhasil disalin ke catatan Anda — silakan edit seperti catatan biasa'
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal mengimpor catatan.')]);
    }
    exit;
}

if ($action === 'upload_note' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_note = (int)($_POST['id_note'] ?? 0);
    if (!$id_note) {
        echo json_encode(['success' => false, 'message' => 'Catatan tidak ditemukan']);
        exit;
    }
    
    try {
        $stmt = $conn->prepare("SELECT judul, konten FROM notes WHERE id_note = ? AND id_user = ? LIMIT 1");
        $stmt->execute([$id_note, $_SESSION['id_user']]);
        $note = $stmt->fetch();
        
        if (!$note) {
            echo json_encode(['success' => false, 'message' => 'Catatan tidak ditemukan']);
            exit;
        }
        
        $filename = sanitizeFilename('[Catatan] ' . ($note['judul'] ?: 'Tanpa Judul') . '.html');
        $content = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <title>" . htmlspecialchars($note['judul']) . "</title>
            <style>
                body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 2.5rem; line-height: 1.6; color: #333; background: #fafafa; }
                .container { max-width: 800px; margin: 0 auto; background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
                h1 { margin-top: 0; color: #1f6feb; border-bottom: 2px solid #eaecef; padding-bottom: 0.5rem; }
            </style>
        </head>
        <body>
            <div class='container'>
                <h1>" . htmlspecialchars($note['judul']) . "</h1>
                <div>" . sanitizeHtmlContent($note['konten']) . "</div>
            </div>
        </body>
        </html>";
        
        $new_name = 'drive_' . time() . '_' . rand(1000, 9999) . '.html';
        $target_dir = __DIR__ . '/../uploads/drive/';
        
        if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
        
        if (file_put_contents($target_dir . $new_name, $content)) {
            $stmt_ins = $conn->prepare("INSERT INTO files (nama, tipe, ukuran, file_path, id_folder, id_user) VALUES (?, 'text/html', ?, ?, NULL, ?)");
            $stmt_ins->execute([
                $filename,
                strlen($content),
                $new_name,
                $_SESSION['id_user']
            ]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal menulis file']);
        }
    } catch(Exception $e) {
        echo json_encode(['success' => false, 'message' => safeErrorMessage($e, 'Gagal mengupload catatan.')]);
    }
    exit;
}

function getFolderPathString($folder_id, $conn) {
    if (!$folder_id) return 'Root';
    $path = [];
    $curr_id = $folder_id;
    $depth = 0;
    while ($curr_id && $depth < 10) {
        try {
            $stmt = $conn->prepare("SELECT nama, parent_id FROM folders WHERE id_folder = ? LIMIT 1");
            $stmt->execute([$curr_id]);
            $f = $stmt->fetch();
            if ($f) {
                array_unshift($path, $f['nama']);
                $curr_id = $f['parent_id'];
            } else {
                break;
            }
        } catch (Exception $e) {
            break;
        }
        $depth++;
    }
    return 'Root' . (count($path) > 0 ? ' / ' . implode(' / ', $path) : '');
}

if ($action === 'search_items') {
    $q = trim($_POST['q'] ?? $_GET['q'] ?? '');
    if (strlen($q) < 1) {
        echo json_encode(['success' => true, 'items' => []]);
        exit;
    }
    
    try {
        $like = '%' . $q . '%';
        
        // Find folders
        $stmt_f = $conn->prepare("
            SELECT f.id_folder, f.nama, f.parent_id, f.id_user, f.created_at, u.nama as creator, 'folder' as item_type 
            FROM folders f 
            JOIN users u ON f.id_user = u.id_user 
            WHERE f.nama LIKE ? 
            LIMIT 10
        ");
        $stmt_f->execute([$like]);
        $found_folders = $stmt_f->fetchAll();
        
        // Find files
        $stmt_fl = $conn->prepare("
            SELECT f.id_file, f.nama, f.tipe, f.ukuran, f.file_path, f.id_folder, f.id_user, f.created_at, u.nama as uploader, 'file' as item_type 
            FROM files f 
            JOIN users u ON f.id_user = u.id_user 
            WHERE f.nama LIKE ? 
            LIMIT 10
        ");
        $stmt_fl->execute([$like]);
        $found_files = $stmt_fl->fetchAll();
        
        $items = [];
        
        $formatBytesFunc = function($bytes) {
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $bytes = max($bytes, 0);
            $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
            $pow = min($pow, count($units) - 1);
            $bytes /= pow(1024, $pow);
            return round($bytes, 2) . ' ' . $units[$pow];
        };
        
        foreach ($found_folders as $folder) {
            $location = getFolderPathString($folder['parent_id'], $conn);
            $items[] = [
                'id_folder' => $folder['id_folder'],
                'nama' => $folder['nama'],
                'parent_id' => $folder['parent_id'],
                'id_user' => $folder['id_user'],
                'created_at' => $folder['created_at'],
                'creator' => $folder['creator'],
                'item_type' => 'folder',
                'location' => $location
            ];
        }
        
        foreach ($found_files as $file) {
            $location = getFolderPathString($file['id_folder'], $conn);
            $mediaType = getDriveMediaType($file['nama']);

            $items[] = [
                'id_file' => $file['id_file'],
                'nama' => $file['nama'],
                'tipe' => $file['tipe'],
                'ukuran' => $formatBytesFunc($file['ukuran']),
                'file_path' => $file['file_path'],
                'id_folder' => $file['id_folder'],
                'id_user' => $file['id_user'],
                'created_at' => $file['created_at'],
                'uploader' => $file['uploader'],
                'item_type' => 'file',
                'media_type' => $mediaType,
                'preview_url' => in_array($mediaType, ['html', 'note_html'], true)
                    ? getDrivePreviewUrl($file['id_file'])
                    : ($mediaType === 'word'
                        ? getDriveWordPreviewUrl($file['id_file'])
                        : getDriveFileUrl($file['id_file'], true)),
                'download_url' => getDriveFileUrl($file['id_file'], false),
                'can_import' => canImportDriveFileToNotes($file['nama']),
                'location' => $location
            ];
        }
        
        echo json_encode(['success' => true, 'items' => $items]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

function deleteFolderRecursive($folder_id, $conn) {
    // 1. Fetch & physically delete all files in this folder
    $stmt_files = $conn->prepare("SELECT id_file, file_path FROM files WHERE id_folder = ?");
    $stmt_files->execute([$folder_id]);
    $files = $stmt_files->fetchAll();
    
    foreach ($files as $f) {
        $path = __DIR__ . '/../uploads/drive/' . $f['file_path'];
        if (file_exists($path)) @unlink($path);
    }
    
    // Delete files database records
    $conn->prepare("DELETE FROM files WHERE id_folder = ?")->execute([$folder_id]);
    
    // 2. Fetch and recursively delete subfolders
    $stmt_subs = $conn->prepare("SELECT id_folder FROM folders WHERE parent_id = ?");
    $stmt_subs->execute([$folder_id]);
    $subs = $stmt_subs->fetchAll();
    
    foreach ($subs as $s) {
        deleteFolderRecursive($s['id_folder'], $conn);
    }
    
    // 3. Delete directory database record
    $conn->prepare("DELETE FROM folders WHERE id_folder = ?")->execute([$folder_id]);
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
?>
