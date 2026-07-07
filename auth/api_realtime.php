<?php
// auth/api_realtime.php
require_once __DIR__ . '/../config/security.php';
initSecureSession();
header('Content-Type: application/json');

if (!file_exists(__DIR__ . '/../config/database.php')) {
    echo json_encode(['success' => false, 'message' => 'Database not found']);
    exit;
}
require_once __DIR__ . '/../config/database.php';

// Verify session authenticity
verifyActiveSession($conn);

if (!isset($_SESSION['id_user'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

requirePostMethod();
requireCsrfToken();

$page = $_POST['page'] ?? '';
$response = ['success' => true];

try {
    if ($page === 'dashboard') {
        // 1. Stats count
        $count_users = $conn->query("SELECT COUNT(*) FROM users WHERE role != 'superadmin'")->fetchColumn();
        $count_msg = $conn->query("SELECT COUNT(*) FROM msg")->fetchColumn();
        $count_files = $conn->query("SELECT COUNT(*) FROM files")->fetchColumn();
        $count_notes = $conn->prepare("SELECT COUNT(*) FROM notes WHERE id_user = ?");
        $count_notes->execute([$_SESSION['id_user']]);
        $count_notes = $count_notes->fetchColumn();

        $response['stats'] = [
            'users' => (int)$count_users,
            'msg' => (int)$count_msg,
            'files' => (int)$count_files,
            'notes' => (int)$count_notes
        ];

        // 2. Class members (online & activity)
        $stmt_online = $conn->query("SELECT id_user, nama, role, foto_profil, is_online, last_online FROM users WHERE role != 'superadmin' ORDER BY is_online DESC, last_online DESC LIMIT 12");
        $online_members = $stmt_online->fetchAll();
        foreach ($online_members as &$m) {
            $m['nama'] = sanitizeOutput($m['nama']);
            $m['foto_profil'] = $m['foto_profil'] ? sanitizeOutput($m['foto_profil']) : '';
        }
        $response['members'] = $online_members;

        // 3. Announcement (latest pinned msg)
        $stmt_ann = $conn->query("
            SELECT c.id_msg, c.pesan, c.tipe, c.file_path, c.created_at, u.nama 
            FROM msg c 
            JOIN users u ON c.id_user = u.id_user 
            WHERE c.pinned = 1 
            ORDER BY c.id_msg DESC 
            LIMIT 1
        ");
        $latest_announcement = $stmt_ann->fetch();
        if ($latest_announcement) {
            $latest_announcement['nama'] = sanitizeOutput($latest_announcement['nama']);
            $latest_announcement['pesan'] = sanitizeOutput($latest_announcement['pesan']);
            $latest_announcement['created_at'] = date('d M Y H:i', strtotime($latest_announcement['created_at']));
        }
        $response['announcement'] = $latest_announcement ?: null;

        // 4. Upcoming event from notes
        $stmt_ev = $conn->prepare("
            SELECT id_note, judul, konten, tanggal_kegiatan, warna, is_global FROM notes 
            WHERE tanggal_kegiatan >= CURDATE() 
              AND (is_global = 1 OR id_user = ?) 
            ORDER BY tanggal_kegiatan ASC 
            LIMIT 1
        ");
        $stmt_ev->execute([$_SESSION['id_user']]);
        $upcoming_event = $stmt_ev->fetch();
        if ($upcoming_event) {
            $upcoming_event['judul'] = sanitizeOutput($upcoming_event['judul']);
            $upcoming_event['konten'] = strip_tags($upcoming_event['konten']);
            $upcoming_event['tanggal_kegiatan'] = date('d M Y', strtotime($upcoming_event['tanggal_kegiatan']));
            $upcoming_event['warna'] = sanitizeOutput($upcoming_event['warna']);
        }
        $response['upcoming_event'] = $upcoming_event ?: null;

        // 5. Polling
        $latest_poll = $conn->query("SELECT * FROM polls ORDER BY id_poll DESC LIMIT 1")->fetch();
        if ($latest_poll) {
            $stmt_opt = $conn->prepare("SELECT po.*, COUNT(pv.id_vote) as votes_count FROM poll_options po LEFT JOIN poll_votes pv ON po.id_option = pv.id_option WHERE po.id_poll = ? GROUP BY po.id_option");
            $stmt_opt->execute([$latest_poll['id_poll']]);
            $poll_options = $stmt_opt->fetchAll();
            
            $total_votes = $conn->prepare("SELECT COUNT(*) FROM poll_votes WHERE id_poll = ?");
            $total_votes->execute([$latest_poll['id_poll']]);
            $total_votes = (int)$total_votes->fetchColumn();

            $check_voted = $conn->prepare("SELECT COUNT(*) FROM poll_votes WHERE id_poll = ? AND id_user = ?");
            $check_voted->execute([$latest_poll['id_poll'], $_SESSION['id_user']]);
            $has_voted = $check_voted->fetchColumn() > 0;

            foreach ($poll_options as &$opt) {
                $opt['teks'] = sanitizeOutput($opt['teks']);
                $opt['gambar'] = $opt['gambar'] ? sanitizeOutput($opt['gambar']) : '';
                $opt['votes_count'] = (int)$opt['votes_count'];
            }

            $response['poll'] = [
                'id_poll' => (int)$latest_poll['id_poll'],
                'pertanyaan' => sanitizeOutput($latest_poll['pertanyaan']),
                'media_path' => $latest_poll['media_path'] ? sanitizeOutput($latest_poll['media_path']) : '',
                'media_type' => $latest_poll['media_type'] ? sanitizeOutput($latest_poll['media_type']) : '',
                'options' => $poll_options,
                'total_votes' => $total_votes,
                'has_voted' => $has_voted
            ];
        } else {
            $response['poll'] = null;
        }

    } elseif ($page === 'admin') {
        // Authorize access
        if (!in_array($_SESSION['role'] ?? '', ['admin', 'operator', 'superadmin'])) {
            echo json_encode(['success' => false, 'message' => 'Forbidden']);
            exit;
        }
        $isSuperAdmin = ($_SESSION['role'] ?? '') === 'superadmin';
        
        $users = [];
        if ($isSuperAdmin) {
            $stmt_u = $conn->prepare("SELECT id_user, nama, username, role, foto_profil, last_online, is_online, created_at, plain_password FROM users WHERE id_user != ? AND role != 'superadmin' ORDER BY role ASC, nama ASC");
            $stmt_u->execute([$_SESSION['id_user']]);
            $users = $stmt_u->fetchAll();
            foreach ($users as &$u) {
                $u['plain_password'] = decryptUserData($u['plain_password']);
            }
        } else {
            $users = $conn->query("SELECT id_user, nama, role, foto_profil, last_online, is_online, created_at FROM users WHERE role != 'superadmin' ORDER BY role ASC, nama ASC")->fetchAll();
        }

        foreach ($users as &$u) {
            $u['id_user'] = (int)$u['id_user'];
            $u['nama'] = sanitizeOutput($u['nama']);
            if (isset($u['username'])) $u['username'] = sanitizeOutput($u['username']);
            if (isset($u['plain_password'])) $u['plain_password'] = $u['plain_password'] ? sanitizeOutput($u['plain_password']) : '(Belum Terisi/Hashed)';
            $u['role'] = sanitizeOutput($u['role']);
            $u['foto_profil'] = $u['foto_profil'] ? sanitizeOutput($u['foto_profil']) : '';
            $u['created_at'] = date('d M Y', strtotime($u['created_at']));
            $u['is_online'] = (int)$u['is_online'];
        }
        $response['users'] = $users;

    } elseif ($page === 'calendar') {
        $year = isset($_POST['year']) ? (int)$_POST['year'] : (int)date('Y');
        $month = isset($_POST['month']) ? (int)$_POST['month'] : (int)date('n');
        $mode = $_POST['mode'] ?? 'server';

        $month_events = [];
        if ($mode === 'pribadi') {
            $stmt = $conn->prepare("
                SELECT n.id_note, n.id_user, n.judul, n.konten, n.tanggal_kegiatan, n.warna, n.is_global, u.nama as creator 
                FROM notes n
                JOIN users u ON n.id_user = u.id_user
                WHERE YEAR(n.tanggal_kegiatan) = ? AND MONTH(n.tanggal_kegiatan) = ? 
                  AND n.id_user = ? AND n.is_global = 0
                ORDER BY n.tanggal_kegiatan ASC, n.updated_at DESC
            ");
            $stmt->execute([$year, $month, $_SESSION['id_user']]);
        } else {
            $stmt = $conn->prepare("
                SELECT n.id_note, n.id_user, n.judul, n.konten, n.tanggal_kegiatan, n.warna, n.is_global, u.nama as creator 
                FROM notes n
                JOIN users u ON n.id_user = u.id_user
                WHERE YEAR(n.tanggal_kegiatan) = ? AND MONTH(n.tanggal_kegiatan) = ?
                  AND n.is_global = 1
                ORDER BY n.tanggal_kegiatan ASC, n.updated_at DESC
            ");
            $stmt->execute([$year, $month]);
        }
        $month_events = $stmt->fetchAll();
        
        $eventsByDay = [];
        foreach ($month_events as $ev) {
            $day = (int)date('j', strtotime($ev['tanggal_kegiatan']));
            $ev['judul'] = sanitizeOutput($ev['judul']);
            $ev['warna'] = sanitizeOutput($ev['warna']);
            $ev['creator'] = sanitizeOutput($ev['creator']);
            $ev['konten'] = sanitizeHtmlContent($ev['konten']);
            $eventsByDay[$day][] = $ev;
        }
        $response['events'] = $eventsByDay;

    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid page requested']);
        exit;
    }

    echo json_encode($response);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => safeErrorMessage($e)]);
}
exit;
?>
