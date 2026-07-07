<?php
// calendar/index.php
require_once __DIR__ . '/../config/security.php';
initSecureSession();

$base_url = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$base_url = preg_replace('/(\/(auth|dashboard|notes|msg|drive|calendar|server|admin|installer))?$/i', '', $base_url);
if ($base_url === '/') $base_url = '';

// Handle calendar parameters via GET and save to session to keep URL clean
if (isset($_GET['year']) || isset($_GET['month']) || isset($_GET['mode'])) {
    if (isset($_GET['year'])) $_SESSION['calendar_year'] = (int)$_GET['year'];
    if (isset($_GET['month'])) $_SESSION['calendar_month'] = (int)$_GET['month'];
    if (isset($_GET['mode'])) $_SESSION['calendar_mode'] = in_array($_GET['mode'], ['server', 'pribadi']) ? $_GET['mode'] : 'server';
    
    header('Location: ' . $base_url . '/calendar/');
    exit;
}

require_once __DIR__ . '/../template/header.php';

// Setup month navigation vars from session
$year = $_SESSION['calendar_year'] ?? (int)date('Y');
$month = $_SESSION['calendar_month'] ?? (int)date('n');
$mode = $_SESSION['calendar_mode'] ?? 'server';

if ($month < 1) { $month = 12; $year--; $_SESSION['calendar_year'] = $year; $_SESSION['calendar_month'] = $month; }
if ($month > 12) { $month = 1; $year++; $_SESSION['calendar_year'] = $year; $_SESSION['calendar_month'] = $month; }

$month_start_timestamp = mktime(0, 0, 0, $month, 1, $year);
$days_in_month = date('t', $month_start_timestamp);
$start_day_of_week = date('N', $month_start_timestamp); // 1 (Mon) - 7 (Sun)

// Translate months to Indonesian
$indo_months = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
$indo_month_name = $indo_months[$month] . ' ' . $year;

// Retrieve notes that act as academic events (associated with dates)
$events = [];
try {
    if ($mode === 'pribadi') {
        $stmt = $conn->prepare("
            SELECT n.*, u.nama as creator 
            FROM notes n
            JOIN users u ON n.id_user = u.id_user
            WHERE YEAR(n.tanggal_kegiatan) = ? AND MONTH(n.tanggal_kegiatan) = ? 
              AND n.id_user = ? AND n.is_global = 0
            ORDER BY n.tanggal_kegiatan ASC, n.updated_at DESC
        ");
        $stmt->execute([$year, $month, $_SESSION['id_user']]);
    } else {
        $stmt = $conn->prepare("
            SELECT n.*, u.nama as creator 
            FROM notes n
            JOIN users u ON n.id_user = u.id_user
            WHERE YEAR(n.tanggal_kegiatan) = ? AND MONTH(n.tanggal_kegiatan) = ?
              AND n.is_global = 1
            ORDER BY n.tanggal_kegiatan ASC, n.updated_at DESC
        ");
        $stmt->execute([$year, $month]);
    }
    $month_events = $stmt->fetchAll();
    
    foreach ($month_events as $ev) {
        $day = (int)date('j', strtotime($ev['tanggal_kegiatan']));
        $events[$day][] = $ev;
    }
} catch (Exception $e) {}

$isAdminOrOperator = in_array($_SESSION['role'] ?? '', ['admin', 'operator', 'superadmin']);
$isSuperAdmin = isSuperAdminRole();
$canCreateGlobal = canCreateGlobalAgenda();
?>

<!-- TinyMCE Rich Text Editor loaded dynamically on-demand -->

<div class="grid grid-cols-12">
    <!-- Title and Toolbar navigation -->
    <div class="col-12">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:1.5rem">
            <h2 style="color:#fff; display:flex; align-items:center; gap:8px">
                <i class="fas fa-calendar-alt text-accent"></i> 
                Kalender Akademik
            </h2>
            <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap">
                <!-- Mode selector buttons -->
                <div style="display:inline-flex; border:1px solid var(--border-color); border-radius:8px; overflow:hidden; background:var(--card-bg)">
                    <a href="?year=<?= $year ?>&month=<?= $month ?>&mode=server" class="btn <?= $mode === 'server' ? 'btn-primary' : 'btn-secondary' ?>" style="border:none; border-radius:0; padding:0.6rem 1rem; font-size:0.85rem">
                        <i class="fas fa-globe"></i> Jadwal Bersama (Server)
                    </a>
                    <a href="?year=<?= $year ?>&month=<?= $month ?>&mode=pribadi" class="btn <?= $mode === 'pribadi' ? 'btn-primary' : 'btn-secondary' ?>" style="border:none; border-radius:0; padding:0.6rem 1rem; font-size:0.85rem">
                        <i class="fas fa-user"></i> Agenda Pribadi
                    </a>
                </div>

                <!-- Navigation arrows -->
                <div style="display:flex; border:1px solid var(--border-color); border-radius:8px; overflow:hidden">
                    <a href="?year=<?= $month == 1 ? $year-1 : $year ?>&month=<?= $month == 1 ? 12 : $month-1 ?>&mode=<?= $mode ?>" class="btn btn-secondary" style="border:none; border-radius:0; padding:0.6rem 0.9rem"><i class="fas fa-chevron-left"></i></a>
                    <div style="background:var(--card-bg); display:flex; align-items:center; padding:0 1rem; font-weight:700; color:#fff; font-size:0.9rem; min-width:140px; justify-content:center">
                        <?= $indo_month_name ?>
                    </div>
                    <a href="?year=<?= $month == 12 ? $year+1 : $year ?>&month=<?= $month == 12 ? 1 : $month+1 ?>&mode=<?= $mode ?>" class="btn btn-secondary" style="border:none; border-radius:0; padding:0.6rem 0.9rem"><i class="fas fa-chevron-right"></i></a>
                </div>
            </div>
        </div>
    </div>

    <!-- Calendar Month Board -->
    <div class="col-12">
        <div class="calendar-grid">
            <div class="calendar-day-header">Sen</div>
            <div class="calendar-day-header">Sel</div>
            <div class="calendar-day-header">Rab</div>
            <div class="calendar-day-header">Kam</div>
            <div class="calendar-day-header">Jum</div>
            <div class="calendar-day-header">Sab</div>
            <div class="calendar-day-header">Min</div>

            <!-- Empty cells before start of month -->
            <?php for ($i = 1; $i < $start_day_of_week; $i++): ?>
                <div class="calendar-day empty"></div>
            <?php endfor; ?>

            <!-- Days of active month -->
            <?php 
            $today_day = (int)date('j');
            $today_month = (int)date('n');
            $today_year = (int)date('Y');
            
            for ($d = 1; $d <= $days_in_month; $d++): 
                $isToday = ($d === $today_day && $month === $today_month && $year === $today_year);
                $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $dayEvents = $events[$d] ?? [];
            ?>
                <div class="calendar-day <?= $isToday ? 'today' : '' ?>" data-date="<?= $dateStr ?>" data-day-num="<?= $d ?>" onclick="openDayModal('<?= $dateStr ?>')">
                    <div class="calendar-day-num"><?= $d ?></div>
                    <div class="calendar-events-wrap">
                        <?php foreach ($dayEvents as $ev): ?>
                            <div class="calendar-event-item" 
                                 data-event-id="<?= $ev['id_note'] ?>"
                                 style="background-color: <?= htmlspecialchars($ev['warna'] ?: '#58a6ff') ?>"
                                 onclick="event.stopPropagation(); openEditAgendaModal(<?= htmlspecialchars(json_encode($ev)) ?>)">
                                <?= htmlspecialchars($ev['judul'] ?: 'Agenda Baru') ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endfor; ?>

            <!-- Empty cells after end of month to align layout -->
            <?php 
            $total_cells = ($start_day_of_week - 1) + $days_in_month;
            $remaining_cells = 7 - ($total_cells % 7);
            if ($remaining_cells < 7): 
                for ($j = 0; $j < $remaining_cells; $j++): 
            ?>
                <div class="calendar-day empty"></div>
            <?php 
                endfor;
            endif; 
            ?>
        </div>
    </div>
</div>

<!-- Modal: Day Agenda List -->
<div class="modal" id="dayAgendaModal">
    <div class="modal-dialog" style="max-width: 420px;">
        <div class="modal-header">
            <h3>Agenda Tanggal: <span id="lblDayModalTitle" class="text-accent"></span></h3>
            <button class="modal-close" onclick="closeDayModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div id="dayAgendaListContainer" style="display:flex; flex-direction:column; gap:8px; margin-bottom:1.25rem; max-height:220px; overflow-y:auto">
                <!-- Dynamically loaded -->
            </div>
            <button class="btn btn-primary" style="width:100%" id="btnAddAgendaFromDay" onclick="createAgendaOnSelectedDate()"><i class="fas fa-plus"></i> Tambah Agenda Baru</button>
        </div>
    </div>
</div>

<!-- Modal: Edit / View Agenda note directly (Word-like editor) -->
<div class="modal" id="editAgendaModal">
    <div class="modal-dialog" style="max-width: 700px; display:flex; flex-direction:column; max-height:90vh;">
        <div class="modal-header">
            <h3>Edit Agenda & Catatan</h3>
            <button class="modal-close" onclick="closeEditAgendaModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" style="overflow-y:auto; display:flex; flex-direction:column; gap:12px; height: 100%;">
            <div style="display:flex; justify-content:space-between; align-items:center">
                <span id="calendarSaveStatus" style="font-size:0.72rem; color:var(--text-muted)">Perubahan disimpan otomatis</span>
                <span id="agendaCreatorBadge" class="badge badge-info" style="display:none"></span>
            </div>
            
            <!-- Title -->
            <input type="text" class="note-editor-title" id="agendaModalTitle" placeholder="Judul agenda..." oninput="triggerCalendarAutosave()">
            
            <!-- Date Picker & color settings -->
            <div style="display:flex; gap:15px; align-items:center; flex-wrap:wrap; padding:8px; background:rgba(0,0,0,0.15); border-radius:8px; font-size:0.85rem">
                <div style="display:flex; gap:10px; align-items:center">
                    <label style="color:var(--text-muted); font-size:0.8rem">Tanggal:</label>
                    <input type="date" id="agendaModalDate" class="form-control" style="width:140px; padding:4px 8px; font-size:0.8rem" onchange="triggerCalendarAutosave()">
                </div>
                
                <div style="display:flex; gap:10px; align-items:center">
                    <label style="color:var(--text-muted); font-size:0.8rem">Warna:</label>
                    <select id="agendaModalColor" class="form-control" style="width:110px; padding:4px 8px; font-size:0.8rem" onchange="triggerCalendarAutosave()">
                        <option value="#58a6ff">Biru</option>
                        <option value="#2ea043">Hijau</option>
                        <option value="#f0ab00">Kuning</option>
                        <option value="#ff7b72">Merah</option>
                    </select>
                </div>
                
                    <?php if ($canCreateGlobal): ?>
                    <label style="display:flex; align-items:center; gap:6px; cursor:pointer" id="lblAgendaGlobal">
                        <input type="checkbox" id="agendaModalGlobal" onchange="onAgendaGlobalToggle()">
                        <span>Terapkan ke Global</span>
                    </label>
                    <?php endif; ?>
            </div>
            
            <!-- TinyMCE editor -->
            <div style="flex:1; min-height:280px">
                <textarea id="agendaModalContent" placeholder="Isi catatan agenda..."></textarea>
            </div>
        </div>
        <div class="modal-footer" id="agendaModalFooter">
            <!-- Rendered dynamically -->
        </div>
    </div>
</div>

<script>
    let activeDayDate = '';
    let currentEditingNote = null;
    let calendarAutosaveTimeout = null;
    let calendarSaveInFlight = null;
    let calendarIsDirty = false;
    let calendarEditorReady = false;
    const currentUserId = <?= (int)$_SESSION['id_user'] ?>;
    const currentMode = '<?= $mode ?>';
    const isAdminOrOperator = <?= $isAdminOrOperator ? 'true' : 'false' ?>;
    const isSuperAdmin = <?= $isSuperAdmin ? 'true' : 'false' ?>;
    const canCreateGlobal = <?= $canCreateGlobal ? 'true' : 'false' ?>;
    
    // Client-side real-time calendar events map
    let calendarEventsMap = <?= json_encode($events) ?> || {};

    function openDayModal(dateStr) {
        activeDayDate = dateStr;
        document.getElementById('lblDayModalTitle').innerText = dateStr;
        
        const list = document.getElementById('dayAgendaListContainer');
        list.innerHTML = '';
        
        const day = parseInt(dateStr.split('-')[2]);
        const dayEvents = calendarEventsMap[day] || [];
        
        if (dayEvents.length === 0) {
            list.innerHTML = '<p style="color:var(--text-muted); font-size:0.85rem; padding:15px 0; text-align:center">Tidak ada agenda pada tanggal ini.</p>';
        } else {
            dayEvents.forEach(ev => {
                list.innerHTML += `
                    <div style="display:flex; justify-content:space-between; align-items:center; padding:8px 12px; border:1px solid var(--border-color); border-radius:8px; background:rgba(255,255,255,0.02)">
                        <div style="flex:1; cursor:pointer" onclick="closeDayModal(); openEditAgendaModal(${JSON.stringify(ev).replace(/"/g, '&quot;')})">
                            <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:${ev.warna || '#58a6ff'}; margin-right:6px"></span>
                            <span style="color:#fff; font-weight:600; font-size:0.85rem">${escapeHTML(ev.judul || 'Agenda Baru')}</span>
                        </div>
                    </div>
                `;
            });
        }
        
        const btnAdd = document.getElementById('btnAddAgendaFromDay');
        if (btnAdd) {
            if (currentMode === 'server' && !canCreateGlobal) {
                btnAdd.style.display = 'none';
            } else {
                btnAdd.style.display = 'block';
            }
        }
        
        document.getElementById('dayAgendaModal').classList.add('open');
    }
    
    function closeDayModal() { document.getElementById('dayAgendaModal').classList.remove('open'); }

    function createAgendaOnSelectedDate() {
        closeDayModal();
        
        const formData = new FormData();
        formData.append('action', 'create_note_date');
        formData.append('tanggal', activeDayDate);
        formData.append('is_global', currentMode === 'server' ? '1' : '0');
        
        fetch('api_calendar.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    // Redirect to the newly created note in editor or open directly in edit modal
                    location.href = `<?= $base_url ?>/notes/?id=${data.id_note}`;
                } else {
                    showToast('Gagal membuat agenda', 'error');
                }
            });
    }

    function resetCalendarEditState() {
        calendarIsDirty = false;
        calendarEditorReady = false;
        clearTimeout(calendarAutosaveTimeout);
        calendarAutosaveTimeout = null;
    }

    function getCalendarEditorContent() {
        const editor = tinymce.get('agendaModalContent');
        if (editor && calendarEditorReady) {
            return editor.getContent();
        }
        return currentEditingNote?.konten || '';
    }

    function getCalendarAgendaIsGlobal() {
        const chkGlobal = document.getElementById('agendaModalGlobal');
        if (chkGlobal) {
            return chkGlobal.checked ? 1 : 0;
        }
        return currentEditingNote?.is_global == 1 ? 1 : 0;
    }

    function hasCalendarPendingSave() {
        return calendarIsDirty || !!calendarAutosaveTimeout || !!calendarSaveInFlight;
    }

    function openEditAgendaModal(ev) {
        resetCalendarEditState();
        currentEditingNote = ev;
        const canEdit = isSuperAdmin || ev.id_user == currentUserId;
        
        document.getElementById('agendaModalTitle').value = ev.judul || '';
        document.getElementById('agendaModalTitle').disabled = !canEdit;
        
        document.getElementById('agendaModalDate').value = ev.tanggal_kegiatan || '';
        document.getElementById('agendaModalDate').disabled = !canEdit;
        
        document.getElementById('agendaModalColor').value = ev.warna || '#58a6ff';
        document.getElementById('agendaModalColor').disabled = !canEdit;
        
        const creatorBadge = document.getElementById('agendaCreatorBadge');
        creatorBadge.innerText = 'Uploader: ' + (ev.creator || 'Saya');
        creatorBadge.style.display = 'block';
        
        const globalCheck = document.getElementById('agendaModalGlobal');
        if (globalCheck) {
            globalCheck.checked = ev.is_global == 1;
            globalCheck.disabled = !canEdit || (!isSuperAdmin && ev.id_user != currentUserId);
        }

        const statusSpan = document.getElementById('calendarSaveStatus');
        if (canEdit) {
            statusSpan.innerText = 'Perubahan disimpan otomatis';
        } else {
            statusSpan.innerText = 'Pratinjau (Hanya Baca)';
        }

        const footer = document.getElementById('agendaModalFooter');
        footer.innerHTML = '';
        
        if (canEdit) {
            footer.innerHTML = `
                <button class="btn btn-danger" style="margin-right:auto" onclick="deleteAgendaNote(${ev.id_note})"><i class="fas fa-trash-alt"></i> Hapus</button>
            `;
            footer.innerHTML += `
                <button class="btn btn-secondary" onclick="location.href='<?= $base_url ?>/notes/?id=${ev.id_note}'"><i class="fas fa-arrow-up-right-from-square"></i> Buka di Catatan</button>
                <button class="btn btn-primary" id="btnFinishAgendaEdit" onclick="finishEditAgendaModal()"><i class="fas fa-check"></i> Selesai</button>
            `;
        } else {
            footer.innerHTML += `
                <button class="btn btn-primary" onclick="closeEditAgendaModal()"><i class="fas fa-times"></i> Tutup</button>
            `;
        }

        // Helper to dynamically load script in background
        function loadTinyMCE(callback) {
            if (window.tinymce) {
                callback();
                return;
            }
            const script = document.createElement('script');
            script.src = "https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.3/tinymce.min.js";
            script.crossOrigin = "anonymous";
            script.referrerPolicy = "origin";
            script.onload = callback;
            document.head.appendChild(script);
        }

        // Initialize or set content of TinyMCE editor asynchronously on demand
        loadTinyMCE(() => {
            if (tinymce.get('agendaModalContent')) {
                const editor = tinymce.get('agendaModalContent');
                calendarEditorReady = false;
                editor.setContent(ev.konten || '');
                editor.mode.set(canEdit ? 'design' : 'readonly');
                setTimeout(() => { calendarEditorReady = true; }, 0);
            } else {
                tinymce.init({
                    selector: '#agendaModalContent',
                    height: 300,
                    plugins: 'lists link image media code table wordcount',
                    toolbar: 'undo redo | formatselect | bold italic underline | alignleft aligncenter alignright alignjustify | forecolor backcolor | bullist numlist | image media table | code',
                    menubar: 'edit view insert format tools table',
                    skin: 'oxide-dark',
                    content_css: 'dark',
                    branding: false,
                    promotion: false,
                    statusbar: false,
                    image_advtab: true,
                    image_caption: true,
                    image_dimensions: true,
                    image_title: true,
                    automatic_uploads: true,
                    file_picker_types: 'image file media',
                    images_upload_handler: function (blobInfo, progress) {
                        return new Promise((resolve, reject) => {
                            const xhr = new XMLHttpRequest();
                            xhr.withCredentials = true;
                            xhr.open('POST', '../auth/api_upload_tinymce.php');
                            
                            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                            if (token) xhr.setRequestHeader('X-CSRF-Token', token);
                            
                            xhr.onload = function() {
                                if (xhr.status < 200 || xhr.status >= 300) {
                                    reject('HTTP Error: ' + xhr.status);
                                    return;
                                }
                                try {
                                    const json = JSON.parse(xhr.responseText);
                                    if (!json || json.error) {
                                        reject(json && json.error && json.error.message ? json.error.message : 'Upload failed');
                                        return;
                                    }
                                    resolve(json.location);
                                } catch(e) {
                                    reject('Invalid server response');
                                }
                            };
                            
                            xhr.onerror = function() {
                                reject('Upload failed due to network error');
                            };
                            
                            const formData = new FormData();
                            formData.append('file', blobInfo.blob(), blobInfo.filename());
                            xhr.send(formData);
                        });
                    },
                    file_picker_callback: function (cb, value, meta) {
                        const input = document.createElement('input');
                        input.setAttribute('type', 'file');
                        if (meta.filetype === 'image') input.setAttribute('accept', 'image/*');
                        else if (meta.filetype === 'media') input.setAttribute('accept', 'video/*,audio/*');
                        
                        input.onchange = function () {
                            const file = this.files[0];
                            const xhr = new XMLHttpRequest();
                            xhr.withCredentials = true;
                            xhr.open('POST', '../auth/api_upload_tinymce.php');
                            
                            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                            if (token) xhr.setRequestHeader('X-CSRF-Token', token);
                            
                            xhr.onload = function() {
                                if (xhr.status < 200 || xhr.status >= 300) {
                                    alert('Gagal mengupload file (HTTP Error: ' + xhr.status + ')');
                                    return;
                                }
                                try {
                                    const json = JSON.parse(xhr.responseText);
                                    if (json && json.location) {
                                        cb(json.location, { text: file.name, title: file.name });
                                    } else {
                                        alert('Gagal mengupload file: ' + (json.error?.message || 'Error tidak diketahui'));
                                    }
                                } catch(e) {
                                    alert('Respons server tidak valid.');
                                }
                            };
                            
                            xhr.onerror = function() {
                                alert('Upload gagal karena masalah koneksi.');
                            };
                            
                            const formData = new FormData();
                            formData.append('file', file, file.name);
                            xhr.send(formData);
                        };
                        input.click();
                    },
                    image_class_list: [
                        {title: 'Default (Rata Teks)', value: ''},
                        {title: 'Rata Kiri (Float Left)', value: 'float-left'},
                        {title: 'Rata Kanan (Float Right)', value: 'float-right'},
                        {title: 'Rata Tengah (Center)', value: 'align-center'},
                        {title: 'Gambar Responsif (Full Width)', value: 'img-responsive'}
                    ],
                    setup: function (editor) {
                        editor.on('init', function() {
                            editor.setContent(ev.konten || '');
                            editor.mode.set(canEdit ? 'design' : 'readonly');
                            calendarEditorReady = true;
                        });
                        editor.on('change keyup', function () {
                            if (canEdit && calendarEditorReady) {
                                calendarIsDirty = true;
                                triggerCalendarAutosave();
                            }
                        });
                    }
                });
            }
        });

        document.getElementById('editAgendaModal').classList.add('open');
    }

    function isEventVisibleInCurrentMode(isGlobal, ownerId) {
        if (currentMode === 'server') return isGlobal === 1;
        return isGlobal === 0 && ownerId == currentUserId;
    }

    function removeEventFromCalendarMap(noteId) {
        Object.keys(calendarEventsMap).forEach(dayKey => {
            if (calendarEventsMap[dayKey]) {
                calendarEventsMap[dayKey] = calendarEventsMap[dayKey].filter(ev => ev.id_note != noteId);
            }
        });
    }

    function upsertEventInCalendarMap(ev) {
        const day = parseInt((ev.tanggal_kegiatan || '').split('-')[2]);
        if (!day) return;

        removeEventFromCalendarMap(ev.id_note);
        if (!calendarEventsMap[day]) calendarEventsMap[day] = [];
        calendarEventsMap[day].push(ev);
    }

    function applyCalendarSaveToUI(title, content, eventDate, eventColor, isGlobal) {
        if (!currentEditingNote) return;

        const noteId = currentEditingNote.id_note;
        const ownerId = currentEditingNote.id_user;
        const visible = isEventVisibleInCurrentMode(isGlobal, ownerId);
        const eventEl = document.querySelector(`.calendar-event-item[data-event-id="${noteId}"]`);

        const updatedEvent = {
            ...currentEditingNote,
            judul: title,
            konten: content,
            tanggal_kegiatan: eventDate,
            warna: eventColor,
            is_global: isGlobal
        };

        if (!visible) {
            if (eventEl) eventEl.remove();
            removeEventFromCalendarMap(noteId);
        } else {
            upsertEventInCalendarMap(updatedEvent);

            if (eventEl) {
                eventEl.innerText = title || 'Agenda Baru';
                eventEl.style.backgroundColor = eventColor;

                const parentDay = eventEl.closest('.calendar-day');
                if (parentDay && parentDay.getAttribute('data-date') !== eventDate) {
                    const newParent = document.querySelector(`.calendar-day[data-date="${eventDate}"]`);
                    if (newParent) {
                        const newWrap = newParent.querySelector('.calendar-events-wrap');
                        if (newWrap) newWrap.appendChild(eventEl);
                    } else {
                        eventEl.remove();
                    }
                }
            } else {
                const day = parseInt((eventDate || '').split('-')[2]);
                const dayCell = document.querySelector(`.calendar-day[data-day-num="${day}"]`);
                const wrap = dayCell ? dayCell.querySelector('.calendar-events-wrap') : null;
                if (wrap) {
                    const newEl = document.createElement('div');
                    newEl.className = 'calendar-event-item';
                    newEl.setAttribute('data-event-id', noteId);
                    newEl.style.backgroundColor = eventColor;
                    newEl.innerText = title || 'Agenda Baru';
                    newEl.onclick = function(e) {
                        e.stopPropagation();
                        openEditAgendaModal(updatedEvent);
                    };
                    wrap.appendChild(newEl);
                }
            }
        }

        currentEditingNote.judul = title;
        currentEditingNote.konten = content;
        currentEditingNote.tanggal_kegiatan = eventDate;
        currentEditingNote.warna = eventColor;
        currentEditingNote.is_global = isGlobal;
    }

    function performCalendarSave(options = {}) {
        if (!currentEditingNote) return Promise.resolve(false);

        const canEdit = isSuperAdmin || currentEditingNote.id_user == currentUserId;
        if (!canEdit) return Promise.resolve(false);

        if (calendarSaveInFlight) return calendarSaveInFlight;

        const statusSpan = document.getElementById('calendarSaveStatus');
        if (statusSpan) statusSpan.innerText = 'Menyimpan...';

        const title = document.getElementById('agendaModalTitle').value;
        const content = getCalendarEditorContent();
        const eventDate = document.getElementById('agendaModalDate').value;
        const eventColor = document.getElementById('agendaModalColor').value;
        const isGlobal = getCalendarAgendaIsGlobal();

        const formData = new FormData();
        formData.append('action', 'update');
        formData.append('id_note', currentEditingNote.id_note);
        formData.append('judul', title);
        formData.append('konten', content);
        formData.append('tanggal_kegiatan', eventDate);
        formData.append('warna', eventColor);
        formData.append('is_global', String(isGlobal));

        calendarSaveInFlight = fetch('../notes/api_notes.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            calendarSaveInFlight = null;
            if (data.success) {
                applyCalendarSaveToUI(title, content, eventDate, eventColor, isGlobal);
                calendarIsDirty = false;
                if (statusSpan) statusSpan.innerText = 'Perubahan disimpan otomatis';
                if (options.successMessage) showToast(options.successMessage);
                return true;
            }

            if (statusSpan) statusSpan.innerText = 'Gagal menyimpan otomatis';
            if (options.revertGlobalOnFail) {
                const chkGlobal = document.getElementById('agendaModalGlobal');
                if (chkGlobal) chkGlobal.checked = !chkGlobal.checked;
            }
            if (options.showError !== false) {
                showToast(data.message || 'Gagal menyimpan otomatis', 'error');
            }
            return false;
        })
        .catch(() => {
            calendarSaveInFlight = null;
            if (statusSpan) statusSpan.innerText = 'Gagal menyimpan otomatis';
            if (options.revertGlobalOnFail) {
                const chkGlobal = document.getElementById('agendaModalGlobal');
                if (chkGlobal) chkGlobal.checked = !chkGlobal.checked;
            }
            if (options.showError !== false) {
                showToast('Gagal menyimpan otomatis', 'error');
            }
            return false;
        });

        return calendarSaveInFlight;
    }

    function onAgendaGlobalToggle() {
        if (!currentEditingNote) return;
        clearTimeout(calendarAutosaveTimeout);
        calendarAutosaveTimeout = null;
        calendarIsDirty = true;

        const chkGlobal = document.getElementById('agendaModalGlobal');
        const isNowPrivate = chkGlobal && !chkGlobal.checked;
        performCalendarSave({
            successMessage: isNowPrivate
                ? 'Agenda diubah menjadi jadwal pribadi'
                : 'Agenda diterapkan ke kalender global',
            revertGlobalOnFail: true
        }).then(success => {
            if (success) refreshCalendar();
        });
    }

    async function finishEditAgendaModal() {
        clearTimeout(calendarAutosaveTimeout);
        calendarAutosaveTimeout = null;

        const finishBtn = document.getElementById('btnFinishAgendaEdit');
        const canEdit = currentEditingNote && (isSuperAdmin || currentEditingNote.id_user == currentUserId);
        const needsSave = canEdit && hasCalendarPendingSave();

        if (needsSave) {
            if (finishBtn) {
                finishBtn.disabled = true;
                finishBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';
            }

            if (calendarSaveInFlight) {
                await calendarSaveInFlight;
            } else {
                await performCalendarSave();
            }
        }

        document.getElementById('editAgendaModal').classList.remove('open');
        currentEditingNote = null;
        resetCalendarEditState();

        if (finishBtn) {
            finishBtn.disabled = false;
            finishBtn.innerHTML = '<i class="fas fa-check"></i> Selesai';
        }

        if (needsSave) refreshCalendar();
    }

    function closeEditAgendaModal() {
        finishEditAgendaModal();
    }

    // Real-time input synchronization for immediate UI response (1ms)
    document.getElementById('agendaModalTitle')?.addEventListener('input', function(e) {
        if (!currentEditingNote) return;
        const val = e.target.value || 'Agenda Baru';
        const eventEl = document.querySelector(`.calendar-event-item[data-event-id="${currentEditingNote.id_note}"]`);
        if (eventEl) eventEl.innerText = val;
    });

    document.getElementById('agendaModalColor')?.addEventListener('change', function(e) {
        if (!currentEditingNote) return;
        const val = e.target.value;
        const eventEl = document.querySelector(`.calendar-event-item[data-event-id="${currentEditingNote.id_note}"]`);
        if (eventEl) eventEl.style.backgroundColor = val;
    });

    document.getElementById('agendaModalDate')?.addEventListener('change', function(e) {
        if (!currentEditingNote) return;
        const val = e.target.value;
        const eventEl = document.querySelector(`.calendar-event-item[data-event-id="${currentEditingNote.id_note}"]`);
        if (eventEl) {
            const newParent = document.querySelector(`.calendar-day[data-date="${val}"]`);
            if (newParent) {
                const newWrap = newParent.querySelector('.calendar-events-wrap');
                if (newWrap) {
                    newWrap.appendChild(eventEl);
                }
            } else {
                eventEl.remove();
            }
        }
    });

    function triggerCalendarAutosave() {
        if (!currentEditingNote) return;

        const canEdit = isSuperAdmin || currentEditingNote.id_user == currentUserId;
        if (!canEdit) return;

        calendarIsDirty = true;

        const statusSpan = document.getElementById('calendarSaveStatus');
        if (statusSpan) statusSpan.innerText = 'Menyimpan...';

        clearTimeout(calendarAutosaveTimeout);
        calendarAutosaveTimeout = setTimeout(() => {
            calendarAutosaveTimeout = null;
            performCalendarSave();
        }, 800);
    }

    function deleteAgendaNote(id) {
        Swal.fire({
            title: 'Hapus Agenda?',
            text: 'Catatan agenda ini akan dihapus secara permanen!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Hapus',
            cancelButtonText: 'Batal',
            confirmButtonColor: '#f85149',
            background: '#161b22',
            color: '#c9d1d9'
        }).then(res => {
            if (res.isConfirmed) {
                // Calls notes API delete directly!
                const fd = new FormData();
                fd.append('action', 'delete');
                fd.append('id', id);
                fetch('../notes/api_notes.php', { method: 'POST', body: fd })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            showToast('Agenda dihapus');
                            if (currentEditingNote) {
                                removeEventFromCalendarMap(currentEditingNote.id_note);
                                const eventEl = document.querySelector(`.calendar-event-item[data-event-id="${currentEditingNote.id_note}"]`);
                                if (eventEl) eventEl.remove();
                            }
                            document.getElementById('editAgendaModal').classList.remove('open');
                            currentEditingNote = null;
                            refreshCalendar();
                        } else {
                            showToast('Gagal menghapus agenda', 'error');
                        }
                    });
            }
        });
    }

    function escapeHTML(str) {
        if (!str) return '';
        return str.replace(/[&<>'"]/g, 
            tag => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[tag] || tag)
        );
    }

    // Real-time calendar polling
    function refreshCalendar() {
        const fd = new FormData();
        fd.append('page', 'calendar');
        fd.append('year', <?= $year ?>);
        fd.append('month', <?= $month ?>);
        fd.append('mode', '<?= $mode ?>');

        fetch('../auth/api_realtime.php', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if (data && data.success && data.events) {
                    calendarEventsMap = data.events;
                    
                    // Loop over all days of the month and update events inside wraps
                    document.querySelectorAll('.calendar-day[data-day-num]').forEach(cell => {
                        const d = parseInt(cell.getAttribute('data-day-num'));
                        const wrap = cell.querySelector('.calendar-events-wrap');
                        if (!wrap) return;

                        const dayEvents = calendarEventsMap[d] || [];
                        let html = '';
                        dayEvents.forEach(ev => {
                            html += `
                                <div class="calendar-event-item" 
                                     data-event-id="${ev.id_note}"
                                     style="background-color: ${ev.warna || '#58a6ff'}"
                                     onclick="event.stopPropagation(); openEditAgendaModal(${JSON.stringify(ev).replace(/"/g, '&quot;')})">
                                    ${escapeHTML(ev.judul || 'Agenda Baru')}
                                </div>
                            `;
                        });
                        wrap.innerHTML = html;
                    });

                    // If day modal is open, refresh its content too
                    const dayModal = document.getElementById('dayAgendaModal');
                    if (dayModal && dayModal.classList.contains('open') && activeDayDate) {
                        const list = document.getElementById('dayAgendaListContainer');
                        if (list) {
                            const day = parseInt(activeDayDate.split('-')[2]);
                            const dayEvents = calendarEventsMap[day] || [];
                            
                            let modalHtml = '';
                            if (dayEvents.length === 0) {
                                modalHtml = '<p style="color:var(--text-muted); font-size:0.85rem; padding:15px 0; text-align:center">Tidak ada agenda pada tanggal ini.</p>';
                            } else {
                                dayEvents.forEach(ev => {
                                    modalHtml += `
                                        <div style="display:flex; justify-content:space-between; align-items:center; padding:8px 12px; border:1px solid var(--border-color); border-radius:8px; background:rgba(255,255,255,0.02)">
                                            <div style="flex:1; cursor:pointer" onclick="closeDayModal(); openEditAgendaModal(${JSON.stringify(ev).replace(/"/g, '&quot;')})">
                                                <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:${ev.warna || '#58a6ff'}; margin-right:6px"></span>
                                                <span style="color:#fff; font-weight:600; font-size:0.85rem">${escapeHTML(ev.judul || 'Agenda Baru')}</span>
                                            </div>
                                        </div>
                                    `;
                                });
                            }
                            list.innerHTML = modalHtml;
                        }
                    }
                }
            })
            .catch(err => console.error('Calendar polling error:', err));
    }

    let calendarPoller = null;

    function startCalendarPolling() {
        if (calendarPoller) return;
        refreshCalendar();
        calendarPoller = setInterval(refreshCalendar, 5000);
    }

    function stopCalendarPolling() {
        if (calendarPoller) {
            clearInterval(calendarPoller);
            calendarPoller = null;
        }
    }

    if (document.visibilityState === 'visible' && document.hasFocus()) {
        startCalendarPolling();
    }

    window.addEventListener('focus', startCalendarPolling);
    window.addEventListener('blur', stopCalendarPolling);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            startCalendarPolling();
        } else {
            stopCalendarPolling();
        }
    });
</script>

<?php require_once __DIR__ . '/../template/footer.php'; ?>
