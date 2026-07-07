// assets/js/tutorial.js

function startTutorial() {
    const userRole = (document.body.getAttribute('data-role') || '').trim().toLowerCase();
    const isSuperAdmin = userRole === 'superadmin';
    const isAdminRole = ['admin', 'operator', 'superadmin'].includes(userRole);
    const isOnPengaturan = window.location.pathname.includes('/auth/pengaturan');

    const steps = [
        {
            title: "🎓 Selamat Datang di Big Family ITS 26!",
            text: "Ini adalah platform kolaborasi akademik terpadu untuk kelas kita. Ikuti panduan singkat ini agar Anda paham cara menggunakan seluruh fitur yang tersedia!",
            selector: null
        },
        {
            title: "🏠 Dashboard Beranda",
            text: "Halaman Beranda menampilkan ringkasan agenda terbaru, status member kelas yang sedang aktif/online, pesan disematkan (pinned msg), dan data statistik Anda.",
            selector: '#sidebar .sidebar-link[data-nav="dashboard"]'
        },
        {
            title: "📝 Catatan Pribadi (MS Word Style)",
            text: "Tulis materi kuliah atau catatan harian Anda dengan editor premium layaknya Microsoft Word. Anda bisa memposisikan gambar secara fleksibel, menyimpannya secara manual, atau mengekspor catatan langsung ke Drive Bersama kelas.",
            selector: '#sidebar .sidebar-link[data-nav="notes"]'
        },
        {
            title: "💬 Pesan Grup Kelas",
            text: isSuperAdmin
                ? "Berkomunikasi real-time dengan seluruh teman kelas. Sebagai Superadmin, Anda dapat menghapus pesan siapa pun — dengan tanda 'pesan dihapus' atau hapus permanen tanpa jejak."
                : "Berkomunikasi real-time dengan seluruh teman kelas. Anda bisa menyematkan pesan penting (pin), membuat polling jawaban, mengirim file lampiran, dan menghapus pesan Anda sendiri.",
            selector: '#sidebar .sidebar-link[data-nav="msg"]'
        },
        {
            title: "📂 Drive Bersama & Preview",
            text: "Tempat berbagi materi kuliah, PDF, Word (.doc/.docx), atau file tugas kelas. Anda dapat melihat pratinjau gambar, PDF, Word, video, audio, dan catatan HTML langsung di browser tanpa mendownload terlebih dahulu.",
            selector: '#sidebar .sidebar-link[data-nav="drive"]'
        },
        {
            title: "📅 Kalender Akademik 2-in-1",
            text: "Kalender pintar yang memadukan agenda pribadi Anda dengan Jadwal Server (akumulasi agenda penting yang diinput oleh semua teman sekelas) agar selalu up-to-date.",
            selector: '#sidebar .sidebar-link[data-nav="calendar"]'
        }
    ];

    if (isAdminRole) {
        steps.push({
            title: "🛡️ Kelola Pengguna",
            text: isSuperAdmin
                ? "Panel administrasi lengkap untuk mengelola akun pengguna, role, dan kredensial. Superadmin memiliki akses penuh termasuk membuat akun superadmin lain."
                : "Panel administrasi untuk mengelola akun pengguna dan role anggota kelas.",
            selector: '#sidebar .sidebar-link[data-nav="admin"]'
        });
    }

    if (isSuperAdmin) {
        steps.push({
            title: "⚙️ Pengaturan Situs",
            text: "Kelola pengaturan judul website dan deskripsi global untuk platform Big Family ITS 26.",
            selector: isOnPengaturan ? '#pengaturan-situs' : '#sidebar .sidebar-link[data-nav="pengaturan-situs"]'
        });
    }

    steps.push({
        title: isSuperAdmin ? "⚙️ Pengaturan Profil" : "⚙️ Pengaturan Profil & Dark Mode",
        text: isSuperAdmin
            ? "Perbarui profil Anda, ubah password, atau unggah foto profil baru."
            : "Di sini Anda dapat memperbarui nama lengkap, mengubah password akun, atau mengunggah foto profil baru dengan editor pemotongan (crop) bertema gelap (dark mode) sepenuhnya.",
        selector: '#sidebar .sidebar-link[data-nav="pengaturan"]'
    });

    if (isSuperAdmin) {
        steps.push({
            title: "🗑️ Kelola Pesan Grup",
            text: "Sebagai Superadmin, Anda dapat membersihkan chat grup yang tidak perlu. Pilih pesan tertentu atau kosongkan semua — dengan 2 opsi: tandai 'Pesan ini telah dihapus' atau hapus permanen tanpa jejak.",
            selector: isOnPengaturan ? '#kelola-pesan-grup' : '#sidebar .sidebar-link[data-nav="kelola-pesan-grup"]'
        });
    }

    let currentStep = 0;
    const sidebar = document.getElementById('sidebar');
    const sidebarNav = sidebar?.querySelector('.sidebar-nav');

    let backdrop = document.getElementById('tutorial-backdrop');
    if (!backdrop) {
        backdrop = document.createElement('div');
        backdrop.id = 'tutorial-backdrop';
        backdrop.style.cssText = `
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 99999;
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        `;
        document.body.appendChild(backdrop);
    }

    let modal = document.getElementById('tutorial-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'tutorial-modal';
        modal.style.cssText = `
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.9);
            background: #161b22;
            border: 1px solid #30363d;
            border-radius: 16px;
            padding: 2rem;
            width: 90%;
            max-width: 440px;
            z-index: 100000;
            box-shadow: 0 15px 40px rgba(0,0,0,0.6);
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            pointer-events: none;
            color: #c9d1d9;
        `;
        document.body.appendChild(modal);
    }

    let highlightRing = document.getElementById('tutorial-highlight-ring');
    if (!highlightRing) {
        highlightRing = document.createElement('div');
        highlightRing.id = 'tutorial-highlight-ring';
        document.body.appendChild(highlightRing);
    }

    if (sidebar) {
        sidebar.dataset.tutorialPrevZIndex = sidebar.style.zIndex || '';
        sidebar.style.zIndex = '100002';
    }

    document.body.classList.add('tutorial-active');
    backdrop.style.opacity = '1';
    backdrop.style.pointerEvents = 'auto';

    modal.style.opacity = '1';
    modal.style.transform = 'translate(-50%, -50%) scale(1)';
    modal.style.pointerEvents = 'auto';

    function clearHighlights() {
        document.querySelectorAll('.tutorial-highlight').forEach(el => {
            el.classList.remove('tutorial-highlight');
        });
        if (highlightRing) {
            highlightRing.style.display = 'none';
        }
    }

    function positionHighlightRing(target) {
        if (!highlightRing || !target) return;
        target.classList.add('tutorial-highlight');
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });

        const updateRing = () => {
            const rect = target.getBoundingClientRect();
            highlightRing.style.display = 'block';
            highlightRing.style.top = `${rect.top - 4}px`;
            highlightRing.style.left = `${rect.left - 4}px`;
            highlightRing.style.width = `${rect.width + 8}px`;
            highlightRing.style.height = `${rect.height + 8}px`;
        };

        updateRing();
        requestAnimationFrame(updateRing);
    }

    function renderStep() {
        const step = steps[currentStep];
        clearHighlights();

        if (step.selector) {
            const target = document.querySelector(step.selector);
            if (target) {
                positionHighlightRing(target);
            }
        }

        modal.innerHTML = `
            <div class="tutorial-header">
                <span class="tutorial-step-label">Panduan Onboarding (${currentStep + 1}/${steps.length})</span>
                <button type="button" onclick="closeTutorial()" class="tutorial-close-btn" aria-label="Tutup"><i class="fas fa-times"></i></button>
            </div>
            <h3 class="tutorial-title">${step.title}</h3>
            <p class="tutorial-text">${step.text}</p>
            <div class="tutorial-footer">
                <button type="button" onclick="closeTutorial()" class="btn btn-secondary tutorial-btn-skip">Lewati</button>
                <div class="tutorial-footer-nav">
                    ${currentStep > 0 ? `<button type="button" onclick="prevTutorialStep()" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Kembali</button>` : ''}
                    <button type="button" onclick="nextTutorialStep()" class="btn btn-primary">
                        ${currentStep === steps.length - 1 ? 'Mulai! 🎉' : 'Lanjut <i class="fas fa-arrow-right"></i>'}
                    </button>
                </div>
            </div>
        `;
    }

    window.nextTutorialStep = function() {
        if (currentStep < steps.length - 1) {
            currentStep++;
            renderStep();
        } else {
            closeTutorial();
        }
    };

    window.prevTutorialStep = function() {
        if (currentStep > 0) {
            currentStep--;
            renderStep();
        }
    };

    window.closeTutorial = function() {
        backdrop.style.opacity = '0';
        backdrop.style.pointerEvents = 'none';
        modal.style.opacity = '0';
        modal.style.transform = 'translate(-50%, -50%) scale(0.9)';
        modal.style.pointerEvents = 'none';

        clearHighlights();
        document.body.classList.remove('tutorial-active');

        if (sidebar) {
            sidebar.style.zIndex = sidebar.dataset.tutorialPrevZIndex || '';
            delete sidebar.dataset.tutorialPrevZIndex;
        }

        localStorage.setItem('tutorial-finished', '1');
    };

    window.addEventListener('resize', () => {
        const activeTarget = document.querySelector('.tutorial-highlight');
        if (activeTarget && highlightRing) {
            const rect = activeTarget.getBoundingClientRect();
            highlightRing.style.top = `${rect.top - 4}px`;
            highlightRing.style.left = `${rect.left - 4}px`;
            highlightRing.style.width = `${rect.width + 8}px`;
            highlightRing.style.height = `${rect.height + 8}px`;
        }
    });

    renderStep();
}

// Auto start tutorial on dashboard if first time
document.addEventListener('DOMContentLoaded', () => {
    const pageAttr = document.body.getAttribute('data-page');
    if (pageAttr === 'index' && window.location.pathname.includes('/dashboard')) {
        if (localStorage.getItem('tutorial-finished') !== '1') {
            setTimeout(startTutorial, 1500);
        }
    }
});
