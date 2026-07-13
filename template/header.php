<?php
// template/header.php
require_once __DIR__ . '/../config/security.php';
initSecureSession();
setSecurityHeaders();
$cspNonce = htmlspecialchars(getCspNonce(), ENT_QUOTES, 'UTF-8');

$base_url = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$base_url = preg_replace('/(\/(auth|dashboard|notes|msg|drive|calendar|server|admin|installer))?$/i', '', $base_url);
if ($base_url === '/') $base_url = '';

$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$current_page = basename($scriptName);
$pageKey = basename($scriptName, '.php');
if ($pageKey === 'index') {
    $moduleKey = basename(dirname($scriptName));
    if (in_array($moduleKey, ['admin', 'calendar', 'dashboard', 'drive', 'msg', 'notes', 'server'], true)) {
        $pageKey = $moduleKey;
    }
}
$allowedPageKeys = ['admin', 'calendar', 'dashboard', 'drive', 'error', 'index', 'login', 'msg', 'notes', 'pengaturan', 'preview', 'preview_word', 'register', 'server'];
if (!in_array($pageKey, $allowedPageKeys, true)) {
    $pageKey = 'index';
}
$sessionRole = $_SESSION['role'] ?? 'user';
if (!in_array($sessionRole, ['user', 'operator', 'admin', 'superadmin'], true)) {
    $sessionRole = 'user';
}

if (!file_exists(__DIR__ . '/../config/database.php')) {
    header("Location: $base_url/installer/");
    exit;
}
require_once __DIR__ . '/../config/database.php';

// Auto-logout if session credentials do not match database
verifyActiveSession($conn, $base_url);

// Redirect guests to login
if (!isset($_SESSION['id_user']) && empty($allow_guest_access)) {
    header("Location: $base_url/auth/login");
    exit;
}

// Fetch settings
$app_settings = ['site_title' => 'BIG FAMILY ITS 26', 'site_description' => 'Platform Kolaborasi Mahasiswa'];
try {
    if (isset($conn)) {
        $stmt_set = $conn->query("SELECT * FROM settings WHERE id = 1");
        if ($stmt_set && $s = $stmt_set->fetch()) {
            $app_settings = array_merge($app_settings, $s);
        }
    }
} catch (Exception $e) {}

$__userAvatar = !empty($_SESSION['foto_profil']) 
    ? $base_url.'/uploads/profil/'.htmlspecialchars($_SESSION['foto_profil']) 
    : 'https://ui-avatars.com/api/?name='.urlencode($_SESSION['nama'] ?? 'U').'&background=1f6feb&color=fff&size=80';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0">
    <title><?= htmlspecialchars($app_settings['site_title']) ?></title>
    <meta name="description" content="<?= htmlspecialchars($app_settings['site_description']) ?>">
    <meta name="csrf-token" content="<?= generateCsrfToken() ?>">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎓</text></svg>">
    <!-- DNS Prefetch & Preconnect for CDN speed -->
    <link rel="dns-prefetch" href="//fonts.googleapis.com">
    <link rel="dns-prefetch" href="//cdnjs.cloudflare.com">
    <link rel="dns-prefetch" href="//cdn.jsdelivr.net">
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
    <link rel="stylesheet" href="<?= $base_url ?>/assets/css/style.css?v=<?= assetVersion(__DIR__ . '/../assets/css/style.css') ?>">
    <!-- SweetAlert2 -->
    <script nonce="<?= $cspNonce ?>" src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script nonce="<?= $cspNonce ?>">
        // Setup CSRF header for all fetch requests automatically
        const originalFetch = window.fetch;
        window.fetch = function(url, options = {}) {
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            if (token) {
                options.headers = options.headers || {};
                if (options.headers instanceof Headers) {
                    options.headers.set('X-CSRF-Token', token);
                } else {
                    options.headers['X-CSRF-Token'] = token;
                }
            }
            return originalFetch(url, options);
        };
    </script>
</head>
<body data-page="<?= htmlspecialchars($pageKey, ENT_QUOTES, 'UTF-8') ?>" data-role="<?= htmlspecialchars($sessionRole, ENT_QUOTES, 'UTF-8') ?>">
    <script nonce="<?= $cspNonce ?>">
        if (localStorage.getItem('sidebar-minimized') === '1' && window.innerWidth >= 992) {
            document.body.classList.add('sidebar-minimized');
        }
    </script>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <img src="<?= $base_url ?>/assets/logo.jpeg" alt="" class="sidebar-brand-img">
            <div class="sidebar-brand-text"><?= htmlspecialchars($app_settings['site_title']) ?><small>Panel Mahasiswa</small></div>
            <button id="sidebarCollapseBtn" class="sidebar-collapse-btn" onclick="toggleSidebarMinimize()" title="Perkecil Sidebar"><i class="fas fa-angles-left"></i></button>
        </div>

        <nav class="sidebar-nav">
            <div class="sidebar-section">Menu Utama</div>
            <a href="<?= $base_url ?>/dashboard/" data-nav="dashboard" class="sidebar-link <?= strpos($current_page, 'index') !== false && strpos($_SERVER['REQUEST_URI'], 'dashboard') !== false ? 'active' : '' ?>">
                <i class="fas fa-home"></i><span>Beranda</span>
            </a>
            <a href="<?= $base_url ?>/notes/" data-nav="notes" class="sidebar-link <?= strpos($_SERVER['REQUEST_URI'], 'notes') !== false ? 'active' : '' ?>">
                <i class="fas fa-sticky-note"></i><span>Catatan Pribadi</span>
            </a>
            <a href="<?= $base_url ?>/msg/" data-nav="msg" class="sidebar-link <?= strpos($_SERVER['REQUEST_URI'], 'msg') !== false ? 'active' : '' ?>">
                <i class="fas fa-comments"></i><span>Pesan Grup</span>
                <span class="sidebar-badge" id="badge-msg" style="display:none">0</span>
            </a>
            <a href="<?= $base_url ?>/drive/" data-nav="drive" class="sidebar-link <?= strpos($_SERVER['REQUEST_URI'], 'drive') !== false ? 'active' : '' ?>">
                <i class="fas fa-folder-open"></i><span>Drive Bersama</span>
            </a>

            <div class="sidebar-section">Akademik</div>
            <a href="<?= $base_url ?>/calendar/" data-nav="calendar" class="sidebar-link <?= strpos($_SERVER['REQUEST_URI'], 'calendar') !== false ? 'active' : '' ?>">
                <i class="fas fa-calendar-alt"></i><span>Kalender</span>
            </a>

            <?php if (in_array($_SESSION['role'] ?? '', ['admin', 'operator', 'superadmin'])): ?>
            <div class="sidebar-section" data-admin-section="true">Administrasi</div>
            <a href="<?= $base_url ?>/admin/" data-nav="admin" data-admin-section="true" class="sidebar-link <?= strpos($_SERVER['REQUEST_URI'], 'admin') !== false ? 'active' : '' ?>">
                <i class="fas fa-user-shield"></i><span>Kelola Pengguna</span>
            </a>
            <?php if (($_SESSION['role'] ?? '') === 'superadmin'): ?>
            <a href="<?= $base_url ?>/auth/pengaturan#pengaturan-situs" data-nav="pengaturan-situs" data-admin-section="true" class="sidebar-link <?= strpos($_SERVER['REQUEST_URI'], 'pengaturan') !== false && strpos($_SERVER['REQUEST_URI'], '#pengaturan-situs') !== false ? 'active' : '' ?>">
                <i class="fas fa-sliders"></i><span>Pengaturan Situs</span>
            </a>
            <a href="<?= $base_url ?>/auth/pengaturan#kelola-pesan-grup" data-nav="kelola-pesan-grup" data-admin-section="true" class="sidebar-link <?= strpos($_SERVER['REQUEST_URI'], 'pengaturan') !== false && strpos($_SERVER['REQUEST_URI'], '#kelola-pesan-grup') !== false ? 'active' : '' ?>">
                <i class="fas fa-comments"></i><span>Kelola Pesan Grup</span>
            </a>
            <?php endif; ?>
            <?php endif; ?>

            <div class="sidebar-section">Lainnya</div>
            <a href="<?= $base_url ?>/auth/pengaturan" data-nav="pengaturan" class="sidebar-link <?= strpos($current_page, 'pengaturan') !== false ? 'active' : '' ?>">
                <i class="fas fa-gear"></i><span>Pengaturan</span>
            </a>
            <a href="javascript:void(0)" class="sidebar-link" onclick="startTutorial()" title="Buka Panduan Tutorial">
                <i class="fas fa-circle-question"></i><span>Panduan Tutorial</span>
            </a>
            <button type="button" id="logoutTrigger" class="sidebar-link sidebar-logout">
                <i class="fas fa-sign-out-alt"></i><span>Keluar</span>
            </button>
        </nav>

        <div class="sidebar-user">
            <div class="sidebar-user-avatar">
                <img src="<?= $__userAvatar ?>" alt="">
            </div>
            <div class="sidebar-user-info">
                <?= htmlspecialchars($_SESSION['nama'] ?? 'User') ?>
                <small><?= htmlspecialchars($_SESSION['role'] ?? 'user') ?></small>
            </div>
        </div>
    </aside>

    <dialog id="logoutDialog" class="logout-dialog" aria-labelledby="logoutDialogTitle" aria-describedby="logoutDialogDescription">
        <div class="logout-dialog-content">
            <div class="logout-dialog-icon" aria-hidden="true"><i class="fas fa-sign-out-alt"></i></div>
            <h2 id="logoutDialogTitle">Keluar dari akun?</h2>
            <p id="logoutDialogDescription">Sesi Anda akan diakhiri dan Anda perlu masuk kembali untuk mengakses aplikasi.</p>
            <form method="POST" action="<?= $base_url ?>/auth/logout" class="logout-dialog-actions">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <button type="button" id="logoutCancelButton" class="btn btn-secondary">Batal</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-sign-out-alt"></i> Ya, Keluar</button>
            </form>
        </div>
    </dialog>

    <!-- Topbar -->
    <nav class="topbar">
        <button class="topbar-toggle" id="sidebarToggle" aria-label="Buka menu navigasi" title="Buka menu navigasi"><i class="fas fa-bars"></i></button>
        <div class="topbar-brand">
            <img src="<?= $base_url ?>/assets/logo.jpeg" alt="" class="topbar-logo">
            <span><?= htmlspecialchars($app_settings['site_title']) ?></span>
        </div>
        <div class="topbar-actions">
            <button type="button" class="topbar-user" aria-label="Buka menu pengguna" title="Buka menu pengguna" onclick="document.getElementById('sidebarToggle').click()">
                <img src="<?= $__userAvatar ?>" alt="">
            </button>
        </div>
    </nav>

    <!-- Main Content Wrapper -->
    <main class="main-content">
