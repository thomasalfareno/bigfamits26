<?php
// drive/preview.php — sanitized HTML preview (no script execution)
require_once __DIR__ . '/../config/security.php';
initSecureSession();

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
if (!$id) {
    http_response_code(404);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT nama, file_path FROM files WHERE id_file = ? LIMIT 1");
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

$ext = strtolower(pathinfo($file['nama'], PATHINFO_EXTENSION));
if (!isDriveHtmlExtension($ext)) {
    http_response_code(415);
    exit;
}

$storageName = basename($file['file_path']);
$path = __DIR__ . '/../uploads/drive/' . $storageName;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$rawHtml = file_get_contents($path);
if ($rawHtml === false) {
    http_response_code(500);
    exit;
}

$title = extractHtmlDocumentTitle($rawHtml, pathinfo($file['nama'], PATHINFO_FILENAME));
$safeBody = extractSanitizedHtmlPreview($rawHtml);

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; script-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'; connect-src 'none'; media-src 'none'");

echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
    . '<style>'
    . 'body{margin:0;padding:1.25rem;font-family:"Segoe UI",Tahoma,Geneva,Verdana,sans-serif;line-height:1.65;color:#24292f;background:#fff;}'
    . 'h1{font-size:1.35rem;margin:0 0 1rem;padding-bottom:.5rem;border-bottom:1px solid #d0d7de;color:#1f6feb;}'
    . 'img,video{max-width:100%;height:auto;}'
    . 'table{border-collapse:collapse;width:100%;}'
    . 'td,th{border:1px solid #d0d7de;padding:6px 8px;}'
    . 'a{color:#0969da;text-decoration:underline;pointer-events:none;}'
    . '</style></head><body>'
    . '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
    . '<div class="drive-preview-content">' . $safeBody . '</div>'
    . '</body></html>';
exit;
