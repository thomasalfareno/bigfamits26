    </main><!-- /.main-content -->

    <script nonce="<?= $cspNonce ?>" src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
    <script nonce="<?= $cspNonce ?>">
        // Sidebar toggle
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const toggle = document.getElementById('sidebarToggle');
        
        function openSidebar() { sidebar.classList.add('open'); overlay.classList.add('active'); }
        function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('active'); }
        
        toggle?.addEventListener('click', () => sidebar.classList.contains('open') ? closeSidebar() : openSidebar());
        overlay?.addEventListener('click', closeSidebar);

        // Sidebar minimize for desktop
        function toggleSidebarMinimize() {
            document.body.classList.toggle('sidebar-minimized');
            const isMinimized = document.body.classList.contains('sidebar-minimized');
            localStorage.setItem('sidebar-minimized', isMinimized ? '1' : '0');
        }

        // Close sidebar on navigation (mobile)
        document.querySelectorAll('.sidebar-link:not(.sidebar-logout)').forEach(link => {
            link.addEventListener('click', () => { if (window.innerWidth < 992) closeSidebar(); });
        });

        // Accessible, dependency-free logout confirmation.
        const logoutTrigger = document.getElementById('logoutTrigger');
        const logoutDialog = document.getElementById('logoutDialog');
        const logoutCancelButton = document.getElementById('logoutCancelButton');
        let logoutPreviousFocus = null;

        function closeLogoutDialog() {
            if (logoutDialog?.open) logoutDialog.close();
            logoutPreviousFocus?.focus();
        }

        logoutTrigger?.addEventListener('click', () => {
            logoutPreviousFocus = document.activeElement;
            if (typeof logoutDialog?.showModal === 'function') {
                logoutDialog.showModal();
                logoutCancelButton?.focus();
            }
        });
        logoutCancelButton?.addEventListener('click', closeLogoutDialog);
        logoutDialog?.addEventListener('cancel', event => {
            event.preventDefault();
            closeLogoutDialog();
        });
        logoutDialog?.addEventListener('click', event => {
            if (event.target === logoutDialog) closeLogoutDialog();
        });

        // Toast helper
        function showToast(msg, icon = 'success') {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: icon,
                title: msg,
                showConfirmButton: false,
                timer: 2500,
                background: '#161b22',
                color: '#c9d1d9'
            });
        }

        // Real-time session heartbeat with role sync & focus-based online/offline
        (function() {
            const baseUrl = '<?= $base_url ?>';
            const isLoggedIn = <?= isset($_SESSION['id_user']) ? 'true' : 'false' ?>;
            let currentRole = '<?= $_SESSION['role'] ?? '' ?>';
            const adminRoles = ['admin', 'operator', 'superadmin'];
            let heartbeatInterval = null;

            if (!isLoggedIn) return;

            // Send heartbeat with online/offline status
            function sendHeartbeat(status) {
                const fd = new FormData();
                fd.append('status', status);
                fetch(baseUrl + '/auth/api_heartbeat.php', { method: 'POST', body: fd })
                    .then(res => {
                        if (!res.ok) throw new Error('Network error');
                        return res.json();
                    })
                    .then(data => {
                        if (data && data.success === false) {
                            Swal.fire({
                                title: 'Sesi Berakhir',
                                text: data.message || 'Sesi Anda telah berakhir karena akun dihapus atau password diubah. Silakan login kembali.',
                                icon: 'warning',
                                confirmButtonText: 'Login Kembali',
                                allowOutsideClick: false,
                                background: '#161b22',
                                color: '#c9d1d9'
                            }).then(() => {
                                window.location.href = baseUrl + '/auth/login?logout_reason=session_invalid';
                            });
                            return;
                        }

                        // Real-time role synchronization
                        if (data && data.success && data.role && data.role !== currentRole) {
                            const oldRole = currentRole;
                            currentRole = data.role;

                            // Update body data-role attribute
                            document.body.setAttribute('data-role', currentRole);

                            // Update sidebar user info role text
                            const sidebarUserSmall = document.querySelector('.sidebar-user-info small');
                            if (sidebarUserSmall) {
                                sidebarUserSmall.textContent = currentRole;
                            }

                            // Update sidebar Administrasi section
                            const sidebarNav = document.querySelector('.sidebar-nav');
                            if (sidebarNav) {
                                // Remove all existing admin section elements
                                sidebarNav.querySelectorAll('[data-admin-section="true"]').forEach(el => el.remove());

                                const isNowAdmin = adminRoles.includes(currentRole);
                                if (isNowAdmin) {
                                    const calendarLink = sidebarNav.querySelector('a[href*="/calendar/"]');
                                    if (calendarLink) {
                                        const adminHeader = document.createElement('div');
                                        adminHeader.className = 'sidebar-section';
                                        adminHeader.setAttribute('data-admin-section', 'true');
                                        adminHeader.textContent = 'Administrasi';

                                        const adminLink = document.createElement('a');
                                        adminLink.href = baseUrl + '/admin/';
                                        adminLink.className = 'sidebar-link';
                                        adminLink.setAttribute('data-nav', 'admin');
                                        adminLink.setAttribute('data-admin-section', 'true');
                                        adminLink.innerHTML = '<i class="fas fa-user-shield"></i><span>Kelola Pengguna</span>';

                                        calendarLink.insertAdjacentElement('afterend', adminHeader);
                                        adminHeader.insertAdjacentElement('afterend', adminLink);

                                        adminLink.addEventListener('click', () => { if (window.innerWidth < 992) closeSidebar(); });

                                        if (currentRole === 'superadmin') {
                                            const settingsLink = document.createElement('a');
                                            settingsLink.href = baseUrl + '/auth/pengaturan#pengaturan-situs';
                                            settingsLink.className = 'sidebar-link';
                                            settingsLink.setAttribute('data-nav', 'pengaturan-situs');
                                            settingsLink.setAttribute('data-admin-section', 'true');
                                            settingsLink.innerHTML = '<i class="fas fa-sliders"></i><span>Pengaturan Situs</span>';

                                            const clearChatLink = document.createElement('a');
                                            clearChatLink.href = baseUrl + '/auth/pengaturan#kelola-pesan-grup';
                                            clearChatLink.className = 'sidebar-link';
                                            clearChatLink.setAttribute('data-nav', 'kelola-pesan-grup');
                                            clearChatLink.setAttribute('data-admin-section', 'true');
                                            clearChatLink.innerHTML = '<i class="fas fa-comments"></i><span>Kelola Pesan Grup</span>';

                                            adminLink.insertAdjacentElement('afterend', settingsLink);
                                            settingsLink.insertAdjacentElement('afterend', clearChatLink);

                                            settingsLink.addEventListener('click', () => { if (window.innerWidth < 992) closeSidebar(); });
                                            clearChatLink.addEventListener('click', () => { if (window.innerWidth < 992) closeSidebar(); });
                                        }
                                    }
                                }
                            }

                            if (!adminRoles.includes(currentRole) && adminRoles.includes(oldRole) && window.location.pathname.indexOf('/admin') !== -1) {
                                    Swal.fire({
                                        title: 'Akses Dicabut',
                                        text: 'Role Anda telah diubah menjadi "' + currentRole + '". Anda tidak lagi memiliki akses ke halaman ini.',
                                        icon: 'info',
                                        confirmButtonText: 'OK',
                                        allowOutsideClick: false,
                                        background: '#161b22',
                                        color: '#c9d1d9'
                                    }).then(() => {
                                        window.location.href = baseUrl + '/dashboard/';
                                    });
                                    return;
                                }
                            Swal.fire({
                                toast: true,
                                position: 'top-end',
                                icon: 'info',
                                title: 'Role Anda diubah: ' + oldRole + ' → ' + currentRole,
                                showConfirmButton: false,
                                timer: 4000,
                                timerProgressBar: true,
                                background: '#161b22',
                                color: '#c9d1d9'
                            });
                        }
                    })
                    .catch(err => {
                        console.error('Heartbeat failure:', err);
                    });
            }

            // Start periodic heartbeat (only while page is focused)
            function startHeartbeat() {
                if (heartbeatInterval) return; // already running
                sendHeartbeat('online');
                heartbeatInterval = setInterval(() => sendHeartbeat('online'), 5000);
            }

            // Stop periodic heartbeat and send offline status
            function stopHeartbeat() {
                if (heartbeatInterval) {
                    clearInterval(heartbeatInterval);
                    heartbeatInterval = null;
                }
                sendHeartbeat('offline');
            }

            // Focus/Blur events — window level
            window.addEventListener('focus', startHeartbeat);
            window.addEventListener('blur', stopHeartbeat);

            // Visibility change — handles tab switching more reliably
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') {
                    startHeartbeat();
                } else {
                    stopHeartbeat();
                }
            });

            // Before unload — mark offline when closing/navigating away
            window.addEventListener('beforeunload', () => {
                // Use sendBeacon for reliable delivery on page close
                const fd = new FormData();
                fd.append('status', 'offline');
                fd.append('_csrf_token', document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
                navigator.sendBeacon(baseUrl + '/auth/api_heartbeat.php', fd);
            });

            // Initial state — start heartbeat if page is currently focused
            if (document.visibilityState === 'visible' && document.hasFocus()) {
                startHeartbeat();
            } else {
                // Page loaded but not focused (e.g. opened in background tab)
                sendHeartbeat('offline');
            }
        })();
    </script>
    <script nonce="<?= $cspNonce ?>" src="<?= $base_url ?>/assets/js/tutorial.js?v=<?= assetVersion(__DIR__ . '/../assets/js/tutorial.js') ?>"></script>
</body>
</html>
