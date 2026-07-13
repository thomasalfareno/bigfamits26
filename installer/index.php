<?php
// installer/index.php
require_once __DIR__ . '/../config/security.php';
initSecureSession();
setSecurityHeaders();
$cspNonce = htmlspecialchars(getCspNonce(), ENT_QUOTES, 'UTF-8');
require_once __DIR__ . '/../config/migration_security.php';

$base_url = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$base_url = preg_replace('/(\/(auth|dashboard|notes|msg|drive|calendar|server|admin|installer))?$/i', '', $base_url);
if ($base_url === '/') $base_url = '';

$dbConfigured = file_exists(__DIR__ . '/../config/database.php');
$mode = $_GET['mode'] ?? $_POST['mode'] ?? '';
$step = (int)($_GET['step'] ?? $_POST['step'] ?? 0);
$error = '';
$migrationResult = null;
$activeDbConfig = null;
$csrfToken = generateCsrfToken();

if ($dbConfigured && $mode === '' && $step === 0) {
    $mode = 'migrate';
}
if ($dbConfigured && $mode !== 'migrate' && !($mode === 'install' && $step === 2)) {
    header("Location: " . $base_url . "/dashboard/");
    exit;
}

if ($dbConfigured && $mode === 'migrate' && !SqlMigrationValidator::requireSuperAdminSession()) {
    header("Location: " . $base_url . "/auth/login?redirect=" . urlencode($base_url . "/installer/index?mode=migrate&step=2"));
    exit;
}

if ($dbConfigured && $mode === 'migrate') {
    require_once __DIR__ . '/../config/database.php';
    verifyActiveSession($conn, $base_url);
    $activeDbConfig = [
        'host' => $host,
        'user' => $user,
        'pass' => $pass,
        'name' => $db,
    ];
}

function writeDatabaseConfig(string $host, string $user, string $pass, string $name): void
{
    $esc_host = addcslashes($host, "'\\");
    $esc_user = addcslashes($user, "'\\");
    $esc_pass = addcslashes($pass, "'\\");
    $esc_name = addcslashes($name, "'\\");

    $config_content = "<?php\n// config/database.php\ndate_default_timezone_set('Asia/Jakarta');\n\n\$host = '$esc_host';\n\$user = '$esc_user';\n\$pass = '$esc_pass';\n\$db   = '$esc_name';\n\ntry {\n    \$conn = new PDO(\"mysql:host=\$host;dbname=\$db;charset=utf8mb4\", \$user, \$pass);\n    \$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);\n    \$conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);\n    \$conn->exec(\"SET time_zone = '+07:00'\");\n} catch(PDOException \$e) {\n    header('Location: ' . dirname(\$_SERVER['SCRIPT_NAME']) . '/../installer/');\n    exit;\n}\n?>";

    $config_dir = __DIR__ . '/../config';
    if (!is_dir($config_dir)) mkdir($config_dir, 0755, true);
    file_put_contents($config_dir . '/database.php', $config_content);
}

function ensureUploadDirs(): void
{
    $uploads_dir = __DIR__ . '/../uploads';
    if (!is_dir($uploads_dir)) mkdir($uploads_dir, 0755, true);
    foreach (['msg', 'profil', 'drive', 'notes', 'rate_limit'] as $sub) {
        if (!is_dir($uploads_dir . '/' . $sub)) mkdir($uploads_dir . '/' . $sub, 0755, true);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        $error = 'Token keamanan tidak valid. Muat ulang halaman dan coba lagi.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    if ($mode === 'install' && $step === 1) {
        $host = $_POST['db_host'] ?? 'localhost';
        $user = $_POST['db_user'] ?? 'root';
        $pass = $_POST['db_pass'] ?? '';
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['db_name'] ?? 'bigfam_its26');
        $superAdminUsername = trim($_POST['superadmin_username'] ?? '');
        $superAdminPassword = $_POST['superadmin_password'] ?? '';
        $superAdminPasswordConfirm = $_POST['superadmin_password_confirm'] ?? '';
        $adminUsername = trim($_POST['admin_username'] ?? '');
        $adminPassword = $_POST['admin_password'] ?? '';
        $adminPasswordConfirm = $_POST['admin_password_confirm'] ?? '';

        if (empty($name)) {
            $error = "Nama database tidak valid.";
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $superAdminUsername)) {
            $error = 'Username Super Admin harus 3-50 karakter dan hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda minus.';
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $adminUsername)) {
            $error = 'Username Admin harus 3-50 karakter dan hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda minus.';
        } elseif (strcasecmp($superAdminUsername, $adminUsername) === 0) {
            $error = 'Username Admin dan Super Admin harus berbeda.';
        } elseif (!isStrongPassword($superAdminPassword)) {
            $error = 'Password Super Admin minimal 8 karakter.';
        } elseif (!hash_equals($superAdminPassword, $superAdminPasswordConfirm)) {
            $error = 'Konfirmasi password Super Admin tidak cocok.';
        } elseif (!isStrongPassword($adminPassword)) {
            $error = 'Password Admin minimal 8 karakter.';
        } elseif (!hash_equals($adminPassword, $adminPasswordConfirm)) {
            $error = 'Konfirmasi password Admin tidak cocok.';
        } elseif (hash_equals($superAdminPassword, $adminPassword)) {
            $error = 'Password Admin dan Super Admin harus berbeda.';
        } elseif (isReservedSuperAdminUsername($adminUsername)) {
            $error = 'Username Admin tersebut dicadangkan untuk Super Admin.';
        } else {
            try {
                $conn = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass);
                $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $conn->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;");

                $conn = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass);
                $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                $tableExists = $conn->query("SHOW TABLES LIKE 'users'")->rowCount() > 0;
                if (!$tableExists) {
                    $sql_file = __DIR__ . '/../database.sql';
                    if (file_exists($sql_file)) {
                        $conn->exec(file_get_contents($sql_file));
                    } else {
                        $error = "File database.sql tidak ditemukan di root project.";
                    }
                }

                if (empty($error)) {
                    require_once __DIR__ . '/../config/security.php';
                    finalizeInstallationAccounts($conn, $superAdminUsername, $superAdminPassword, $adminUsername, $adminPassword);
                    $_SESSION['_install_credentials'] = [
                        'superadmin_username' => $superAdminUsername,
                        'admin_username' => $adminUsername,
                    ];
                    writeDatabaseConfig($host, $user, $pass, $name);
                    ensureUploadDirs();
                    header("Location: " . $base_url . "/installer/index?mode=install&step=2");
                    exit;
                }
            } catch (PDOException $e) {
                $error = "Kesalahan Database: Hubungi Administrator.";
                error_log("Database installation error: " . $e->getMessage());
            }
        }
    }

    if ($mode === 'migrate' && $step === 1) {
        $host = $_POST['db_host'] ?? 'localhost';
        $user = $_POST['db_user'] ?? 'root';
        $pass = $_POST['db_pass'] ?? '';
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['db_name'] ?? 'bigfam_its26');

        if (empty($name)) {
            $error = "Nama database tidak valid.";
        } else {
            try {
                $conn = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass);
                $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $conn->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;");

                $_SESSION['_migrate_db'] = compact('host', 'user', 'pass', 'name');
                header("Location: " . $base_url . "/installer/index?mode=migrate&step=2");
                exit;
            } catch (PDOException $e) {
                $error = "Koneksi database gagal. Periksa host, user, dan password.";
                error_log("Migration DB config error: " . $e->getMessage());
            }
        }
    }

    if ($mode === 'migrate' && $step === 2) {
        if (!checkIpRateLimit('sql_migration', 3, 3600)) {
            $error = 'Terlalu banyak percobaan migrasi. Coba lagi dalam 1 jam.';
        } elseif (empty($_POST['confirm_backup'])) {
            $error = 'Centang konfirmasi bahwa file SQL berasal dari backup resmi.';
        } elseif ($dbConfigured && !SqlMigrationValidator::verifySuperAdminPassword(
            $conn,
            (int)($_SESSION['id_user'] ?? 0),
            $_POST['superadmin_password'] ?? ''
        )) {
            $error = 'Password Super Admin salah. Migrasi ditolak.';
        } elseif (empty($_FILES['sql_file']['tmp_name'])) {
            $error = 'File SQL wajib diupload.';
        } else {
            $fileValidation = SqlMigrationValidator::validateUploadedFile($_FILES['sql_file']);
            if (!$fileValidation['valid']) {
                $error = implode(' ', $fileValidation['errors']);
            } else {
                $db = $_SESSION['_migrate_db'] ?? null;
                if (!$db) {
                    if ($dbConfigured && $activeDbConfig) {
                        $db = $activeDbConfig;
                    } else {
                        $error = 'Sesi migrasi kadaluarsa. Ulangi konfigurasi database.';
                    }
                }

                if (empty($error)) {
                    try {
                        $conn = new PDO(
                            "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4",
                            $db['user'],
                            $db['pass']
                        );
                        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        $conn->exec("SET time_zone = '+07:00'");

                        $sqlContent = file_get_contents($_FILES['sql_file']['tmp_name']);
                        require_once __DIR__ . '/../config/smart_migration.php';
                        $protectedUsernames = [];
                        if ($dbConfigured) {
                            $stmtProtected = $conn->query("SELECT username FROM users WHERE role IN ('admin', 'superadmin')");
                            $protectedUsernames = $stmtProtected->fetchAll(PDO::FETCH_COLUMN);
                        }
                        $migrator = new SmartMigration($conn, $protectedUsernames);
                        $migrationResult = $migrator->run($sqlContent);

                        if (empty($migrationResult['success'])) {
                            $errMsgs = $migrationResult['errors'] ?? array_column(
                                array_filter($migrationResult['log'], fn($l) => $l['type'] === 'error'),
                                'message'
                            );
                            $error = implode(' ', $errMsgs) ?: 'File SQL ditolak oleh sistem keamanan.';
                        } else {
                            $_SESSION['_migrate_result'] = $migrationResult;
                            if (!$dbConfigured) {
                                $superAdminPassword = generateInitialPassword();
                                $adminPassword = generateInitialPassword();
                                finalizeInstallationAccounts($conn, 'addmbigfamits26', $superAdminPassword, 'admin', $adminPassword);
                                $_SESSION['_migration_credentials'] = [
                                    'superadmin_username' => 'addmbigfamits26',
                                    'superadmin_password' => $superAdminPassword,
                                    'admin_username' => 'admin',
                                    'admin_password' => $adminPassword,
                                ];
                            }

                            if (!$dbConfigured) {
                                writeDatabaseConfig($db['host'], $db['user'], $db['pass'], $db['name']);
                            }
                            ensureUploadDirs();
                            unset($_SESSION['_migrate_db']);

                            error_log('[Migration] Success by IP ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') .
                                ' — imported: ' . ($migrationResult['stats']['rows_imported'] ?? 0));

                            header("Location: " . $base_url . "/installer/index?mode=migrate&step=3");
                            exit;
                        }
                    } catch (PDOException $e) {
                        $error = 'Migrasi gagal. Periksa format file SQL atau hubungi administrator.';
                        error_log("Migration error: " . $e->getMessage());
                    } catch (Throwable $e) {
                        $error = 'Migrasi gagal. File SQL tidak valid atau ditolak sistem keamanan.';
                        error_log("Migration error: " . $e->getMessage());
                    }
                }
            }
        }
    }
}

if ($mode === 'migrate' && $step === 3) {
    $migrationResult = $_SESSION['_migrate_result'] ?? null;
    if (!$migrationResult) {
        header("Location: " . $base_url . "/installer/index?mode=migrate&step=2");
        exit;
    }
    unset($_SESSION['_migrate_result']);
}

if ($mode === 'migrate' && $step === 0) {
    $step = $dbConfigured ? 2 : 1;
}
if ($mode === 'install' && $step === 0) {
    $step = 1;
}

$installCredentials = $_SESSION['_install_credentials'] ?? null;
$migrationCredentials = $_SESSION['_migration_credentials'] ?? null;
unset($_SESSION['_install_credentials'], $_SESSION['_migration_credentials']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installer — Big Family ITS 26</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style nonce="<?= $cspNonce ?>">
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Inter',sans-serif;background:#0d1117;color:#c9d1d9;display:flex;justify-content:center;align-items:center;min-height:100vh;position:relative;padding:1.5rem}
        body::before{content:'';position:absolute;inset:0;background:radial-gradient(circle at 30% 20%,rgba(88,166,255,.1),transparent 50%),radial-gradient(circle at 70% 80%,rgba(240,171,0,.06),transparent 50%);pointer-events:none}
        .card{position:relative;z-index:1;background:rgba(22,27,34,.9);padding:2.5rem;border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.5);width:100%;max-width:560px;border:1px solid #30363d;backdrop-filter:blur(10px)}
        .card.wide{max-width:680px}
        .logo-row{display:flex;align-items:center;justify-content:center;gap:12px;margin-bottom:1.5rem}
        .logo-row img{width:52px;height:52px;border-radius:50%;object-fit:cover;border:2px solid #58a6ff;padding:2px}
        .logo-row h1{font-size:1.3rem;font-weight:700;background:linear-gradient(135deg,#58a6ff,#f0ab00);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
        .subtitle{text-align:center;color:#8b949e;margin-bottom:2rem;font-size:.88rem;line-height:1.6}
        .form-group{margin-bottom:1.25rem}
        .form-group label{display:block;font-size:.82rem;font-weight:500;color:#c9d1d9;margin-bottom:.4rem}
        .form-group input,.form-group select{width:100%;padding:.75rem 1rem;background:#0d1117;border:1px solid #30363d;border-radius:10px;color:#c9d1d9;font-size:.9rem;transition:all .2s}
        .form-group input[type=file]{padding:.6rem 1rem}
        .form-group input:focus{outline:none;border-color:#58a6ff;box-shadow:0 0 0 3px rgba(88,166,255,.15)}
        .form-group .hint{font-size:.72rem;color:#8b949e;margin-top:.3rem;line-height:1.5}
        .btn{display:block;width:100%;padding:.8rem;border:none;border-radius:10px;font-weight:600;font-size:.95rem;cursor:pointer;transition:all .2s;margin-top:1rem;text-align:center;text-decoration:none}
        .btn-primary{background:linear-gradient(135deg,#238636,#2ea043);color:#fff;box-shadow:0 4px 12px rgba(35,134,54,.3)}
        .btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(35,134,54,.4)}
        .btn-secondary{background:rgba(88,166,255,.12);color:#58a6ff;border:1px solid rgba(88,166,255,.25)}
        .btn-secondary:hover{background:rgba(88,166,255,.2)}
        .btn-migrate{background:linear-gradient(135deg,#1f6feb,#388bfd);color:#fff;box-shadow:0 4px 12px rgba(31,111,235,.3)}
        .btn-migrate:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(31,111,235,.4)}
        .alert{padding:.85rem 1rem;border-radius:8px;margin-bottom:1.5rem;font-size:.85rem;line-height:1.5}
        .alert-error{background:rgba(248,81,73,.12);color:#f85149;border:1px solid rgba(248,81,73,.2)}
        .alert-success{background:rgba(46,160,67,.12);color:#3fb950;border:1px solid rgba(46,160,67,.2)}
        .alert-info{background:rgba(88,166,255,.1);color:#58a6ff;border:1px solid rgba(88,166,255,.2)}
        code{background:#0d1117;padding:2px 6px;border-radius:4px;font-size:.82rem;color:#f0ab00}
        .menu-grid{display:grid;gap:1rem;margin-top:.5rem}
        .menu-card{display:block;padding:1.25rem 1.5rem;border-radius:12px;border:1px solid #30363d;background:#0d1117;text-decoration:none;color:inherit;transition:all .2s}
        .menu-card:hover{border-color:#58a6ff;transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.3)}
        .menu-card h3{font-size:1rem;margin-bottom:.35rem;color:#c9d1d9}
        .menu-card p{font-size:.8rem;color:#8b949e;line-height:1.5;margin:0}
        .menu-card.install{border-left:3px solid #2ea043}
        .menu-card.migrate{border-left:3px solid #388bfd}
        .step-badge{display:inline-block;font-size:.7rem;font-weight:600;padding:.25rem .6rem;border-radius:20px;background:rgba(88,166,255,.15);color:#58a6ff;margin-bottom:1rem}
        .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:.75rem;margin:1.25rem 0}
        .stat-box{background:#0d1117;border:1px solid #30363d;border-radius:10px;padding:.85rem;text-align:center}
        .stat-box .num{font-size:1.4rem;font-weight:700;color:#58a6ff}
        .stat-box .lbl{font-size:.72rem;color:#8b949e;margin-top:.2rem}
        .log-box{max-height:220px;overflow-y:auto;background:#0d1117;border:1px solid #30363d;border-radius:10px;padding:.75rem 1rem;margin-top:1rem;font-size:.78rem;line-height:1.6}
        .log-item{margin-bottom:.35rem}
        .log-ok{color:#3fb950}.log-warn{color:#f0ab00}.log-info{color:#8b949e}.log-error{color:#f85149}
        .back-link{display:inline-block;margin-top:1rem;font-size:.82rem;color:#8b949e;text-decoration:none}
        .back-link:hover{color:#58a6ff}
        .feature-list{text-align:left;margin:1rem 0;padding:0;list-style:none}
        .feature-list li{font-size:.8rem;color:#8b949e;padding:.35rem 0 .35rem 1.4rem;position:relative;line-height:1.4}
        .feature-list li::before{content:'✓';position:absolute;left:0;color:#3fb950;font-weight:700}
    </style>
</head>
<body>
<div class="card <?= ($mode === 'migrate' && $step === 3) ? 'wide' : '' ?>">
    <div class="logo-row">
        <img src="../assets/logo.jpeg" alt="Logo" onerror="this.style.display='none'">
        <h1>BIG FAMILY ITS 26</h1>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($mode === '' && $step === 0): ?>
        <p class="subtitle">Pilih cara setup sistem Anda.</p>
        <div class="menu-grid">
            <a href="<?= $base_url ?>/installer/index?mode=install&step=1" class="menu-card install">
                <h3>🆕 Instalasi Baru</h3>
                <p>Setup database kosong dengan skema terbaru. Cocok untuk instalasi pertama kali.</p>
            </a>
            <a href="<?= $base_url ?>/installer/index?mode=migrate" class="menu-card migrate">
                <h3>🔄 Migrasi Langsung</h3>
                <p>Upload file .sql lama (users saja atau full dump). Sistem akan migrasi otomatis ke skema terbaru.</p>
            </a>
        </div>

    <?php elseif ($mode === 'install' && $step === 1): ?>
        <span class="step-badge">Instalasi Baru — Langkah 1/2</span>
        <p class="subtitle">Masukkan konfigurasi database untuk instalasi baru.</p>
        <form method="POST">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="mode" value="install">
            <input type="hidden" name="step" value="1">
            <div class="form-group">
                <label>Database Host</label>
                <input type="text" name="db_host" value="localhost" required>
            </div>
            <div class="form-group">
                <label>Database User</label>
                <input type="text" name="db_user" value="root" required>
            </div>
            <div class="form-group">
                <label>Database Password</label>
                <input type="password" name="db_pass" placeholder="Kosongkan jika default XAMPP">
                <div class="hint">Biarkan kosong jika default XAMPP/Laragon</div>
            </div>
            <div class="form-group">
                <label>Database Name</label>
                <input type="text" name="db_name" value="bigfam_its26" required>
            </div>
            <div class="form-group">
                <label>Username Super Admin</label>
                <input type="text" name="superadmin_username" value="<?= htmlspecialchars($_POST['superadmin_username'] ?? '') ?>" minlength="3" maxlength="50" autocomplete="username" required>
                <div class="hint">Gunakan username khusus yang tidak mudah ditebak.</div>
            </div>
            <div class="form-group">
                <label>Password Super Admin</label>
                <input type="password" name="superadmin_password" minlength="8" autocomplete="new-password" required>
                <div class="hint">Minimal 8 karakter.</div>
            </div>
            <div class="form-group">
                <label>Konfirmasi Password Super Admin</label>
                <input type="password" name="superadmin_password_confirm" minlength="8" autocomplete="new-password" required>
            </div>
            <div class="form-group">
                <label>Username Admin</label>
                <input type="text" name="admin_username" value="<?= htmlspecialchars($_POST['admin_username'] ?? '') ?>" minlength="3" maxlength="50" autocomplete="username" required>
            </div>
            <div class="form-group">
                <label>Password Admin</label>
                <input type="password" name="admin_password" minlength="8" autocomplete="new-password" required>
                <div class="hint">Harus berbeda dari password Super Admin.</div>
            </div>
            <div class="form-group">
                <label>Konfirmasi Password Admin</label>
                <input type="password" name="admin_password_confirm" minlength="8" autocomplete="new-password" required>
            </div>
            <button type="submit" class="btn btn-primary">Install Database</button>
        </form>
        <a href="<?= $base_url ?>/installer/" class="back-link">← Kembali ke menu</a>

    <?php elseif ($mode === 'install' && $step === 2): ?>
        <div class="alert alert-success">
            <strong>Instalasi Berhasil!</strong><br>
            Database dan tabel berhasil dibuat. File <code>config/database.php</code> telah disimpan otomatis.
        </div>
        <?php if ($installCredentials): ?>
        <p class="subtitle">Super Admin: <strong><?= htmlspecialchars($installCredentials['superadmin_username']) ?></strong></p>
        <p class="subtitle" style="margin-top:.75rem;font-size:.8rem">Admin: <strong><?= htmlspecialchars($installCredentials['admin_username']) ?></strong></p>
        <p style="color:#f0ab00;font-size:.78rem;text-align:center;margin-bottom:1rem">Gunakan password yang Anda masukkan pada langkah sebelumnya.</p>
        <?php else: ?>
        <p class="subtitle">Kredensial instalasi sudah tidak tersedia. Atur ulang password melalui database atau jalankan instalasi bersih.</p>
        <?php endif; ?>
        <p style="color:#f85149;font-size:.78rem;text-align:center;margin-bottom:1rem"><strong>Keamanan:</strong> Hapus folder <code>installer</code> setelah login pertama.</p>
        <a href="<?= $base_url ?>/auth/login" class="btn btn-primary">Masuk ke Aplikasi</a>

    <?php elseif ($mode === 'migrate' && $step === 1): ?>
        <span class="step-badge">Migrasi Langsung — Langkah 1/3</span>
        <p class="subtitle">Konfigurasi database tujuan migrasi. Bisa database baru atau yang sudah ada.</p>
        <form method="POST">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="mode" value="migrate">
            <input type="hidden" name="step" value="1">
            <div class="form-group">
                <label>Database Host</label>
                <input type="text" name="db_host" value="localhost" required>
            </div>
            <div class="form-group">
                <label>Database User</label>
                <input type="text" name="db_user" value="root" required>
            </div>
            <div class="form-group">
                <label>Database Password</label>
                <input type="password" name="db_pass" placeholder="Kosongkan jika default XAMPP">
            </div>
            <div class="form-group">
                <label>Database Name</label>
                <input type="text" name="db_name" value="bigfam_its26" required>
            </div>
            <button type="submit" class="btn btn-migrate">Lanjut Upload SQL</button>
        </form>
        <a href="<?= $base_url ?>/installer/" class="back-link">← Kembali ke menu</a>

    <?php elseif ($mode === 'migrate' && $step === 2): ?>
        <span class="step-badge">Migrasi Langsung — Langkah <?= $dbConfigured ? '1/2' : '2/3' ?></span>
        <p class="subtitle">
            <?php if ($dbConfigured): ?>
                Database aktif terdeteksi. Upload file <code>.sql</code> untuk digabung ke sistem yang sudah berjalan.
            <?php else: ?>
                Upload file <code>.sql</code> dari sistem lama. Migrator cerdas akan menyesuaikan skema otomatis.
            <?php endif; ?>
        </p>

        <ul class="feature-list">
            <li>Mendukung dump users saja atau full database</li>
            <li>Sinkronisasi skema ke versi terbaru otomatis</li>
            <li>Mapping kolom &amp; hash password plain-text</li>
            <li>Duplikat data ditangani dengan upsert</li>
            <li>Perlindungan anti-manipulasi SQL aktif</li>
        </ul>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="mode" value="migrate">
            <input type="hidden" name="step" value="2">
            <div class="form-group">
                <label>File SQL</label>
                <input type="file" name="sql_file" accept=".sql" required>
                <div class="hint">Format: .sql (maks. 50 MB). Export dari phpMyAdmin atau mysqldump.</div>
            </div>
            <?php if ($dbConfigured): ?>
            <div class="form-group">
                <label>Password Super Admin</label>
                <input type="password" name="superadmin_password" required autocomplete="current-password" placeholder="Konfirmasi identitas Anda">
                <div class="hint">Wajib diisi untuk mencegah penyalahgunaan fitur migrasi.</div>
            </div>
            <?php endif; ?>
            <div class="form-group" style="display:flex;align-items:flex-start;gap:.6rem;margin-top:1rem">
                <input type="checkbox" name="confirm_backup" value="1" id="confirmBackup" required style="width:auto;margin-top:.2rem">
                <label for="confirmBackup" style="margin:0;font-size:.8rem;line-height:1.5;color:#8b949e">Saya confirm file SQL ini berasal dari backup resmi Big Family ITS 26, bukan file manipulasi pihak ketiga.</label>
            </div>
            <button type="submit" class="btn btn-migrate">Mulai Migrasi Cerdas</button>
        </form>
        <?php if (!$dbConfigured): ?>
            <a href="<?= $base_url ?>/installer/index?mode=migrate&step=1" class="back-link">← Ubah konfigurasi database</a>
        <?php else: ?>
            <a href="<?= $base_url ?>/dashboard/" class="back-link">← Kembali ke Dashboard</a>
        <?php endif; ?>

    <?php elseif ($mode === 'migrate' && $step === 3 && $migrationResult): ?>
        <span class="step-badge">Migrasi Selesai</span>
        <div class="alert alert-success">
            <strong>Migrasi Berhasil!</strong> Data dari file SQL telah diproses ke skema terbaru.
        </div>

        <?php $s = $migrationResult['stats']; ?>
        <div class="stats-grid">
            <div class="stat-box"><div class="num"><?= (int)$s['tables_synced'] ?></div><div class="lbl">Tabel disinkronkan</div></div>
            <div class="stat-box"><div class="num"><?= (int)$s['columns_added'] ?></div><div class="lbl">Kolom ditambahkan</div></div>
            <div class="stat-box"><div class="num"><?= (int)$s['rows_imported'] ?></div><div class="lbl">Baris diimport</div></div>
            <div class="stat-box"><div class="num"><?= (int)$s['rows_updated'] ?></div><div class="lbl">Baris diupdate</div></div>
            <div class="stat-box"><div class="num"><?= (int)($s['rows_blocked'] ?? 0) ?></div><div class="lbl">Baris diblokir</div></div>
        </div>

        <?php if (!empty($migrationResult['detected_tables'])): ?>
            <div class="alert alert-info">
                Tabel terdeteksi: <code><?= htmlspecialchars(implode(', ', $migrationResult['detected_tables'])) ?></code>
            </div>
        <?php endif; ?>

        <?php if (!empty($migrationResult['log'])): ?>
            <div class="log-box">
                <?php foreach ($migrationResult['log'] as $entry): ?>
                    <div class="log-item log-<?= htmlspecialchars($entry['type']) ?>">
                        <?= htmlspecialchars($entry['message']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($migrationCredentials): ?>
        <p class="subtitle" style="margin-top:1.25rem">Super Admin: <strong><?= htmlspecialchars($migrationCredentials['superadmin_username']) ?></strong> / <code><?= htmlspecialchars($migrationCredentials['superadmin_password']) ?></code></p>
        <p class="subtitle">Admin: <strong><?= htmlspecialchars($migrationCredentials['admin_username']) ?></strong> / <code><?= htmlspecialchars($migrationCredentials['admin_password']) ?></code></p>
        <p style="color:#f0ab00;font-size:.78rem;text-align:center">Simpan kredensial ini sekarang. Password hanya ditampilkan pada halaman ini.</p>
        <?php endif; ?>
        <a href="<?= $base_url ?>/auth/login" class="btn btn-primary">Masuk ke Aplikasi</a>

    <?php endif; ?>
</div>
</body>
</html>
