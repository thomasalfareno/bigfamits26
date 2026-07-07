<?php
// drive/index.php
require_once __DIR__ . '/../config/security.php';
initSecureSession();

$base_url = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$base_url = preg_replace('/(\/(auth|dashboard|notes|msg|drive|calendar|server|admin|installer))?$/i', '', $base_url);
if ($base_url === '/') $base_url = '';

// Handle folder change via GET parameter to keep URL clean
if (isset($_GET['folder'])) {
    $folder_val = $_GET['folder'];
    if ($folder_val === '0' || $folder_val === 'root' || empty($folder_val)) {
        unset($_SESSION['active_folder_id']);
    } else {
        $_SESSION['active_folder_id'] = (int)$folder_val;
    }
    header('Location: ' . $base_url . '/drive/');
    exit;
}

require_once __DIR__ . '/../template/header.php';

$parent_id = $_SESSION['active_folder_id'] ?? null;
$isSuperAdmin = ($_SESSION['role'] ?? '') === 'superadmin';

// Get breadcrumbs path
$breadcrumbs = [];
$curr_folder_id = $parent_id;
while ($curr_folder_id) {
    try {
        $stmt_f = $conn->prepare("SELECT id_folder, nama, parent_id FROM folders WHERE id_folder = ? LIMIT 1");
        $stmt_f->execute([$curr_folder_id]);
        $f = $stmt_f->fetch();
        if ($f) {
            array_unshift($breadcrumbs, $f);
            $curr_folder_id = $f['parent_id'];
        } else {
            $curr_folder_id = null;
        }
    } catch(Exception $e) {
        $curr_folder_id = null;
    }
}

// Parent folder for "back" navigation
$backFolderId = 0;
$showBackButton = false;
if ($parent_id) {
    $showBackButton = true;
    try {
        $stmt_back = $conn->prepare("SELECT parent_id FROM folders WHERE id_folder = ? LIMIT 1");
        $stmt_back->execute([$parent_id]);
        $currFolder = $stmt_back->fetch();
        $backFolderId = ($currFolder && $currFolder['parent_id']) ? (int)$currFolder['parent_id'] : 0;
    } catch (Exception $e) {
        $backFolderId = 0;
    }
}

// Fetch subfolders in current folder
$subfolders = [];
try {
    if ($parent_id) {
        $stmt_sf = $conn->prepare("SELECT f.*, u.nama as creator FROM folders f JOIN users u ON f.id_user = u.id_user WHERE f.parent_id = ? ORDER BY f.nama ASC");
        $stmt_sf->execute([$parent_id]);
    } else {
        $stmt_sf = $conn->query("SELECT f.*, u.nama as creator FROM folders f JOIN users u ON f.id_user = u.id_user WHERE f.parent_id IS NULL ORDER BY f.nama ASC");
    }
    $subfolders = $stmt_sf->fetchAll();
} catch (Exception $e) {}

// Fetch files in current folder
$files = [];
try {
    if ($parent_id) {
        $stmt_fl = $conn->prepare("SELECT f.*, u.nama as uploader FROM files f JOIN users u ON f.id_user = u.id_user WHERE f.id_folder = ? ORDER BY f.nama ASC");
        $stmt_fl->execute([$parent_id]);
    } else {
        $stmt_fl = $conn->query("SELECT f.*, u.nama as uploader FROM files f JOIN users u ON f.id_user = u.id_user WHERE f.id_folder IS NULL ORDER BY f.nama ASC");
    }
    $files = $stmt_fl->fetchAll();
} catch (Exception $e) {}

$allFolders = [];
try {
    $allFolders = $conn->query("SELECT id_folder, nama, parent_id FROM folders ORDER BY nama ASC")->fetchAll();
} catch (Exception $e) {}
?>

<style>
    #dragDropOverlay {
        transition: all 0.2s ease-in-out;
    }
    @keyframes dragBounce {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-12px); }
    }
    .drag-bounce-icon {
        animation: dragBounce 1.5s infinite ease-in-out;
    }
    .drop-zone {
        border: 2px dashed rgba(88,166,255,0.3);
        border-radius: 12px;
        padding: 2.5rem 1.5rem;
        text-align: center;
        cursor: pointer;
        transition: all 0.3s ease;
        background: rgba(88,166,255,0.03);
        position: relative;
    }
    .drop-zone:hover {
        border-color: #58a6ff;
        background: rgba(88,166,255,0.08);
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(88,166,255,0.12);
    }
    .drop-zone.drag-active {
        border-color: #58a6ff;
        background: rgba(88,166,255,0.12);
        box-shadow: 0 0 30px rgba(88,166,255,0.15);
    }
    .drop-zone-icon {
        font-size: 2.5rem;
        color: #58a6ff;
        margin-bottom: 0.75rem;
        opacity: 0.7;
        transition: all 0.3s ease;
    }
    .drop-zone:hover .drop-zone-icon {
        opacity: 1;
        transform: scale(1.1);
    }
    .drop-zone-mini {
        padding: 1.25rem 1rem;
        margin-top: 1rem;
    }
    .drop-zone-mini .drop-zone-icon {
        font-size: 1.5rem;
        margin-bottom: 0.4rem;
    }
    .file-card.folder-card {
        background: linear-gradient(135deg, rgba(240, 171, 0, 0.06), rgba(22, 27, 34, 0.6));
        border-color: rgba(240, 171, 0, 0.25);
        box-shadow: 0 4px 15px rgba(240, 171, 0, 0.03);
    }
    .file-card.folder-card:hover {
        border-color: #f0ab00 !important;
        box-shadow: 0 6px 20px rgba(240, 171, 0, 0.15) !important;
        transform: translateY(-3px);
    }
    
    #driveSearchSuggestions {
        position: absolute;
        left: 0;
        right: 0;
        top: 100%;
        background: #161b22;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        z-index: 1000;
        max-height: 320px;
        overflow-y: auto;
        display: none;
        margin-top: 6px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.5);
        padding: 0.5rem 0;
    }
    .search-suggestion-item {
        display: flex;
        align-items: center;
        padding: 0.7rem 1rem;
        color: #c9d1d9;
        cursor: pointer;
        transition: background 0.2s ease;
        gap: 12px;
        text-decoration: none !important;
    }
    .search-suggestion-item:hover {
        background: rgba(88, 166, 255, 0.1);
    }
    .search-suggestion-icon {
        font-size: 1.1rem;
        width: 20px;
        text-align: center;
    }
    .search-suggestion-icon.folder {
        color: #f0ab00;
    }
    .search-suggestion-icon.image { color: #2ea043; }
    .search-suggestion-icon.pdf { color: #f85149; }
    .search-suggestion-icon.word { color: #2b579a; }
    .search-suggestion-icon.video { color: #bc8ff3; }
    .search-suggestion-icon.audio { color: #ff7b72; }
    .search-suggestion-icon.zip { color: #bc8ff3; }
    .search-suggestion-icon.file {
        color: #58a6ff;
    }
    .search-suggestion-info {
        display: flex;
        flex-direction: column;
        flex: 1;
        min-width: 0;
    }
    .search-suggestion-name {
        font-size: 0.88rem;
        font-weight: 500;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .search-suggestion-location {
        font-size: 0.72rem;
        color: var(--text-muted);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        margin-top: 2px;
    }
    .move-folder-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        background: rgba(255,255,255,0.02);
        cursor: pointer;
        transition: all 0.2s ease;
        margin-bottom: 8px;
    }
    .move-folder-item:hover,
    .move-folder-item.selected {
        border-color: #58a6ff;
        background: rgba(88,166,255,0.1);
    }
    .move-folder-item.disabled {
        opacity: 0.45;
        cursor: not-allowed;
        pointer-events: none;
    }
    .move-folder-list {
        max-height: 320px;
        overflow-y: auto;
        padding-right: 4px;
    }
    .move-breadcrumb {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 4px;
        flex: 1;
        min-width: 0;
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        padding: 0.55rem 0.85rem;
        border-radius: 8px;
        font-size: 0.82rem;
    }
    .move-breadcrumb a {
        color: var(--accent-color);
        cursor: pointer;
        text-decoration: none;
    }
    .move-breadcrumb a:hover { text-decoration: underline; }
    .move-current-label {
        font-size: 0.78rem;
        color: var(--text-muted);
        margin-bottom: 10px;
    }
    .preview-html-frame {
        width: 100%;
        height: 380px;
        border: none;
        border-radius: 6px;
        background: #fff;
    }
    .drive-nav-bar {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 1.25rem;
        flex-wrap: wrap;
    }
    .drive-back-btn {
        flex-shrink: 0;
        padding: 0.45rem 0.85rem !important;
        font-size: 0.82rem !important;
        white-space: nowrap;
    }
    .drive-breadcrumbs {
        flex: 1;
        min-width: 0;
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        padding: 0.6rem 1rem;
        border-radius: 8px;
        font-size: 0.85rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .move-nav-bar {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 10px;
        flex-wrap: wrap;
    }
    .move-back-btn {
        flex-shrink: 0;
        padding: 0.4rem 0.75rem !important;
        font-size: 0.78rem !important;
        white-space: nowrap;
    }
</style>

<!-- Drag and Drop Overlay -->
<div id="dragDropOverlay" style="display:none; position:fixed; inset:0; background:rgba(13,17,23,0.85); z-index:9999; border:3px dashed #58a6ff; justify-content:center; align-items:center; flex-direction:column; color:#fff; backdrop-filter:blur(4px)">
    <i class="fas fa-cloud-arrow-up drag-bounce-icon" style="font-size:4rem; color:#58a6ff; margin-bottom:1rem"></i>
    <h3 style="font-weight:700">Lepaskan file di sini untuk mengupload</h3>
    <p style="color:var(--text-muted); font-size:0.9rem; margin-top:0.5rem">File akan diupload ke folder saat ini.</p>
</div>

<div class="grid grid-cols-12">
    <!-- Header toolbar -->
    <div class="col-12">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:1.5rem">
            <h2 style="color:#fff; display:flex; align-items:center; gap:8px">
                <i class="fas fa-folder-open text-gold"></i> 
                Drive Bersama
            </h2>
            <div style="display:flex; gap:10px">
                <button class="btn btn-secondary" onclick="openNewFolderModal()"><i class="fas fa-folder-plus"></i> Folder Baru</button>
                <button class="btn btn-primary" onclick="triggerFileInput()"><i class="fas fa-upload"></i> Upload File</button>
            </div>
        </div>
        
        <!-- Breadcrumbs + Back Navigation -->
        <div class="drive-nav-bar">
            <?php if ($showBackButton): ?>
                <a href="?folder=<?= $backFolderId ?>" class="btn btn-secondary drive-back-btn">
                    <i class="fas fa-arrow-left"></i> Folder Sebelumnya
                </a>
            <?php endif; ?>
            <div class="drive-breadcrumbs">
                <a href="?folder=0" style="color:var(--accent-color); text-decoration:none"><i class="fas fa-home"></i> Root</a>
                <?php foreach ($breadcrumbs as $b): ?>
                    <span style="color:var(--text-muted)"> / </span>
                    <a href="?folder=<?= $b['id_folder'] ?>" style="color:var(--accent-color); text-decoration:none"><?= htmlspecialchars($b['nama']) ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Search Bar -->
        <div style="position:relative; margin-bottom:1.5rem">
            <input type="text" id="driveSearchInput" class="form-control" placeholder="Cari file atau folder..." style="padding-left:2.5rem; background:rgba(22, 27, 34, 0.4); border-color:var(--border-color)">
            <i class="fas fa-search" style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--text-muted); pointer-events:none"></i>
            <div id="driveSearchSuggestions"></div>
        </div>
    </div>

    <!-- Drive Items Listing Grid -->
    <div class="col-12">
        <div class="card">
            <?php if (count($subfolders) === 0 && count($files) === 0): ?>
                <div class="drop-zone" id="dropZoneMain" onclick="triggerFileInput()">
                    <div class="drop-zone-icon"><i class="fas fa-cloud-arrow-up"></i></div>
                    <h4 style="color:var(--text-color); font-weight:600; margin-bottom:0.4rem">Seret & lepas file di sini</h4>
                    <p style="color:var(--text-muted); font-size:0.82rem; margin-bottom:1rem">atau klik untuk memilih file dari perangkat kamu</p>
                    <div style="display:flex; gap:8px; justify-content:center; flex-wrap:wrap">
                        <span style="background:rgba(88,166,255,0.1); color:#58a6ff; padding:4px 10px; border-radius:20px; font-size:0.72rem"><i class="fas fa-images"></i> Gambar</span>
                        <span style="background:rgba(46,160,67,0.1); color:#2ea043; padding:4px 10px; border-radius:20px; font-size:0.72rem"><i class="fas fa-file-pdf"></i> PDF</span>
                        <span style="background:rgba(43,87,154,0.12); color:#2b579a; padding:4px 10px; border-radius:20px; font-size:0.72rem"><i class="fas fa-file-word"></i> Word</span>
                        <span style="background:rgba(240,171,0,0.1); color:#f0ab00; padding:4px 10px; border-radius:20px; font-size:0.72rem"><i class="fas fa-file-video"></i> Video</span>
                        <span style="background:rgba(188,143,243,0.1); color:#bc8ff3; padding:4px 10px; border-radius:20px; font-size:0.72rem"><i class="fas fa-file-zipper"></i> Arsip</span>
                        <span style="background:rgba(255,123,114,0.1); color:#ff7b72; padding:4px 10px; border-radius:20px; font-size:0.72rem"><i class="fas fa-file-lines"></i> Catatan HTML</span>
                        <span style="background:rgba(201,209,217,0.1); color:#c9d1d9; padding:4px 10px; border-radius:20px; font-size:0.72rem"><i class="fas fa-file"></i> Semua format</span>
                    </div>
                </div>
            <?php else: ?>
                <div class="file-list">
                    <!-- Folders first -->
                    <?php foreach ($subfolders as $folder): ?>
                        <div class="file-card folder-card" onclick="openFolderDetailModal(<?= htmlspecialchars(json_encode($folder)) ?>)">
                            <div class="file-card-icon folder"><i class="fas fa-folder"></i></div>
                            <div class="file-card-name" title="<?= htmlspecialchars($folder['nama']) ?>"><?= htmlspecialchars($folder['nama']) ?></div>
                            <div class="file-card-meta">Folder</div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Files next -->
                    <?php foreach ($files as $file): 
                        $mediaType = getDriveMediaType($file['nama']);
                        $icon = 'fa-file';
                        $iconClass = $mediaType === 'file' ? '' : $mediaType;
                        
                        if ($mediaType === 'image') { $icon = 'fa-file-image'; }
                        elseif ($mediaType === 'pdf') { $icon = 'fa-file-pdf'; }
                        elseif ($mediaType === 'video') { $icon = 'fa-file-video'; }
                        elseif ($mediaType === 'audio') { $icon = 'fa-file-audio'; }
                        elseif ($mediaType === 'note_html') { $icon = 'fa-file-lines'; $iconClass = 'document'; }
                        elseif ($mediaType === 'html') { $icon = 'fa-file-code'; $iconClass = 'document'; }
                        elseif ($mediaType === 'word') { $icon = 'fa-file-word'; $iconClass = 'word'; }
                        elseif ($mediaType === 'restricted') { $icon = 'fa-file-code'; $iconClass = 'restricted'; }
                        elseif ($mediaType === 'zip') { $icon = 'fa-file-zipper'; }
                        elseif ($mediaType === 'document') { $icon = 'fa-file-lines'; $iconClass = 'document'; }
                        
                        $fileData = [
                            'id_file' => $file['id_file'],
                            'nama' => $file['nama'],
                            'ukuran' => formatBytes($file['ukuran']),
                            'file_path' => $file['file_path'],
                            'id_folder' => $file['id_folder'],
                            'uploader' => $file['uploader'],
                            'id_user' => $file['id_user'],
                            'created_at' => $file['created_at'],
                            'media_type' => $mediaType,
                            'preview_url' => in_array($mediaType, ['html', 'note_html'], true)
                                ? getDrivePreviewUrl($file['id_file'])
                                : ($mediaType === 'word'
                                    ? getDriveWordPreviewUrl($file['id_file'])
                                    : getDriveFileUrl($file['id_file'], true)),
                            'download_url' => getDriveFileUrl($file['id_file'], false),
                            'can_import' => canImportDriveFileToNotes($file['nama']),
                        ];
                    ?>
                        <div class="file-card" onclick="openFilePreviewModal(<?= htmlspecialchars(json_encode($fileData)) ?>)">
                            <div class="file-card-icon <?= $iconClass ?>"><i class="fas <?= $icon ?>"></i></div>
                            <div class="file-card-name" title="<?= htmlspecialchars($file['nama']) ?>"><?= htmlspecialchars($file['nama']) ?></div>
                            <div class="file-card-meta"><?= formatBytes($file['ukuran']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <!-- Mini drop zone below file list -->
                <div class="drop-zone drop-zone-mini" id="dropZoneMini" onclick="triggerFileInput()">
                    <div class="drop-zone-icon"><i class="fas fa-plus"></i></div>
                    <p style="color:var(--text-muted); font-size:0.8rem; margin:0">Seret file ke sini atau <span style="color:#58a6ff; font-weight:600">klik untuk upload</span></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal: Folder Baru -->
<div class="modal" id="newFolderModal">
    <div class="modal-dialog" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Folder Baru</h3>
            <button class="modal-close" onclick="closeFolderModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label style="color:var(--text-muted); display:block; font-size:0.85rem; margin-bottom:0.4rem">Nama Folder</label>
                <input type="text" class="form-control" id="newFolderNameInput" placeholder="Masukkan nama folder baru...">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeFolderModal()">Batal</button>
            <button class="btn btn-primary" onclick="createFolderSubmit()">Buat Folder</button>
        </div>
    </div>
</div>

<!-- Modal: Folder Detail / Delete Option -->
<div class="modal" id="folderDetailModal">
    <div class="modal-dialog" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Detail Folder</h3>
            <button class="modal-close" onclick="closeFolderDetailModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" style="color:var(--text-color); font-size:0.9rem">
            <p style="margin-bottom:8px"><strong>Nama Folder:</strong> <span id="lblFolderName"></span></p>
            <p style="margin-bottom:8px"><strong>Dibuat oleh:</strong> <span id="lblFolderCreator"></span></p>
            <p style="margin-bottom:8px"><strong>Waktu:</strong> <span id="lblFolderDate"></span></p>
        </div>
        <div class="modal-footer modal-footer--wrap" id="folderDetailFooter">
            <!-- Populated dynamically -->
        </div>
    </div>
</div>

<!-- Modal: Upload Preview (Preview before uploading — supports multiple files) -->
<div class="modal" id="uploadPreviewModal">
    <div class="modal-dialog" style="max-width: 480px; max-height: 90vh; display: flex; flex-direction: column;">
        <div class="modal-header" style="flex-shrink:0">
            <h3><i class="fas fa-cloud-arrow-up" style="margin-right:6px"></i> Konfirmasi Upload <span id="lblUploadCount" class="badge badge-info" style="font-size:0.75rem; margin-left:6px"></span></h3>
            <button class="modal-close" onclick="closeUploadPreviewModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" style="padding:1.25rem; overflow-y: auto; flex: 1; min-height: 0;">
            <div id="uploadFileListContainer" style="display:flex; flex-direction:column; gap:8px">
                <!-- File list populated dynamically -->
            </div>
        </div>
        <div class="modal-footer" style="flex-shrink:0">
            <button class="btn btn-secondary" onclick="closeUploadPreviewModal()">Batal</button>
            <button class="btn btn-primary" id="btnUploadAll" onclick="uploadAllFiles()"><i class="fas fa-upload"></i> Upload Semua</button>
        </div>
    </div>
</div>

<!-- Modal: File Detail & Live Preview -->
<div class="modal" id="filePreviewModal">
    <div class="modal-dialog" style="max-width: 700px; max-height: 90vh; display: flex; flex-direction: column;">
        <div class="modal-header" style="flex-shrink:0">
            <h3 id="previewFileName">Nama File</h3>
            <button class="modal-close" onclick="closeFilePreviewModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" style="padding:1.25rem; overflow-y: auto; flex: 1; min-height: 0;">
            <div id="previewMediaBox" style="display:flex; justify-content:center; align-items:center; background:#0d1117; min-height:120px; max-height:55vh; border-radius:8px; margin-bottom:1.25rem; overflow:auto">
                <!-- Live preview frame -->
            </div>
            
            <div style="font-size:0.85rem; color:var(--text-color); background:rgba(255,255,255,0.02); padding:12px; border-radius:8px; border:1px solid var(--border-color); flex-shrink:0">
                <p style="margin-bottom:6px"><strong>Pengunggah:</strong> <span id="previewFileUploader" class="text-accent" style="font-weight:600"></span></p>
                <p style="margin-bottom:6px"><strong>Ukuran:</strong> <span id="previewFileSize"></span></p>
                <p><strong>Tanggal Upload:</strong> <span id="previewFileDate"></span></p>
            </div>
        </div>
        <div class="modal-footer modal-footer--wrap" id="previewFileFooter" style="flex-shrink:0">
            <!-- Dynamic action buttons -->
        </div>
    </div>
</div>

<!-- Modal: Pindahkan File / Folder -->
<div class="modal" id="moveItemModal">
    <div class="modal-dialog" style="max-width: 460px;">
        <div class="modal-header">
            <h3><i class="fas fa-arrows-up-down-left-right" style="margin-right:6px"></i> Pindahkan</h3>
            <button class="modal-close" onclick="closeMoveModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <p id="moveItemLabel" style="font-size:0.85rem; color:var(--text-muted); margin-bottom:12px"></p>
            <div class="move-nav-bar">
                <button type="button" id="moveBackBtn" class="btn btn-secondary move-back-btn" style="display:none" onclick="navigateMoveBack()">
                    <i class="fas fa-arrow-left"></i> Folder Sebelumnya
                </button>
                <div class="move-breadcrumb" id="moveBreadcrumb"></div>
            </div>
            <div class="move-current-label">Pilih folder tujuan:</div>
            <div class="move-folder-list" id="moveFolderList"></div>
        </div>
        <div class="modal-footer modal-footer--wrap">
            <button class="btn btn-secondary" onclick="closeMoveModal()">Batal</button>
            <button class="btn btn-primary" id="btnConfirmMove" onclick="confirmMove()"><i class="fas fa-check"></i> Pindahkan ke sini</button>
        </div>
    </div>
</div>

<!-- Invisible input for uploading files -->
<input type="file" id="uploadFileInputField" style="display: none;" multiple onchange="handleFileSelection(event)">

<script>
    const currentFolderId = <?= $parent_id ? (int)$parent_id : 'null' ?>;
    const currentUserId = <?= (int)$_SESSION['id_user'] ?>;
    const isSuperAdmin = <?= $isSuperAdmin ? 'true' : 'false' ?>;
    const allFolders = <?= json_encode($allFolders, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    let selectedFiles = [];
    let moveState = { type: null, id: null, browseParentId: null, excludeIds: [] };

    function openNewFolderModal() { document.getElementById('newFolderModal').classList.add('open'); document.getElementById('newFolderNameInput').focus(); }
    function closeFolderModal() { document.getElementById('newFolderModal').classList.remove('open'); document.getElementById('newFolderNameInput').value = ''; }

    function buildFooterButtons(buttons) {
        return buttons.map(btn => {
            if (btn.href) {
                return `<a href="${btn.href}" ${btn.download ? 'download' : ''} class="btn ${btn.cls || 'btn-secondary'}${btn.fullSm ? ' btn-full-sm' : ''}" ${btn.onclick ? `onclick="${btn.onclick}"` : ''}><i class="fas ${btn.icon}"></i> ${btn.label}</a>`;
            }
            return `<button type="button" class="btn ${btn.cls || 'btn-secondary'}${btn.fullSm ? ' btn-full-sm' : ''}" onclick="${btn.onclick}"><i class="fas ${btn.icon}"></i> ${btn.label}</button>`;
        }).join('');
    }

    function canMoveDriveItem(ownerId) {
        return isSuperAdmin || parseInt(ownerId, 10) === currentUserId;
    }

    function openFolderDetailModal(folder) {
        document.getElementById('lblFolderName').innerText = folder.nama;
        document.getElementById('lblFolderCreator').innerText = folder.creator;
        document.getElementById('lblFolderDate').innerText = folder.created_at;
        
        const footer = document.getElementById('folderDetailFooter');
        const buttons = [
            { label: 'Buka Folder', icon: 'fa-folder-open', cls: 'btn-primary btn-full-sm', onclick: `location.href='?folder=${folder.id_folder}'` },
        ];

        if (canMoveDriveItem(folder.id_user)) {
            buttons.push({ label: 'Pindahkan', icon: 'fa-arrows-up-down-left-right', cls: 'btn-secondary', onclick: `openMoveModal('folder', ${folder.id_folder}, '${folder.nama.replace(/'/g, "\\'")}', ${folder.parent_id ? folder.parent_id : 'null'})` });
        }

        if (folder.id_user == currentUserId || isSuperAdmin) {
            buttons.push(
                { label: 'Ganti Nama', icon: 'fa-edit', cls: 'btn-secondary', onclick: `renameFolder(${folder.id_folder}, '${folder.nama.replace(/'/g, "\\'")}')` },
                { label: 'Hapus', icon: 'fa-trash-alt', cls: 'btn-danger', onclick: `deleteFolder(${folder.id_folder})` }
            );
        }

        footer.innerHTML = buildFooterButtons(buttons);
        document.getElementById('folderDetailModal').classList.add('open');
    }
    function closeFolderDetailModal() { document.getElementById('folderDetailModal').classList.remove('open'); }

    function triggerFileInput() {
        document.getElementById('uploadFileInputField').click();
    }

    function handleFileSelection(e) {
        const files = Array.from(e.target.files);
        if (files.length === 0) return;
        showUploadPreview(files);
    }

    function showUploadPreview(files) {
        selectedFiles = files;
        const container = document.getElementById('uploadFileListContainer');
        container.innerHTML = '';
        document.getElementById('lblUploadCount').innerText = files.length + ' file';

        files.forEach((file, i) => {
            const isImage = file.type && file.type.startsWith('image/');
            const isVideo = file.type && file.type.startsWith('video/');
            const isAudio = file.type && file.type.startsWith('audio/');
            const ext = file.name.split('.').pop().toLowerCase();
            let iconClass = 'fa-file';
            if (isImage) iconClass = 'fa-file-image';
            else if (ext === 'pdf') iconClass = 'fa-file-pdf';
            else if (['doc','docx','docm'].includes(ext)) iconClass = 'fa-file-word';
            else if (['mp4','webm','mov'].includes(ext)) iconClass = 'fa-file-video';
            else if (['mp3','wav','ogg','m4a'].includes(ext)) iconClass = 'fa-file-audio';
            else if (['zip','rar','7z'].includes(ext)) iconClass = 'fa-file-zipper';

            const row = document.createElement('div');
            row.id = 'uploadRow_' + i;
            row.style.cssText = 'background:rgba(0,0,0,0.15); border-radius:8px; border:1px solid var(--border-color); overflow:hidden; transition: border-color 0.3s';

            // Header row (always visible)
            const header = document.createElement('div');
            header.style.cssText = 'display:flex; align-items:center; gap:10px; padding:10px 12px; cursor:pointer';
            header.innerHTML = `
                <i class="fas ${iconClass}" style="font-size:1.4rem; color:var(--accent-color); min-width:24px; text-align:center"></i>
                <div style="flex:1; min-width:0">
                    <div style="font-size:0.85rem; font-weight:600; color:var(--text-color); white-space:nowrap; overflow:hidden; text-overflow:ellipsis" title="${file.name}">${file.name}</div>
                    <div style="font-size:0.75rem; color:var(--text-muted)">${formatBytesJS(file.size)} · ${file.type || 'Unknown'}</div>
                </div>
                <span id="uploadStatus_${i}" style="font-size:0.85rem; color:var(--text-muted)"></span>
                ${(isImage || isVideo || isAudio) ? `<button class="btn" style="padding:4px 8px; font-size:0.7rem; background:rgba(88,166,255,0.12); color:#58a6ff; border:none; border-radius:4px; cursor:pointer" onclick="event.stopPropagation(); togglePreview(${i})" title="Preview"><i class="fas fa-eye" id="previewIcon_${i}"></i></button>` : ''}
                <button class="btn" style="padding:4px 8px; font-size:0.75rem; background:rgba(248,81,73,0.15); color:#f85149; border:none; border-radius:4px; cursor:pointer" onclick="event.stopPropagation(); removeFileFromUploadList(${i})" title="Hapus"><i class="fas fa-times"></i></button>
            `;
            row.appendChild(header);

            // Preview panel (hidden by default)
            if (isImage || isVideo || isAudio) {
                const previewPanel = document.createElement('div');
                previewPanel.id = 'previewPanel_' + i;
                previewPanel.style.cssText = 'display:none; padding:0 12px 12px; text-align:center; background:rgba(0,0,0,0.2); border-top:1px solid var(--border-color)';
                row.appendChild(previewPanel);

                // Generate preview content
                if (isImage) {
                    const reader = new FileReader();
                    reader.onload = function(evt) {
                        previewPanel.innerHTML = `<img src="${evt.target.result}" style="max-width:100%; max-height:180px; border-radius:6px; object-fit:contain; margin-top:10px">`;
                    };
                    reader.readAsDataURL(file);
                } else if (isVideo) {
                    const url = URL.createObjectURL(file);
                    previewPanel.innerHTML = `<video src="${url}" controls style="max-width:100%; max-height:180px; border-radius:6px; margin-top:10px"></video>`;
                } else if (isAudio) {
                    const url = URL.createObjectURL(file);
                    previewPanel.innerHTML = `<audio src="${url}" controls style="width:100%; margin-top:10px"></audio>`;
                }
            }

            container.appendChild(row);
        });

        document.getElementById('uploadPreviewModal').classList.add('open');
    }

    function togglePreview(index) {
        const panel = document.getElementById('previewPanel_' + index);
        const icon = document.getElementById('previewIcon_' + index);
        if (!panel) return;
        const isHidden = panel.style.display === 'none';
        panel.style.display = isHidden ? 'block' : 'none';
        if (icon) {
            icon.className = isHidden ? 'fas fa-eye-slash' : 'fas fa-eye';
        }
    }

    function removeFileFromUploadList(index) {
        selectedFiles = selectedFiles.filter((_, i) => i !== index);
        if (selectedFiles.length === 0) {
            closeUploadPreviewModal();
            return;
        }
        showUploadPreview(selectedFiles);
    }

    function closeUploadPreviewModal() {
        document.getElementById('uploadPreviewModal').classList.remove('open');
        document.getElementById('uploadFileInputField').value = '';
        selectedFiles = [];
    }

    async function uploadAllFiles() {
        if (selectedFiles.length === 0) return;

        const filesToUpload = [...selectedFiles];
        const total = filesToUpload.length;
        const btn = document.getElementById('btnUploadAll');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mengupload...';

        let success = 0;
        let failed = 0;

        for (let i = 0; i < total; i++) {
            const statusEl = document.getElementById('uploadStatus_' + i);
            const rowEl = document.getElementById('uploadRow_' + i);
            if (statusEl) statusEl.innerHTML = '<i class="fas fa-spinner fa-spin" style="color:#58a6ff"></i>';

            const formData = new FormData();
            formData.append('drive_file', filesToUpload[i]);
            formData.append('action', 'upload_file');
            if (currentFolderId) formData.append('id_folder', currentFolderId);

            try {
                const res = await fetch('api_drive.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    success++;
                    if (statusEl) statusEl.innerHTML = '<i class="fas fa-check-circle" style="color:#2ea043"></i>';
                    if (rowEl) rowEl.style.borderColor = '#2ea043';
                } else {
                    failed++;
                    if (statusEl) statusEl.innerHTML = '<i class="fas fa-times-circle" style="color:#f85149"></i>';
                    if (rowEl) rowEl.style.borderColor = '#f85149';
                }
            } catch(e) {
                failed++;
                if (statusEl) statusEl.innerHTML = '<i class="fas fa-times-circle" style="color:#f85149"></i>';
                if (rowEl) rowEl.style.borderColor = '#f85149';
            }
        }

        btn.innerHTML = '<i class="fas fa-check"></i> Selesai';
        showToast(`${success} file berhasil diupload` + (failed > 0 ? `, ${failed} gagal` : ''));
        setTimeout(() => location.reload(), 1500);
    }

    function openFilePreviewModal(file) {
        document.getElementById('previewFileName').innerText = file.nama;
        document.getElementById('previewFileUploader').innerText = file.uploader;
        document.getElementById('previewFileSize').innerText = file.ukuran;
        document.getElementById('previewFileDate').innerText = file.created_at;
        
        const mediaBox = document.getElementById('previewMediaBox');
        mediaBox.innerHTML = '';
        
        const previewUrl = file.preview_url || `file?id=${file.id_file}&inline=1`;
        const downloadUrl = file.download_url || `file?id=${file.id_file}`;
        
        // Render professional live preview depending on file type
        if (file.media_type === 'restricted') {
            mediaBox.innerHTML = `
                <div style="text-align:center; padding:2rem">
                    <i class="fas fa-shield-halved" style="font-size:3.5rem; color:#f0ab00; margin-bottom:0.75rem"></i>
                    <p style="color:#fff; font-size:0.95rem; font-weight:600; margin-bottom:0.35rem">Pratinjau dinonaktifkan</p>
                    <p style="color:var(--text-muted); font-size:0.85rem">File JS/SVG tidak ditampilkan di browser untuk keamanan sistem.<br>Silakan unduh file untuk membukanya secara lokal.</p>
                </div>
            `;
        } else if (file.media_type === 'html' || file.media_type === 'note_html') {
            mediaBox.innerHTML = `<iframe src="${previewUrl}" class="preview-html-frame" sandbox="allow-same-origin" title="Pratinjau HTML aman"></iframe>`;
        } else if (file.media_type === 'word') {
            mediaBox.innerHTML = `<iframe src="${previewUrl}" class="preview-html-frame" sandbox="allow-same-origin" title="Pratinjau Word"></iframe>`;
        } else if (file.media_type === 'image') {
            mediaBox.innerHTML = `<img src="${previewUrl}" style="max-width:100%; max-height:350px; object-fit:contain">`;
        } else if (file.media_type === 'video') {
            mediaBox.innerHTML = `<video src="${previewUrl}" controls style="max-width:100%; max-height:350px"></video>`;
        } else if (file.media_type === 'audio') {
            mediaBox.innerHTML = `<audio src="${previewUrl}" controls style="width:80%; margin:2rem 0"></audio>`;
        } else if (file.media_type === 'pdf') {
            mediaBox.innerHTML = `<iframe src="${previewUrl}" style="width:100%; height:380px; border:none"></iframe>`;
        } else {
            mediaBox.innerHTML = `
                <div style="text-align:center; padding:2rem">
                    <i class="fas fa-file-arrow-down" style="font-size:3.5rem; color:var(--text-muted); margin-bottom:0.75rem"></i>
                    <p style="color:var(--text-muted); font-size:0.85rem">Pratinjau tidak tersedia untuk format ini.</p>
                </div>
            `;
        }
        
        const footer = document.getElementById('previewFileFooter');
        const safeName = file.nama.replace(/'/g, "\\'");
        const buttons = [];

        if (file.can_import) {
            buttons.push({ label: 'Salin Catatan', icon: 'fa-file-import', cls: 'btn-primary', onclick: `importNoteFromFile(${file.id_file})` });
        }
        buttons.push(
            { label: 'Download', icon: 'fa-download', cls: 'btn-primary', href: downloadUrl, download: true }
        );

        if (canMoveDriveItem(file.id_user)) {
            buttons.push({ label: 'Pindahkan', icon: 'fa-arrows-up-down-left-right', cls: 'btn-secondary', onclick: `openMoveModal('file', ${file.id_file}, '${safeName}', ${file.id_folder ? file.id_folder : 'null'})` });
        }

        if (file.id_user == currentUserId || isSuperAdmin) {
            buttons.push(
                { label: 'Ganti Nama', icon: 'fa-edit', cls: 'btn-secondary', onclick: `renameFile(${file.id_file}, '${safeName}')` },
                { label: 'Hapus', icon: 'fa-trash-alt', cls: 'btn-danger', onclick: `deleteFile(${file.id_file})` }
            );
        }

        buttons.push({ label: 'Tutup', icon: 'fa-times', cls: 'btn-secondary btn-full-sm', onclick: 'closeFilePreviewModal()' });
        footer.innerHTML = buildFooterButtons(buttons);
        
        document.getElementById('filePreviewModal').classList.add('open');
    }

    function closeFilePreviewModal() {
        // Stop audio/video playing
        const media = document.querySelector('#previewMediaBox audio, #previewMediaBox video');
        if (media) media.pause();
        const iframe = document.querySelector('#previewMediaBox iframe');
        if (iframe) iframe.src = 'about:blank';
        document.getElementById('filePreviewModal').classList.remove('open');
    }

    function getFolderChildren(parentId) {
        const pid = parentId === null || parentId === 'root' ? null : parseInt(parentId, 10);
        return allFolders.filter(f => {
            const fParent = f.parent_id === null ? null : parseInt(f.parent_id, 10);
            return fParent === pid;
        });
    }

    function getFolderById(id) {
        return allFolders.find(f => parseInt(f.id_folder, 10) === parseInt(id, 10));
    }

    function getFolderDescendantIds(folderId) {
        const ids = [parseInt(folderId, 10)];
        let queue = [parseInt(folderId, 10)];
        while (queue.length) {
            const current = queue.shift();
            allFolders.forEach(f => {
                const fParent = f.parent_id === null ? null : parseInt(f.parent_id, 10);
                const fId = parseInt(f.id_folder, 10);
                if (fParent === current && !ids.includes(fId)) {
                    ids.push(fId);
                    queue.push(fId);
                }
            });
        }
        return ids;
    }

    function renderMoveBreadcrumb() {
        const box = document.getElementById('moveBreadcrumb');
        const browseId = moveState.browseParentId;
        let html = `<a onclick="navigateMoveBrowse(null)"><i class="fas fa-home"></i> Root</a>`;

        if (browseId !== null) {
            const path = [];
            let curr = getFolderById(browseId);
            while (curr) {
                path.unshift(curr);
                curr = curr.parent_id ? getFolderById(curr.parent_id) : null;
            }
            path.forEach(f => {
                html += ` <span style="color:var(--text-muted)">/</span> `;
                html += `<a onclick="navigateMoveBrowse(${f.id_folder})">${escapeHtml(f.nama)}</a>`;
            });
        }

        box.innerHTML = html;
        updateMoveBackButton();
    }

    function updateMoveBackButton() {
        const backBtn = document.getElementById('moveBackBtn');
        if (!backBtn) return;

        const browseId = moveState.browseParentId;
        if (browseId === null) {
            backBtn.style.display = 'none';
            return;
        }

        const curr = getFolderById(browseId);
        backBtn.style.display = '';
    }

    function navigateMoveBack() {
        const browseId = moveState.browseParentId;
        if (browseId === null) return;

        const curr = getFolderById(browseId);
        if (!curr || !curr.parent_id) {
            navigateMoveBrowse(null);
        } else {
            navigateMoveBrowse(parseInt(curr.parent_id, 10));
        }
    }

    function renderMoveFolderList() {
        const list = document.getElementById('moveFolderList');
        const browseId = moveState.browseParentId;
        const children = getFolderChildren(browseId);
        let html = '';

        const isCurrentLocation = (moveState.type === 'file' && browseId === moveState.currentParentId) ||
            (moveState.type === 'folder' && browseId === moveState.currentParentId);
        const isInvalidTarget = moveState.type === 'folder' && browseId !== null && moveState.excludeIds.includes(browseId);
        const btnMove = document.getElementById('btnConfirmMove');
        if (btnMove) {
            btnMove.disabled = isCurrentLocation || isInvalidTarget;
            btnMove.innerHTML = isCurrentLocation
                ? '<i class="fas fa-check"></i> Sudah di sini'
                : isInvalidTarget
                    ? '<i class="fas fa-ban"></i> Lokasi tidak valid'
                    : '<i class="fas fa-check"></i> Pindahkan ke sini';
        }

        if (children.length === 0) {
            html = `<div style="text-align:center; padding:1.5rem; color:var(--text-muted); font-size:0.85rem">Tidak ada subfolder. Pindahkan ke lokasi ini.</div>`;
        } else {
            children.forEach(folder => {
                const fId = parseInt(folder.id_folder, 10);
                const disabled = moveState.excludeIds.includes(fId);
                html += `
                    <div class="move-folder-item ${disabled ? 'disabled' : ''}" ${disabled ? '' : `onclick="navigateMoveBrowse(${fId})"`}>
                        <i class="fas fa-folder" style="color:#f0ab00"></i>
                        <span style="flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis">${escapeHtml(folder.nama)}</span>
                        ${disabled ? '<span style="font-size:0.72rem; color:var(--text-muted)">(tidak valid)</span>' : '<i class="fas fa-chevron-right" style="color:var(--text-muted); font-size:0.75rem"></i>'}
                    </div>
                `;
            });
        }

        list.innerHTML = html;
    }

    function navigateMoveBrowse(folderId) {
        moveState.browseParentId = folderId === null ? null : parseInt(folderId, 10);
        renderMoveBreadcrumb();
        renderMoveFolderList();
    }

    function openMoveModal(type, id, name, currentParentId) {
        if (type === 'file') closeFilePreviewModal();
        if (type === 'folder') closeFolderDetailModal();

        const parentId = currentParentId === null || currentParentId === 'null' || currentParentId === undefined
            ? null
            : parseInt(currentParentId, 10);

        moveState = {
            type,
            id: parseInt(id, 10),
            browseParentId: null,
            currentParentId: parentId,
            excludeIds: type === 'folder' ? getFolderDescendantIds(id) : []
        };

        document.getElementById('moveItemLabel').innerText = `Memindahkan ${type === 'file' ? 'file' : 'folder'}: "${name}"`;
        renderMoveBreadcrumb();
        renderMoveFolderList();
        document.getElementById('moveItemModal').classList.add('open');
    }

    function closeMoveModal() {
        document.getElementById('moveItemModal').classList.remove('open');
        moveState = { type: null, id: null, browseParentId: null, excludeIds: [] };
    }

    function confirmMove() {
        const targetId = moveState.browseParentId;
        const fd = new FormData();
        fd.append('action', moveState.type === 'file' ? 'move_file' : 'move_folder');
        fd.append('id', moveState.id);
        if (moveState.type === 'file') {
            fd.append('id_folder', targetId === null ? 'root' : targetId);
        } else {
            fd.append('parent_id', targetId === null ? 'root' : targetId);
        }

        fetch('api_drive.php', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                closeMoveModal();
                if (data.success) {
                    showToast('Berhasil dipindahkan');
                    setTimeout(() => location.reload(), 800);
                } else {
                    showToast(data.message || 'Gagal memindahkan', 'error');
                }
            })
            .catch(() => showToast('Gagal memindahkan', 'error'));
    }

    async function importNoteFromFile(idFile) {
        const result = await Swal.fire({
            title: 'Salin ke Catatan Saya?',
            text: 'Konten file akan dikonversi ke catatan pribadi Anda. Setelah disalin, Anda bisa mengeditnya seperti catatan biasa di editor.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Salin Catatan',
            cancelButtonText: 'Batal',
            confirmButtonColor: '#3085d6',
            background: '#161b22',
            color: '#c9d1d9'
        });

        if (!result.isConfirmed) return;

        const fd = new FormData();
        fd.append('action', 'import_note_from_file');
        fd.append('id_file', idFile);

        try {
            const res = await fetch('api_drive.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                closeFilePreviewModal();
                showToast(data.message || 'Catatan berhasil disalin');
                Swal.fire({
                    title: 'Berhasil!',
                    text: 'Catatan siap diedit. Buka halaman Catatan untuk melihat dan mengubah isinya.',
                    icon: 'success',
                    confirmButtonText: 'Buka & Edit',
                    showCancelButton: true,
                    cancelButtonText: 'Tutup',
                    confirmButtonColor: '#3085d6',
                    background: '#161b22',
                    color: '#c9d1d9'
                }).then(r => {
                    if (r.isConfirmed && data.id_note) {
                        window.location.href = '../notes/?id=' + data.id_note;
                    }
                });
            } else {
                showToast(data.message || 'Gagal mengimpor catatan', 'error');
            }
        } catch (e) {
            showToast('Gagal mengimpor catatan', 'error');
        }
    }

    function createFolderSubmit() {
        const name = document.getElementById('newFolderNameInput').value.trim();
        if (!name) return;

        const formData = new FormData();
        formData.append('action', 'create_folder');
        formData.append('nama', name);
        if (currentFolderId) formData.append('parent_id', currentFolderId);

        fetch('api_drive.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                closeFolderModal();
                if (data.success) {
                    showToast('Folder berhasil dibuat');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.message || 'Gagal membuat folder', 'error');
                }
            });
    }

    function deleteFolder(id) {
        closeFolderDetailModal();
        Swal.fire({
            title: 'Hapus Folder?',
            text: 'Semua file & subfolder didalamnya juga akan dihapus permanen!',
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
                fd.append('action', 'delete_folder');
                fd.append('id', id);
                fetch('api_drive.php', { method: 'POST', body: fd })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            showToast('Folder dihapus');
                            setTimeout(() => location.reload(), 1000);
                        } else {
                            showToast(data.message || 'Gagal menghapus folder', 'error');
                        }
                    });
            }
        });
    }

    function deleteFile(id) {
        closeFilePreviewModal();
        Swal.fire({
            title: 'Hapus File?',
            text: 'File akan dihapus secara permanen!',
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
                fd.append('action', 'delete_file');
                fd.append('id', id);
                fetch('api_drive.php', { method: 'POST', body: fd })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            showToast('File dihapus');
                            setTimeout(() => location.reload(), 1000);
                        } else {
                            showToast(data.message || 'Gagal menghapus file', 'error');
                        }
                    });
            }
        });
    }

    function formatBytesJS(bytes, precision = 2) {
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        bytes = Math.max(bytes, 0);
        let pow = Math.floor((bytes ? Math.log(bytes) : 0) / Math.log(1024));
        pow = Math.min(pow, units.length - 1);
        bytes /= Math.pow(1024, pow);
        return Math.round(bytes, precision) + ' ' + units[pow];
    }

    function renameFolder(id, currentName) {
        closeFolderDetailModal();
        Swal.fire({
            title: 'Ganti Nama Folder',
            input: 'text',
            inputValue: currentName,
            inputPlaceholder: 'Masukkan nama folder baru...',
            showCancelButton: true,
            confirmButtonText: 'Simpan',
            cancelButtonText: 'Batal',
            confirmButtonColor: '#3085d6',
            background: '#161b22',
            color: '#c9d1d9',
            inputValidator: (value) => {
                if (!value || !value.trim()) {
                    return 'Nama folder tidak boleh kosong!';
                }
            }
        }).then(res => {
            if (res.isConfirmed) {
                const name = res.value.trim();
                const fd = new FormData();
                fd.append('action', 'rename_folder');
                fd.append('id', id);
                fd.append('nama', name);
                
                fetch('api_drive.php', { method: 'POST', body: fd })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            showToast('Nama folder berhasil diubah');
                            setTimeout(() => location.reload(), 1000);
                        } else {
                            showToast(data.message || 'Gagal mengubah nama folder', 'error');
                        }
                    });
            }
        });
    }

    function renameFile(id, currentName) {
        closeFilePreviewModal();
        Swal.fire({
            title: 'Ganti Nama File',
            input: 'text',
            inputValue: currentName,
            inputPlaceholder: 'Masukkan nama file baru...',
            showCancelButton: true,
            confirmButtonText: 'Simpan',
            cancelButtonText: 'Batal',
            confirmButtonColor: '#3085d6',
            background: '#161b22',
            color: '#c9d1d9',
            inputValidator: (value) => {
                if (!value || !value.trim()) {
                    return 'Nama file tidak boleh kosong!';
                }
            }
        }).then(res => {
            if (res.isConfirmed) {
                const name = res.value.trim();
                const fd = new FormData();
                fd.append('action', 'rename_file');
                fd.append('id', id);
                fd.append('nama', name);
                
                fetch('api_drive.php', { method: 'POST', body: fd })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            showToast('Nama file berhasil diubah');
                            setTimeout(() => location.reload(), 1000);
                        } else {
                            showToast(data.message || 'Gagal mengubah nama file', 'error');
                        }
                    });
            }
        });
    }

    // Drag & Drop visual overlay listeners with counter to prevent flickering
    const dragOverlay = document.getElementById('dragDropOverlay');
    const dropZones = document.querySelectorAll('.drop-zone');
    let dragCounter = 0;

    window.addEventListener('dragenter', (e) => {
        e.preventDefault();
        dragCounter++;
        dragOverlay.style.display = 'flex';
        dropZones.forEach(z => z.classList.add('drag-active'));
    });

    window.addEventListener('dragover', (e) => {
        e.preventDefault();
    });

    window.addEventListener('dragleave', (e) => {
        e.preventDefault();
        dragCounter--;
        if (dragCounter === 0) {
            dragOverlay.style.display = 'none';
            dropZones.forEach(z => z.classList.remove('drag-active'));
        }
    });

    window.addEventListener('drop', (e) => {
        e.preventDefault();
        dragCounter = 0;
        dragOverlay.style.display = 'none';
        dropZones.forEach(z => z.classList.remove('drag-active'));
        
        const files = Array.from(e.dataTransfer.files);
        if (files.length > 0) {
            showUploadPreview(files);
        }
    });

    // Real-time Drive items search & Suggestions
    let searchDebounceTimer;
    const searchInput = document.getElementById('driveSearchInput');
    const suggestionsBox = document.getElementById('driveSearchSuggestions');

    function escapeHtml(text) {
        if (!text) return '';
        return text
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    searchInput?.addEventListener('input', function(e) {
        const val = e.target.value.trim();
        const filterVal = val.toLowerCase();
        const cards = document.querySelectorAll('.file-list .file-card');
        const listContainer = document.querySelector('.file-list');
        
        // 1. Client-side local filter of current directory items
        let matchCount = 0;
        cards.forEach(card => {
            const nameEl = card.querySelector('.file-card-name');
            const name = nameEl ? nameEl.innerText.toLowerCase() : '';
            if (name.includes(filterVal)) {
                card.style.display = '';
                matchCount++;
            } else {
                card.style.display = 'none';
            }
        });
        
        let noResultsMsg = document.getElementById('searchNoResultsMsg');
        if (matchCount === 0 && filterVal !== '' && listContainer) {
            if (!noResultsMsg) {
                noResultsMsg = document.createElement('div');
                noResultsMsg.id = 'searchNoResultsMsg';
                noResultsMsg.style.cssText = 'grid-column: 1 / -1; text-align:center; padding:3.5rem 1.5rem; color:var(--text-muted); background: rgba(0,0,0,0.1); border-radius: 10px; border: 1px dashed var(--border-color); width:100%';
                noResultsMsg.innerHTML = `
                    <i class="fas fa-folder-open" style="font-size:3rem; margin-bottom:1rem; color:var(--border-color); display:block"></i>
                    <p style="font-size:0.95rem; font-weight:500; margin:0">Tidak ada folder atau file yang cocok dengan "${escapeHtml(e.target.value)}"</p>
                `;
                listContainer.appendChild(noResultsMsg);
            }
        } else {
            if (noResultsMsg) noResultsMsg.remove();
        }

        // 2. Server-side global recursive search suggestions
        clearTimeout(searchDebounceTimer);
        if (val.length < 1) {
            if (suggestionsBox) {
                suggestionsBox.style.display = 'none';
                suggestionsBox.innerHTML = '';
            }
            return;
        }

        searchDebounceTimer = setTimeout(() => {
            const fd = new FormData();
            fd.append('action', 'search_items');
            fd.append('q', val);
            
            fetch('api_drive.php', {
                method: 'POST',
                body: fd
            })
            .then(res => res.json())
            .then(data => {
                if (!suggestionsBox) return;
                suggestionsBox.innerHTML = '';
                
                if (data.success && data.items && data.items.length > 0) {
                    data.items.forEach(item => {
                        const itemEl = document.createElement('div');
                        itemEl.className = 'search-suggestion-item';
                        
                        let icon = 'fa-file';
                        let iconClass = 'file';
                        if (item.item_type === 'folder') {
                            icon = 'fa-folder';
                            iconClass = 'folder';
                        } else {
                            if (item.media_type === 'image') { icon = 'fa-file-image'; iconClass = 'image'; }
                            else if (item.media_type === 'pdf') { icon = 'fa-file-pdf'; iconClass = 'pdf'; }
                            else if (item.media_type === 'video') { icon = 'fa-file-video'; iconClass = 'video'; }
                            else if (item.media_type === 'audio') { icon = 'fa-file-audio'; iconClass = 'audio'; }
                            else if (item.media_type === 'note_html') { icon = 'fa-file-lines'; iconClass = 'document'; }
                            else if (item.media_type === 'html') { icon = 'fa-file-code'; iconClass = 'document'; }
                            else if (item.media_type === 'word') { icon = 'fa-file-word'; iconClass = 'word'; }
                            else if (item.media_type === 'restricted') { icon = 'fa-file-code'; iconClass = 'restricted'; }
                            else if (item.tipe === 'zip' || item.tipe === 'rar') { icon = 'fa-file-zipper'; iconClass = 'zip'; }
                        }
                        
                        itemEl.innerHTML = `
                            <div class="search-suggestion-icon ${iconClass}"><i class="fas ${icon}"></i></div>
                            <div class="search-suggestion-info">
                                <span class="search-suggestion-name">${escapeHtml(item.nama)}</span>
                                <span class="search-suggestion-location">${escapeHtml(item.location)}</span>
                            </div>
                        `;
                        
                        itemEl.addEventListener('click', () => {
                            suggestionsBox.style.display = 'none';
                            searchInput.value = '';
                            
                            // Reset local filters
                            cards.forEach(card => card.style.display = '');
                            if (noResultsMsg) noResultsMsg.remove();
                            
                            if (item.item_type === 'folder') {
                                openFolderDetailModal(item);
                            } else {
                                openFilePreviewModal(item);
                            }
                        });
                        
                        suggestionsBox.appendChild(itemEl);
                    });
                    suggestionsBox.style.display = 'block';
                } else {
                    suggestionsBox.innerHTML = `
                        <div style="padding:1rem; text-align:center; color:var(--text-muted); font-size:0.85rem">
                            Tidak ada saran pencarian global yang ditemukan.
                        </div>
                    `;
                    suggestionsBox.style.display = 'block';
                }
            })
            .catch(err => console.error('Error fetching suggestions:', err));
        }, 250);
    });

    // Hide suggestions on outside click
    document.addEventListener('click', function(e) {
        if (!searchInput?.contains(e.target) && !suggestionsBox?.contains(e.target)) {
            if (suggestionsBox) suggestionsBox.style.display = 'none';
        }
    });
</script>

<?php 
require_once __DIR__ . '/../template/footer.php'; 

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}
?>
