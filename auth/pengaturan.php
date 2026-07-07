<?php
// auth/pengaturan.php
require_once __DIR__ . '/../template/header.php';

$error = '';
$success = '';

// Handle basic profile info update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $nama = trim($_POST['nama'] ?? '');
    $password_lama = $_POST['password_lama'] ?? '';
    $password_baru = $_POST['password_baru'] ?? '';
    
    if (empty($nama)) {
        $error = 'Nama tidak boleh kosong!';
    } else {
        try {
            // Check uniqueness of nama (exclude self)
            $check_nama = $conn->prepare("SELECT id_user FROM users WHERE nama = ? AND id_user != ?");
            $check_nama->execute([$nama, $_SESSION['id_user']]);
            if ($check_nama->fetch()) {
                $error = 'Nama tersebut sudah digunakan oleh pengguna lain.';
            } elseif (!empty($password_baru)) {
                if (empty($password_lama)) {
                    $error = 'Password lama wajib diisi untuk mengubah password.';
                } elseif (strlen($password_baru) < 6) {
                    $error = 'Password baru minimal 6 karakter.';
                } else {
                    $stmt_pw = $conn->prepare("SELECT password FROM users WHERE id_user = ? LIMIT 1");
                    $stmt_pw->execute([$_SESSION['id_user']]);
                    $current_hashed = $stmt_pw->fetchColumn();
                    if (!$current_hashed || !password_verify($password_lama, $current_hashed)) {
                        $error = 'Password lama salah.';
                    } else {
                        $hashed = password_hash($password_baru, PASSWORD_DEFAULT);
                        $storePlain = shouldStorePlainPassword($_SESSION['role'] ?? '');
                        if ($storePlain) {
                            $encrypted_plain = encryptUserData($password_baru);
                            $stmt = $conn->prepare("UPDATE users SET nama = ?, password = ?, plain_password = ? WHERE id_user = ?");
                            $stmt->execute([$nama, $hashed, $encrypted_plain, $_SESSION['id_user']]);
                        } else {
                            $stmt = $conn->prepare("UPDATE users SET nama = ?, password = ?, plain_password = NULL WHERE id_user = ?");
                            $stmt->execute([$nama, $hashed, $_SESSION['id_user']]);
                        }
                        $_SESSION['nama'] = $nama;
                        $_SESSION['login_password_hash'] = $hashed;
                        $success = 'Profil dan password berhasil diperbarui!';
                    }
                }
            } else {
                $stmt = $conn->prepare("UPDATE users SET nama = ? WHERE id_user = ?");
                $stmt->execute([$nama, $_SESSION['id_user']]);
                $_SESSION['nama'] = $nama;
                $success = 'Profil berhasil diperbarui!';
            }
        } catch (Exception $e) {
            $error = safeErrorMessage($e, 'Gagal menyimpan perubahan.');
        }
    }
}

// Handle profile photo upload (Base64 cropped data)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_avatar') {
    $img_data = $_POST['avatar_base64'] ?? '';
    if (!empty($img_data)) {
        try {
            // Parse base64
            list($type, $img_data) = explode(';', $img_data);
            list(, $img_data)      = explode(',', $img_data);
            $img_decoded = base64_decode($img_data);
            
            if ($img_decoded === false) {
                $error = 'Data gambar tidak valid.';
            } else {
                // Size limit: max 2MB
                if (strlen($img_decoded) > 2 * 1024 * 1024) {
                    $error = 'Ukuran foto maksimal 2MB.';
                } else {
                    // Validate MIME type of decoded binary data
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->buffer($img_decoded);
                    $allowed_image_mimes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
                    
                    if (!in_array($mime, $allowed_image_mimes)) {
                        $error = 'Format file tidak diizinkan. Hanya gambar (PNG, JPG, GIF, WEBP).';
                    } else {
                        // Determine extension from actual MIME
                        $ext_map = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                        $ext = $ext_map[$mime] ?? 'png';
                        $file_name = 'avatar_' . $_SESSION['id_user'] . '_' . time() . '.' . $ext;
                        $target_path = __DIR__ . '/../uploads/profil/' . $file_name;
                        
                        // Delete old avatar if exists
                        if (!empty($_SESSION['foto_profil'])) {
                            $old_avatar = __DIR__ . '/../uploads/profil/' . $_SESSION['foto_profil'];
                            if (file_exists($old_avatar)) @unlink($old_avatar);
                        }
                        
                        if (file_put_contents($target_path, $img_decoded)) {
                            $stmt = $conn->prepare("UPDATE users SET foto_profil = ? WHERE id_user = ?");
                            $stmt->execute([$file_name, $_SESSION['id_user']]);
                            $_SESSION['foto_profil'] = $file_name;
                            $success = 'Foto profil berhasil diperbarui!';
                        } else {
                            $error = 'Gagal menyimpan file foto profil.';
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $error = safeErrorMessage($e, 'Gagal memproses foto profil.');
        }
    } else {
        $error = 'Data foto profil kosong.';
    }
}

// Handle site title & settings updates (Admin, Operator & Superadmin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_settings') {
    if (!in_array($_SESSION['role'] ?? '', ['admin', 'operator', 'superadmin'])) {
        $error = 'Anda tidak memiliki akses administrasi ini.';
    } else {
        $site_title_val = trim($_POST['site_title'] ?? 'BIG FAMILY ITS 26');
        $site_desc_val = trim($_POST['site_description'] ?? '');
        try {
            $stmt = $conn->prepare("UPDATE settings SET site_title = ?, site_description = ? WHERE id = 1");
            $stmt->execute([$site_title_val, $site_desc_val]);
            $success = 'Pengaturan situs berhasil diperbarui!';
            // Refresh settings in header session-like variable
            $app_settings['site_title'] = $site_title_val;
            $app_settings['site_description'] = $site_desc_val;
        } catch (Exception $e) {
            $error = safeErrorMessage($e, 'Gagal menyimpan pengaturan.');
        }
    }
}
?>

<div class="grid grid-cols-12">
    <div class="col-12">
        <h2 style="color: #fff; margin-bottom: 1.5rem;"><i class="fas fa-gear"></i> Pengaturan</h2>
    </div>

    <!-- User Information Panel -->
    <div class="col-8">
        <?php if ($error): ?>
            <div class="alert-danger" style="background:rgba(248,81,73,0.1); color:#f85149; border:1px solid rgba(248,81,73,0.2); padding:1rem; border-radius:8px; margin-bottom:1.5rem">
                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert-success" style="background:rgba(46,160,67,0.1); color:#2ea043; border:1px solid rgba(46,160,67,0.2); padding:1rem; border-radius:8px; margin-bottom:1.5rem">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-user-edit"></i> Edit Profil</h3>
            </div>
            <form method="POST">
                <input type="hidden" name="_csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="action" value="update_profile">
                
                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label style="color: var(--text-muted); display:block; font-size:0.85rem; margin-bottom:0.4rem">Username (tidak dapat diubah)</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($_SESSION['username']) ?>" disabled style="opacity: 0.6; cursor: not-allowed;">
                </div>

                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label style="color: var(--text-muted); display:block; font-size:0.85rem; margin-bottom:0.4rem">Nama Lengkap</label>
                    <input type="text" name="nama" class="form-control" value="<?= htmlspecialchars($_SESSION['nama']) ?>" required>
                </div>

                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label style="color: var(--text-muted); display:block; font-size:0.85rem; margin-bottom:0.4rem">Password Lama (wajib jika ingin mengubah password)</label>
                    <input type="password" name="password_lama" class="form-control" placeholder="Masukkan password saat ini" autocomplete="current-password">
                </div>

                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label style="color: var(--text-muted); display:block; font-size:0.85rem; margin-bottom:0.4rem">Password Baru (kosongkan jika tidak ingin diubah)</label>
                    <input type="password" name="password_baru" class="form-control" placeholder="Masukkan password baru (minimal 6 karakter)" minlength="6" autocomplete="new-password">
                </div>

                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Perubahan</button>
            </form>
        </div>

        <?php if (in_array($_SESSION['role'] ?? '', ['admin', 'operator', 'superadmin'])): ?>
        <!-- Site Configuration -->
        <div class="card" id="pengaturan-situs">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-sliders"></i> Pengaturan Situs</h3>
            </div>
            <form method="POST">
                <input type="hidden" name="_csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="action" value="update_settings">
                
                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label style="color: var(--text-muted); display:block; font-size:0.85rem; margin-bottom:0.4rem">Judul Website</label>
                    <input type="text" name="site_title" class="form-control" value="<?= htmlspecialchars($app_settings['site_title']) ?>" required>
                </div>

                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label style="color: var(--text-muted); display:block; font-size:0.85rem; margin-bottom:0.4rem">Deskripsi Website</label>
                    <textarea name="site_description" class="form-control" rows="3" required><?= htmlspecialchars($app_settings['site_description']) ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Pengaturan</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if (($_SESSION['role'] ?? '') === 'superadmin'): ?>
        <?php
        $msg_count = 0;
        try {
            $msg_count = (int)$conn->query("SELECT COUNT(*) FROM msg")->fetchColumn();
        } catch (Exception $e) {}
        ?>
        <div class="card" id="kelola-pesan-grup" style="margin-top: 1.5rem;">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-comments"></i> Kelola Pesan Grup</h3>
            </div>
            <p style="font-size:0.88rem; color:var(--text-muted); margin-bottom:1rem;">
                Total pesan grup saat ini: <strong style="color:#fff"><?= number_format($msg_count) ?></strong>
            </p>
            <p style="font-size:0.85rem; color:var(--text-muted); margin-bottom:1.25rem;">
                Bersihkan chat grup yang tidak perlu. Pilih metode: tandai semua sebagai dihapus (jejak tetap ada) atau hapus permanen (hilang total).
            </p>
            <div style="display:flex; flex-wrap:wrap; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="clearGroupChat('soft')">
                    <i class="fas fa-eraser"></i> Kosongkan (Tandai Dihapus)
                </button>
                <button type="button" class="btn btn-danger" onclick="clearGroupChat('permanent')">
                    <i class="fas fa-trash-alt"></i> Kosongkan Permanen
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Avatar Upload with Cropper.js Crop Modal -->
    <div class="col-4">
        <div class="card" style="text-align: center;">
            <div class="card-header">
                <h3 class="card-title" style="margin: 0 auto;"><i class="fas fa-image"></i> Foto Profil</h3>
            </div>
            
            <div style="margin: 1.5rem 0;">
                <img src="<?= $__userAvatar ?>" alt="Avatar" style="width: 130px; height: 130px; border-radius: 50%; object-fit: cover; border: 3px solid var(--accent-color); box-shadow: 0 4px 15px rgba(88,166,255,0.2)">
            </div>
            
            <p style="font-size:0.82rem; color: var(--text-muted); margin-bottom: 1.5rem;">Gunakan foto berformat PNG, JPG, atau JPEG. Anda dapat memotong foto sebelum menyimpannya.</p>
            
            <label class="btn btn-secondary" style="width: 100%; display: inline-flex; cursor: pointer; justify-content:center">
                <i class="fas fa-camera"></i> Pilih Foto Baru
                <input type="file" id="avatarFileInput" accept="image/*" style="display: none;">
            </label>
        </div>
    </div>
</div>

<!-- Cropper Modal -->
<div class="modal" id="cropperModal">
    <div class="modal-dialog" style="max-width: 450px;">
        <div class="modal-header">
            <h3>Sesuaikan Foto</h3>
            <button class="modal-close" onclick="closeCropper()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="cropper-container-wrapper">
                <img id="cropperImageSrc" src="">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeCropper()">Batal</button>
            <button class="btn btn-primary" id="saveCroppedAvatarBtn">Potong & Simpan</button>
        </div>
    </div>
</div>

<form id="avatarUploadForm" method="POST" style="display: none;">
    <input type="hidden" name="_csrf_token" value="<?= generateCsrfToken() ?>">
    <input type="hidden" name="action" value="update_avatar">
    <textarea name="avatar_base64" id="avatarBase64Input"></textarea>
</form>

<!-- Cropper JS and logic integration -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
<script>
    let cropper = null;
    const fileInput = document.getElementById('avatarFileInput');
    const modal = document.getElementById('cropperModal');
    const cropImg = document.getElementById('cropperImageSrc');
    const saveBtn = document.getElementById('saveCroppedAvatarBtn');
    const base64Input = document.getElementById('avatarBase64Input');
    const uploadForm = document.getElementById('avatarUploadForm');

    fileInput.addEventListener('change', function(e) {
        const files = e.target.files;
        if (files && files.length > 0) {
            const file = files[0];
            const reader = new FileReader();
            reader.onload = function(evt) {
                cropImg.src = evt.target.result;
                modal.classList.add('open');
                
                if (cropper) {
                    cropper.destroy();
                }
                
                setTimeout(() => {
                    cropper = new Cropper(cropImg, {
                        aspectRatio: 1,
                        viewMode: 1,
                        background: false
                    });
                }, 200);
            };
            reader.readAsDataURL(file);
        }
    });

    function closeCropper() {
        modal.classList.remove('open');
        if (cropper) {
            cropper.destroy();
            cropper = null;
        }
        fileInput.value = '';
    }

    saveBtn.addEventListener('click', function() {
        if (!cropper) return;
        
        const canvas = cropper.getCroppedCanvas({
            width: 300,
            height: 300
        });
        
        const base64Data = canvas.toDataURL('image/png');
        base64Input.value = base64Data;
        
        closeCropper();
        
        // Show indicator and submit
        Swal.fire({
            title: 'Mengupload foto...',
            allowOutsideClick: false,
            background: '#161b22',
            color: '#c9d1d9',
            didOpen: () => {
                Swal.showLoading();
                uploadForm.submit();
            }
        });
    });
</script>

<script>
    // URL Hash Routing / Highlighting for sidebar links
    document.addEventListener('DOMContentLoaded', () => {
        function updateSidebarActiveTab() {
            const hash = window.location.hash;
            
            // Remove active class from settings links in the sidebar
            document.querySelectorAll('.sidebar-link[data-nav^="pengaturan"], .sidebar-link[data-nav="kelola-pesan-grup"]').forEach(link => {
                link.classList.remove('active');
            });
            
            if (hash === '#pengaturan-situs') {
                const link = document.querySelector('.sidebar-link[data-nav="pengaturan-situs"]');
                if (link) link.classList.add('active');
                const targetCard = document.getElementById('pengaturan-situs');
                if (targetCard) targetCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } else if (hash === '#kelola-pesan-grup') {
                const link = document.querySelector('.sidebar-link[data-nav="kelola-pesan-grup"]');
                if (link) link.classList.add('active');
                const targetCard = document.getElementById('kelola-pesan-grup');
                if (targetCard) targetCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } else {
                const link = document.querySelector('.sidebar-link[data-nav="pengaturan"]');
                if (link) link.classList.add('active');
            }
        }
        
        window.addEventListener('hashchange', updateSidebarActiveTab);
        setTimeout(updateSidebarActiveTab, 100);
    });
</script>

<?php if (($_SESSION['role'] ?? '') === 'superadmin'): ?>
<script>
    function clearGroupChat(mode) {
        const isPermanent = mode === 'permanent';
        Swal.fire({
            title: isPermanent ? 'Kosongkan Chat Permanen?' : 'Kosongkan Chat (Tandai Dihapus)?',
            text: isPermanent
                ? 'Semua pesan grup akan dihapus total tanpa jejak. Tindakan ini tidak dapat dibatalkan.'
                : 'Semua pesan akan ditandai "Pesan ini telah dihapus" untuk semua pengguna.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: isPermanent ? 'Ya, Hapus Permanen' : 'Ya, Tandai Dihapus',
            cancelButtonText: 'Batal',
            confirmButtonColor: isPermanent ? '#d33' : '#3085d6',
            background: '#161b22',
            color: '#c9d1d9'
        }).then(result => {
            if (!result.isConfirmed) return;

            const formData = new FormData();
            formData.append('action', 'clear_group_chat');
            formData.append('mode', mode);

            Swal.fire({
                title: 'Memproses...',
                allowOutsideClick: false,
                background: '#161b22',
                color: '#c9d1d9',
                didOpen: () => Swal.showLoading()
            });

            fetch('../msg/api_msg.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Berhasil',
                            text: data.message || 'Pesan grup berhasil dibersihkan.',
                            background: '#161b22',
                            color: '#c9d1d9'
                        }).then(() => location.reload());
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: data.message || 'Gagal membersihkan pesan grup.',
                            background: '#161b22',
                            color: '#c9d1d9'
                        });
                    }
                })
                .catch(() => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal',
                        text: 'Terjadi kesalahan jaringan.',
                        background: '#161b22',
                        color: '#c9d1d9'
                    });
                });
        });
    }
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../template/footer.php'; ?>
