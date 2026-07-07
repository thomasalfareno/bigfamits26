<?php
// auth/api_upload_tinymce.php
require_once __DIR__ . '/../config/security.php';
initSecureSession();
header('Content-Type: application/json');

// Disable displaying errors to output to ensure we always return valid JSON
ini_set('display_errors', '0');
error_reporting(0);

// Check if request is POST but $_FILES is empty (typically happens when post_max_size is exceeded)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH'])) {
    echo json_encode(['error' => ['message' => 'Ukuran file terlalu besar melebihi batas upload server.']]);
    exit;
}

if (!file_exists(__DIR__ . '/../config/database.php')) {
    echo json_encode(['error' => ['message' => 'Database not found']]);
    exit;
}
require_once __DIR__ . '/../config/database.php';

// If active session is invalid, verifyActiveSession will destroy it and exit
verifyActiveSession($conn);

if (!isset($_SESSION['id_user'])) {
    echo json_encode(['error' => ['message' => 'Unauthorized']]);
    exit;
}

// Security validations
requirePostMethod();
requireCsrfToken();

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => ['message' => 'Gagal mengupload file (Error Code: ' . ($_FILES['file']['error'] ?? 'NONE') . ')']]);
    exit;
}

$file = $_FILES['file'];

// Sanitize filename & extension checks
$original_name = sanitizeFilename($file['name']);
if (!isAllowedFileType($original_name, 'media')) {
    echo json_encode(['error' => ['message' => 'Tipe file tidak diizinkan untuk alasan keamanan.']]);
    exit;
}

// Server-side MIME validation
if (!validateFileMimeType($file['tmp_name'], 'media')) {
    echo json_encode(['error' => ['message' => 'Konten file tidak valid atau berbahaya.']]);
    exit;
}

// Size limit: 250MB for video/audio (media), 10MB for other file types (images/docs)
$ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
$media_exts = ['mp4', 'webm', 'mov', 'avi', 'mkv', 'mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac'];
$is_media = in_array($ext, $media_exts);
$max_size = $is_media ? 250 * 1024 * 1024 : 10 * 1024 * 1024;

if ($file['size'] > $max_size) {
    $max_size_text = $is_media ? '250MB' : '10MB';
    echo json_encode(['error' => ['message' => 'Ukuran file maksimal untuk editor adalah ' . $max_size_text . '.']]);
    exit;
}

$new_name = generateSafeFilename($original_name, 'editor');
$target_dir = __DIR__ . '/../uploads/notes/';

if (!is_dir($target_dir)) {
    @mkdir($target_dir, 0755, true);
}

// Write .htaccess inside uploads/notes/ to block PHP file executions but allow images/files
$htaccessFile = $target_dir . '.htaccess';
if (!file_exists($htaccessFile)) {
    @file_put_contents($htaccessFile, "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phps .phar\nForceType application/octet-stream\n<FilesMatch \"\\.(?i:jpe?g|gif|png|webp|pdf|zip|rar|7z|mp4|mp3|docx?|xlsx?|pptx?)$\">\n    ForceType none\n</FilesMatch>\n");
}

if (move_uploaded_file($file['tmp_name'], $target_dir . $new_name)) {
    // Generate absolute HTTP URL
    $base_url = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    $base_url = preg_replace('/(\/(auth|dashboard|notes|msg|drive|calendar|server|admin|installer))?$/i', '', $base_url);
    if ($base_url === '/') $base_url = '';

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $full_base_url = $protocol . '://' . $host . $base_url;
    
    $file_url = $full_base_url . '/uploads/notes/' . $new_name;

    // TinyMCE expects a "location" key with the path to the uploaded file
    echo json_encode(['location' => $file_url]);
} else {
    echo json_encode(['error' => ['message' => 'Gagal memindahkan file ke direktori tujuan.']]);
}
exit;
?>
