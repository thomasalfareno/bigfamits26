<?php
// admin/index.php
require_once __DIR__ . '/../config/security.php';
initSecureSession();

$base_url = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$base_url = preg_replace('/(\/(auth|dashboard|notes|msg|drive|calendar|server|admin|installer))?$/i', '', $base_url);
if ($base_url === '/') $base_url = '';

if (!file_exists(__DIR__ . '/../config/database.php')) {
    header("Location: $base_url/installer/");
    exit;
}
require_once __DIR__ . '/../config/database.php';
verifyActiveSession($conn, $base_url);

if (!isset($_SESSION['id_user'])) {
    header("Location: $base_url/auth/login");
    exit;
}

$isSuperAdmin = ($_SESSION['role'] ?? '') === 'superadmin';
$adminPageUrl = $base_url . '/admin/';

$redirectAdminFlash = static function (?string $success = null, ?string $error = null) use ($adminPageUrl): void {
    if ($success !== null && $success !== '') {
        $_SESSION['_admin_flash_success'] = $success;
    }
    if ($error !== null && $error !== '') {
        $_SESSION['_admin_flash_error'] = $error;
    }
    header('Location: ' . $adminPageUrl);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!in_array($_SESSION['role'] ?? '', ['admin', 'operator', 'superadmin'])) {
        $redirectAdminFlash(null, 'Akses ditolak.');
    }

    if (!validateCsrfToken()) {
        $redirectAdminFlash(null, 'Token keamanan tidak valid atau telah kadaluarsa. Silakan muat ulang halaman.');
    }

    $action = $_POST['action'];

    if ($action === 'create_user') {
        $nama = trim($_POST['nama'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'user';

        if (empty($nama) || empty($username) || empty($password)) {
            $redirectAdminFlash(null, 'Semua kolom wajib diisi!');
        } elseif (!isStrongPassword($password)) {
            $redirectAdminFlash(null, 'Password minimal 8 karakter.');
        } elseif (($_SESSION['role'] ?? '') === 'operator' && !in_array($role, ['user', 'operator'])) {
            $redirectAdminFlash(null, 'Operator hanya diperbolehkan membuat user dengan role User atau Operator.');
        } elseif ($role === 'superadmin' && !$isSuperAdmin) {
            $redirectAdminFlash(null, 'Hanya Super Admin yang diizinkan membuat akun Super Admin.');
        } elseif (isReservedSuperAdminUsername($username)) {
            $redirectAdminFlash(null, 'Username ini tidak diperbolehkan.');
        }

        try {
            $check_u = $conn->prepare("SELECT id_user FROM users WHERE username = ?");
            $check_u->execute([$username]);
            $check_n = $conn->prepare("SELECT id_user FROM users WHERE nama = ?");
            $check_n->execute([$nama]);

            if ($check_u->fetch()) {
                $redirectAdminFlash(null, 'Username sudah terdaftar.');
            } elseif ($check_n->fetch()) {
                $redirectAdminFlash(null, 'Nama Lengkap sudah terdaftar.');
            }

            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (nama, username, password, plain_password, role) VALUES (?, ?, ?, NULL, ?)");
            $stmt->execute([$nama, $username, $hashed, $role]);
            $redirectAdminFlash("User baru '$nama' berhasil dibuat!");
        } catch (Exception $e) {
            $redirectAdminFlash(null, safeErrorMessage($e, 'Gagal membuat user baru.'));
        }
    }

    if ($action === 'update_role') {
        $id_user = (int)$_POST['id_user'];
        $role = $_POST['role'] ?? 'user';

        try {
            $stmt_chk_role = $conn->prepare("SELECT role FROM users WHERE id_user = ?");
            $stmt_chk_role->execute([$id_user]);
            $target_user_role = $stmt_chk_role->fetchColumn();

            if (($_SESSION['role'] ?? '') === 'operator' && !in_array($role, ['user', 'operator'])) {
                $redirectAdminFlash(null, 'Operator hanya diperbolehkan mengubah role menjadi User atau Operator.');
            } elseif ($id_user === (int)$_SESSION['id_user']) {
                $redirectAdminFlash(null, 'Anda tidak dapat merubah role Anda sendiri.');
            } elseif ($target_user_role === 'superadmin' && !$isSuperAdmin) {
                $redirectAdminFlash(null, 'Anda tidak memiliki hak untuk merubah role Super Admin.');
            } elseif ($role === 'superadmin' && !$isSuperAdmin) {
                $redirectAdminFlash(null, 'Hanya Super Admin yang dapat menunjuk Super Admin baru.');
            }

            $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id_user = ?");
            $stmt->execute([$role, $id_user]);
            $redirectAdminFlash('Role user berhasil diupdate!');
        } catch (Exception $e) {
            $redirectAdminFlash(null, safeErrorMessage($e, 'Gagal mengupdate role.'));
        }
    }

    if ($action === 'reset_password') {
        $id_user = (int)$_POST['id_user'];
        $password_baru = $_POST['password_baru'] ?? '';
        $operator_password = $_POST['operator_password'] ?? '';
        $admin_password = $_POST['admin_password'] ?? '';

        if (empty($password_baru)) {
            $redirectAdminFlash(null, 'Password baru wajib diisi!');
        } elseif (!isStrongPassword($password_baru)) {
            $redirectAdminFlash(null, 'Password baru minimal 8 karakter.');
        } elseif (($_SESSION['role'] ?? '') === 'operator' && empty($operator_password)) {
            $redirectAdminFlash(null, 'Operator wajib memasukkan password miliknya untuk konfirmasi!');
        } elseif (($_SESSION['role'] ?? '') === 'admin' && empty($admin_password)) {
            $redirectAdminFlash(null, 'Admin wajib memasukkan password miliknya untuk konfirmasi!');
        }

        try {
            $verified = true;
            if (($_SESSION['role'] ?? '') === 'operator') {
                $stmt_op = $conn->prepare("SELECT password FROM users WHERE id_user = ? LIMIT 1");
                $stmt_op->execute([$_SESSION['id_user']]);
                $op_hashed = $stmt_op->fetchColumn();
                if (!$op_hashed || !password_verify($operator_password, $op_hashed)) {
                    $verified = false;
                    $redirectAdminFlash(null, 'Password konfirmasi Anda (Operator) salah!');
                }
            } elseif (($_SESSION['role'] ?? '') === 'admin') {
                $stmt_ad = $conn->prepare("SELECT password FROM users WHERE id_user = ? LIMIT 1");
                $stmt_ad->execute([$_SESSION['id_user']]);
                $ad_hashed = $stmt_ad->fetchColumn();
                if (!$ad_hashed || !password_verify($admin_password, $ad_hashed)) {
                    $verified = false;
                    $redirectAdminFlash(null, 'Password konfirmasi Anda (Admin) salah!');
                }
            }

            if ($verified) {
                $stmt_chk_role = $conn->prepare("SELECT role FROM users WHERE id_user = ?");
                $stmt_chk_role->execute([$id_user]);
                $target_user_role = $stmt_chk_role->fetchColumn();

                if ($target_user_role === 'superadmin' && !$isSuperAdmin) {
                    $redirectAdminFlash(null, 'Anda tidak memiliki hak untuk mengubah password Super Admin.');
                } elseif (($_SESSION['role'] ?? '') === 'operator' && in_array($target_user_role, ['admin', 'superadmin'])) {
                    $redirectAdminFlash(null, 'Operator tidak diizinkan mengubah password Admin atau Super Admin.');
                }

                $hashed = password_hash($password_baru, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ?, plain_password = NULL WHERE id_user = ?");
                $stmt->execute([$hashed, $id_user]);
                $redirectAdminFlash('Password user berhasil direset!');
            }
        } catch (Exception $e) {
            $redirectAdminFlash(null, safeErrorMessage($e, 'Gagal mereset password.'));
        }
    }

    $redirectAdminFlash(null, 'Aksi tidak dikenali.');
}

require_once __DIR__ . '/../template/header.php';

$error = $_SESSION['_admin_flash_error'] ?? '';
$success = $_SESSION['_admin_flash_success'] ?? '';
unset($_SESSION['_admin_flash_error'], $_SESSION['_admin_flash_success']);

// Authorization lock
if (!in_array($_SESSION['role'] ?? '', ['admin', 'operator', 'superadmin'])) {
    echo '<div class="alert-danger" style="background:rgba(248,81,73,0.1); color:#f85149; border:1px solid rgba(248,81,73,0.2); padding:1rem; border-radius:8px;">Akses ditolak. Halaman ini hanya untuk Administrator/Operator.</div>';
    require_once __DIR__ . '/../template/footer.php';
    exit;
}

// Fetch users — superadmin accounts are never listed; credentials visible only to super admin
$users = [];
try {
    if ($isSuperAdmin) {
        $stmt_u = $conn->prepare("SELECT id_user, nama, username, role, foto_profil, last_online, is_online, created_at FROM users WHERE id_user != ? AND role != 'superadmin' ORDER BY role ASC, nama ASC");
        $stmt_u->execute([$_SESSION['id_user']]);
        $users = $stmt_u->fetchAll();
    } else {
        $users = $conn->query("SELECT id_user, nama, role, foto_profil, last_online, is_online, created_at FROM users WHERE role != 'superadmin' ORDER BY role ASC, nama ASC")->fetchAll();
    }
} catch (Exception $e) {}
?>

<div class="grid grid-cols-12">
    <div class="col-12">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem">
            <h2 style="color: #fff;"><i class="fas fa-user-shield"></i> Kelola Pengguna</h2>
            <button class="btn btn-primary" onclick="openNewUserModal()"><i class="fas fa-user-plus"></i> Tambah Pengguna</button>
        </div>
        
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
    </div>

    <!-- Users Table card -->
    <div class="col-12">
        <div class="card" style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border-color); color:#fff">
                        <th style="padding: 12px 8px;">Foto</th>
                        <th style="padding: 12px 8px;">Nama</th>
                        <?php if ($isSuperAdmin): ?>
                            <th style="padding: 12px 8px;">Username</th>
                        <?php endif; ?>
                        <th style="padding: 12px 8px;">Role</th>
                        <th style="padding: 12px 8px;">Bergabung</th>
                        <th style="padding: 12px 8px; text-align:right">Aksi</th>
                    </tr>
                </thead>
                <tbody id="admin-users-tbody">
                    <?php foreach ($users as $u): 
                        $u_avatar = !empty($u['foto_profil']) 
                            ? '../uploads/profil/' . htmlspecialchars($u['foto_profil']) 
                            : 'https://ui-avatars.com/api/?name='.urlencode($u['nama']).'&background=1f6feb&color=fff&size=80';
                    ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 12px 8px;">
                                <img src="<?= $u_avatar ?>" alt="" style="width:36px; height:36px; border-radius:50%; object-fit:cover; border: 1px solid var(--border-color)">
                            </td>
                            <td style="padding: 12px 8px; font-weight:600; color:#fff"><?= htmlspecialchars($u['nama']) ?></td>
                            <?php if ($isSuperAdmin): ?>
                                <td style="padding: 12px 8px; color:var(--text-muted)"><?= htmlspecialchars($u['username']) ?></td>
                            <?php endif; ?>
                            <td style="padding: 12px 8px;">
                                <span class="badge <?= $u['role'] === 'superadmin' ? 'badge-danger' : ($u['role'] === 'admin' ? 'badge-danger' : ($u['role'] === 'operator' ? 'badge-info' : 'badge-success')) ?>">
                                    <?= htmlspecialchars($u['role']) ?>
                                </span>
                            </td>
                            <td style="padding: 12px 8px; font-size:0.8rem; color:var(--text-muted)"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                            <td style="padding: 12px 8px; text-align:right">
                                <div style="display:inline-flex; gap:6px">
                                    <?php if ($isSuperAdmin): ?>
                                        <button class="btn btn-secondary" style="padding:0.4rem 0.8rem; font-size:0.75rem; background:rgba(88,166,255,0.12); color:var(--accent-color); border-color:rgba(88,166,255,0.2)" onclick="openUserNotesModal(<?= $u['id_user'] ?>, '<?= htmlspecialchars($u['nama']) ?>')">
                                            <i class="fas fa-sticky-note"></i> Catatan
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($u['role'] !== 'superadmin'): ?>
                                        <button class="btn btn-secondary" style="padding:0.4rem 0.8rem; font-size:0.75rem" onclick="openResetModal(<?= $u['id_user'] ?>, '<?= htmlspecialchars($u['nama']) ?>')"><i class="fas fa-key"></i> Reset Pass</button>
                                        
                                        <?php if ($u['id_user'] !== (int)$_SESSION['id_user']): ?>
                                            <button class="btn btn-secondary" style="padding:0.4rem 0.8rem; font-size:0.75rem" onclick="openRoleModal(<?= $u['id_user'] ?>, '<?= $u['role'] ?>')"><i class="fas fa-user-edit"></i> Edit Role</button>
                                            <button class="btn btn-danger" style="padding:0.4rem 0.8rem; font-size:0.75rem" onclick="deleteUser(<?= $u['id_user'] ?>)"><i class="fas fa-trash-alt"></i></button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: New User -->
<div class="modal" id="newUserModal">
    <div class="modal-dialog" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Tambah Pengguna Baru</h3>
            <button class="modal-close" onclick="closeNewUserModal()"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="_csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="create_user">
            <div class="modal-body">
                <div class="form-group" style="margin-bottom:1rem">
                    <label style="color:var(--text-muted); display:block; font-size:0.85rem; margin-bottom:0.4rem">Nama Lengkap</label>
                    <input type="text" name="nama" class="form-control" placeholder="Nama lengkap..." required>
                </div>
                <div class="form-group" style="margin-bottom:1rem">
                    <label style="color:var(--text-muted); display:block; font-size:0.85rem; margin-bottom:0.4rem">Username</label>
                    <input type="text" name="username" class="form-control" placeholder="Username unik..." required>
                </div>
                <div class="form-group" style="margin-bottom:1rem">
                    <label style="color:var(--text-muted); display:block; font-size:0.85rem; margin-bottom:0.4rem">Password</label>
                    <input type="password" name="password" class="form-control" placeholder="Minimal 8 karakter" minlength="8" required>
                </div>
                <div class="form-group">
                    <label style="color:var(--text-muted); display:block; font-size:0.85rem; margin-bottom:0.4rem">Role</label>
                    <select name="role" class="form-control">
                        <option value="user">User (Mahasiswa)</option>
                        <option value="operator">Operator (Koordinator Kelas)</option>
                        <?php if (($_SESSION['role'] ?? '') !== 'operator'): ?>
                            <option value="admin">Admin (Dosen/Super)</option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeNewUserModal()">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan User</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Reset Password -->
<div class="modal" id="resetModal">
    <div class="modal-dialog" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Reset Password — <span id="resetName"></span></h3>
            <button class="modal-close" onclick="closeResetModal()"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="_csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="id_user" id="resetIdInput">
            <div class="modal-body">
                <?php if (($_SESSION['role'] ?? '') === 'operator'): ?>
                    <div class="form-group" style="margin-bottom:1rem">
                        <label style="color:var(--text-muted); display:block; font-size:0.85rem; margin-bottom:0.4rem">Password Konfirmasi (Password Anda)</label>
                        <input type="password" name="operator_password" class="form-control" placeholder="Masukkan password Anda..." required>
                    </div>
                <?php elseif (($_SESSION['role'] ?? '') === 'admin'): ?>
                    <div class="form-group" style="margin-bottom:1rem">
                        <label style="color:var(--text-muted); display:block; font-size:0.85rem; margin-bottom:0.4rem">Password Konfirmasi (Password Anda)</label>
                        <input type="password" name="admin_password" class="form-control" placeholder="Masukkan password Anda..." required>
                    </div>
                <?php endif; ?>
                <div class="form-group">
                    <label style="color:var(--text-muted); display:block; font-size:0.85rem; margin-bottom:0.4rem">Password Baru</label>
                    <input type="password" name="password_baru" class="form-control" placeholder="Minimal 8 karakter" minlength="8" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeResetModal()">Batal</button>
                <button type="submit" class="btn btn-primary">Reset Password</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Role -->
<div class="modal" id="roleModal">
    <div class="modal-dialog" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Edit Role</h3>
            <button class="modal-close" onclick="closeRoleModal()"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="_csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="update_role">
            <input type="hidden" name="id_user" id="roleIdInput">
            <div class="modal-body">
                <div class="form-group">
                    <label style="color:var(--text-muted); display:block; font-size:0.85rem; margin-bottom:0.4rem">Role Baru</label>
                    <select name="role" id="roleSelectInput" class="form-control">
                        <option value="user">User (Mahasiswa)</option>
                        <option value="operator">Operator (Koordinator Kelas)</option>
                        <?php if (($_SESSION['role'] ?? '') !== 'operator'): ?>
                            <option value="admin">Admin (Dosen/Super)</option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeRoleModal()">Batal</button>
                <button type="submit" class="btn btn-primary">Update Role</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: User Notes Browser (Super Admin Only) -->
<div class="modal" id="userNotesModal">
    <div class="modal-dialog" style="max-width: 650px; display:flex; flex-direction:column; max-height:85vh;">
        <div class="modal-header">
            <h3>Daftar Catatan — <span id="notesOwnerName"></span></h3>
            <button class="modal-close" onclick="closeUserNotesModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" style="overflow-y:auto; display:flex; gap:15px; height: 350px;">
            <div style="width: 220px; border-right: 1px solid var(--border-color); padding-right:15px; overflow-y:auto; display:flex; flex-direction:column; gap:4px;" id="userNotesList">
                <!-- Loaded dynamically -->
            </div>
            <div style="flex: 1; display:flex; flex-direction:column; overflow-y:auto; padding-left:5px;">
                <h4 id="userNoteTitleDetail" style="color:#fff; margin-bottom:10px;">Pilih catatan di samping</h4>
                <div id="userNoteBodyDetail" style="color:var(--text-color); font-size:0.9rem; line-height:1.6; overflow-y:auto;"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeUserNotesModal()">Tutup</button>
        </div>
    </div>
</div>

<script nonce="<?= $cspNonce ?>">
    const isSuperAdmin = <?= $isSuperAdmin ? 'true' : 'false' ?>;

    function openNewUserModal() { document.getElementById('newUserModal').classList.add('open'); }
    function closeNewUserModal() { document.getElementById('newUserModal').classList.remove('open'); }

    function openResetModal(id, name) {
        document.getElementById('resetIdInput').value = id;
        document.getElementById('resetName').innerText = name;
        document.getElementById('resetModal').classList.add('open');
    }
    function closeResetModal() { document.getElementById('resetModal').classList.remove('open'); }

    function openRoleModal(id, currentRole) {
        document.getElementById('roleIdInput').value = id;
        document.getElementById('roleSelectInput').value = currentRole;
        document.getElementById('roleModal').classList.add('open');
    }
    function closeRoleModal() { document.getElementById('roleModal').classList.remove('open'); }

    function deleteUser(id) {
        const swalConfig = {
            title: 'Hapus User?',
            text: 'User ini beserta seluruh data drive/catatan pribadinya akan dihapus permanen!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Hapus',
            cancelButtonColor: '#d33',
            background: '#161b22',
            color: '#c9d1d9'
        };

        if (!isSuperAdmin) {
            swalConfig.input = 'password';
            swalConfig.inputLabel = 'Password Konfirmasi Akun Target';
            swalConfig.inputPlaceholder = 'Masukkan password akun yang hendak dihapus';
            swalConfig.footer = '<span style="font-size:0.8rem;color:#8b949e">Demi keamanan, Anda wajib memasukkan password akun yang hendak dihapus ini.</span>';
            swalConfig.inputAttributes = { autocapitalize: 'off', autocorrect: 'off' };
            swalConfig.preConfirm = (password) => {
                if (!password || !String(password).trim()) {
                    Swal.showValidationMessage('Password wajib diisi');
                    return false;
                }
                return password;
            };
        }

        Swal.fire(swalConfig).then(res => {
            if (res.isConfirmed) {
                const fd = new FormData();
                fd.append('action', 'delete_user');
                fd.append('id', id);
                if (!isSuperAdmin) {
                    fd.append('target_password', res.value || '');
                }
                fetch('api_admin.php', { method: 'POST', body: fd })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            showToast('User berhasil dihapus');
                            // Trigger immediate refresh instead of reloading page
                            refreshAdminUsers();
                        } else {
                            showToast(data.message || 'Gagal menghapus user', 'error');
                        }
                    })
                    .catch(() => showToast('Gagal menghubungi server. Muat ulang halaman lalu coba lagi.', 'error'));
            }
        });
    }

    function openUserNotesModal(uid, ownerName) {
        document.getElementById('notesOwnerName').innerText = ownerName;
        document.getElementById('userNoteTitleDetail').innerText = 'Pilih catatan di samping';
        document.getElementById('userNoteBodyDetail').innerHTML = '';
        document.getElementById('userNotesList').innerHTML = '<p style="color:var(--text-muted)">Loading...</p>';
        
        const fd = new FormData();
        fd.append('action', 'list_user_notes');
        fd.append('id_user', uid);

        fetch('../notes/api_notes.php', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    let html = '';
                    if (data.notes.length === 0) {
                        html = '<p style="font-size:0.8rem; color:var(--text-muted); padding:10px;">Tidak ada catatan.</p>';
                    } else {
                        data.notes.forEach(note => {
                            html += `<div style="padding:10px; border:1px solid var(--border-color); border-radius:8px; background:rgba(255,255,255,0.02); cursor:pointer;" onclick="viewUserNoteDetail(${note.id_note})">
                                <strong style="font-size:0.85rem; color:#fff; display:block; text-overflow:ellipsis; overflow:hidden; white-space:nowrap;">${escapeHTML(note.judul || 'Tanpa Judul')}</strong>
                                <small style="font-size:0.7rem; color:var(--text-muted);">${note.updated_at}</small>
                            </div>`;
                        });
                    }
                    document.getElementById('userNotesList').innerHTML = html;
                    document.getElementById('userNotesModal').classList.add('open');
                } else {
                    showToast('Gagal memuat catatan', 'error');
                }
            });
    }

    function viewUserNoteDetail(nid) {
        document.getElementById('userNoteBodyDetail').innerHTML = '<p style="color:var(--text-muted)">Loading...</p>';
        
        const fd = new FormData();
        fd.append('action', 'get_note_detail');
        fd.append('id_note', nid);

        fetch('../notes/api_notes.php', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.note) {
                    document.getElementById('userNoteTitleDetail').textContent = data.note.judul || 'Tanpa Judul';
                    document.getElementById('userNoteBodyDetail').innerHTML = data.note.konten || '<p style="color:var(--text-muted);">Kosong</p>';
                } else {
                    document.getElementById('userNoteBodyDetail').textContent = 'Gagal memuat detail.';
                }
            });
    }

    function closeUserNotesModal() {
        document.getElementById('userNotesModal').classList.remove('open');
    }

    function escapeHTML(str) {
        if (!str) return '';
        return str.replace(/[&<>'"]/g, 
            tag => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[tag] || tag)
        );
    }

    // Real-time admin users polling
    function refreshAdminUsers() {
        const fd = new FormData();
        fd.append('page', 'admin');
        fetch('../auth/api_realtime.php', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if (data && data.success && data.users) {
                    const tbody = document.getElementById('admin-users-tbody');
                    if (!tbody) return;

                    let html = '';
                    const currentUserId = <?= (int)$_SESSION['id_user'] ?>;
                    
                    data.users.forEach(u => {
                        const avatar = u.foto_profil
                            ? `../uploads/profil/${u.foto_profil}`
                            : `https://ui-avatars.com/api/?name=${encodeURIComponent(u.nama)}&background=1f6feb&color=fff&size=80`;
                        
                        const badgeClass = u.role === 'superadmin' ? 'badge-danger' : 
                                           (u.role === 'admin' ? 'badge-danger' : 
                                           (u.role === 'operator' ? 'badge-info' : 'badge-success'));

                        html += `<tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 12px 8px;">
                                <img src="${avatar}" alt="" style="width:36px; height:36px; border-radius:50%; object-fit:cover; border: 1px solid var(--border-color)">
                            </td>
                            <td style="padding: 12px 8px; font-weight:600; color:#fff">${u.nama}</td>`;
                        
                        if (isSuperAdmin) {
                            html += `
                            <td style="padding: 12px 8px; color:var(--text-muted)">${u.username || ''}</td>`;
                        }

                        html += `
                            <td style="padding: 12px 8px;">
                                <span class="badge ${badgeClass}">${u.role}</span>
                            </td>
                            <td style="padding: 12px 8px; font-size:0.8rem; color:var(--text-muted)">${u.created_at}</td>
                            <td style="padding: 12px 8px; text-align:right">
                                <div style="display:inline-flex; gap:6px">`;
                        
                        if (isSuperAdmin) {
                            html += `
                                    <button class="btn btn-secondary" style="padding:0.4rem 0.8rem; font-size:0.75rem; background:rgba(88,166,255,0.12); color:var(--accent-color); border-color:rgba(88,166,255,0.2)" onclick="openUserNotesModal(${u.id_user}, '${u.nama.replace(/'/g, "\\'")}')">
                                        <i class="fas fa-sticky-note"></i> Catatan
                                    </button>`;
                        }

                        if (u.role !== 'superadmin') {
                            html += `
                                    <button class="btn btn-secondary" style="padding:0.4rem 0.8rem; font-size:0.75rem" onclick="openResetModal(${u.id_user}, '${u.nama.replace(/'/g, "\\'")}')"><i class="fas fa-key"></i> Reset Pass</button>`;
                            
                            if (u.id_user !== currentUserId) {
                                html += `
                                    <button class="btn btn-secondary" style="padding:0.4rem 0.8rem; font-size:0.75rem" onclick="openRoleModal(${u.id_user}, '${u.role}')"><i class="fas fa-user-edit"></i> Edit Role</button>
                                    <button class="btn btn-danger" style="padding:0.4rem 0.8rem; font-size:0.75rem" onclick="deleteUser(${u.id_user})"><i class="fas fa-trash-alt"></i></button>`;
                            }
                        }

                        html += `
                                </div>
                            </td>
                        </tr>`;
                    });

                    tbody.innerHTML = html;
                }
            })
            .catch(err => console.error('Admin users polling error:', err));
    }

    let adminPoller = null;

    function startAdminPolling() {
        if (adminPoller) return;
        refreshAdminUsers();
        adminPoller = setInterval(refreshAdminUsers, 5000);
    }

    function stopAdminPolling() {
        if (adminPoller) {
            clearInterval(adminPoller);
            adminPoller = null;
        }
    }

    if (document.visibilityState === 'visible' && document.hasFocus()) {
        startAdminPolling();
    }

    window.addEventListener('focus', startAdminPolling);
    window.addEventListener('blur', stopAdminPolling);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            startAdminPolling();
        } else {
            stopAdminPolling();
        }
    });
</script>

<?php require_once __DIR__ . '/../template/footer.php'; ?>
