<?php
// drive/file.php — secure file delivery (no direct uploads/drive/ access)
require_once __DIR__ . '/../config/security.php';
initSecureSession();
setSecurityHeaders();

if (!file_exists(__DIR__ . '/../config/database.php')) {
    http_response_code(503);
    exit;
}

require_once __DIR__ . '/../config/database.php';
verifyActiveSession($conn);

if (!isset($_SESSION['id_user'])) {
    http_response_code(403);
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$inline = isset($_GET['inline']) && $_GET['inline'] === '1';

if (!$id) {
    http_response_code(404);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT id_file, nama, file_path FROM files WHERE id_file = ? LIMIT 1");
    $stmt->execute([$id]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    exit;
}

if (!$file) {
    http_response_code(404);
    exit;
}

$storageName = basename($file['file_path']);
$path = __DIR__ . '/../uploads/drive/' . $storageName;

if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$downloadName = sanitizeFilename($file['nama']);
$restricted = isRestrictedDriveExtension(pathinfo($downloadName, PATHINFO_EXTENSION));
$allowInline = $inline && !$restricted && canDriveFileInlinePreview($downloadName);

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$detectedMime = $finfo ? finfo_file($finfo, $path) : 'application/octet-stream';
if ($finfo) {
    finfo_close($finfo);
}

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Content-Length: ' . filesize($path));

if ($allowInline) {
    header('Content-Type: ' . $detectedMime);
    header('Content-Disposition: inline; filename="' . $downloadName . '"');
} else {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
}

readfile($path);
exit;
