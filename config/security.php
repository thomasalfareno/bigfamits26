<?php
// config/security.php — Core Security Utilities
// This file provides reusable security functions for the entire application.

/**
 * Initialize secure session settings.
 * Call this BEFORE session_start().
 */
function initSecureSession() {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.gc_maxlifetime', 2592000);
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.use_strict_mode', 1);
        ini_set('session.use_only_cookies', 1);
        
        // Set Secure flag only if HTTPS
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            ini_set('session.cookie_secure', 1);
        }
        
        session_set_cookie_params([
            'lifetime' => 2592000,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ]);
        session_start();
        
        // Regenerate session ID periodically (every 30 minutes)
        if (!isset($_SESSION['_last_regen'])) {
            $_SESSION['_last_regen'] = time();
        } elseif (time() - $_SESSION['_last_regen'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['_last_regen'] = time();
        }
    }
}

/**
 * Generate a CSRF token and store it in session.
 */
function generateCsrfToken() {
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

/**
 * Force regenerate CSRF token (useful on login/register/logout).
 */
function regenerateCsrfToken() {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['_csrf_token'];
}

/**
 * Validate CSRF token from request.
 * Checks POST body and X-CSRF-Token header.
 */
function validateCsrfToken() {
    $token = $_POST['_csrf_token'] 
        ?? $_SERVER['HTTP_X_CSRF_TOKEN'] 
        ?? '';
    
    if (empty($token) || empty($_SESSION['_csrf_token'])) {
        return false;
    }
    
    return hash_equals($_SESSION['_csrf_token'], $token);
}

/**
 * Require valid CSRF token or die with JSON error.
 * Use in API endpoints.
 */
function requireCsrfToken() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCsrfToken()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']);
        exit;
    }
}

/**
 * Sanitize output for HTML context. Prevents XSS.
 */
function sanitizeOutput($str) {
    if ($str === null) return '';
    return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Sanitize HTML content — strip dangerous tags/attributes but keep safe formatting.
 * Used for rich text content (TinyMCE notes).
 */
function sanitizeHtmlContent($html) {
    if (empty($html)) return '';
    
    // Normalize html entity encoding to prevent obfuscated bypass (e.g. j&#97;vascript:)
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // Remove script tags and their content recursively
    while (preg_match('/<script\b[^>]*>(.*?)<\/script>/is', $html)) {
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
    }
    
    // Remove event handlers (onerror, onclick, onload, onmouseover, etc.)
    $html = preg_replace('/\b(on\w+)\s*=\s*["\'][^"\']*["\']/is', '', $html);
    $html = preg_replace('/\b(on\w+)\s*=\s*[^\s>]+/is', '', $html);
    
    // Block javascript/vbscript in URLs
    $html = preg_replace('/(href|src|style|background|url)\s*=\s*["\']?\s*(javascript|vbscript)\s*:/is', '$1="#blocked"', $html);
    // Allow data:image in src only; block other data: URIs
    $html = preg_replace('/\bsrc\s*=\s*["\']?\s*data\s*:\s*(?!image\/(?:png|jpeg|jpg|gif|webp|bmp))/is', 'src="#blocked"', $html);
    $html = preg_replace('/(href|style|background|url)\s*=\s*["\']?\s*data\s*:/is', '$1="#blocked"', $html);
    
    // Remove dangerous style expressions / dynamic styles
    $html = preg_replace('/expression\s*\(/is', 'blocked_expression(', $html);
    $html = preg_replace('/behavior\s*:/is', 'blocked_behavior:', $html);
    
    // Remove srcdoc attributes (inline HTML in iframes)
    $html = preg_replace('/\bsrcdoc\s*=\s*["\'][^"\']*["\']/is', '', $html);
    $html = preg_replace('/\bsrcdoc\s*=\s*[^\s>]+/is', '', $html);

    // Remove dangerous tags and their content
    $dangerous_tags = [
        'iframe', 'object', 'embed', 'applet', 'form', 'input', 'button',
        'textarea', 'select', 'base', 'meta', 'link', 'style', 'svg',
        'math', 'frameset', 'frame', 'script', 'noscript', 'template',
        'foreignobject', 'use', 'import', 'portal', 'marquee', 'isindex',
        'keygen', 'canvas'
    ];
    foreach ($dangerous_tags as $tag) {
        while (preg_match('/<' . $tag . '\b[^>]*>(.*?)<\/' . $tag . '>/is', $html)) {
            $html = preg_replace('/<' . $tag . '\b[^>]*>(.*?)<\/' . $tag . '>/is', '', $html);
        }
        $html = preg_replace('/<' . $tag . '\b[^>]*\/?>/is', '', $html);
    }

    // Block data:text/html and other dangerous data URIs in attributes
    $html = preg_replace('/(href|src|xlink:href|formaction|poster|background)\s*=\s*["\']?\s*data\s*:\s*text\/html/is', '$1="#blocked"', $html);

    // Final pass: remove any remaining null bytes
    $html = str_replace("\0", '', $html);

    return $html;
}

/**
 * Sanitize a filename for safe filesystem storage.
 */
function sanitizeFilename($name) {
    // Remove path components
    $name = basename($name);
    // Remove null bytes
    $name = str_replace("\0", '', $name);
    // Keep only alphanumeric, dashes, underscores, dots
    $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
    // Prevent double extensions like .php.jpg
    $name = preg_replace('/\.{2,}/', '.', $name);
    return $name;
}

/**
 * Whitelist of allowed file extensions (chat & rich-text media uploads).
 */
function getAllowedExtensions($context = 'drive') {
    $common_safe = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico',
                    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
                    'txt', 'csv', 'rtf', 'odt', 'ods', 'odp',
                    'zip', 'rar', '7z', 'tar', 'gz',
                    'mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac',
                    'mp4', 'webm', 'mov', 'avi', 'mkv'];

    if ($context === 'msg' || $context === 'media') {
        return $common_safe;
    }

    return $common_safe;
}

/**
 * Blocked (dangerous) file extensions — executable/server-side script files.
 */
function getBlockedExtensions() {
    return [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar',
        'cgi', 'pl', 'py', 'pyc', 'rb', 'sh', 'bat', 'cmd', 'com',
        'exe', 'msi', 'dll', 'so',
        'asp', 'aspx', 'jsp', 'jspx',
        'htaccess', 'htpasswd',
        'vbs', 'wsf', 'wsh',
    ];
}

/**
 * Drive extensions allowed for upload but never rendered inline in the browser.
 */
function getRestrictedDriveExtensions() {
    return ['svg', 'js', 'mjs', 'ts', 'shtml', 'shtm', 'xhtml', 'html', 'htm'];
}

/**
 * Extensions that may be previewed inline through the secure drive file endpoint.
 */
function getDriveInlineExtensions() {
    return [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico',
        'pdf',
        'mp4', 'webm', 'mov',
        'mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac',
    ];
}

function isRestrictedDriveExtension($ext) {
    return in_array(strtolower(ltrim((string)$ext, '.')), getRestrictedDriveExtensions(), true);
}

function canDriveFileInlinePreview($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, getDriveInlineExtensions(), true);
}

function isDriveNoteExportFilename($filename) {
    return stripos(basename((string)$filename), '[Catatan]') === 0;
}

function isDriveHtmlExtension($ext) {
    return in_array(strtolower(ltrim((string)$ext, '.')), ['html', 'htm'], true);
}

function isDriveWordExtension($ext) {
    return in_array(strtolower(ltrim((string)$ext, '.')), ['doc', 'docx', 'docm'], true);
}

function isDriveDocxExtension($ext) {
    return in_array(strtolower(ltrim((string)$ext, '.')), ['docx', 'docm'], true);
}

/**
 * Absolute URL base for uploaded note media (used when importing DOCX images).
 */
function getAppUploadUrlBase($subdir = 'notes') {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $base_url = preg_replace('/(\/(auth|dashboard|notes|msg|drive|calendar|server|admin|installer))?$/i', '', $scriptDir);
    if ($base_url === '/') {
        $base_url = '';
    }
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . '://' . $host . $base_url . '/uploads/' . trim($subdir, '/') . '/';
}

function getNotesUploadDir() {
    return __DIR__ . '/../uploads/notes/';
}

function buildDocxImageRelationshipMap(ZipArchive $zip) {
    $relsXml = $zip->getFromName('word/_rels/document.xml.rels');
    $map = [];
    if ($relsXml === false || $relsXml === '') {
        return $map;
    }

    $dom = new DOMDocument();
    if (@$dom->loadXML($relsXml) === false) {
        return $map;
    }

    foreach ($dom->getElementsByTagName('Relationship') as $rel) {
        if (!$rel instanceof DOMElement) {
            continue;
        }

        $id = $rel->getAttribute('Id');
        $target = str_replace('\\', '/', $rel->getAttribute('Target'));
        $type = $rel->getAttribute('Type');
        if ($id === '' || $target === '') {
            continue;
        }

        $isImage = stripos($type, 'image') !== false
            || preg_match('/\.(png|jpe?g|gif|webp|bmp|tiff?)$/i', $target);

        if (!$isImage) {
            continue;
        }

        if (strpos($target, 'word/') === 0) {
            $map[$id] = $target;
        } else {
            $map[$id] = 'word/' . ltrim(preg_replace('#^\.\./#', '', $target), '/');
        }
    }

    return $map;
}

function getDocxEmbedIdFromRun(DOMXPath $xpath, DOMNode $run) {
    $nsR = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
    $xpath->registerNamespace('r', $nsR);
    $xpath->registerNamespace('v', 'urn:schemas-microsoft-com:vml');

    $blips = $xpath->query('.//a:blip', $run);
    if ($blips && $blips->length > 0) {
        $node = $blips->item(0);
        if ($node instanceof DOMElement) {
            $embed = $node->getAttributeNS($nsR, 'embed');
            if ($embed === '') {
                $embed = $node->getAttribute('r:embed');
            }
            if ($embed !== '') {
                return $embed;
            }
        }
    }

    $imageNodes = $xpath->query('.//v:imagedata', $run);
    if ($imageNodes && $imageNodes->length > 0) {
        $node = $imageNodes->item(0);
        if ($node instanceof DOMElement) {
            $relId = $node->getAttributeNS($nsR, 'id');
            if ($relId === '') {
                $relId = $node->getAttribute('r:id');
            }
            if ($relId !== '') {
                return $relId;
            }
        }
    }

    return null;
}

function renderDocxEmbeddedImage(ZipArchive $zip, $embedId, array $relMap, array $options) {
    if ($embedId === null || $embedId === '' || !isset($relMap[$embedId])) {
        return '';
    }

    $mediaPath = $relMap[$embedId];
    $imageData = $zip->getFromName($mediaPath);
    if ($imageData === false || $imageData === '') {
        return '';
    }

    $ext = strtolower(pathinfo($mediaPath, PATHINFO_EXTENSION));
    $allowedImg = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'];
    if (!in_array($ext, $allowedImg, true)) {
        return '';
    }

    $imageMode = $options['images'] ?? 'none';
    $style = 'max-width:100%;height:auto;display:block;margin:8px 0';

    if ($imageMode === 'save') {
        $dir = rtrim($options['save_dir'] ?? getNotesUploadDir(), '/\\') . DIRECTORY_SEPARATOR;
        $urlBase = $options['url_base'] ?? getAppUploadUrlBase('notes');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $newName = generateSafeFilename('image.' . $ext, 'docx');
        if (@file_put_contents($dir . $newName, $imageData) === false) {
            return '';
        }
        $src = $urlBase . $newName;
        return '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="" style="' . $style . '" />';
    }

    if ($imageMode === 'inline') {
        $mimeMap = [
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
        ];
        $mime = $mimeMap[$ext] ?? 'image/png';
        $src = 'data:' . $mime . ';base64,' . base64_encode($imageData);
        return '<img src="' . $src . '" alt="" style="' . $style . '" />';
    }

    return '';
}

/**
 * Convert DOCX (Office Open XML) to safe HTML with optional embedded images.
 *
 * Options:
 *   images => 'none' | 'inline' (base64 preview) | 'save' (copy to uploads/notes)
 *   save_dir, url_base — required when images=save
 */
function convertDocxToHtml($path, array $options = []) {
    if (!class_exists('ZipArchive')) {
        return null;
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return null;
    }

    $xmlContent = $zip->getFromName('word/document.xml');
    if ($xmlContent === false || $xmlContent === '') {
        $zip->close();
        return null;
    }

    $relMap = buildDocxImageRelationshipMap($zip);

    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    if (@$dom->loadXML($xmlContent) === false) {
        $zip->close();
        return null;
    }

    $xpath = new DOMXPath($dom);
    $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $xpath->registerNamespace('w', $ns);

    $readAttr = static function (DOMNode $node, $localName) use ($ns) {
        if (!$node instanceof DOMElement) {
            return '';
        }
        $val = $node->getAttributeNS($ns, $localName);
        return $val !== '' ? $val : $node->getAttribute('w:' . $localName);
    };

    $isRunFlagOn = static function (DOMXPath $xpath, DOMNode $run, $flag) use ($readAttr, $ns) {
        $nodes = $xpath->query('.//w:' . $flag, $run);
        if (!$nodes || $nodes->length === 0) {
            return false;
        }
        $node = $nodes->item(0);
        if (!$node instanceof DOMElement || !$node->hasAttributes()) {
            return true;
        }
        $val = $readAttr($node, 'val');
        return !in_array(strtolower($val), ['0', 'false', 'off'], true);
    };

    $html = '';
    $paragraphs = $xpath->query('//w:body/w:p');
    if (!$paragraphs || $paragraphs->length === 0) {
        $zip->close();
        return '<p><em>Dokumen kosong.</em></p>';
    }

    foreach ($paragraphs as $paragraph) {
        $tag = 'p';
        $styleNodes = $xpath->query('./w:pPr/w:pStyle', $paragraph);
        if ($styleNodes && $styleNodes->length > 0) {
            $styleVal = $readAttr($styleNodes->item(0), 'val');
            if (preg_match('/heading\s*1/i', $styleVal) || $styleVal === 'Title') {
                $tag = 'h1';
            } elseif (preg_match('/heading\s*2/i', $styleVal)) {
                $tag = 'h2';
            } elseif (preg_match('/heading\s*3/i', $styleVal)) {
                $tag = 'h3';
            }
        }

        $inner = '';
        $runs = $xpath->query('./w:r', $paragraph);
        if ($runs) {
            foreach ($runs as $run) {
                $embedId = getDocxEmbedIdFromRun($xpath, $run);
                if ($embedId !== null) {
                    $inner .= renderDocxEmbeddedImage($zip, $embedId, $relMap, $options);
                    continue;
                }

                $textNodes = $xpath->query('.//w:t', $run);
                $runText = '';
                if ($textNodes) {
                    foreach ($textNodes as $textNode) {
                        $runText .= $textNode->textContent;
                    }
                }

                $breakNodes = $xpath->query('.//w:br', $run);
                if ($breakNodes && $breakNodes->length > 0) {
                    $runText .= '<br>';
                }

                if ($runText === '') {
                    continue;
                }

                $escaped = htmlspecialchars($runText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $escaped = str_replace('&lt;br&gt;', '<br>', $escaped);

                if ($isRunFlagOn($xpath, $run, 'b')) {
                    $escaped = '<strong>' . $escaped . '</strong>';
                }
                if ($isRunFlagOn($xpath, $run, 'i')) {
                    $escaped = '<em>' . $escaped . '</em>';
                }
                if ($isRunFlagOn($xpath, $run, 'u')) {
                    $escaped = '<u>' . $escaped . '</u>';
                }

                $inner .= $escaped;
            }
        }

        $hasImage = stripos($inner, '<img') !== false;
        if (trim(strip_tags($inner)) === '' && !$hasImage) {
            $html .= '<p><br></p>';
        } else {
            $html .= '<' . $tag . '>' . $inner . '</' . $tag . '>';
        }
    }

    $zip->close();
    return sanitizeHtmlContent($html);
}

/**
 * Convert DOCX for in-app preview (images embedded as base64).
 */
function convertDocxToHtmlPreview($path) {
    return convertDocxToHtml($path, ['images' => 'inline']);
}

function getDriveWordPreviewUrl($id_file) {
    return 'preview_word?id=' . (int)$id_file;
}

function canImportDriveFileToNotes($filename) {
    $ext = strtolower(pathinfo((string)$filename, PATHINFO_EXTENSION));
    return isDriveHtmlExtension($ext) || isDriveDocxExtension($ext);
}

function extractDocxDocumentTitle($path, $fallback = 'Catatan Impor') {
    if (!class_exists('ZipArchive')) {
        return $fallback;
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return $fallback;
    }

    $core = $zip->getFromName('docProps/core.xml');
    $zip->close();

    if ($core && preg_match('/<dc:title[^>]*>(.*?)<\/dc:title>/is', $core, $matches)) {
        $title = trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($title !== '') {
            return $title;
        }
    }

    return $fallback;
}

function parseDriveWordNoteImport($path, $filename = '') {
    $fallbackTitle = 'Catatan Impor';
    if ($filename !== '') {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        if (trim($base) !== '') {
            $fallbackTitle = trim($base);
        }
    }

    $konten = convertDocxToHtml($path, [
        'images' => 'save',
        'save_dir' => getNotesUploadDir(),
        'url_base' => getAppUploadUrlBase('notes'),
    ]);
    if ($konten === null) {
        return null;
    }

    return [
        'judul' => extractDocxDocumentTitle($path, $fallbackTitle),
        'konten' => $konten,
    ];
}

function parseDriveFileNoteImport($path, $filename) {
    $ext = strtolower(pathinfo((string)$filename, PATHINFO_EXTENSION));

    if (isDriveDocxExtension($ext)) {
        return parseDriveWordNoteImport($path, $filename);
    }

    if (isDriveHtmlExtension($ext)) {
        $rawHtml = file_get_contents($path);
        if ($rawHtml === false) {
            return null;
        }
        return parseDriveHtmlNoteImport($rawHtml, $filename);
    }

    return null;
}

/**
 * Extract and sanitize HTML body content for safe in-app preview.
 */
function extractSanitizedHtmlPreview($html) {
    if (empty($html)) {
        return '';
    }

    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    if (preg_match('/<div\s+class=[\'"]container[\'"][^>]*>\s*<h1[^>]*>.*?<\/h1>\s*<div>(.*?)<\/div>\s*<\/div>/is', $html, $matches)) {
        return sanitizeHtmlContent($matches[1]);
    }

    if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $matches)) {
        return sanitizeHtmlContent($matches[1]);
    }

    return sanitizeHtmlContent($html);
}

function extractHtmlDocumentTitle($html, $fallback = 'Catatan Impor') {
    if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $matches)) {
        $title = trim(strip_tags($matches[1]));
        if ($title !== '') {
            return $title;
        }
    }

    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
        $title = trim(strip_tags($matches[1]));
        if ($title !== '') {
            return $title;
        }
    }

    return $fallback;
}

function parseDriveHtmlNoteImport($html, $filename = '') {
    $fallbackTitle = 'Catatan Impor';
    if ($filename !== '') {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $base = preg_replace('/^\[Catatan\]\s*/i', '', $base);
        if (trim($base) !== '') {
            $fallbackTitle = trim($base);
        }
    }

    return [
        'judul' => extractHtmlDocumentTitle($html, $fallbackTitle),
        'konten' => extractSanitizedHtmlPreview($html),
    ];
}

function getDriveMediaType($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if (isDriveHtmlExtension($ext)) {
        return isDriveNoteExportFilename($filename) ? 'note_html' : 'html';
    }
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'ico'])) {
        return 'image';
    }
    if ($ext === 'pdf') {
        return 'pdf';
    }
    if (in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'mkv'])) {
        return 'video';
    }
    if (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac'])) {
        return 'audio';
    }
    if (isRestrictedDriveExtension($ext)) {
        return 'restricted';
    }
    if (in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz'])) {
        return 'zip';
    }
    if (isDriveWordExtension($ext)) {
        return 'word';
    }
    if (in_array($ext, ['xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'rtf', 'odt', 'ods', 'odp'])) {
        return 'document';
    }

    return 'file';
}

function getDriveFileUrl($id_file, $inline = false) {
    $url = 'file?id=' . (int)$id_file;
    if ($inline) {
        $url .= '&inline=1';
    }
    return $url;
}

function getDrivePreviewUrl($id_file) {
    return 'preview?id=' . (int)$id_file;
}

/**
 * Reject filenames that hide a dangerous extension (e.g. shell.php.txt).
 */
function hasDangerousExtensionInFilename($filename) {
    $blocked = getBlockedExtensions();
    $parts = explode('.', strtolower(basename($filename)));
    if (count($parts) <= 1) {
        return false;
    }

    for ($i = 1; $i < count($parts); $i++) {
        if (in_array($parts[$i], $blocked, true)) {
            return true;
        }
    }

    return false;
}

/**
 * Check if a file extension is allowed for upload.
 */
function isAllowedFileType($filename, $context = 'drive') {
    if (hasDangerousExtensionInFilename($filename)) {
        return false;
    }

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $blocked = getBlockedExtensions();

    if (in_array($ext, $blocked, true)) {
        return false;
    }

    if ($context === 'msg' || $context === 'media') {
        return in_array($ext, getAllowedExtensions($context), true);
    }

    // Drive accepts all file types except dangerous executables/scripts.
    return true;
}

/**
 * Validate MIME type of uploaded file server-side.
 * Returns true if the file's actual MIME type is safe for the given context.
 */
function validateFileMimeType($filepath, $context = 'drive') {
    if (!file_exists($filepath)) {
        return false;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) {
        return false;
    }

    $mime = finfo_file($finfo, $filepath);
    finfo_close($finfo);

    $blocked_mimes = [
        'application/x-httpd-php',
        'application/x-php',
        'text/x-php',
        'application/x-executable',
        'application/x-msdownload',
        'application/x-dosexec',
        'application/x-sharedlib',
        'application/x-shellscript',
    ];

    if ($context === 'msg' || $context === 'media') {
        $blocked_mimes = array_merge($blocked_mimes, [
            'text/html',
            'application/xhtml+xml',
            'image/svg+xml',
            'application/javascript',
            'text/javascript',
        ]);
    }

    return !in_array($mime, $blocked_mimes, true);
}

/**
 * Generate a safe random filename preserving the (validated) extension.
 */
function generateSafeFilename($originalName, $prefix = 'file') {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    return $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
}

/**
 * Set security headers for all responses.
 */
function setSecurityHeaders() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    
    // Content Security Policy — allow inline styles/scripts (needed for the app)
    // but block external script sources except trusted CDNs
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://cdn.tiny.cloud https://*.tinymce.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://cdn.tiny.cloud https://cdn.jsdelivr.net https://*.tinymce.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; img-src 'self' data: blob: https://ui-avatars.com https://sp.tinymce.com https://*.tinymce.com https://cdn.tiny.cloud https://cdn.jsdelivr.net; connect-src 'self' https://cdn.jsdelivr.net https://cdn.tiny.cloud https://sp.tinymce.com https://*.tinymce.com; frame-src 'self' data: blob:; object-src 'none'; base-uri 'self';");
}

/**
 * Simple rate limiting using session.
 * Returns true if the action is allowed, false if rate limited.
 */
function checkRateLimit($action, $maxAttempts = 5, $windowSeconds = 900) {
    $key = '_rl_' . $action;
    $now = time();
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = [];
    }
    
    // Clean old entries
    $_SESSION[$key] = array_filter($_SESSION[$key], function($ts) use ($now, $windowSeconds) {
        return ($now - $ts) < $windowSeconds;
    });
    
    if (count($_SESSION[$key]) >= $maxAttempts) {
        return false;
    }
    
    $_SESSION[$key][] = $now;
    return true;
}

/**
 * File-based IP rate limiter to prevent bot brute force / spam actions.
 * Stores attempt timestamps in serialized json files under uploads/rate_limit/.
 */
function checkIpRateLimit($action, $maxAttempts = 5, $windowSeconds = 900) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $hash = md5($ip . '_' . $action);
    $dir = __DIR__ . '/../uploads/rate_limit/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    
    // Ensure direct access to rate limits is forbidden
    $htaccessFile = $dir . '.htaccess';
    if (!file_exists($htaccessFile)) {
        @file_put_contents($htaccessFile, "Require all denied\n");
    }
    
    $file = $dir . $hash . '.json';
    $now = time();
    $attempts = [];
    if (file_exists($file)) {
        $content = @file_get_contents($file);
        $attempts = json_decode($content, true) ?: [];
    }
    
    // Clean old entries
    $attempts = array_filter($attempts, function($ts) use ($now, $windowSeconds) {
        return ($now - $ts) < $windowSeconds;
    });
    
    if (count($attempts) >= $maxAttempts) {
        return false;
    }
    
    $attempts[] = $now;
    @file_put_contents($file, json_encode(array_values($attempts)));
    return true;
}

/**
 * Return a safe error message for API responses.
 * Never expose internal error details to the user.
 */
function safeErrorMessage($exception, $genericMessage = 'Terjadi kesalahan pada server.') {
    // In production, always return generic message
    // Optionally log the real error
    error_log('[BigFamITS26] Error: ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine());
    return $genericMessage;
}

/**
 * Finalize default accounts after database installation.
 * Writes superadmin.local.php and syncs hashed passwords in the database.
 */
function finalizeInstallationAccounts(PDO $conn, $superAdminUsername, $superAdminPassword, $adminPassword = 'admin123') {
    $saHash = password_hash($superAdminPassword, PASSWORD_DEFAULT);
    $adminHash = password_hash($adminPassword, PASSWORD_DEFAULT);

    $localFile = __DIR__ . '/superadmin.local.php';
    $localContent = "<?php\n"
        . "/**\n * Super Admin credentials — generated at install. Do not commit.\n */\n"
        . "return [\n"
        . "    'username' => " . var_export($superAdminUsername, true) . ",\n"
        . "    'password_hash' => " . var_export($saHash, true) . ",\n"
        . "    'display_name' => 'System',\n"
        . "    'role' => 'superadmin',\n"
        . "];\n";
    file_put_contents($localFile, $localContent);

    $stmt = $conn->prepare("SELECT id_user FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$superAdminUsername]);
    if ($stmt->fetch()) {
        $conn->prepare("UPDATE users SET password = ?, role = 'superadmin', plain_password = NULL WHERE username = ?")
            ->execute([$saHash, $superAdminUsername]);
    } else {
        $conn->prepare("INSERT INTO users (nama, username, password, plain_password, role) VALUES ('System', ?, ?, NULL, 'superadmin')")
            ->execute([$superAdminUsername, $saHash]);
    }

    $conn->exec("UPDATE users SET plain_password = NULL WHERE role = 'superadmin'");

    $stmtAdmin = $conn->prepare("SELECT id_user FROM users WHERE username = 'admin' LIMIT 1");
    $stmtAdmin->execute();
    if ($stmtAdmin->fetch()) {
        $conn->prepare("UPDATE users SET password = ? WHERE username = 'admin'")->execute([$adminHash]);
    } else {
        $conn->prepare("INSERT INTO users (nama, username, password, role) VALUES ('Administrator', 'admin', ?, 'admin')")
            ->execute([$adminHash]);
    }
}

/**
 * Super Admin configuration.
 * Credentials are loaded from config/superadmin.local.php (not committed to repo).
 * Copy superadmin.local.php.example and customize for your deployment.
 */
function getSuperAdminConfig() {
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $localFile = __DIR__ . '/superadmin.local.php';
    if (file_exists($localFile)) {
        $loaded = require $localFile;
        if (is_array($loaded) && !empty($loaded['username']) && !empty($loaded['password_hash'])) {
            $config = $loaded;
            return $config;
        }
    }

    $config = [
        'username' => '',
        'password_hash' => '',
        'display_name' => 'System',
        'role' => 'superadmin',
    ];
    return $config;
}

/**
 * Usernames reserved for the protected super admin account.
 */
function getReservedSuperAdminUsernames() {
    $saConfig = getSuperAdminConfig();
    $reserved = ['bigfamits26'];
    if (!empty($saConfig['username'])) {
        $reserved[] = strtolower($saConfig['username']);
    }
    return array_unique($reserved);
}

function isReservedSuperAdminUsername($username) {
    return in_array(strtolower(trim($username)), getReservedSuperAdminUsernames(), true);
}

/**
 * Plain-text password storage is disabled for super admin accounts.
 */
function shouldStorePlainPassword($role) {
    return ($role ?? '') !== 'superadmin';
}

/**
 * Role 'user' is displayed as Mahasiswa in the UI.
 */
function isMahasiswaRole($role = null) {
    return ($role ?? $_SESSION['role'] ?? '') === 'user';
}

function isSuperAdminRole($role = null) {
    return ($role ?? $_SESSION['role'] ?? '') === 'superadmin';
}

/**
 * All roles except mahasiswa (user) may create global calendar agendas.
 */
function canCreateGlobalAgenda($role = null) {
    return !isMahasiswaRole($role);
}

/**
 * Verify delete confirmation password.
 * ONLY accepts the TARGET user's password — the user being deleted.
 * This ensures that only someone who knows the target's password can delete the account.
 */
function verifyDeleteConfirmationPassword(PDO $conn, int $targetId, string $password): bool {
    $password = trim($password);
    if ($password === '') {
        return false;
    }

    // Verify against the target user's password in database
    $stmt = $conn->prepare("SELECT password FROM users WHERE id_user = ? LIMIT 1");
    $stmt->execute([$targetId]);
    $targetHash = $stmt->fetchColumn();
    if ($targetHash && password_verify($password, $targetHash)) {
        return true;
    }

    return false;
}

/**
 * Verify if the logged-in user session matches the database records.
 * Logs out users immediately if username/password hash has changed or user is deleted.
 */
function verifyActiveSession($conn, $base_url = '') {
    if (isset($_SESSION['id_user']) && isset($conn)) {
        try {
            $stmt = $conn->prepare("SELECT password, username FROM users WHERE id_user = ? LIMIT 1");
            $stmt->execute([$_SESSION['id_user']]);
            $user = $stmt->fetch();
            
            if (!$user || 
                (isset($_SESSION['login_password_hash']) && $_SESSION['login_password_hash'] !== $user['password']) ||
                (isset($_SESSION['username']) && $_SESSION['username'] !== $user['username'])
            ) {
                // Clear session variables
                $_SESSION = array();
                
                // Destroy session cookie
                if (ini_get("session.use_cookies")) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000,
                        $params["path"], $params["domain"],
                        $params["secure"], $params["httponly"]
                    );
                }
                
                // Destroy session
                session_destroy();
                
                // If it is an API request, return JSON. Otherwise redirect.
                if (strpos($_SERVER['SCRIPT_NAME'], 'api_') !== false) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Sesi Anda telah berakhir karena perubahan akun. Silakan login kembali.']);
                    exit;
                } else {
                    header("Location: " . $base_url . "/auth/login?logout_reason=session_invalid");
                    exit;
                }
            }
        } catch(Exception $e) {}
    }
}

/**
 * Force POST request method for write APIs.
 */
function requirePostMethod() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('HTTP/1.1 405 Method Not Allowed');
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Method Not Allowed. Hanya request POST yang diperbolehkan.']);
        exit;
    }
}

define('DB_ENCRYPTION_KEY', 'a8c14f9d6571520281b95ff883df1e5927c6f0923f798e21bc56338abffc46a8');

/**
 * Encrypt a plain-text password using AES-256-CBC.
 */
function encryptUserData($data) {
    if (empty($data)) return null;
    $key = hash('sha256', DB_ENCRYPTION_KEY);
    $iv_length = openssl_cipher_iv_length('aes-256-cbc');
    $iv = openssl_random_pseudo_bytes($iv_length);
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
    return base64_encode($iv . '::' . $encrypted);
}

/**
 * Decrypt a ciphertext back into plain text.
 * Falls back to returning the original data if it's not encrypted (legacy plain text).
 */
function decryptUserData($data) {
    if (empty($data)) return null;
    $decoded = base64_decode($data, true);
    if ($decoded === false) {
        return $data; // Plain text / legacy
    }
    if (strpos($decoded, '::') === false) {
        return $data; // Plain text / legacy
    }
    list($iv, $encrypted) = explode('::', $decoded, 2);
    $key = hash('sha256', DB_ENCRYPTION_KEY);
    $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
    if ($decrypted === false) {
        return $data; // If decryption fails, return as-is
    }
    return $decrypted;
}

/**
 * Check whether plain_password is stored in AES format (encryptUserData).
 */
function isPlainPasswordEncrypted(?string $data): bool
{
    if ($data === null || $data === '') {
        return false;
    }

    $decoded = base64_decode($data, true);
    if ($decoded === false || strpos($decoded, '::') === false) {
        return false;
    }

    $decrypted = decryptUserData($data);
    return $decrypted !== null && $decrypted !== '' && $decrypted !== $data;
}

/**
 * Backfill plain_password after successful login when only bcrypt exists.
 * Bcrypt cannot be reversed — the verified login password is the only safe source.
 */
function backfillPlainPasswordAfterLogin(PDO $conn, array $user, string $verifiedPlainPassword): bool
{
    if (!shouldStorePlainPassword($user['role'] ?? '')) {
        return false;
    }

    $current = $user['plain_password'] ?? null;
    if (isPlainPasswordEncrypted($current)) {
        return false;
    }

    $encrypted = encryptUserData($verifiedPlainPassword);
    if ($encrypted === null) {
        return false;
    }

    $conn->prepare('UPDATE users SET plain_password = ? WHERE id_user = ?')
        ->execute([$encrypted, $user['id_user']]);

    return true;
}
?>
