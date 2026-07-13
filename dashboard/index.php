<?php
// dashboard/index.php
require_once __DIR__ . '/../template/header.php';

// Fetch summary metrics
$count_users = 0;
$count_msg = 0;
$count_files = 0;
$count_notes = 0;

try {
    $count_users = $conn->query("SELECT COUNT(*) FROM users WHERE role != 'superadmin'")->fetchColumn();
    $count_msg = $conn->query("SELECT COUNT(*) FROM msg")->fetchColumn();
    $count_files = $conn->query("SELECT COUNT(*) FROM files")->fetchColumn();
    $count_notes = $conn->prepare("SELECT COUNT(*) FROM notes WHERE id_user = ?");
    $count_notes->execute([$_SESSION['id_user']]);
    $count_notes = $count_notes->fetchColumn();
} catch (Exception $e) {}

// Fetch active members (online or recently active)
$online_members = [];
try {
    $stmt_online = $conn->query("SELECT * FROM users WHERE role != 'superadmin' ORDER BY is_online DESC, last_online DESC LIMIT 12");
    $online_members = $stmt_online->fetchAll();
} catch (Exception $e) {}

// Fetch global announcement/latest note (from pinned msg messages)
$latest_announcement = null;
try {
    $stmt_ann = $conn->query("
        SELECT c.*, u.nama 
        FROM msg c 
        JOIN users u ON c.id_user = u.id_user 
        WHERE c.pinned = 1 
        ORDER BY c.id_msg DESC 
        LIMIT 1
    ");
    $latest_announcement = $stmt_ann->fetch();
} catch (Exception $e) {}

// Fetch upcoming event from notes (which acts as academic calendar now)
$upcoming_event = null;
try {
    $stmt_ev = $conn->prepare("
        SELECT * FROM notes 
        WHERE tanggal_kegiatan >= CURDATE() 
          AND (is_global = 1 OR id_user = ?) 
        ORDER BY tanggal_kegiatan ASC 
        LIMIT 1
    ");
    $stmt_ev->execute([$_SESSION['id_user']]);
    $upcoming_event = $stmt_ev->fetch();
} catch (Exception $e) {}

// Fetch latest polling
$latest_poll = null;
$poll_options = [];
$total_votes = 0;
$has_voted = false;
try {
    $latest_poll = $conn->query("SELECT * FROM polls ORDER BY id_poll DESC LIMIT 1")->fetch();
    if ($latest_poll) {
        $stmt_opt = $conn->prepare("SELECT po.*, COUNT(pv.id_vote) as votes_count FROM poll_options po LEFT JOIN poll_votes pv ON po.id_option = pv.id_option WHERE po.id_poll = ? GROUP BY po.id_option");
        $stmt_opt->execute([$latest_poll['id_poll']]);
        $poll_options = $stmt_opt->fetchAll();
        
        $total_votes = $conn->prepare("SELECT COUNT(*) FROM poll_votes WHERE id_poll = ?");
        $total_votes->execute([$latest_poll['id_poll']]);
        $total_votes = $total_votes->fetchColumn();

        $check_voted = $conn->prepare("SELECT COUNT(*) FROM poll_votes WHERE id_poll = ? AND id_user = ?");
        $check_voted->execute([$latest_poll['id_poll'], $_SESSION['id_user']]);
        $has_voted = $check_voted->fetchColumn() > 0;
    }
} catch (Exception $e) {}
?>

<div class="grid grid-cols-12">
    <!-- Top Greeting Banner -->
    <div class="col-12">
        <div class="card" style="background: linear-gradient(135deg, rgba(88,166,255,0.1), rgba(240,171,0,0.05)); border-color: rgba(88,166,255,0.2)">
            <h2 style="color: #fff; margin-bottom: 0.5rem;">Selamat Datang Kembali, <?= htmlspecialchars($_SESSION['nama']) ?>! 👋</h2>
            <p style="color: var(--text-muted); font-size: 0.95rem;">Hari ini adalah <?= date('l, d F Y') ?>. Tetap produktif dan semangat belajarnya!</p>
        </div>
    </div>

    <!-- Quick Metrics -->
    <div class="col-3">
        <div class="stat-box">
            <div class="stat-icon primary"><i class="fas fa-users"></i></div>
            <div>
                <div class="stat-num" id="metric-users"><?= $count_users ?></div>
                <div class="stat-label">Total Anggota</div>
            </div>
        </div>
    </div>
    <div class="col-3">
        <div class="stat-box">
            <div class="stat-icon success"><i class="fas fa-file-alt"></i></div>
            <div>
                <div class="stat-num" id="metric-files"><?= $count_files ?></div>
                <div class="stat-label">File Dibagikan</div>
            </div>
        </div>
    </div>
    <div class="col-3">
        <div class="stat-box">
            <div class="stat-icon warning"><i class="fas fa-sticky-note"></i></div>
            <div>
                <div class="stat-num" id="metric-notes"><?= $count_notes ?></div>
                <div class="stat-label">Catatan Pribadi</div>
            </div>
        </div>
    </div>
    <div class="col-3">
        <div class="stat-box">
            <div class="stat-icon" style="background-color:rgba(163,113,247,0.12); color:#a371f7"><i class="fas fa-comments"></i></div>
            <div>
                <div class="stat-num" id="metric-msg"><?= $count_msg ?></div>
                <div class="stat-label">Pesan Grup</div>
            </div>
        </div>
    </div>

    <!-- Left Side: Main Widgets -->
    <div class="col-8">
        <!-- Info / Pengumuman Terkini (from pinned msg) -->
        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-bullhorn" style="color: var(--gold-color)"></i> Informasi Pinned Grup</h3>
                <a href="../msg/" style="color: var(--accent-color); font-size: 0.82rem; text-decoration: none;">Lihat Semua</a>
            </div>
            <div id="announcement-content">
                <?php if ($latest_announcement): ?>
                    <h4 style="color:#fff; margin-bottom:0.5rem; font-size:1.05rem">Pesan Penting dari <?= htmlspecialchars($latest_announcement['nama']) ?></h4>
                    <div style="font-size: 0.9rem; line-height: 1.6; color: var(--text-color); margin-bottom: 1rem;">
                        <?= $latest_announcement['tipe'] === 'text' ? nl2br(htmlspecialchars($latest_announcement['pesan'])) : '<i class="fas fa-paperclip"></i> Lampiran file' ?>
                    </div>
                    <div style="font-size: 0.72rem; color: var(--text-muted);">
                        Diposting pada: <?= date('d M Y H:i', strtotime($latest_announcement['created_at'])) ?>
                    </div>
                <?php else: ?>
                    <p style="color: var(--text-muted); font-size: 0.88rem; text-align: center; padding: 1.5rem 0;">Belum ada pengumuman disematkan saat ini.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Latest Polling -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-chart-bar" style="color: var(--accent-color)"></i> Polling Suara Grup</h3>
                <a href="../msg/" style="color: var(--accent-color); font-size: 0.82rem; text-decoration: none;">Semua Polling</a>
            </div>
            <div id="poll-content">
                <?php if ($latest_poll): ?>
                    <h4 style="color:#fff; margin-bottom:1rem; font-size:0.98rem"><?= htmlspecialchars($latest_poll['pertanyaan']) ?></h4>
                    
                    <?php if (!empty($latest_poll['media_path'])): ?>
                        <div style="margin-bottom:1rem; border-radius:8px; overflow:hidden; border:1px solid var(--border-color); background:#0d1117; max-height:200px; display:flex; justify-content:center; align-items:center">
                            <?php if ($latest_poll['media_type'] === 'image'): ?>
                                <img src="../uploads/msg/<?= htmlspecialchars($latest_poll['media_path']) ?>" style="max-width:100%; max-height:200px; object-fit:contain">
                            <?php elseif ($latest_poll['media_type'] === 'video'): ?>
                                <video src="../uploads/msg/<?= htmlspecialchars($latest_poll['media_path']) ?>" controls style="max-width:100%; max-height:200px"></video>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php foreach ($poll_options as $opt): 
                        $percent = $total_votes > 0 ? round(($opt['votes_count'] / $total_votes) * 100) : 0;
                    ?>
                        <div class="poll-option-row" onclick="location.href='../msg/'">
                            <div class="poll-option-progress" style="width: 100%;">
                                <div class="poll-option-bar" style="width: <?= $percent ?>%"></div>
                                <div style="display:flex; justify-content:space-between; align-items:center; width:100%; position:relative; z-index:2">
                                    <div style="display:flex; align-items:center; gap:8px; min-width:0">
                                        <?php if (!empty($opt['gambar'])): ?>
                                            <img src="../uploads/msg/<?= htmlspecialchars($opt['gambar']) ?>" style="width:24px; height:24px; object-fit:cover; border-radius:4px; border:1px solid var(--border-color); flex-shrink:0">
                                        <?php endif; ?>
                                        <span class="poll-option-text" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis"><?= htmlspecialchars($opt['teks']) ?></span>
                                    </div>
                                    <span class="poll-option-votes" style="flex-shrink:0"><?= $opt['votes_count'] ?> suara (<?= $percent ?>%)</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:0.75rem; display:flex; justify-content:space-between">
                        <span>Total partisipasi: <?= $total_votes ?> suara</span>
                        <span><?= $has_voted ? '✓ Sudah memilih' : '⚠ Belum memilih' ?></span>
                    </div>
                <?php else: ?>
                    <p style="color: var(--text-muted); font-size: 0.88rem; text-align: center; padding: 1.5rem 0;">Tidak ada polling aktif.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Side: Secondary widgets -->
    <div class="col-4">
        <!-- Upcoming Event (Calendar Agenda) -->
        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-calendar-day" style="color: #ff7b72"></i> Agenda Terdekat</h3>
                <a href="../calendar/" style="color: var(--accent-color); font-size: 0.82rem; text-decoration: none;">Kalender</a>
            </div>
            <div id="event-content">
                <?php if ($upcoming_event): ?>
                    <div style="border-left: 3px solid <?= htmlspecialchars($upcoming_event['warna']) ?>; padding-left: 12px; margin-bottom: 0.5rem;">
                        <div style="font-weight: 700; color: #fff; font-size: 0.95rem; margin-bottom: 3px;">
                            <?= htmlspecialchars($upcoming_event['judul']) ?>
                        </div>
                        <div style="font-size: 0.82rem; color: var(--text-muted);">
                            <i class="far fa-calendar-alt"></i> <?= date('d M Y', strtotime($upcoming_event['tanggal_kegiatan'])) ?> 
                        </div>
                    </div>
                    <?php if ($upcoming_event['konten']): ?>
                        <div style="font-size: 0.82rem; color: var(--text-muted); margin-top: 0.5rem; line-height: 1.4; max-height:80px; overflow:hidden">
                            <?= strip_tags($upcoming_event['konten']) ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p style="color: var(--text-muted); font-size: 0.82rem; text-align: center; padding: 1rem 0;">Tidak ada agenda terdekat.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Online Members -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-circle" style="color: var(--success-color); font-size: 0.75rem"></i> Anggota Kelas</h3>
            </div>
            <div class="avatar-list" id="members-list" style="margin-bottom: 1rem;">
                <?php foreach ($online_members as $m): 
                    $m_avatar = !empty($m['foto_profil']) 
                        ? '../uploads/profil/' . htmlspecialchars($m['foto_profil']) 
                        : 'https://ui-avatars.com/api/?name='.urlencode($m['nama']).'&background=1f6feb&color=fff&size=80';
                ?>
                    <div class="avatar-item" title="<?= htmlspecialchars($m['nama']) ?> (<?= $m['is_online'] ? 'Online' : 'Offline' ?>)">
                        <img src="<?= $m_avatar ?>" alt="">
                        <?php if ($m['is_online']): ?>
                            <div class="online-dot"></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="font-size: 0.72rem; color: var(--text-muted); text-align: center;">
                Menampilkan aktivitas anggota kelas terpopuler.
            </div>
        </div>
    </div>
</div>

<script nonce="<?= $cspNonce ?>">
(function() {
    const baseUrl = '<?= $base_url ?>';
    const isLoggedIn = <?= isset($_SESSION['id_user']) ? 'true' : 'false' ?>;
    if (!isLoggedIn) return;

    function refreshDashboard() {
        const fd = new FormData();
        fd.append('page', 'dashboard');
        
        fetch(baseUrl + '/auth/api_realtime.php', {
            method: 'POST',
            body: fd
        })
        .then(res => res.json())
        .then(data => {
            if (data && data.success) {
                // 1. Update stats
                if (data.stats) {
                    const u = document.getElementById('metric-users');
                    const f = document.getElementById('metric-files');
                    const n = document.getElementById('metric-notes');
                    const m = document.getElementById('metric-msg');
                    if (u) u.textContent = data.stats.users;
                    if (f) f.textContent = data.stats.files;
                    if (n) n.textContent = data.stats.notes;
                    if (m) m.textContent = data.stats.msg;
                }

                // 2. Update announcement
                const annContainer = document.getElementById('announcement-content');
                if (annContainer) {
                    if (data.announcement) {
                        let contentHtml = `<h4 style="color:#fff; margin-bottom:0.5rem; font-size:1.05rem">Pesan Penting dari ${data.announcement.nama}</h4>`;
                        if (data.announcement.tipe === 'text') {
                            contentHtml += `<div style="font-size: 0.9rem; line-height: 1.6; color: var(--text-color); margin-bottom: 1rem;">${data.announcement.pesan.replace(/\n/g, '<br>')}</div>`;
                        } else {
                            contentHtml += `<div style="font-size: 0.9rem; line-height: 1.6; color: var(--text-color); margin-bottom: 1rem;"><i class="fas fa-paperclip"></i> Lampiran file</div>`;
                        }
                        contentHtml += `<div style="font-size: 0.72rem; color: var(--text-muted);">Diposting pada: ${data.announcement.created_at}</div>`;
                        annContainer.innerHTML = contentHtml;
                    } else {
                        annContainer.innerHTML = `<p style="color: var(--text-muted); font-size: 0.88rem; text-align: center; padding: 1.5rem 0;">Belum ada pengumuman disematkan saat ini.</p>`;
                    }
                }

                // 3. Update Polling
                const pollContainer = document.getElementById('poll-content');
                if (pollContainer) {
                    if (data.poll) {
                        let pollHtml = `<h4 style="color:#fff; margin-bottom:1rem; font-size:0.98rem">${data.poll.pertanyaan}</h4>`;
                        
                        if (data.poll.media_path) {
                            pollHtml += `<div style="margin-bottom:1rem; border-radius:8px; overflow:hidden; border:1px solid var(--border-color); background:#0d1117; max-height:200px; display:flex; justify-content:center; align-items:center">`;
                            if (data.poll.media_type === 'image') {
                                pollHtml += `<img src="../uploads/msg/${data.poll.media_path}" style="max-width:100%; max-height:200px; object-fit:contain">`;
                            } else if (data.poll.media_type === 'video') {
                                pollHtml += `<video src="../uploads/msg/${data.poll.media_path}" controls style="max-width:100%; max-height:200px"></video>`;
                            }
                            pollHtml += `</div>`;
                        }

                        data.poll.options.forEach(opt => {
                            const percent = data.poll.total_votes > 0 ? Math.round((opt.votes_count / data.poll.total_votes) * 100) : 0;
                            pollHtml += `
                                <div class="poll-option-row" onclick="location.href='../msg/'">
                                    <div class="poll-option-progress" style="width: 100%;">
                                        <div class="poll-option-bar" style="width: ${percent}%"></div>
                                        <div style="display:flex; justify-content:space-between; align-items:center; width:100%; position:relative; z-index:2">
                                            <div style="display:flex; align-items:center; gap:8px; min-width:0">`;
                            if (opt.gambar) {
                                pollHtml += `            <img src="../uploads/msg/${opt.gambar}" style="width:24px; height:24px; object-fit:cover; border-radius:4px; border:1px solid var(--border-color); flex-shrink:0">`;
                            }
                            pollHtml += `                <span class="poll-option-text" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis">${opt.teks}</span>
                                            </div>
                                            <span class="poll-option-votes" style="flex-shrink:0">${opt.votes_count} suara (${percent}%)</span>
                                        </div>
                                    </div>
                                </div>`;
                        });

                        pollHtml += `
                            <div style="font-size:0.75rem; color:var(--text-muted); margin-top:0.75rem; display:flex; justify-content:space-between">
                                <span>Total partisipasi: ${data.poll.total_votes} suara</span>
                                <span>${data.poll.has_voted ? '✓ Sudah memilih' : '⚠ Belum memilih'}</span>
                            </div>`;
                        pollContainer.innerHTML = pollHtml;
                    } else {
                        pollContainer.innerHTML = `<p style="color: var(--text-muted); font-size: 0.88rem; text-align: center; padding: 1.5rem 0;">Tidak ada polling aktif.</p>`;
                    }
                }

                // 4. Update upcoming event
                const eventContainer = document.getElementById('event-content');
                if (eventContainer) {
                    if (data.upcoming_event) {
                        let eventHtml = `
                            <div style="border-left: 3px solid ${data.upcoming_event.warna}; padding-left: 12px; margin-bottom: 0.5rem;">
                                <div style="font-weight: 700; color: #fff; font-size: 0.95rem; margin-bottom: 3px;">
                                    ${data.upcoming_event.judul}
                                </div>
                                <div style="font-size: 0.82rem; color: var(--text-muted);">
                                    <i class="far fa-calendar-alt"></i> ${data.upcoming_event.tanggal_kegiatan}
                                </div>
                            </div>`;
                        if (data.upcoming_event.konten) {
                            eventHtml += `
                                <div style="font-size: 0.82rem; color: var(--text-muted); margin-top: 0.5rem; line-height: 1.4; max-height:80px; overflow:hidden">
                                    ${data.upcoming_event.konten}
                                </div>`;
                        }
                        eventContainer.innerHTML = eventHtml;
                    } else {
                        eventContainer.innerHTML = `<p style="color: var(--text-muted); font-size: 0.82rem; text-align: center; padding: 1rem 0;">Tidak ada agenda terdekat.</p>`;
                    }
                }

                // 5. Update members list
                const membersContainer = document.getElementById('members-list');
                if (membersContainer && data.members) {
                    let membersHtml = '';
                    data.members.forEach(m => {
                        const avatar = m.foto_profil 
                            ? `../uploads/profil/${m.foto_profil}`
                            : `https://ui-avatars.com/api/?name=${encodeURIComponent(m.nama)}&background=1f6feb&color=fff&size=80`;
                        membersHtml += `
                            <div class="avatar-item" title="${m.nama} (${m.is_online ? 'Online' : 'Offline'})">
                                <img src="${avatar}" alt="">`;
                        if (m.is_online) {
                            membersHtml += `    <div class="online-dot"></div>`;
                        }
                        membersHtml += `</div>`;
                    });
                    membersContainer.innerHTML = membersHtml;
                }
            }
        })
        .catch(err => console.error('Dashboard polling error:', err));
    }

    let dashboardPoller = null;

    function startDashboardPolling() {
        if (dashboardPoller) return;
        refreshDashboard();
        dashboardPoller = setInterval(refreshDashboard, 5000);
    }

    function stopDashboardPolling() {
        if (dashboardPoller) {
            clearInterval(dashboardPoller);
            dashboardPoller = null;
        }
    }

    if (document.visibilityState === 'visible' && document.hasFocus()) {
        startDashboardPolling();
    }

    window.addEventListener('focus', startDashboardPolling);
    window.addEventListener('blur', stopDashboardPolling);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            startDashboardPolling();
        } else {
            stopDashboardPolling();
        }
    });
})();
</script>

<?php require_once __DIR__ . '/../template/footer.php'; ?>
