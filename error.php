<?php
// error.php
session_start();

$base_url = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$base_url = preg_replace('/(\/(auth|dashboard|notes|msg|drive|calendar|server|admin|installer))?$/i', '', $base_url);
if ($base_url === '/') $base_url = '';

// Get requested route
$requested = isset($_GET['route']) ? trim($_GET['route'], '/') : '';

// Valid route definitions
$routes = [
    'dashboard' => $base_url . '/dashboard/',
    'notes' => $base_url . '/notes/',
    'msg' => $base_url . '/msg/',
    'drive' => $base_url . '/drive/',
    'calendar' => $base_url . '/calendar/',
    'admin' => $base_url . '/admin/',
    'auth/pengaturan' => $base_url . '/auth/pengaturan'
];

$closest = 'dashboard';
$shortest = -1;

foreach (array_keys($routes) as $r) {
    $lev = levenshtein($requested, $r);
    if ($lev == 0) {
        $closest = $r;
        $shortest = 0;
        break;
    }
    if ($lev <= $shortest || $shortest < 0) {
        $closest = $r;
        $shortest = $lev;
    }
}

$target_url = $routes[$closest];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Direktori Tidak Ditemukan</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #0d1117;
            color: #c9d1d9;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            text-align: center;
            overflow: hidden;
        }
        .container {
            max-width: 480px;
            padding: 2.5rem;
            background: #161b22;
            border: 1px solid #30363d;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
            animation: pulseIn 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        @keyframes pulseIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
        .icon {
            font-size: 3.5rem;
            color: #ff7b72;
            margin-bottom: 1.5rem;
            animation: rotateWarn 1s ease-in-out infinite alternate;
        }
        @keyframes rotateWarn {
            from { transform: rotate(-5deg); }
            to { transform: rotate(5deg); }
        }
        h1 {
            font-size: 1.5rem;
            font-weight: 800;
            color: #fff;
            margin-bottom: 0.75rem;
        }
        p {
            font-size: 0.9rem;
            color: #8b949e;
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }
        .match-badge {
            display: inline-block;
            background: rgba(88, 166, 255, 0.1);
            color: #58a6ff;
            border: 1px solid rgba(88, 166, 255, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            margin-bottom: 1.5rem;
        }
        .loader {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-size: 0.8rem;
            color: #8b949e;
        }
        .dot {
            width: 6px;
            height: 6px;
            background-color: #58a6ff;
            border-radius: 50%;
            animation: bounce 1.4s infinite ease-in-out both;
        }
        .dot:nth-child(1) { animation-delay: -0.32s; }
        .dot:nth-child(2) { animation-delay: -0.16s; }
        @keyframes bounce {
            0%, 80%, 100% { transform: scale(0); }
            40% { transform: scale(1.0); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">
            <i class="fas fa-folder-closed"></i>
        </div>
        <h1>Direktori tidak ditemukan!</h1>
        <p>Halaman yang Anda tuju tidak tersedia di server Big Family ITS 26.</p>
        
        <div style="font-size:0.8rem; color:#8b949e; margin-bottom:0.4rem">Mencari halaman terdekat...</div>
        <div class="match-badge">
            <i class="fas fa-arrow-turn-down" style="margin-right: 6px;"></i> /<?= htmlspecialchars($closest) ?>
        </div>
        
        <div class="loader">
            <span>Mengalihkan secara otomatis</span>
            <div class="dot"></div>
            <div class="dot"></div>
            <div class="dot"></div>
        </div>
    </div>

    <script>
        setTimeout(() => {
            window.location.href = '<?= $target_url ?>';
        }, 2200);
    </script>
</body>
</html>
