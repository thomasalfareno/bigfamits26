<?php
// drive/preview_word.php — sanitized Microsoft Word preview
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
if (!isDriveWordExtension($ext)) {
    http_response_code(415);
    exit;
}

$storageName = basename($file['file_path']);
$path = __DIR__ . '/../uploads/drive/' . $storageName;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$title = pathinfo($file['nama'], PATHINFO_FILENAME);
$safeBody = '';

if (isDriveDocxExtension($ext)) {
    $converted = convertDocxToHtmlPreview($path);
    $safeBody = $converted !== null ? $converted : '<p><em>Pratinjau tidak dapat dimuat. Silakan unduh file untuk membukanya.</em></p>';
} else {
    $safeBody = '<p><em>Format <strong>.doc</strong> (Word lama) tidak mendukung pratinjau di browser.</em></p>'
        . '<p>Silakan unduh file dan buka dengan Microsoft Word atau LibreOffice.</p>';
}

$cspNonce = getCspNonce();
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header("Content-Security-Policy: default-src 'none'; style-src-elem 'nonce-{$cspNonce}'; style-src-attr 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; script-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'; connect-src 'none'; media-src 'none'");

echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
    . '<style nonce="' . htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') . '">'
    . 'body{margin:0;padding:1.25rem;font-family:"Segoe UI",Tahoma,Geneva,Verdana,sans-serif;line-height:1.65;color:#24292f;background:#fff;}'
    . 'h1{font-size:1.35rem;margin:0 0 1rem;padding-bottom:.5rem;border-bottom:1px solid #d0d7de;color:#2b579a;}'
    . 'h2{font-size:1.15rem;margin:1rem 0 .5rem;color:#2b579a;}'
    . 'h3{font-size:1rem;margin:.75rem 0 .35rem;color:#2b579a;}'
    . 'p{margin:0 0 .75rem;}'
    . 'img{max-width:100%;height:auto;display:block;margin:8px 0;}'
    . 'table{border-collapse:collapse;width:100%;}'
    . 'td,th{border:1px solid #d0d7de;padding:6px 8px;}'
    . 'a{color:#0969da;text-decoration:underline;pointer-events:none;}'
    . '</style></head><body>'
    . '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
    . '<div class="word-preview-content">' . $safeBody . '</div>'
    . '</body></html>';
exit;
