<?php
// auth/login.php
require_once __DIR__ . '/../config/security.php';
initSecureSession();
setSecurityHeaders();
$cspNonce = htmlspecialchars(getCspNonce(), ENT_QUOTES, 'UTF-8');

$base_url = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$base_url = preg_replace('/(\/(auth|dashboard|notes|msg|drive|calendar|server|admin|installer))?$/i', '', $base_url);
if ($base_url === '/') $base_url = '';

if (!file_exists(__DIR__ . '/../config/database.php')) {
    header("Location: " . $base_url . "/installer/");
    exit;
}
require_once __DIR__ . '/../config/database.php';
if (isset($_SESSION['id_user'])) {
    header("Location: " . $base_url . "/dashboard/");
    exit;
}

$error = '';
$loginRedirect = $_GET['redirect'] ?? '';
if ($loginRedirect !== '' && !preg_match('#^' . preg_quote($base_url, '#') . '/#', $loginRedirect)) {
    $loginRedirect = '';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!validateCsrfToken()) {
        $error = 'Token keamanan tidak valid. Silakan coba lagi.';
    } else {
        $honeypot = $_POST['email_confirm'] ?? '';
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (!empty($honeypot)) {
            $error = 'Deteksi otomatis bot. Request Anda ditolak.';
        } elseif (empty($username) || empty($password)) {
            $error = 'Username dan password wajib diisi!';
        } else {
            // IP-based & Session-based Rate Limit Check
            if (!checkIpRateLimit('login_attempt', 5, 900) || !checkRateLimit('login_attempt', 5, 900)) {
                $error = 'Terlalu banyak percobaan masuk dari koneksi Anda. Silakan tunggu 15 menit.';
            } else {
                $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
                $stmt->execute([$username]);
                $user = $stmt->fetch();
                
                if ($user && password_verify($password, $user['password'])) {
                    // Success login: clear rate limit history
                    unset($_SESSION['_rl_login_attempt']);

                    // Session security upgrades
                    session_regenerate_id(true);
                    regenerateCsrfToken();
                    
                    $_SESSION['id_user'] = $user['id_user'];
                    $_SESSION['nama'] = $user['nama'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['foto_profil'] = $user['foto_profil'];
                    $_SESSION['login_password_hash'] = $user['password'];
                    
                    $conn->prepare("UPDATE users SET last_online = NOW(), is_online = 1 WHERE id_user = ?")->execute([$user['id_user']]);
                    
                    $redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? '';
                    if ($redirect !== '' && preg_match('#^' . preg_quote($base_url, '#') . '/#', $redirect)) {
                        header("Location: " . $redirect);
                    } else {
                        header("Location: " . $base_url . "/dashboard");
                    }
                    exit;
                } else {
                    $error = 'Username atau password salah.';
                }
            }
        }
    }
}

$app_settings = ['site_title' => 'BIG FAMILY ITS 26'];
try { $s = $conn->query("SELECT * FROM settings WHERE id = 1")->fetch(); if ($s) $app_settings = array_merge($app_settings, $s); } catch(Exception $e) {}
$site_title = htmlspecialchars($app_settings['site_title']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk — <?= $site_title ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= assetVersion(__DIR__ . '/../assets/css/style.css') ?>">
    <style nonce="<?= $cspNonce ?>">
        html,body{overflow-x:hidden;max-width:100vw}
        .split-layout{display:flex;min-height:100vh;width:100%;overflow-x:hidden}
        @media(max-width:767px){.split-layout{flex-direction:column}}
        .split-image{display:none;background:url('../assets/logo.jpeg') center/cover no-repeat;flex:1;position:relative;overflow:hidden}
        .split-image::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,rgba(31,111,235,.85) 0%,rgba(88,166,255,.7) 50%,rgba(0,0,0,.3) 100%);z-index:1}
        .split-image-content{position:absolute;bottom:3rem;left:3rem;right:3rem;color:white;z-index:2}
        .split-image-content h1{font-size:2.5rem;font-weight:800;margin-bottom:.75rem;line-height:1.2;text-shadow:0 2px 10px rgba(0,0,0,.2)}
        .split-image-content p{font-size:1.1rem;opacity:.95;line-height:1.6}
        .split-form{flex:1;display:flex;align-items:center;justify-content:center;padding:2rem 2.5rem;background:var(--bg-color);position:relative;overflow:hidden}
        .split-form::before{content:'';position:absolute;width:300px;height:300px;border-radius:50%;background:radial-gradient(circle,rgba(88,166,255,.08) 0%,transparent 70%);top:-100px;right:-100px;pointer-events:none}
        .split-form-inner{width:100%;max-width:420px;animation:authSlide .7s cubic-bezier(.16,1,.3,1) forwards}
        @keyframes authSlide{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
        @media(min-width:768px){.split-image{display:block}}
        @media(max-width:767px){.split-image{display:block;min-height:200px;flex:none}.split-image-content{bottom:1.5rem;left:1.5rem;right:1.5rem}.split-image-content h1{font-size:1.5rem}.split-form{padding:1.5rem}}
        .auth-logo-row{display:flex;align-items:center;gap:12px;margin-bottom:2rem}
        .auth-logo-row img{width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid var(--accent-color)}
        .auth-logo-row h2{font-size:1.2rem;font-weight:700;color:#fff}
        .form-group{margin-bottom:1.25rem}
        .form-group label{display:block;font-size:.82rem;font-weight:500;color:var(--text-muted);margin-bottom:.4rem}
        .form-group .input-wrap{position:relative}
        .form-group .input-wrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:.9rem;transition:color .2s}
        .form-group input{width:100%;padding:.8rem 1rem .8rem 2.6rem;background:var(--bg-color);border:1.5px solid var(--border-color);border-radius:10px;color:var(--text-color);font-size:.9rem;transition:all .2s}
        .form-group input:focus{outline:none;border-color:var(--accent-color);box-shadow:0 0 0 3px rgba(88,166,255,.12)}
        .form-group input:focus~i{color:var(--accent-color)}
        .auth-btn{width:100%;padding:.85rem;border:none;border-radius:10px;background:linear-gradient(135deg,#1f6feb,#388bfd);color:#fff;font-size:1rem;font-weight:600;cursor:pointer;transition:all .2s;box-shadow:0 4px 14px rgba(31,111,235,.3)}
        .auth-btn:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(31,111,235,.4)}
        .form-footer{text-align:center;margin-top:1.5rem;color:var(--text-muted);font-size:.85rem}
        .form-footer a{color:var(--accent-color);text-decoration:none;font-weight:500}
        .form-footer a:hover{text-decoration:underline}
        .alert-error{background:rgba(248,81,73,.1);color:#f85149;padding:.75rem 1rem;border-radius:8px;margin-bottom:1.25rem;font-size:.85rem;border:1px solid rgba(248,81,73,.2)}
    </style>
</head>
<body>
<div class="split-layout">
    <div class="split-image">
        <div class="split-image-content">
            <h1><?= $site_title ?></h1>
            <p>Platform kolaborasi akademik — catatan, pesan, drive, kalender, dan polling kelas dalam satu tempat.</p>
        </div>
    </div>
    <div class="split-form">
        <div class="split-form-inner">
            <div class="auth-logo-row">
                <img src="../assets/logo.jpeg" alt="Logo">
                <h2>Masuk</h2>
            </div>
            
            <?php if ($error): ?>
                <div class="alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="_csrf_token" value="<?= generateCsrfToken() ?>">
                <?php if ($loginRedirect !== ''): ?>
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($loginRedirect) ?>">
                <?php endif; ?>
                <div style="display:none;"><input type="text" name="email_confirm" autocomplete="off" tabindex="-1"></div>
                <div class="form-group">
                    <label>Username</label>
                    <div class="input-wrap">
                        <input type="text" name="username" placeholder="Masukkan username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                        <i class="fas fa-user"></i>
                    </div>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <div class="input-wrap">
                        <input type="password" name="password" placeholder="Masukkan password" required>
                        <i class="fas fa-lock"></i>
                    </div>
                </div>
                <button type="submit" class="auth-btn">Masuk</button>
            </form>

            <p class="form-footer">Belum punya akun? <a href="register">Buat akun baru</a></p>
        </div>
    </div>
</div>
</body>
</html>
