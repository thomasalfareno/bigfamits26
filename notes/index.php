<?php
// notes/index.php
require_once __DIR__ . '/../config/security.php';
initSecureSession();

// Handle note change via GET parameter to keep URL clean
if (isset($_GET['id'])) {
    $_SESSION['active_note_id'] = (int)$_GET['id'];
    header('Location: ./');
    exit;
}

require_once __DIR__ . '/../template/header.php';

// Fetch notes belonging to the logged-in user
$user_notes = [];
try {
    $stmt = $conn->prepare("SELECT * FROM notes WHERE id_user = ? ORDER BY updated_at DESC");
    $stmt->execute([$_SESSION['id_user']]);
    $user_notes = $stmt->fetchAll();
} catch (Exception $e) {}

// Active note logic
$active_note = null;
$active_id = $_SESSION['active_note_id'] ?? null;

if ($active_id) {
    try {
        $stmt_act = $conn->prepare("SELECT * FROM notes WHERE id_note = ? AND id_user = ? LIMIT 1");
        $stmt_act->execute([$active_id, $_SESSION['id_user']]);
        $active_note = $stmt_act->fetch();
    } catch(Exception $e) {}
}

// Fallback to latest note if none chosen
if (!$active_note && count($user_notes) > 0) {
    $active_note = $user_notes[0];
    $_SESSION['active_note_id'] = $active_note['id_note'];
}

$isAdminOrOperator = in_array($_SESSION['role'] ?? '', ['admin', 'operator', 'superadmin']);
$canCreateGlobal = canCreateGlobalAgenda();
?>

<!-- TinyMCE Rich Text Editor loaded dynamically on-demand -->

<div class="notes-layout" id="notesLayout">
    <!-- Sidebar List -->
    <div class="notes-sidebar">
        <div class="notes-sidebar-header">
            <button class="btn btn-primary" style="flex:1" onclick="createNewNote()"><i class="fas fa-plus"></i> Catatan Baru</button>
        </div>
        <div class="notes-list" id="notesListContainer">
            <?php if (count($user_notes) > 0): ?>
                <?php foreach ($user_notes as $n): 
                    $isActive = ($active_note && $active_note['id_note'] == $n['id_note']);
                ?>
                    <div class="note-item <?= $isActive ? 'active' : '' ?>" onclick="loadNote(<?= $n['id_note'] ?>)">
                        <div class="note-item-title"><?= htmlspecialchars($n['judul'] ?: 'Catatan Baru') ?></div>
                        <div class="note-item-preview"><?= htmlspecialchars(substr(strip_tags($n['konten']), 0, 50)) ?: 'Belum ada konten...' ?></div>
                        <div class="note-item-date"><?= date('d M Y H:i', strtotime($n['updated_at'])) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="padding:2rem; text-align:center; color:var(--text-muted); font-size:0.85rem" id="noNotesPlaceholder">Belum ada catatan.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Active Editor Panel -->
    <div class="notes-content">
        <?php if ($active_note): 
            $hasDate = !empty($active_note['tanggal_kegiatan']);
        ?>
            <div class="notes-content-header" style="flex-wrap:wrap; gap:10px">
                <button class="btn btn-secondary" id="btnToggleNotesSidebar" onclick="toggleNotesSidebar()" style="padding:0.4rem 0.8rem; font-size:0.8rem" title="Tampilkan/Sembunyikan Daftar Catatan"><i class="fas fa-columns"></i> Sembunyikan List</button>
                <button class="btn btn-secondary" id="btnToggleFullscreen" onclick="toggleFullscreen()" style="padding:0.4rem 0.8rem; font-size:0.8rem" title="Mode Layar Penuh"><i class="fas fa-expand"></i> Layar Penuh</button>
                <span style="font-size:0.75rem; color:var(--success-color); font-weight: 500" id="saveStatus">Tersimpan ke database</span>
                <div style="display:flex; gap:8px; margin-left:auto">
                    <button class="btn btn-primary" onclick="saveActiveNote()" style="padding:0.4rem 0.8rem; font-size:0.8rem" title="Simpan Catatan"><i class="fas fa-save"></i> Simpan Catatan</button>
                    <button class="btn btn-secondary" onclick="exportNoteToDrive()" style="padding:0.4rem 0.8rem; font-size:0.8rem" title="Upload ke Drive Bersama"><i class="fas fa-cloud-arrow-up text-accent"></i> Ekspor ke Drive</button>
                    <button class="btn btn-danger" onclick="deleteActiveNote()" style="padding:0.4rem 0.8rem; font-size:0.8rem"><i class="fas fa-trash-alt"></i> Hapus</button>
                </div>
            </div>
            
            <div class="notes-content-body" style="padding:1rem">
                <!-- Editor Title -->
                <input type="text" class="note-editor-title" id="noteEditorTitle" value="<?= htmlspecialchars($active_note['judul']) ?>" placeholder="Judul Catatan..." oninput="markUnsaved()">
                
                <!-- Agenda Calendar Options -->
                <div style="display:flex; gap:15px; align-items:center; flex-wrap:wrap; padding:8px; background:rgba(0,0,0,0.15); border-radius:8px; margin-bottom:1rem; font-size:0.85rem">
                    <label style="display:flex; align-items:center; gap:6px; cursor:pointer">
                        <input type="checkbox" id="chkIsCalendar" <?= $hasDate ? 'checked' : '' ?> onchange="toggleCalendarFields(this.checked)">
                        <span>Jadikan Agenda Kalender</span>
                    </label>
                    
                    <div id="calendarOptionsBox" style="display: <?= $hasDate ? 'flex' : 'none' ?>; gap:12px; align-items:center;">
                        <input type="date" id="noteEventDate" class="form-control" style="width:140px; padding:4px 8px; font-size:0.8rem" value="<?= htmlspecialchars($active_note['tanggal_kegiatan'] ?? '') ?>" onchange="markUnsaved()">
                        
                        <select id="noteEventColor" class="form-control" style="width:110px; padding:4px 8px; font-size:0.8rem" onchange="markUnsaved()">
                            <option value="#58a6ff" <?= ($active_note['warna'] === '#58a6ff') ? 'selected' : '' ?>>Biru</option>
                            <option value="#2ea043" <?= ($active_note['warna'] === '#2ea043') ? 'selected' : '' ?>>Hijau</option>
                            <option value="#f0ab00" <?= ($active_note['warna'] === '#f0ab00') ? 'selected' : '' ?>>Kuning</option>
                            <option value="#ff7b72" <?= ($active_note['warna'] === '#ff7b72') ? 'selected' : '' ?>>Merah</option>
                        </select>
                        
                        <?php if ($canCreateGlobal): ?>
                            <label style="display:flex; align-items:center; gap:6px; cursor:pointer; margin-left:10px" id="lblNoteGlobal">
                                <input type="checkbox" id="chkIsGlobal" <?= ($active_note['is_global'] == 1) ? 'checked' : '' ?> onchange="onGlobalToggle()">
                                <span>Terapkan ke Global</span>
                            </label>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Rich WYSIWYG Editor container -->
                <div style="flex:1; min-height:300px">
                    <textarea id="noteEditorText" placeholder="Tulis catatan kamu di sini..."><?= htmlspecialchars($active_note['konten']) ?></textarea>
                </div>
            </div>
        <?php else: ?>
            <div style="flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:3rem; text-align:center; color:var(--text-muted)">
                <i class="fas fa-sticky-note" style="font-size:3rem; margin-bottom:1rem; opacity:0.3"></i>
                <h3>Buat catatan pribadi pertama kamu!</h3>
                <p style="font-size:0.85rem; margin-top:0.5rem; max-width:300px">Semua catatan disimpan secara aman ke database pribadi Anda.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    let activeNoteId = <?= $active_note ? (int)$active_note['id_note'] : 'null' ?>;
    let hasUnsavedChanges = false;
    const canCreateGlobal = <?= $canCreateGlobal ? 'true' : 'false' ?>;

    document.addEventListener('DOMContentLoaded', () => {
        if (activeNoteId) {
            // Restore collapsed state if set
            if (localStorage.getItem('notes-sidebar-hidden') === '1') {
                document.getElementById('notesLayout').classList.add('sidebar-hidden');
                const btn = document.getElementById('btnToggleNotesSidebar');
                if (btn) btn.innerHTML = '<i class="fas fa-columns"></i> Tampilkan List';
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

            // Initialize TinyMCE Word-like premium configuration asynchronously
            loadTinyMCE(() => {
                tinymce.init({
                    selector: '#noteEditorText',
                    min_height: 400,
                    plugins: 'lists link image media code table wordcount autoresize',
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
                    autoresize_bottom_margin: 50,
                    autoresize_overflow_padding: 10,
                    image_class_list: [
                        {title: 'Default (Rata Teks)', value: ''},
                        {title: 'Rata Kiri (Float Left)', value: 'float-left'},
                        {title: 'Rata Kanan (Float Right)', value: 'float-right'},
                        {title: 'Rata Tengah (Center)', value: 'align-center'},
                        {title: 'Gambar Responsif (Full Width)', value: 'img-responsive'}
                    ],
                    setup: function (editor) {
                        editor.on('change keyup', function () {
                            markUnsaved();
                        });
                    }
                });
            });
        }
    });

    function toggleNotesSidebar() {
        const layout = document.getElementById('notesLayout');
        layout.classList.toggle('sidebar-hidden');
        const isHidden = layout.classList.contains('sidebar-hidden');
        localStorage.setItem('notes-sidebar-hidden', isHidden ? '1' : '0');
        
        const btn = document.getElementById('btnToggleNotesSidebar');
        if (btn) {
            btn.innerHTML = isHidden ? '<i class="fas fa-columns"></i> Tampilkan List' : '<i class="fas fa-columns"></i> Sembunyikan List';
        }
    }

    function toggleFullscreen() {
        const layout = document.getElementById('notesLayout');
        const btn = document.getElementById('btnToggleFullscreen');
        layout.classList.toggle('fullscreen');
        const isFS = layout.classList.contains('fullscreen');
        
        // Hide/show the main topbar and sidebar navigation
        const topbar = document.querySelector('.topbar');
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.querySelector('.main-content');
        if (topbar) topbar.style.display = isFS ? 'none' : '';
        if (sidebar) sidebar.style.display = isFS ? 'none' : '';
        if (mainContent) {
            mainContent.style.marginLeft = isFS ? '0' : '';
            mainContent.style.padding = isFS ? '0' : '';
        }
        document.body.style.overflow = isFS ? 'hidden' : '';
        
        if (btn) {
            btn.innerHTML = isFS ? '<i class="fas fa-compress"></i> Keluar Layar Penuh' : '<i class="fas fa-expand"></i> Layar Penuh';
        }

        // Re-trigger TinyMCE resize after layout change
        setTimeout(() => {
            const editor = tinymce.get('noteEditorText');
            if (editor && editor.execCommand) {
                editor.execCommand('mceAutoResize');
            }
        }, 300);
    }

    // ESC key exits fullscreen
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            const layout = document.getElementById('notesLayout');
            if (layout && layout.classList.contains('fullscreen')) {
                toggleFullscreen();
            }
        }
    });

    function markUnsaved() {
        if (!activeNoteId) return;
        hasUnsavedChanges = true;
        const statusSpan = document.getElementById('saveStatus');
        if (statusSpan) {
            statusSpan.innerText = 'Perubahan belum disimpan';
            statusSpan.style.color = 'var(--gold-color)';
        }
    }

    function toggleCalendarFields(show) {
        document.getElementById('calendarOptionsBox').style.display = show ? 'flex' : 'none';
        const chkGlobal = document.getElementById('chkIsGlobal');
        if (!show) {
            document.getElementById('noteEventDate').value = '';
            if (chkGlobal) chkGlobal.checked = false;
        } else if (!document.getElementById('noteEventDate').value) {
            document.getElementById('noteEventDate').value = new Date().toISOString().split('T')[0];
        }
        markUnsaved();
    }

    function getNoteAgendaValues() {
        const isCalChecked = document.getElementById('chkIsCalendar').checked;
        const chkGlobal = document.getElementById('chkIsGlobal');
        const isGlobal = (isCalChecked && canCreateGlobal && chkGlobal && chkGlobal.checked) ? 1 : 0;
        return {
            tanggal_kegiatan: isCalChecked ? (document.getElementById('noteEventDate').value || '') : '',
            warna: isCalChecked ? document.getElementById('noteEventColor').value : '#58a6ff',
            is_global: isGlobal
        };
    }

    function buildNoteUpdateFormData() {
        const agenda = getNoteAgendaValues();
        const formData = new FormData();
        formData.append('action', 'update');
        formData.append('id_note', activeNoteId);
        formData.append('judul', document.getElementById('noteEditorTitle').value);
        formData.append('konten', tinymce.get('noteEditorText') ? tinymce.get('noteEditorText').getContent() : '');
        formData.append('tanggal_kegiatan', agenda.tanggal_kegiatan);
        formData.append('warna', agenda.warna);
        formData.append('is_global', String(agenda.is_global));
        return formData;
    }

    function onGlobalToggle() {
        if (!activeNoteId) return;
        const chkGlobal = document.getElementById('chkIsGlobal');
        const isNowPrivate = chkGlobal && !chkGlobal.checked;
        markUnsaved();
        saveActiveNote({
            successMessage: isNowPrivate
                ? 'Agenda diubah menjadi jadwal pribadi'
                : 'Agenda diterapkan ke kalender global',
            revertGlobalOnFail: true
        });
    }

    function createNewNote() {
        if (hasUnsavedChanges) {
            Swal.fire({
                title: 'Simpan perubahan?',
                text: 'Catatan ini memiliki perubahan yang belum disimpan. Simpan sekarang?',
                icon: 'warning',
                showCancelButton: true,
                showDenyButton: true,
                confirmButtonText: 'Simpan & Buat Baru',
                denyButtonText: 'Buat Baru Tanpa Menyimpan',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#2ea043',
                denyButtonColor: '#3085d6',
                background: '#161b22',
                color: '#c9d1d9'
            }).then(res => {
                if (res.isConfirmed) {
                    saveActiveNoteAndCreateNew();
                } else if (res.isDenied) {
                    hasUnsavedChanges = false;
                    executeCreateNewNote();
                }
            });
        } else {
            executeCreateNewNote();
        }
    }

    function executeCreateNewNote() {
        const fd = new FormData();
        fd.append('action', 'create');
        fetch('api_notes.php', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    hasUnsavedChanges = false;
                    location.href = './';
                } else {
                    showToast('Gagal membuat catatan', 'error');
                }
            })
            .catch(() => showToast('Gagal membuat catatan', 'error'));
    }

    function saveActiveNoteAndCreateNew() {
        const formData = buildNoteUpdateFormData();
        
        fetch('api_notes.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                hasUnsavedChanges = false;
                executeCreateNewNote();
            } else {
                showToast(data.message || 'Gagal menyimpan catatan', 'error');
            }
        })
        .catch(() => {
            showToast('Gagal menyimpan catatan', 'error');
        });
    }

    function loadNote(id) {
        if (hasUnsavedChanges) {
            Swal.fire({
                title: 'Simpan perubahan?',
                text: 'Catatan ini memiliki perubahan yang belum disimpan. Simpan sekarang?',
                icon: 'warning',
                showCancelButton: true,
                showDenyButton: true,
                confirmButtonText: 'Simpan & Buka',
                denyButtonText: 'Buka Tanpa Menyimpan',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#2ea043',
                denyButtonColor: '#3085d6',
                background: '#161b22',
                color: '#c9d1d9'
            }).then(res => {
                if (res.isConfirmed) {
                    saveActiveNoteAndRedirect(id);
                } else if (res.isDenied) {
                    hasUnsavedChanges = false;
                    switchNoteViaAjax(id);
                }
            });
        } else {
            switchNoteViaAjax(id);
        }
    }

    function switchNoteViaAjax(id) {
        const fd = new FormData();
        fd.append('action', 'get');
        fd.append('id_note', id);
        fetch('api_notes.php', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.note) {
                    const n = data.note;
                    activeNoteId = n.id_note;
                    hasUnsavedChanges = false;

                    // Update editor fields
                    document.getElementById('noteEditorTitle').value = n.judul || '';
                    const editor = tinymce.get('noteEditorText');
                    if (editor) editor.setContent(n.konten || '');

                    // Update calendar fields
                    const hasDate = !!n.tanggal_kegiatan;
                    document.getElementById('chkIsCalendar').checked = hasDate;
                    document.getElementById('calendarOptionsBox').style.display = hasDate ? 'flex' : 'none';
                    document.getElementById('noteEventDate').value = n.tanggal_kegiatan || '';
                    document.getElementById('noteEventColor').value = n.warna || '#58a6ff';
                    const chkGlobal = document.getElementById('chkIsGlobal');
                    if (chkGlobal) chkGlobal.checked = n.is_global == 1;

                    // Update sidebar active state
                    document.querySelectorAll('.note-item').forEach(el => el.classList.remove('active'));
                    const items = document.querySelectorAll('.note-item');
                    items.forEach(el => {
                        if (el.getAttribute('onclick')?.includes(id)) el.classList.add('active');
                    });

                    // Update save status
                    const statusSpan = document.getElementById('saveStatus');
                    if (statusSpan) {
                        statusSpan.innerText = 'Tersimpan ke database';
                        statusSpan.style.color = 'var(--success-color)';
                    }

                    // Update browser URL without reload
                    history.replaceState(null, '', './');
                } else {
                    showToast(data.message || 'Gagal memuat catatan', 'error');
                }
            })
            .catch(() => showToast('Gagal memuat catatan', 'error'));
    }

    function saveActiveNote(options = {}) {
        if (!activeNoteId) return;
        
        const statusSpan = document.getElementById('saveStatus');
        if (statusSpan) {
            statusSpan.innerText = 'Menyimpan...';
            statusSpan.style.color = 'var(--text-muted)';
        }
        
        const title = document.getElementById('noteEditorTitle').value;
        const content = tinymce.get('noteEditorText') ? tinymce.get('noteEditorText').getContent() : '';
        const formData = buildNoteUpdateFormData();
        
        fetch('api_notes.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                hasUnsavedChanges = false;
                if (statusSpan) {
                    statusSpan.innerText = 'Tersimpan ke database';
                    statusSpan.style.color = 'var(--success-color)';
                }
                const activeItem = document.querySelector('.note-item.active .note-item-title');
                if (activeItem) activeItem.innerText = title || 'Catatan Baru';
                
                const activePreview = document.querySelector('.note-item.active .note-item-preview');
                const cleanPreview = content.replace(/<[^>]*>/g, '');
                if (activePreview) activePreview.innerText = cleanPreview.substring(0, 50) || 'Belum ada konten...';
                
                showToast(options.successMessage || 'Catatan berhasil disimpan');
            } else {
                if (statusSpan) {
                    statusSpan.innerText = 'Gagal menyimpan';
                    statusSpan.style.color = 'var(--danger-color)';
                }
                showToast(data.message || 'Gagal menyimpan catatan', 'error');
                const chkGlobal = document.getElementById('chkIsGlobal');
                if (options.revertGlobalOnFail && chkGlobal) {
                    chkGlobal.checked = !chkGlobal.checked;
                }
            }
        })
        .catch(() => {
            if (statusSpan) {
                statusSpan.innerText = 'Gagal menyimpan';
                statusSpan.style.color = 'var(--danger-color)';
            }
            showToast('Gagal menyimpan catatan', 'error');
            const chkGlobal = document.getElementById('chkIsGlobal');
            if (options.revertGlobalOnFail && chkGlobal) {
                chkGlobal.checked = !chkGlobal.checked;
            }
        });
    }

    function saveActiveNoteAndRedirect(redirectId) {
        const formData = buildNoteUpdateFormData();
        
        fetch('api_notes.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                hasUnsavedChanges = false;
                switchNoteViaAjax(redirectId);
            } else {
                showToast(data.message || 'Gagal menyimpan catatan', 'error');
            }
        })
        .catch(() => {
            showToast('Gagal menyimpan catatan', 'error');
        });
    }

    function deleteActiveNote() {
        if (!activeNoteId) return;
        
        Swal.fire({
            title: 'Hapus Catatan?',
            text: 'Catatan ini akan dihapus permanen!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Hapus',
            cancelButtonText: 'Batal',
            confirmButtonColor: '#f85149',
            background: '#161b22',
            color: '#c9d1d9'
        }).then(res => {
            if (res.isConfirmed) {
                const fd = new FormData();
                fd.append('action', 'delete');
                fd.append('id', activeNoteId);
                fetch('api_notes.php', { method: 'POST', body: fd })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            hasUnsavedChanges = false;
                            location.href = './';
                        } else {
                            showToast('Gagal menghapus catatan', 'error');
                        }
                    });
            }
        });
    }

    function exportNoteToDrive() {
        if (!activeNoteId) return;

        Swal.fire({
            title: 'Ekspor ke Drive?',
            text: 'Catatan ini akan diunggah ke Drive Bersama sebagai file HTML.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Ekspor',
            cancelButtonText: 'Batal',
            background: '#161b22',
            color: '#c9d1d9'
        }).then(result => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Mengekspor...',
                    allowOutsideClick: false,
                    background: '#161b22',
                    color: '#c9d1d9',
                    didOpen: () => { Swal.showLoading(); }
                });

                const formData = new FormData();
                formData.append('action', 'upload_note');
                formData.append('id_note', activeNoteId);

                fetch('../drive/api_drive.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    Swal.close();
                    if (data.success) {
                        showToast('Berhasil diekspor ke Drive Bersama');
                    } else {
                        showToast(data.message || 'Gagal mengekspor catatan', 'error');
                    }
                })
                .catch(() => {
                    Swal.close();
                    showToast('Gagal mengekspor catatan', 'error');
                });
            }
        });
    }

    window.addEventListener('beforeunload', (e) => {
        if (hasUnsavedChanges) {
            e.preventDefault();
            e.returnValue = 'Perubahan Anda belum disimpan. Yakin ingin keluar?';
        }
    });
</script>

<?php require_once __DIR__ . '/../template/footer.php'; ?>
