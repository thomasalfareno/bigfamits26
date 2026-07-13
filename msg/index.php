<?php

require_once __DIR__ . '/../template/header.php';
?>

<div class="msg-layout" style="position: relative;">
    
    <div id="pinnedBar" class="msg-pinned-bar" style="display:none;">
        <div class="pinned-bar-header">
            <i class="fas fa-thumbtack text-gold"></i>
            <span>Pesan Dipin</span>
        </div>
        <div class="pinned-bar-slider" id="pinnedItemsContainer">
            
        </div>
    </div>

    
    <div class="msg-messages" id="msgMessagesContainer">
        
    </div>

    
    <div class="msg-reply-bar" id="msgReplyBar" style="display: none;">
        <div class="reply-bar-text">
            <i class="fas fa-reply text-accent" style="margin-right: 6px;"></i>
            Membalas <span id="replyTargetSender" style="font-weight:600; color:#fff"></span>: 
            <span id="replyTargetPreview" style="font-style:italic"></span>
        </div>
        <button class="reply-bar-close" onclick="cancelReply()"><i class="fas fa-times"></i></button>
    </div>

    
    <div class="msg-reply-bar" id="msgEditBar" style="display: none; background-color: rgba(240, 171, 0, 0.15)">
        <div class="reply-bar-text" style="color: var(--gold-color)">
            <i class="fas fa-pencil-alt" style="margin-right: 6px;"></i>
            Mengedit pesan: <span id="editTargetPreview" style="font-style:italic; color:#fff"></span>
        </div>
        <button class="reply-bar-close" onclick="cancelEdit()" style="color: var(--gold-color)"><i class="fas fa-times"></i></button>
    </div>

    
    <div id="mentionSuggestions" class="msg-mention-suggestions"></div>

    
    <div class="msg-input-area">
        <button class="msg-btn-file" onclick="triggerFileInput()" title="Kirim File/Gambar"><i class="fas fa-paperclip"></i></button>
        <button class="msg-btn-file" onclick="openPollModal()" title="Buat Polling Baru" style="color:var(--success-color)"><i class="fas fa-chart-simple"></i></button>
        
        <form id="msgForm" onsubmit="handleMsgSubmit(event)" style="display:flex; flex:1; gap:10px">
            <input type="hidden" name="reply_to" id="msgReplyToInput" value="">
            <input type="text" class="form-control" name="pesan" id="msgTextInput" placeholder="Ketik pesan..." required autocomplete="off">
            <button type="submit" class="btn btn-primary btn-msg-send" title="Kirim" id="msgSendBtn"><i class="fas fa-paper-plane"></i></button>
        </form>
    </div>
</div>


<form id="msgFileForm" style="display: none;">
    <input type="file" name="msg_file" id="msgFileInputField" multiple onchange="showUploadPreview()">
    <input type="hidden" name="reply_to" id="msgFileReplyToInput" value="">
</form>


<div class="modal" id="msgUploadPreviewModal">
    <div class="modal-dialog" style="max-width: 500px; background:#0d1117; max-height: 90vh; display: flex; flex-direction: column;">
        <div class="modal-header" style="border-bottom:none; flex-shrink:0">
            <h3 style="font-size:1.1rem; font-weight:600"><i class="fas fa-cloud-arrow-up" style="margin-right:6px"></i> Kirim File <span id="msgUploadCountBadge" class="badge badge-info" style="font-size:0.75rem; margin-left:6px"></span></h3>
            <button class="modal-close" onclick="cancelUploadPreview()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" style="padding:1.25rem; overflow-y: auto; flex: 1; min-height: 0;">
            <div id="msgUploadFileListContainer" style="display:flex; flex-direction:column; gap:8px">
                
            </div>
            <div class="form-group" style="margin-top:12px; margin-bottom:0; flex-shrink:0">
                <input type="text" id="uploadPreviewCaptionInput" class="form-control" placeholder="Tambahkan pesan (opsional, berlaku untuk file pertama)..." style="background:#161b22; border-color:#30363d; color:#fff" autocomplete="off">
            </div>
        </div>
        <div class="modal-footer" style="border-top:none; display:flex; justify-content:flex-end; gap:10px; flex-shrink:0">
            <button class="btn btn-secondary" onclick="cancelUploadPreview()">Batal</button>
            <button class="btn btn-primary" id="btnSendAllMsgFiles" onclick="proceedUploadMsgFile()"><i class="fas fa-paper-plane"></i> Kirim Semua</button>
        </div>
    </div>
</div>


<div class="modal" id="mediaPreviewModal">
    <div class="modal-dialog" style="max-width: 700px; background:#0d1117">
        <div class="modal-header" style="border-bottom:none">
            <h3 id="mediaPreviewTitle" style="font-size:0.95rem; font-weight:500">Pratinjau File</h3>
            <button class="modal-close" onclick="closeMediaPreview()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" style="text-align:center; padding:1.5rem">
            <div id="mediaPreviewContent" style="display:flex; justify-content:center; align-items:center; min-height:200px">
                
            </div>
        </div>
        <div class="modal-footer" style="border-top:none">
            <a href="" id="mediaDownloadBtn" download class="btn btn-primary"><i class="fas fa-download"></i> Download File</a>
            <button class="btn btn-secondary" onclick="closeMediaPreview()">Tutup</button>
        </div>
    </div>
</div>


<div class="modal" id="msgPollModal">
    <div class="modal-dialog" style="max-width: 450px; max-height: 90vh; display: flex; flex-direction: column;">
        <div class="modal-header">
            <h3>Buat Polling Baru</h3>
            <button class="modal-close" onclick="closePollModal()"><i class="fas fa-times"></i></button>
        </div>
        <form onsubmit="submitMsgPoll(event)" style="display: flex; flex-direction: column; overflow: hidden; height: 100%;">
            <div class="modal-body" style="overflow-y: auto; flex: 1; min-height: 0;">
                <div class="form-group" style="margin-bottom:1rem">
                    <label style="color:var(--text-muted); display:block; font-size:0.85rem; margin-bottom:0.4rem">Pertanyaan Polling</label>
                    <input type="text" id="pollQuestionInput" class="form-control" placeholder="Tulis pertanyaan..." required>
                </div>

                <div class="form-group" style="margin-bottom:1rem">
                    <label style="color:var(--text-muted); display:block; font-size:0.85rem; margin-bottom:0.4rem">Lampiran Media Pertanyaan (Gambar / Video / GIF) - Opsional</label>
                    <div style="display:flex; flex-direction:column; gap:6px; background:rgba(255,255,255,0.02); padding:8px; border-radius:8px; border:1px solid var(--border-color)">
                        <div style="display:flex; gap:8px; align-items:center; justify-content:space-between">
                            <span style="color:var(--text-muted); font-size:0.82rem">Pilih Media Lampiran...</span>
                            <label class="btn btn-secondary" style="padding:0; width:36px; height:36px; display:inline-flex; align-items:center; justify-content:center; margin:0; cursor:pointer" title="Tambah Media Pertanyaan">
                                <i class="fas fa-image"></i>
                                <input type="file" id="pollMediaInput" accept="image/*,video/*" style="display:none">
                            </label>
                        </div>
                        <div id="pollMediaPreviewContainer" style="display:none; margin-top:6px; text-align:center; position:relative">
                            
                        </div>
                    </div>
                </div>
                
                <div id="msgPollOptionsContainer">
                    <label style="color:var(--text-muted); display:block; font-size:0.85rem; margin-bottom:0.4rem">Pilihan Jawaban (Gambar Opsional)</label>
                    
                    
                    <div style="display:flex; flex-direction:column; gap:6px; margin-bottom:12px; background:rgba(255,255,255,0.02); padding:8px; border-radius:8px; border:1px solid var(--border-color)">
                        <div style="display:flex; gap:8px; align-items:center">
                            <input type="text" name="opsi[]" class="form-control" placeholder="Pilihan 1" required style="flex:1">
                            <label class="btn btn-secondary" style="padding:0; width:36px; height:36px; display:inline-flex; align-items:center; justify-content:center; margin:0; cursor:pointer" title="Tambah Gambar Opsi">
                                <i class="fas fa-image"></i>
                                <input type="file" name="opsi_gambar[]" accept="image/*" style="display:none" onchange="previewOptionImage(this)">
                            </label>
                        </div>
                        <div class="option-image-preview" style="display:none; margin-top:6px; text-align:center; position:relative">
                            <img src="" style="max-height:80px; border-radius:6px; object-fit:contain">
                            <button type="button" class="btn" style="position:absolute; top:2px; right:2px; padding:2px 6px; font-size:0.7rem; background:rgba(248,81,73,0.8); color:#fff; border:none; border-radius:4px" onclick="removeOptionImage(this)">Hapus</button>
                        </div>
                    </div>

                    
                    <div style="display:flex; flex-direction:column; gap:6px; margin-bottom:12px; background:rgba(255,255,255,0.02); padding:8px; border-radius:8px; border:1px solid var(--border-color)">
                        <div style="display:flex; gap:8px; align-items:center">
                            <input type="text" name="opsi[]" class="form-control" placeholder="Pilihan 2" required style="flex:1">
                            <label class="btn btn-secondary" style="padding:0; width:36px; height:36px; display:inline-flex; align-items:center; justify-content:center; margin:0; cursor:pointer" title="Tambah Gambar Opsi">
                                <i class="fas fa-image"></i>
                                <input type="file" name="opsi_gambar[]" accept="image/*" style="display:none" onchange="previewOptionImage(this)">
                            </label>
                        </div>
                        <div class="option-image-preview" style="display:none; margin-top:6px; text-align:center; position:relative">
                            <img src="" style="max-height:80px; border-radius:6px; object-fit:contain">
                            <button type="button" class="btn" style="position:absolute; top:2px; right:2px; padding:2px 6px; font-size:0.7rem; background:rgba(248,81,73,0.8); color:#fff; border:none; border-radius:4px" onclick="removeOptionImage(this)">Hapus</button>
                        </div>
                    </div>
                </div>
                
                <button type="button" class="btn btn-secondary" style="padding:0.4rem 0.8rem; font-size:0.8rem; margin-top:0.25rem" onclick="addMsgPollOption()"><i class="fas fa-plus"></i> Tambah Opsi</button>

                <div class="form-group" style="margin-top:1.25rem; margin-bottom:0">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.85rem; color:#fff">
                        <input type="checkbox" id="pollMultipleInput" name="tipe" value="multiple" style="width: 16px; height: 16px;">
                        <span>Pilihan Ganda (Bisa memilih lebih dari satu opsi)</span>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePollModal()">Batal</button>
                <button type="submit" class="btn btn-primary">Kirim Polling</button>
            </div>
        </form>
    </div>
</div>


<div class="modal" id="msgReadDetailsModal">
    <div class="modal-dialog" style="max-width: 400px; background:#0d1117">
        <div class="modal-header">
            <h3>Info Detail Pesan</h3>
            <button class="modal-close" onclick="closeReadDetailsModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" style="padding:1.25rem; max-height:350px; overflow-y:auto">
            <h4 style="font-size:0.8rem; text-transform:uppercase; color:var(--text-muted); margin-bottom:10px; font-weight:600">Dibaca Oleh:</h4>
            <ul id="msgReadDetailsList" class="msg-detail-list">
                
            </ul>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeReadDetailsModal()">Tutup</button>
        </div>
    </div>
</div>


<div id="mentionProfileOverlay" class="mention-profile-overlay" style="display:none;" onclick="closeMentionProfile()">
    <div class="mention-profile-card" onclick="event.stopPropagation()">
        <button class="mention-profile-close" onclick="closeMentionProfile()"><i class="fas fa-times"></i></button>
        <img id="mentionProfileAvatar" src="" class="mention-profile-avatar">
        <h3 id="mentionProfileName" class="mention-profile-name"></h3>
        <p id="mentionProfileUsername" class="mention-profile-username"></p>
        <span id="mentionProfileRole" class="mention-profile-role"></span>
        <p id="mentionProfileJoined" class="mention-profile-joined"></p>
        <div class="mention-profile-action">
            <button onclick="fillInputMentionFromCard()">Sebut Pengguna</button>
        </div>
    </div>
</div>

<script nonce="<?= $cspNonce ?>">
    let lastMsgId = 0;
    let autoScroll = true;
    let totalUsersCount = 0;
    let editingMessageId = null;
    let groupUsers = []; 
    let activeSuggestionIndex = -1;

    const currentUserId = <?= (int)$_SESSION['id_user'] ?>;
    const currentUserNama = <?= json_encode($_SESSION['nama'] ?? 'Saya') ?>;
    const isAdminOrOperator = <?= in_array($_SESSION['role'] ?? '', ['admin', 'operator', 'superadmin']) ? 'true' : 'false' ?>;
    const isSuperAdmin = <?= ($_SESSION['role'] ?? '') === 'superadmin' ? 'true' : 'false' ?>;

    let lastActivityTime = Date.now();
    let pollInterval = 2500; // Fast 2.5 seconds when active
    let pollTimer = null;
    let consecutiveErrors = 0;
    let groupUsersLoaded = false;

    // Track user activity to determine idle state
    function updateActivity() {
        lastActivityTime = Date.now();
        if (pollInterval > 2500) {
            pollInterval = 2500;
            schedulePoll();
        }
    }
    window.addEventListener('mousemove', updateActivity);
    window.addEventListener('keydown', updateActivity);
    window.addEventListener('scroll', updateActivity, true);

    function schedulePoll() {
        if (pollTimer) clearTimeout(pollTimer);
        
        // If user is idle for more than 1 minute, slow down the polling
        const idleDuration = Date.now() - lastActivityTime;
        let currentInterval = pollInterval;
        if (idleDuration > 60000) {
            currentInterval = 10000; // Slow down to 10 seconds
        }
        
        pollTimer = setTimeout(() => {
            if (!document.hidden) {
                loadMessages();
                loadPinnedMessages();
            }
            schedulePoll();
        }, currentInterval);
    }

    // Pause/resume polling when tab visibility changes
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            // Reset activity on show
            updateActivity();
            loadMessages();
            loadPinnedMessages();
            schedulePoll();
        }
    });

    document.addEventListener('DOMContentLoaded', async () => {
        
        await fetchGroupUsers();
        loadMessages();
        loadPinnedMessages();
        schedulePoll();
        
        const container = document.getElementById('msgMessagesContainer');
        container.addEventListener('scroll', () => {
            if (container.scrollTop + container.clientHeight < container.scrollHeight - 100) {
                autoScroll = false;
            } else {
                autoScroll = true;
            }
        });

        document.addEventListener('click', () => {
            document.querySelectorAll('.msg-bubble-dropdown-menu.show').forEach(m => m.classList.remove('show'));
        });

        const msgInput = document.getElementById('msgTextInput');
        msgInput.addEventListener('input', handleInputMentions);
        msgInput.addEventListener('keydown', handleKeydownMentions);
    });

    
    async function safeFetch(url, options) {
        try {
            const res = await fetch(url, options);
            if (res.status === 403) {
                consecutiveErrors++;
                // Back off aggressively on 403 to avoid InfinityFree ban
                pollInterval = Math.min(60000, 15000 * Math.pow(1.5, consecutiveErrors));
                console.warn(`[Msg] 403 rate limited. Backing off to ${pollInterval/1000}s (error #${consecutiveErrors})`);
                return { success: false, message: 'Rate limited (403)' };
            }
            const text = await res.text();
            try {
                const json = JSON.parse(text);
                
                if (consecutiveErrors > 0) {
                    consecutiveErrors = 0;
                    pollInterval = 2500;
                }
                return json;
            } catch (e) {
                consecutiveErrors++;
                pollInterval = Math.min(60000, 15000 * Math.pow(1.5, consecutiveErrors));
                return { success: false, message: 'Server returned non-JSON response' };
            }
        } catch (e) {
            consecutiveErrors++;
            pollInterval = Math.min(60000, 15000 * Math.pow(1.5, consecutiveErrors));
            return { success: false, message: 'Network error' };
        }
    }

    async function fetchGroupUsers() {
        const formData = new FormData();
        formData.append('action', 'get_users');
        const data = await safeFetch('api_msg.php', { method: 'POST', body: formData });
        if (data.success && data.users) {
            groupUsers = data.users;
            groupUsersLoaded = true;
        }
    }

    
    function toggleBubbleDropdown(e, id) {
        e.stopPropagation();
        
        document.querySelectorAll('.msg-bubble-dropdown-menu.show').forEach(m => {
            if (m.id !== 'dropdown-' + id) m.classList.remove('show');
        });
        const menu = document.getElementById('dropdown-' + id);
        if (menu) menu.classList.toggle('show');
    }

    function updateReplyPreviewsInDOM(id, text) {
        const targets = document.querySelectorAll(`[data-reply-to="${id}"]`);
        targets.forEach(target => {
            const textEl = target.querySelector('.reply-preview-text');
            if (textEl) {
                textEl.innerText = text || '[Lampiran]';
            }
        });
    }

    function markReplyAsPermanentlyDeleted(id) {
        const targets = document.querySelectorAll(`[data-reply-to="${id}"]`);
        targets.forEach(target => {
            target.style.opacity = '0.6';
            target.style.cursor = 'default';
            target.removeAttribute('onclick');
            target.innerHTML = `<div class="reply-preview-text" style="font-size:0.75rem; font-style:italic; color:var(--text-muted)">🚫 Pesan ini dihapus permanen</div>`;
        });
    }

    function loadMessages() {
        const formData = new FormData();
        formData.append('action', 'get');
        formData.append('last_id', lastMsgId);

        safeFetch('api_msg.php', {
            method: 'POST',
            body: formData
        })
            .then(data => {
                if (data.success && data.messages) {
                    totalUsersCount = data.total_users || 0;
                    const container = document.getElementById('msgMessagesContainer');
                    let newMsgsLoaded = false;
                    let maxIdFetched = 0;

                    
                    window._messageStateCache = window._messageStateCache || {};

                    const returnedIds = new Set();
                    let minId = Infinity;
                    let maxId = -Infinity;

                    data.messages.forEach(msg => {
                        returnedIds.add(parseInt(msg.id_msg));
                        if (parseInt(msg.id_msg) < minId) minId = parseInt(msg.id_msg);
                        if (parseInt(msg.id_msg) > maxId) maxId = parseInt(msg.id_msg);

                        // Real-time update for reply previews pointing to this message
                        let previewText = msg.pesan;
                        if (!previewText) {
                            previewText = msg.tipe === 'image' ? '[Gambar]' : (msg.tipe === 'file' ? '[File]' : '[Lampiran]');
                        }
                        updateReplyPreviewsInDOM(msg.id_msg, previewText);

                        const existing = document.getElementById('msg-msg-' + msg.id_msg);
                        const isMe = msg.id_user == currentUserId;
                        
                        
                        const currentHash = JSON.stringify({
                            pesan: msg.pesan,
                            pinned: msg.pinned,
                            edited: msg.edited,
                            read_count: msg.read_count,
                            reply_preview: msg.reply_preview || '',
                            reply_sender: msg.reply_sender || '',
                            votes: msg.poll_options ? msg.poll_options.map(o => o.votes_count).join(',') : '',
                            my_votes: msg.my_votes ? msg.my_votes.join(',') : ''
                        });

                        if (existing) {
                            
                            if (window._messageStateCache[msg.id_msg] !== currentHash) {
                                const updatedHTML = buildMessageBubble(msg, isMe);
                                existing.outerHTML = updatedHTML;
                                window._messageStateCache[msg.id_msg] = currentHash;
                            }
                        } else {
                            
                            const bubbleHTML = buildMessageBubble(msg, isMe);
                            container.insertAdjacentHTML('beforeend', bubbleHTML);
                            window._messageStateCache[msg.id_msg] = currentHash;
                            newMsgsLoaded = true;
                        }
                        
                        lastMsgId = Math.max(lastMsgId, msg.id_msg);
                        maxIdFetched = Math.max(maxIdFetched, msg.id_msg);
                    });

                    // Reconcile deleted messages within the returned ID range
                    const allBubbles = document.querySelectorAll('.msg-message-row');
                    if (allBubbles.length > 0) {
                        if (data.messages && data.messages.length > 0) {
                            allBubbles.forEach(bubble => {
                                const bubbleId = parseInt(bubble.id.replace('msg-msg-', ''));
                                if (bubbleId >= minId && bubbleId <= maxId) {
                                    if (!returnedIds.has(bubbleId)) {
                                        bubble.remove();
                                        markReplyAsPermanentlyDeleted(bubbleId);
                                    }
                                }
                            });
                        } else {
                            // If the group chat was completely cleared (0 messages returned)
                            allBubbles.forEach(bubble => {
                                bubble.remove();
                                const bubbleId = parseInt(bubble.id.replace('msg-msg-', ''));
                                markReplyAsPermanentlyDeleted(bubbleId);
                            });
                            lastMsgId = 0;
                            window._messageStateCache = {};
                        }
                    }
                    
                    if (newMsgsLoaded && autoScroll) {
                        scrollToBottom();
                    }

                    if (maxIdFetched > 0) {
                        markMessagesAsRead(maxIdFetched);
                    }
                }
            });
    }

    function markMessagesAsRead(lastId) {
        const formData = new FormData();
        formData.append('action', 'mark_read');
        formData.append('last_id', lastId);
        fetch('api_msg.php', {
            method: 'POST',
            body: formData
        });
    }

    
    function highlightMentions(text) {
        if (!text) return '';
        let formatted = escapeHTML(text);
        
        groupUsers.forEach(user => {
            const mentionPatterns = ['@' + user.nama, '@' + user.username];
            mentionPatterns.forEach(pattern => {
                const escaped = pattern.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&');
                const regex = new RegExp('(' + escaped + ')\\b', 'g');
                const safeName = escapeHTML(user.nama).replace(/'/g, "\\'");
                formatted = formatted.replace(regex, `<span class="msg-mention" onclick="event.stopPropagation(); showMentionProfile('${safeName}')">$1</span>`);
            });
        });
        
        return formatted;
    }

    function fillInputMention(name) {
        const input = document.getElementById('msgTextInput');
        input.value = '@' + name + ' ' + input.value;
        input.focus();
    }

    function buildMessageBubble(msg, isMe) {
        const avatar = msg.foto_profil 
            ? '../uploads/profil/' + msg.foto_profil 
            : 'https://ui-avatars.com/api/?name=' + encodeURIComponent(msg.nama) + '&background=1f6feb&color=fff&size=80';
        
        const uploadBase = '../uploads/msg/';
        
        let contentHTML = '';
        const hasBeenDeleted = msg.pesan && msg.pesan.includes('Pesan ini telah dihapus');

        if (msg.tipe === 'text') {
            contentHTML = `<div class="msg-bubble-text">${highlightMentions(msg.pesan)}</div>`;
        } else if (msg.tipe === 'image') {
            contentHTML = `
                <div class="msg-bubble-image">
                    <img src="${uploadBase}${msg.file_path}" onerror="this.onerror=null; this.src='../uploads/msg/${msg.file_path}'; this.onerror=function(){ this.src='https://ui-avatars.com/api/?name=Image+Error&background=f85149&color=fff'; };" onclick="previewMedia('${uploadBase}${msg.file_path}', 'image', '${escapeHTML(msg.file_name)}')">
                </div>
                ${msg.pesan ? `<div class="msg-bubble-text" style="margin-top:5px">${highlightMentions(msg.pesan)}</div>` : ''}
            `;
        } else if (msg.tipe === 'file') {
            const ext = msg.file_name.split('.').pop().toLowerCase();
            let mediaType = 'document';
            let mediaIcon = 'fa-file-lines';
            
            if (['mp3', 'wav', 'ogg', 'm4a'].includes(ext)) { mediaType = 'audio'; mediaIcon = 'fa-file-audio'; }
            else if (['mp4', 'webm', 'mov'].includes(ext)) { mediaType = 'video'; mediaIcon = 'fa-file-video'; }
            else if (ext === 'pdf') { mediaType = 'pdf'; mediaIcon = 'fa-file-pdf'; }

            contentHTML = `
                <a href="javascript:void(0)" onclick="previewMedia('${uploadBase}${msg.file_path}', '${mediaType}', '${escapeHTML(msg.file_name)}')" class="msg-bubble-file">
                    <i class="fas ${mediaIcon}"></i> ${escapeHTML(msg.file_name)}
                </a>
                ${msg.pesan ? `<div class="msg-bubble-text" style="margin-top:5px">${highlightMentions(msg.pesan)}</div>` : ''}
            `;
        } else if (msg.tipe === 'poll') {
            let totalVotes = 0;
            if (msg.poll_options) {
                msg.poll_options.forEach(opt => totalVotes += parseInt(opt.votes_count || 0));
            }
            
            const isMultiple = msg.poll_type === 'multiple';
            const choiceBadge = isMultiple 
                ? '<span class="poll-badge-tipe">Pilihan Ganda</span>' 
                : '<span class="poll-badge-tipe single">Pilihan Tunggal</span>';

            let pollHtml = `<div class="msg-bubble-poll-container">`;
            pollHtml += `<div class="poll-question-header">
                            <span><i class="fas fa-square-poll-vertical"></i> ${escapeHTML(msg.pesan)}</span>
                            ${choiceBadge}
                         </div>`;
            
            if (msg.poll_media_path) {
                const mediaUrl = `../uploads/msg/${msg.poll_media_path}`;
                if (msg.poll_media_type === 'image') {
                    pollHtml += `<div class="poll-media-preview-container" style="margin-bottom:12px; border-radius:8px; overflow:hidden; border:1px solid var(--border-color); background:#0d1117; max-height:220px; display:flex; justify-content:center; align-items:center;">
                                    <img src="${mediaUrl}" style="max-width:100%; max-height:220px; object-fit:contain; cursor:pointer;" onclick="openMediaPreview('${mediaUrl}', 'image')">
                                 </div>`;
                } else if (msg.poll_media_type === 'video') {
                    pollHtml += `<div class="poll-media-preview-container" style="margin-bottom:12px; border-radius:8px; overflow:hidden; border:1px solid var(--border-color); background:#0d1117; max-height:220px; display:flex; justify-content:center; align-items:center;">
                                    <video src="${mediaUrl}" controls style="max-width:100%; max-height:220px;"></video>
                                 </div>`;
                }
            }
            
            if (msg.poll_options) {
                msg.poll_options.forEach(opt => {
                    const isChecked = msg.my_votes && msg.my_votes.some(v => v == opt.id_option);
                    const percent = totalVotes > 0 ? Math.round((parseInt(opt.votes_count) / totalVotes) * 100) : 0;
                    
                    
                    const controlClass = isMultiple ? 'poll-custom-checkbox' : 'poll-custom-radio';
                    const activeRowClass = isChecked ? 'msg-poll-option-row voted' : 'msg-poll-option-row';

                    let votersListHTML = `<div class="poll-option-voters-list">`;
                    if (opt.voter_names) {
                        const names = opt.voter_names.split(', ');
                        names.forEach(name => {
                            votersListHTML += `<span class="poll-voter-chip" data-voter-name="${escapeHTML(name)}" title="${escapeHTML(name)}"><i class="fas fa-user" style="font-size:0.55rem;"></i> ${escapeHTML(name)}</span>`;
                        });
                    }
                    votersListHTML += `</div>`;

                    let optionImgHTML = '';
                    if (opt.gambar) {
                        const optImgUrl = `../uploads/msg/${opt.gambar}`;
                        optionImgHTML = `<img src="${optImgUrl}" style="width:36px; height:36px; object-fit:cover; border-radius:4px; border:1px solid var(--border-color); cursor:pointer; flex-shrink:0" onclick="event.stopPropagation(); openMediaPreview('${optImgUrl}', 'image')">`;
                    }

                    pollHtml += `
                        <div class="${activeRowClass}" data-option-id="${opt.id_option}" data-votes="${opt.votes_count}" onclick="event.stopPropagation(); voteMsgPoll(${msg.id_poll}, ${opt.id_option})">
                            <div class="msg-poll-option-bar" style="width: ${percent}%"></div>
                            <div class="msg-poll-option-content" style="display:flex; align-items:center; justify-content:space-between; gap:10px">
                                <label style="margin:0; display:flex; align-items:center; gap:10px; cursor:pointer; flex:1; min-width:0">
                                    <div class="${controlClass} ${isChecked ? 'checked' : ''}" style="flex-shrink:0"></div>
                                    ${optionImgHTML}
                                    <span class="poll-option-label-text" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis">${escapeHTML(opt.teks)}</span>
                                </label>
                                <span class="msg-poll-option-pct" style="flex-shrink:0">${percent}% (${opt.votes_count})</span>
                            </div>
                            ${votersListHTML}
                        </div>
                    `;
                });
            }
            pollHtml += `<div class="poll-total-footer">
                            <span><i class="fas fa-users"></i> Partisipasi</span>
                            <span class="poll-total-votes-value" data-total-votes="${totalVotes}">${totalVotes} suara</span>
                         </div>`;
            pollHtml += `</div>`;
            contentHTML = pollHtml;
        }

        const isPinned = msg.pinned == 1;
        const isOwner = msg.id_user == currentUserId;

        
        let ticksHTML = '';
        if (isMe && !hasBeenDeleted) {
            const count = parseInt(msg.read_count || 0);
            const readAll = count >= (totalUsersCount - 1);
            if (count === 0) {
                ticksHTML = `<span class="msg-read-status unread" title="Terkirim"><i class="fas fa-check"></i></span>`;
            } else if (readAll) {
                ticksHTML = `<span class="msg-read-status read-all" title="Dibaca oleh semua orang (${count} user)"><i class="fas fa-check-double"></i></span>`;
            } else {
                ticksHTML = `<span class="msg-read-status read-some" title="Dibaca oleh beberapa orang (${count} user)"><i class="fas fa-check-double"></i></span>`;
            }
        } else if (!isMe && !hasBeenDeleted) {
            ticksHTML = ``;
        }

        let replyPreview = '';
        if (msg.reply_to) {
            if (msg.reply_sender) {
                replyPreview = `
                    <div class="msg-bubble-reply-preview" data-reply-to="${msg.reply_to}" onclick="scrollToMessage(${msg.reply_to})">
                        <strong>${escapeHTML(msg.reply_sender)}</strong>
                        <div class="reply-preview-text" style="font-size:0.75rem; text-overflow:ellipsis; overflow:hidden; white-space:nowrap">${escapeHTML(msg.reply_preview || '[Lampiran]')}</div>
                    </div>
                `;
            } else {
                replyPreview = `
                    <div class="msg-bubble-reply-preview" data-reply-to="${msg.reply_to}" style="opacity:0.6; cursor:default">
                        <div class="reply-preview-text" style="font-size:0.75rem; font-style:italic; color:var(--text-muted)">🚫 Pesan ini dihapus permanen</div>
                    </div>
                `;
            }
        }

        
        let dropdownHTML = '';
        if (!hasBeenDeleted || isSuperAdmin) {
            dropdownHTML = `
                <div class="msg-bubble-context-trigger" onclick="toggleBubbleDropdown(event, ${msg.id_msg})">
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="msg-bubble-dropdown-menu" id="dropdown-${msg.id_msg}">
                    ${!hasBeenDeleted ? `
                    <button class="msg-bubble-dropdown-item" onclick="startReplyMessage(${msg.id_msg}, '${escapeHTML(msg.nama)}', '${escapeHTML(msg.pesan || (msg.tipe === 'image' ? '[Gambar]' : '[File]'))}')">
                        <i class="fas fa-reply"></i> Balas
                    </button>
                    ` : ''}
                    ${isMe && !hasBeenDeleted ? `
                        <button class="msg-bubble-dropdown-item" onclick="showReadDetails(${msg.id_msg})">
                            <i class="fas fa-check-double text-accent"></i> Info Pesan
                        </button>
                    ` : ''}
                    ${isOwner && msg.tipe === 'text' && !hasBeenDeleted ? `
                        <button class="msg-bubble-dropdown-item" onclick="startEditMessage(${msg.id_msg}, '${escapeHTML(msg.pesan).replace(/'/g, "\\'")}')">
                            <i class="fas fa-pencil-alt"></i> Edit Pesan
                        </button>
                    ` : ''}
                    ${isAdminOrOperator && !hasBeenDeleted ? `
                        <button class="msg-bubble-dropdown-item" onclick="${isPinned ? 'unpinMessage' : 'pinMessage'}(${msg.id_msg})">
                            <i class="fas fa-thumbtack text-gold"></i> ${isPinned ? 'Lepas Pin' : 'Sematkan Pesan'}
                        </button>
                    ` : ''}
                    <button class="msg-bubble-dropdown-item delete" onclick="confirmDeleteMessage(${msg.id_msg}, ${isOwner ? 'true' : 'false'})">
                        <i class="fas fa-trash-alt"></i> ${hasBeenDeleted ? 'Hapus Permanen' : 'Hapus Pesan'}
                    </button>
                </div>
            `;
        }

        const isEdited = msg.edited == 1;

        return `
            <div class="msg-message-row ${isMe ? 'me' : 'other'}" id="msg-msg-${msg.id_msg}">
                <div class="msg-message-avatar">
                    <img src="${avatar}">
                </div>
                <div class="msg-message-wrapper">
                    <div class="msg-sender-name">
                        ${escapeHTML(msg.nama)}
                        ${msg.role !== 'user' ? `<span class="badge badge-accent" style="font-size:0.6rem; padding: 2px 6px; margin-left:4px">${msg.role.toUpperCase()}</span>` : ''}
                    </div>
                    <div class="msg-bubble">
                        ${replyPreview}
                        ${contentHTML}
                        <div class="msg-time">
                            ${isEdited ? '<span class="msg-edited-badge">(diedit)</span>' : ''}
                            ${formatTime(msg.created_at)}
                            ${isPinned ? '<i class="fas fa-thumbtack text-gold" style="margin-left:5px"></i>' : ''}
                            ${ticksHTML}
                        </div>
                        ${dropdownHTML}
                    </div>
                </div>
            </div>
        `;
    }

    
    function handleInputMentions(e) {
        const input = e.target;
        const val = input.value;
        const caretPos = input.selectionStart;
        const textBeforeCaret = val.slice(0, caretPos);
        
        
        const match = textBeforeCaret.match(/@(\w*)$/);
        
        if (match) {
            const query = match[1].toLowerCase();
            const filtered = groupUsers.filter(u => 
                u.nama.toLowerCase().includes(query) || 
                (u.username && u.username.toLowerCase().includes(query))
            );
            
            if (filtered.length > 0) {
                showMentionSuggestions(filtered, match.index, query.length);
            } else {
                hideMentionSuggestions();
            }
        } else {
            hideMentionSuggestions();
        }
    }

    function showMentionSuggestions(users, matchIndex, queryLength) {
        const suggestionsBox = document.getElementById('mentionSuggestions');
        suggestionsBox.innerHTML = '';
        activeSuggestionIndex = -1;
        
        users.forEach((user, idx) => {
            const avatar = user.foto_profil 
                ? '../uploads/profil/' + user.foto_profil 
                : 'https://ui-avatars.com/api/?name=' + encodeURIComponent(user.nama) + '&background=1f6feb&color=fff&size=80';
            
            const div = document.createElement('div');
            div.className = 'msg-mention-item';
            div.innerHTML = `
                <img src="${avatar}" class="msg-mention-avatar">
                <div>
                    <div class="msg-mention-name">${escapeHTML(user.nama)}</div>
                    <div class="msg-mention-username">@${escapeHTML(user.username || 'user')}</div>
                </div>
            `;
            div.onclick = (e) => {
                e.stopPropagation();
                selectMention(user, matchIndex, queryLength);
            };
            suggestionsBox.appendChild(div);
        });
        
        suggestionsBox.classList.add('show');
    }

    function hideMentionSuggestions() {
        const suggestionsBox = document.getElementById('mentionSuggestions');
        suggestionsBox.classList.remove('show');
        suggestionsBox.innerHTML = '';
        activeSuggestionIndex = -1;
    }

    function selectMention(user, matchIndex, queryLength) {
        const input = document.getElementById('msgTextInput');
        const val = input.value;
        
        const partBefore = val.slice(0, matchIndex);
        const partAfter = val.slice(matchIndex + 1 + queryLength);
        
        
        const mentionText = '@' + user.nama + ' ';
        input.value = partBefore + mentionText + partAfter;
        
        const newCaretPos = matchIndex + mentionText.length;
        input.setSelectionRange(newCaretPos, newCaretPos);
        input.focus();
        
        hideMentionSuggestions();
    }

    function handleKeydownMentions(e) {
        const suggestionsBox = document.getElementById('mentionSuggestions');
        if (!suggestionsBox.classList.contains('show')) return;
        
        const items = suggestionsBox.querySelectorAll('.msg-mention-item');
        if (items.length === 0) return;
        
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeSuggestionIndex = (activeSuggestionIndex + 1) % items.length;
            updateActiveSuggestion(items);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeSuggestionIndex = (activeSuggestionIndex - 1 + items.length) % items.length;
            updateActiveSuggestion(items);
        } else if (e.key === 'Enter') {
            if (activeSuggestionIndex >= 0) {
                e.preventDefault();
                items[activeSuggestionIndex].click();
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            hideMentionSuggestions();
        }
    }

    function updateActiveSuggestion(items) {
        items.forEach((item, idx) => {
            if (idx === activeSuggestionIndex) {
                item.classList.add('active');
                item.scrollIntoView({ block: 'nearest' });
            } else {
                item.classList.remove('active');
            }
        });
    }

    function scrollToBottom() {
        const container = document.getElementById('msgMessagesContainer');
        container.scrollTop = container.scrollHeight;
    }

    function scrollToMessage(id) {
        const el = document.getElementById('msg-msg-' + id);
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            el.style.backgroundColor = 'rgba(88, 166, 255, 0.15)';
            el.style.borderRadius = '8px';
            setTimeout(() => {
                el.style.backgroundColor = '';
                el.style.borderRadius = '';
            }, 2000);
        }
    }

    function triggerFileInput() {
        document.getElementById('msgFileInputField').click();
    }

    function startReplyMessage(id, sender, preview) {
        cancelEdit(); 
        document.getElementById('msgReplyToInput').value = id;
        document.getElementById('msgFileReplyToInput').value = id;
        document.getElementById('replyTargetSender').innerText = sender;
        document.getElementById('replyTargetPreview').innerText = preview.substring(0, 40) + (preview.length > 40 ? '...' : '');
        document.getElementById('msgReplyBar').style.display = 'flex';
        document.getElementById('msgTextInput').focus();
    }

    function cancelReply() {
        document.getElementById('msgReplyToInput').value = '';
        document.getElementById('msgFileReplyToInput').value = '';
        document.getElementById('msgReplyBar').style.display = 'none';
    }

    function startEditMessage(id, text) {
        cancelReply(); 
        editingMessageId = id;
        document.getElementById('msgTextInput').value = text;
        document.getElementById('editTargetPreview').innerText = text.substring(0, 40) + (text.length > 40 ? '...' : '');
        document.getElementById('msgEditBar').style.display = 'flex';
        document.getElementById('msgTextInput').focus();
        document.getElementById('msgSendBtn').innerHTML = '<i class="fas fa-check"></i>';
        document.getElementById('msgSendBtn').title = 'Simpan Edit';
    }

    function cancelEdit() {
        editingMessageId = null;
        document.getElementById('msgTextInput').value = '';
        document.getElementById('msgEditBar').style.display = 'none';
        document.getElementById('msgSendBtn').innerHTML = '<i class="fas fa-paper-plane"></i>';
        document.getElementById('msgSendBtn').title = 'Kirim';
    }

    function handleMsgSubmit(e) {
        e.preventDefault();
        const input = document.getElementById('msgTextInput');
        const text = input.value.trim();

        if (text === '') return;

        if (editingMessageId !== null) {
            
            const formData = new FormData();
            formData.append('action', 'edit');
            formData.append('id', editingMessageId);
            formData.append('pesan', text);

            safeFetch('api_msg.php', {
                method: 'POST',
                body: formData
            }).then(data => {
                if (data.success) {
                    cancelEdit();
                    loadMessages();
                } else {
                    showToast(data.message || 'Gagal mengubah pesan', 'error');
                }
            });
        } else {
            
            const replyTo = document.getElementById('msgReplyToInput').value;
            const formData = new FormData();
            formData.append('action', 'send');
            formData.append('pesan', text);
            if (replyTo) formData.append('reply_to', replyTo);

            safeFetch('api_msg.php', {
                method: 'POST',
                body: formData
            }).then(data => {
                if (data.success) {
                    input.value = '';
                    cancelReply();
                    autoScroll = true;
                    loadMessages();
                } else {
                    showToast(data.message || 'Gagal mengirim pesan', 'error');
                }
            });
        }
    }

    
    let pendingMsgFiles = [];

    function showUploadPreview() {
        const fileInput = document.getElementById('msgFileInputField');
        if (fileInput.files.length === 0) return;

        
        for (let i = 0; i < fileInput.files.length; i++) {
            pendingMsgFiles.push(fileInput.files[i]);
        }

        renderMsgFileList();
        document.getElementById('msgUploadPreviewModal').classList.add('open');
        
        fileInput.value = '';
    }

    function renderMsgFileList() {
        const container = document.getElementById('msgUploadFileListContainer');
        const badge = document.getElementById('msgUploadCountBadge');
        container.innerHTML = '';
        badge.textContent = pendingMsgFiles.length + ' file';

        pendingMsgFiles.forEach((file, idx) => {
            const card = document.createElement('div');
            card.style.cssText = 'display:flex; gap:10px; align-items:center; background:#161b22; padding:10px; border-radius:8px; border:1px solid #30363d';

            
            let previewHTML = '';
            const url = URL.createObjectURL(file);
            if (file.type.startsWith('image/')) {
                previewHTML = `<img src="${url}" style="width:48px; height:48px; object-fit:cover; border-radius:6px; flex-shrink:0; cursor:pointer" onclick="window.open('${url}')">`;
            } else if (file.type.startsWith('video/')) {
                previewHTML = `<video src="${url}" style="width:48px; height:48px; object-fit:cover; border-radius:6px; flex-shrink:0" muted></video>`;
            } else if (file.type.startsWith('audio/')) {
                previewHTML = `<div style="width:48px; height:48px; display:flex; align-items:center; justify-content:center; background:#21262d; border-radius:6px; flex-shrink:0"><i class="fas fa-music" style="color:#58a6ff; font-size:1.2rem"></i></div>`;
            } else {
                let ic = 'fa-file-lines';
                if (file.name.endsWith('.pdf')) ic = 'fa-file-pdf';
                else if (file.name.endsWith('.zip') || file.name.endsWith('.rar')) ic = 'fa-file-zipper';
                previewHTML = `<div style="width:48px; height:48px; display:flex; align-items:center; justify-content:center; background:#21262d; border-radius:6px; flex-shrink:0"><i class="fas ${ic}" style="color:#8b949e; font-size:1.2rem"></i></div>`;
            }

            const sizeMB = (file.size / (1024 * 1024)).toFixed(2);

            card.innerHTML = `
                ${previewHTML}
                <div style="flex:1; min-width:0">
                    <div style="font-size:0.85rem; font-weight:600; color:#e6edf3; white-space:nowrap; overflow:hidden; text-overflow:ellipsis">${escapeHTML(file.name)}</div>
                    <div style="font-size:0.75rem; color:#8b949e; margin-top:2px">${sizeMB} MB</div>
                </div>
                <button type="button" onclick="removePendingMsgFile(${idx})" style="background:none; border:none; color:#f85149; cursor:pointer; padding:4px 8px; font-size:1rem" title="Hapus file ini">
                    <i class="fas fa-trash-alt"></i>
                </button>
            `;
            container.appendChild(card);
        });

        
        const btn = document.getElementById('btnSendAllMsgFiles');
        if (btn) {
            btn.innerHTML = pendingMsgFiles.length > 1
                ? `<i class="fas fa-paper-plane"></i> Kirim ${pendingMsgFiles.length} File`
                : `<i class="fas fa-paper-plane"></i> Kirim`;
        }
    }

    function removePendingMsgFile(index) {
        pendingMsgFiles.splice(index, 1);
        if (pendingMsgFiles.length === 0) {
            cancelUploadPreview();
        } else {
            renderMsgFileList();
        }
    }

    function cancelUploadPreview() {
        pendingMsgFiles = [];
        document.getElementById('msgFileInputField').value = '';
        document.getElementById('msgUploadPreviewModal').classList.remove('open');
        document.getElementById('uploadPreviewCaptionInput').value = '';
    }

    async function proceedUploadMsgFile() {
        if (pendingMsgFiles.length === 0) return;

        const replyTo = document.getElementById('msgFileReplyToInput').value;
        const caption = document.getElementById('uploadPreviewCaptionInput').value.trim();
        const filesToSend = [...pendingMsgFiles];

        document.getElementById('msgUploadPreviewModal').classList.remove('open');
        pendingMsgFiles = [];

        const totalFiles = filesToSend.length;
        let successCount = 0;

        Swal.fire({
            title: `Mengirim file (0/${totalFiles})...`,
            allowOutsideClick: false,
            background: '#161b22',
            color: '#c9d1d9',
            didOpen: () => { Swal.showLoading(); }
        });

        for (let i = 0; i < filesToSend.length; i++) {
            Swal.update({ title: `Mengirim file (${i + 1}/${totalFiles})...` });

            const formData = new FormData();
            formData.append('action', 'upload');
            formData.append('msg_file', filesToSend[i]);
            
            if (i === 0 && caption) formData.append('pesan', caption);
            
            if (i === 0 && replyTo) formData.append('reply_to', replyTo);

            try {
                const data = await safeFetch('api_msg.php', {
                    method: 'POST',
                    body: formData
                });
                if (data.success) successCount++;
            } catch (e) {
                console.error('Upload error:', e);
            }
        }

        Swal.close();
        document.getElementById('msgFileInputField').value = '';
        cancelReply();
        autoScroll = true;
        loadMessages();

        if (successCount === totalFiles) {
            showToast(`${successCount} file berhasil dikirim`);
        } else {
            showToast(`${successCount}/${totalFiles} file berhasil dikirim`, 'warning');
        }
    }

    function pinMessage(id) {
        const formData = new FormData();
        formData.append('action', 'pin');
        formData.append('id', id);

        safeFetch('api_msg.php', {
            method: 'POST',
            body: formData
        })
            .then(data => {
                if (data.success) {
                    showToast('Pesan berhasil dipin');
                    loadPinnedMessages();
                    loadMessages();
                }
            });
    }

    function unpinMessage(id) {
        const formData = new FormData();
        formData.append('action', 'unpin');
        formData.append('id', id);

        safeFetch('api_msg.php', {
            method: 'POST',
            body: formData
        })
            .then(data => {
                if (data.success) {
                    showToast('Pesan dilepas');
                    loadPinnedMessages();
                    loadMessages();
                }
            });
    }

    function confirmDeleteMessage(id, isMe) {
        const msgRow = document.getElementById('msg-msg-' + id);
        const hasBeenDeleted = msgRow && msgRow.querySelector('.msg-bubble').textContent.includes('Pesan ini telah dihapus');

        if (isSuperAdmin) {
            if (hasBeenDeleted) {
                Swal.fire({
                    title: 'Hapus Pesan Dihapus?',
                    html: `
                        <p style="margin-bottom:1rem;font-size:0.9rem;color:#8b949e">Pesan ini sudah ditandai dihapus. Pilih metode penghapusan:</p>
                        <div style="display:flex;flex-direction:column;gap:8px;text-align:left">
                            <button type="button" class="btn btn-danger" style="width:100%;justify-content:center" id="btnDeletePermanent">
                                <i class="fas fa-trash-alt"></i> Hapus Permanen (Hilang total)
                            </button>
                            <button type="button" class="btn btn-secondary" style="width:100%;justify-content:center" id="btnDeleteMe">
                                <i class="fas fa-eye-slash"></i> Hapus untuk Saya Saja
                            </button>
                        </div>
                    `,
                    showConfirmButton: false,
                    showCancelButton: true,
                    cancelButtonText: 'Batal',
                    background: '#161b22',
                    color: '#c9d1d9',
                    didOpen: () => {
                        document.getElementById('btnDeletePermanent').onclick = () => {
                            Swal.close();
                            confirmPermanentDelete(id);
                        };
                        document.getElementById('btnDeleteMe').onclick = () => {
                            Swal.close();
                            deleteMessageMe(id);
                        };
                    }
                });
            } else {
                Swal.fire({
                    title: 'Hapus Pesan?',
                    html: `
                        <p style="margin-bottom:1rem;font-size:0.9rem;color:#8b949e">Pilih metode penghapusan:</p>
                        <div style="display:flex;flex-direction:column;gap:8px;text-align:left">
                            <button type="button" class="btn btn-secondary" style="width:100%;justify-content:center" id="btnDeleteSoft">
                                <i class="fas fa-ban"></i> Hapus untuk Semua (Tandai dihapus)
                            </button>
                            <button type="button" class="btn btn-danger" style="width:100%;justify-content:center" id="btnDeletePermanent">
                                <i class="fas fa-trash-alt"></i> Hapus Permanen (Hilang total)
                            </button>
                            <button type="button" class="btn btn-secondary" style="width:100%;justify-content:center" id="btnDeleteMe">
                                <i class="fas fa-eye-slash"></i> Hapus untuk Saya Saja
                            </button>
                        </div>
                    `,
                    showConfirmButton: false,
                    showCancelButton: true,
                    cancelButtonText: 'Batal',
                    background: '#161b22',
                    color: '#c9d1d9',
                    didOpen: () => {
                        document.getElementById('btnDeleteSoft').onclick = () => {
                            Swal.close();
                            deleteMessageEveryone(id);
                        };
                        document.getElementById('btnDeletePermanent').onclick = () => {
                            Swal.close();
                            confirmPermanentDelete(id);
                        };
                        document.getElementById('btnDeleteMe').onclick = () => {
                            Swal.close();
                            deleteMessageMe(id);
                        };
                    }
                });
            }
        } else if (isMe) {
            Swal.fire({
                title: 'Hapus Pesan?',
                text: 'Pilih metode penghapusan pesan Anda.',
                icon: 'question',
                showDenyButton: true,
                showCancelButton: true,
                confirmButtonText: 'Hapus untuk Semua',
                denyButtonText: 'Hapus untuk Saya',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#d33',
                denyButtonColor: '#3085d6',
                background: '#161b22',
                color: '#c9d1d9'
            }).then(result => {
                if (result.isConfirmed) {
                    deleteMessageEveryone(id);
                } else if (result.isDenied) {
                    deleteMessageMe(id);
                }
            });
        } else {
            Swal.fire({
                title: 'Hapus Pesan?',
                text: 'Hapus pesan ini hanya dari tampilan Anda?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Hapus untuk Saya',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#3085d6',
                background: '#161b22',
                color: '#c9d1d9'
            }).then(result => {
                if (result.isConfirmed) {
                    deleteMessageMe(id);
                }
            });
        }
    }

    function confirmPermanentDelete(id) {
        Swal.fire({
            title: 'Hapus Permanen?',
            text: 'Pesan akan hilang total tanpa jejak. Tindakan ini tidak dapat dibatalkan.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Ya, Hapus Permanen',
            cancelButtonText: 'Batal',
            confirmButtonColor: '#d33',
            background: '#161b22',
            color: '#c9d1d9'
        }).then(result => {
            if (result.isConfirmed) {
                deleteMessagePermanent(id);
            }
        });
    }

    function deleteMessageEveryone(id) {
        const formData = new FormData();
        formData.append('action', 'delete_for_everyone');
        formData.append('id', id);

        safeFetch('api_msg.php', {
            method: 'POST',
            body: formData
        })
            .then(data => {
                if (data.success) {
                    showToast('Pesan dihapus untuk semua orang');
                    loadMessages();
                } else {
                    showToast('Gagal menghapus pesan', 'error');
                }
            });
    }

    function deleteMessagePermanent(id) {
        const formData = new FormData();
        formData.append('action', 'delete_permanent');
        formData.append('id', id);

        safeFetch('api_msg.php', {
            method: 'POST',
            body: formData
        })
            .then(data => {
                if (data.success) {
                    const bubble = document.getElementById('msg-msg-' + id);
                    if (bubble) bubble.remove();
                    showToast('Pesan dihapus permanen');
                    loadPinnedMessages();
                    loadMessages();
                } else {
                    showToast(data.message || 'Gagal menghapus pesan permanen', 'error');
                }
            });
    }

    function deleteMessageMe(id) {
        const formData = new FormData();
        formData.append('action', 'delete_for_me');
        formData.append('id', id);

        safeFetch('api_msg.php', {
            method: 'POST',
            body: formData
        })
            .then(data => {
                if (data.success) {
                    const bubble = document.getElementById('msg-msg-' + id);
                    if (bubble) bubble.remove();
                    showToast('Pesan disembunyikan');
                } else {
                    showToast('Gagal menyembunyikan pesan', 'error');
                }
            });
    }

    
    function previewMedia(url, type, name) {
        document.getElementById('mediaPreviewTitle').innerText = name;
        const container = document.getElementById('mediaPreviewContent');
        container.innerHTML = '';

        if (type === 'image') {
            container.innerHTML = `<img src="${url}" style="max-width:100%; max-height:450px; border-radius:8px; object-fit:contain">`;
        } else if (type === 'audio') {
            container.innerHTML = `<audio controls src="${url}" style="width:100%; max-width:400px; margin:2rem 0"></audio>`;
        } else if (type === 'video') {
            container.innerHTML = `<video controls src="${url}" style="max-width:100%; max-height:450px; border-radius:8px"></video>`;
        } else if (type === 'pdf') {
            container.innerHTML = `<iframe src="${url}" style="width:100%; height:450px; border:none; border-radius:8px"></iframe>`;
        } else {
            container.innerHTML = `
                <div style="text-align:center; padding:2rem">
                    <i class="fas fa-file-arrow-down" style="font-size:4rem; color:var(--text-muted); margin-bottom:1rem"></i>
                    <p style="color:var(--text-muted); font-size:0.9rem">Pratinjau tidak tersedia untuk jenis file ini.</p>
                </div>
            `;
        }

        document.getElementById('mediaDownloadBtn').href = url;
        document.getElementById('mediaPreviewModal').classList.add('open');
    }

    function closeMediaPreview() {
        const media = document.querySelector('#mediaPreviewContent audio, #mediaPreviewContent video');
        if (media) media.pause();
        document.getElementById('mediaPreviewModal').classList.remove('open');
    }

    
    function openPollModal() { document.getElementById('msgPollModal').classList.add('open'); }
    function closePollModal() { 
        document.getElementById('msgPollModal').classList.remove('open'); 
        document.getElementById('pollQuestionInput').value = '';
        const mediaInput = document.getElementById('pollMediaInput');
        if (mediaInput) mediaInput.value = '';
        const mediaPreview = document.getElementById('pollMediaPreviewContainer');
        if (mediaPreview) {
            mediaPreview.innerHTML = '';
            mediaPreview.style.display = 'none';
        }
        document.getElementById('pollMultipleInput').checked = false;
        document.getElementById('msgPollOptionsContainer').innerHTML = `
            <label style="color:var(--text-muted); display:block; font-size:0.85rem; margin-bottom:0.4rem">Pilihan Jawaban (Gambar Opsional)</label>
            
            
            <div style="display:flex; flex-direction:column; gap:6px; margin-bottom:12px; background:rgba(255,255,255,0.02); padding:8px; border-radius:8px; border:1px solid var(--border-color)">
                <div style="display:flex; gap:8px; align-items:center">
                    <input type="text" name="opsi[]" class="form-control" placeholder="Pilihan 1" required style="flex:1">
                    <label class="btn btn-secondary" style="padding:0; width:36px; height:36px; display:inline-flex; align-items:center; justify-content:center; margin:0; cursor:pointer" title="Tambah Gambar Opsi">
                        <i class="fas fa-image"></i>
                        <input type="file" name="opsi_gambar[]" accept="image/*" style="display:none" onchange="previewOptionImage(this)">
                    </label>
                </div>
                <div class="option-image-preview" style="display:none; margin-top:6px; text-align:center; position:relative">
                    <img src="" style="max-height:80px; border-radius:6px; object-fit:contain">
                    <button type="button" class="btn" style="position:absolute; top:2px; right:2px; padding:2px 6px; font-size:0.7rem; background:rgba(248,81,73,0.8); color:#fff; border:none; border-radius:4px" onclick="removeOptionImage(this)">Hapus</button>
                </div>
            </div>

            
            <div style="display:flex; flex-direction:column; gap:6px; margin-bottom:12px; background:rgba(255,255,255,0.02); padding:8px; border-radius:8px; border:1px solid var(--border-color)">
                <div style="display:flex; gap:8px; align-items:center">
                    <input type="text" name="opsi[]" class="form-control" placeholder="Pilihan 2" required style="flex:1">
                    <label class="btn btn-secondary" style="padding:0; width:36px; height:36px; display:inline-flex; align-items:center; justify-content:center; margin:0; cursor:pointer" title="Tambah Gambar Opsi">
                        <i class="fas fa-image"></i>
                        <input type="file" name="opsi_gambar[]" accept="image/*" style="display:none" onchange="previewOptionImage(this)">
                    </label>
                </div>
                <div class="option-image-preview" style="display:none; margin-top:6px; text-align:center; position:relative">
                    <img src="" style="max-height:80px; border-radius:6px; object-fit:contain">
                    <button type="button" class="btn" style="position:absolute; top:2px; right:2px; padding:2px 6px; font-size:0.7rem; background:rgba(248,81,73,0.8); color:#fff; border:none; border-radius:4px" onclick="removeOptionImage(this)">Hapus</button>
                </div>
            </div>
        `;
    }

    function addMsgPollOption() {
        const container = document.getElementById('msgPollOptionsContainer');
        const count = container.querySelectorAll('input[type="text"]').length + 1;
        const div = document.createElement('div');
        div.style.cssText = 'display:flex; flex-direction:column; gap:6px; margin-bottom:12px; background:rgba(255,255,255,0.02); padding:8px; border-radius:8px; border:1px solid var(--border-color)';
        div.innerHTML = `
            <div style="display:flex; gap:8px; align-items:center">
                <input type="text" name="opsi[]" class="form-control" placeholder="Pilihan ${count}" required style="flex:1">
                <label class="btn btn-secondary" style="padding:0; width:36px; height:36px; display:inline-flex; align-items:center; justify-content:center; margin:0; cursor:pointer" title="Tambah Gambar Opsi">
                    <i class="fas fa-image"></i>
                    <input type="file" name="opsi_gambar[]" accept="image/*" style="display:none" onchange="previewOptionImage(this)">
                </label>
                <button type="button" class="btn btn-danger" style="padding:0; width:36px; height:36px" onclick="this.parentElement.parentElement.remove()"><i class="fas fa-times"></i></button>
            </div>
            <div class="option-image-preview" style="display:none; margin-top:6px; text-align:center; position:relative">
                <img src="" style="max-height:80px; border-radius:6px; object-fit:contain">
                <button type="button" class="btn" style="position:absolute; top:2px; right:2px; padding:2px 6px; font-size:0.7rem; background:rgba(248,81,73,0.8); color:#fff; border:none; border-radius:4px" onclick="removeOptionImage(this)">Hapus</button>
            </div>
        `;
        container.appendChild(div);
    }

    function previewOptionImage(input) {
        const previewDiv = input.closest('div').nextElementSibling;
        const file = input.files[0];
        if (file && file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewDiv.querySelector('img').src = e.target.result;
                previewDiv.style.display = 'block';
            }
            reader.readAsDataURL(file);
        }
    }

    function removeOptionImage(btn) {
        const previewDiv = btn.closest('.option-image-preview');
        const fileInput = previewDiv.previousElementSibling.querySelector('input[type="file"]');
        if (fileInput) fileInput.value = '';
        previewDiv.style.display = 'none';
        previewDiv.querySelector('img').src = '';
    }

    function removeMainPollMedia() {
        const input = document.getElementById('pollMediaInput');
        if (input) input.value = '';
        const container = document.getElementById('pollMediaPreviewContainer');
        if (container) {
            container.innerHTML = '';
            container.style.display = 'none';
        }
    }

    
    document.addEventListener('DOMContentLoaded', () => {
        const mainMediaInput = document.getElementById('pollMediaInput');
        if (mainMediaInput) {
            mainMediaInput.addEventListener('change', function() {
                const container = document.getElementById('pollMediaPreviewContainer');
                container.innerHTML = '';
                container.style.display = 'none';
                
                const file = this.files[0];
                if (file) {
                    const reader = new FileReader();
                    if (file.type.startsWith('image/')) {
                        reader.onload = function(e) {
                            container.innerHTML = `
                                <img src="${e.target.result}" style="max-height:120px; border-radius:6px; object-fit:contain">
                                <button type="button" class="btn" style="position:absolute; top:4px; right:4px; padding:2px 6px; font-size:0.7rem; background:rgba(248,81,73,0.8); color:#fff; border:none; border-radius:4px" onclick="removeMainPollMedia()">Hapus</button>
                            `;
                            container.style.display = 'block';
                        }
                        reader.readAsDataURL(file);
                    } else if (file.type.startsWith('video/')) {
                        const url = URL.createObjectURL(file);
                        container.innerHTML = `
                            <video src="${url}" controls style="max-height:120px; border-radius:6px; max-width:100%"></video>
                            <button type="button" class="btn" style="position:absolute; top:4px; right:4px; padding:2px 6px; font-size:0.7rem; background:rgba(248,81,73,0.8); color:#fff; border:none; border-radius:4px" onclick="removeMainPollMedia()">Hapus</button>
                        `;
                        container.style.display = 'block';
                    }
                }
            });
        }
    });

    function submitMsgPoll(e) {
        e.preventDefault();
        const question = document.getElementById('pollQuestionInput').value.trim();
        const optionsInputs = document.querySelectorAll('#msgPollOptionsContainer input[type="text"]');
        const isMultiple = document.getElementById('pollMultipleInput').checked;
        const mediaInput = document.getElementById('pollMediaInput');
        
        const formData = new FormData();
        formData.append('action', 'send_poll');
        formData.append('pertanyaan', question);
        formData.append('tipe', isMultiple ? 'multiple' : 'single');
        
        optionsInputs.forEach((input, index) => {
            if (input.value.trim()) {
                formData.append('opsi[]', input.value.trim());
                
                const fileInput = input.closest('div').querySelector('input[type="file"]');
                if (fileInput && fileInput.files.length > 0) {
                    formData.append(`opsi_gambar_${index}`, fileInput.files[0]);
                }
            }
        });
        
        if (mediaInput && mediaInput.files.length > 0) {
            formData.append('poll_media', mediaInput.files[0]);
        }

        closePollModal();

        safeFetch('api_msg.php', {
            method: 'POST',
            body: formData
        })
        .then(data => {
            if (data.success) {
                showToast('Polling terkirim');
                autoScroll = true;
                loadMessages();
            } else {
                showToast(data.message || 'Gagal mengirim polling', 'error');
            }
        });
    }

    function voteMsgPoll(pollId, optionId) {
        const targetRow = event.currentTarget || event.target.closest('.msg-poll-option-row');
        if (targetRow) {
            
            targetRow.classList.add('anim-select');
            setTimeout(() => targetRow.classList.remove('anim-select'), 400);

            
            const pollContainer = targetRow.closest('.msg-bubble-poll-container');
            const isMultiple = pollContainer.querySelector('.poll-badge-tipe').innerText.includes('Ganda');
            
            
            const allRows = pollContainer.querySelectorAll('.msg-poll-option-row');
            const totalVotesSpan = pollContainer.querySelector('.poll-total-votes-value');
            let totalVotes = parseInt(totalVotesSpan.getAttribute('data-total-votes') || 0);

            const wasVoted = targetRow.classList.contains('voted');

            
            if (isMultiple) {
                let currentVotes = parseInt(targetRow.getAttribute('data-votes') || 0);
                if (wasVoted) {
                    
                    currentVotes = Math.max(0, currentVotes - 1);
                    totalVotes = Math.max(0, totalVotes - 1);
                    targetRow.classList.remove('voted');
                    const box = targetRow.querySelector('.poll-custom-checkbox');
                    if (box) box.classList.remove('checked');

                    
                    const list = targetRow.querySelector('.poll-option-voters-list');
                    if (list) {
                        const meChip = list.querySelector(`[data-voter-name="${escapeHTML(currentUserNama)}"]`);
                        if (meChip) meChip.remove();
                    }
                } else {
                    
                    currentVotes += 1;
                    totalVotes += 1;
                    targetRow.classList.add('voted');
                    const box = targetRow.querySelector('.poll-custom-checkbox');
                    if (box) box.classList.add('checked');

                    
                    const list = targetRow.querySelector('.poll-option-voters-list');
                    if (list) {
                        const hasMe = list.querySelector(`[data-voter-name="${escapeHTML(currentUserNama)}"]`);
                        if (!hasMe) {
                            list.insertAdjacentHTML('beforeend', `<span class="poll-voter-chip" data-voter-name="${escapeHTML(currentUserNama)}" title="${escapeHTML(currentUserNama)}"><i class="fas fa-user" style="font-size:0.55rem;"></i> ${escapeHTML(currentUserNama)}</span>`);
                        }
                    }
                }
                targetRow.setAttribute('data-votes', currentVotes);
            } else {
                
                allRows.forEach(row => {
                    const rowOptId = parseInt(row.getAttribute('data-option-id'));
                    let rowVotes = parseInt(row.getAttribute('data-votes') || 0);
                    const rowVoted = row.classList.contains('voted');

                    if (rowOptId === optionId) {
                        if (wasVoted) {
                            
                            rowVotes = Math.max(0, rowVotes - 1);
                            totalVotes = Math.max(0, totalVotes - 1);
                            row.classList.remove('voted');
                            const radio = row.querySelector('.poll-custom-radio');
                            if (radio) radio.classList.remove('checked');

                            const list = row.querySelector('.poll-option-voters-list');
                            if (list) {
                                const meChip = list.querySelector(`[data-voter-name="${escapeHTML(currentUserNama)}"]`);
                                if (meChip) meChip.remove();
                            }
                        } else {
                            
                            rowVotes += 1;
                            totalVotes += 1; 
                            row.classList.add('voted');
                            const radio = row.querySelector('.poll-custom-radio');
                            if (radio) radio.classList.add('checked');

                            const list = row.querySelector('.poll-option-voters-list');
                            if (list) {
                                const hasMe = list.querySelector(`[data-voter-name="${escapeHTML(currentUserNama)}"]`);
                                if (!hasMe) {
                                    list.insertAdjacentHTML('beforeend', `<span class="poll-voter-chip" data-voter-name="${escapeHTML(currentUserNama)}" title="${escapeHTML(currentUserNama)}"><i class="fas fa-user" style="font-size:0.55rem;"></i> ${escapeHTML(currentUserNama)}</span>`);
                                }
                            }
                        }
                        row.setAttribute('data-votes', rowVotes);
                    } else if (rowVoted) {
                        
                        rowVotes = Math.max(0, rowVotes - 1);
                        totalVotes = Math.max(0, totalVotes - 1); 
                        row.classList.remove('voted');
                        row.setAttribute('data-votes', rowVotes);
                        const radio = row.querySelector('.poll-custom-radio');
                        if (radio) radio.classList.remove('checked');

                        const list = row.querySelector('.poll-option-voters-list');
                        if (list) {
                            const meChip = list.querySelector(`[data-voter-name="${escapeHTML(currentUserNama)}"]`);
                            if (meChip) meChip.remove();
                        }
                    }
                });
            }

            
            totalVotesSpan.setAttribute('data-total-votes', totalVotes);
            totalVotesSpan.innerText = totalVotes + ' suara';

            allRows.forEach(row => {
                const votes = parseInt(row.getAttribute('data-votes') || 0);
                const percent = totalVotes > 0 ? Math.round((votes / totalVotes) * 100) : 0;
                
                
                const bar = row.querySelector('.msg-poll-option-bar');
                if (bar) bar.style.width = percent + '%';

                
                const pctText = row.querySelector('.msg-poll-option-pct');
                if (pctText) pctText.innerText = percent + '% (' + votes + ')';
            });
        }

        const formData = new FormData();
        formData.append('action', 'vote_poll');
        formData.append('id_poll', pollId);
        formData.append('id_option', optionId);

        safeFetch('api_msg.php', {
            method: 'POST',
            body: formData
        })
        .then(data => {
            if (data.success) {
                
                loadMessages();
            }
        });
    }

    
    function showReadDetails(msgId) {
        const formData = new FormData();
        formData.append('action', 'get_read_details');
        formData.append('id', msgId);

        safeFetch('api_msg.php', {
            method: 'POST',
            body: formData
        }).then(data => {
            if (data.success && data.readers) {
                const list = document.getElementById('msgReadDetailsList');
                list.innerHTML = '';
                if (data.readers.length === 0) {
                    list.innerHTML = `<div style="color:var(--text-muted); text-align:center; padding:1.5rem 0; font-size:0.85rem">Pesan belum dibaca oleh anggota lain.</div>`;
                } else {
                    data.readers.forEach(r => {
                        const avatar = r.foto_profil 
                            ? '../uploads/profil/' + r.foto_profil 
                            : 'https://ui-avatars.com/api/?name=' + encodeURIComponent(r.nama) + '&background=1f6feb&color=fff&size=80';
                        list.innerHTML += `
                            <li class="msg-detail-item">
                                <div class="msg-detail-user">
                                    <img src="${avatar}" class="msg-detail-avatar">
                                    <span class="msg-detail-name">${escapeHTML(r.nama)}</span>
                                </div>
                                <span class="msg-detail-time">${formatDateTime(r.read_at)}</span>
                            </li>
                        `;
                    });
                }
                document.getElementById('msgReadDetailsModal').classList.add('open');
            }
        });
    }

    function closeReadDetailsModal() {
        document.getElementById('msgReadDetailsModal').classList.remove('open');
    }

    function escapeHTML(str) {
        if (!str) return '';
        return str.replace(/[&<>'"]/g, 
            tag => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[tag] || tag)
        );
    }

    function formatTime(t) {
        const d = new Date(t);
        return d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) + ' WIB';
    }

    function formatDateTime(t) {
        if (!t) return '';
        const cleaned = t.replace(/-/g, '/');
        const d = new Date(cleaned);
        return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short' }) + ' ' + d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) + ' WIB';
    }

    
    let currentSelectedMentionUser = '';
    function showMentionProfile(name) {
        const formData = new FormData();
        formData.append('action', 'get_user_profile');
        formData.append('nama', name);

        safeFetch('api_msg.php', {
            method: 'POST',
            body: formData
        }).then(data => {
            if (data.success && data.user) {
                currentSelectedMentionUser = data.user.nama;
                const avatarUrl = data.user.foto_profil 
                    ? '../uploads/profil/' + data.user.foto_profil 
                    : 'https://ui-avatars.com/api/?name=' + encodeURIComponent(data.user.nama) + '&background=1f6feb&color=fff&size=120';
                
                document.getElementById('mentionProfileAvatar').src = avatarUrl;
                document.getElementById('mentionProfileName').innerText = data.user.nama;
                document.getElementById('mentionProfileUsername').innerText = '@' + (data.user.username || 'user');
                
                const roleEl = document.getElementById('mentionProfileRole');
                roleEl.innerText = data.user.role;
                roleEl.className = 'mention-profile-role ' + data.user.role;

                
                const joined = new Date(data.user.created_at.replace(/-/g, '/'));
                const joinedText = joined.toLocaleDateString('id-ID', { year: 'numeric', month: 'long', day: 'numeric' });
                document.getElementById('mentionProfileJoined').innerText = 'Bergabung sejak: ' + joinedText;

                document.getElementById('mentionProfileOverlay').style.display = 'flex';
            } else {
                showToast(data.message || 'Gagal memuat profil', 'error');
            }
        });
    }

    function closeMentionProfile() {
        document.getElementById('mentionProfileOverlay').style.display = 'none';
    }

    function fillInputMentionFromCard() {
        if (currentSelectedMentionUser) {
            fillInputMention(currentSelectedMentionUser);
            closeMentionProfile();
        }
    }

    
    function loadPinnedMessages() {
        const formData = new FormData();
        formData.append('action', 'get_pinned');

        safeFetch('api_msg.php', {
            method: 'POST',
            body: formData
        }).then(data => {
            const bar = document.getElementById('pinnedBar');
            const container = document.getElementById('pinnedItemsContainer');
            
            if (data.success && data.pinned && data.pinned.length > 0) {
                container.innerHTML = '';
                data.pinned.forEach(p => {
                    const slide = document.createElement('div');
                    slide.className = 'pinned-slide-item';
                    slide.id = 'pin-' + p.id_msg;
                    slide.onclick = () => scrollToMessage(p.id_msg);
                    
                    const text = p.tipe === 'text' 
                        ? escapeHTML(p.pesan) 
                        : `<i class="fas fa-paperclip"></i> ${escapeHTML(p.file_name || 'Lampiran file')}`;
                        
                    slide.innerHTML = `
                        <div class="pinned-slide-text">
                            <strong>${escapeHTML(p.nama)}:</strong> ${text}
                        </div>
                        ${isAdminOrOperator ? `
                            <button class="pinned-slide-unpin" onclick="event.stopPropagation(); unpinMessage(${p.id_msg})" title="Lepas Pin">
                                <i class="fas fa-times"></i>
                            </button>
                        ` : ''}
                    `;
                    container.appendChild(slide);
                });
                bar.style.display = 'flex';
            } else {
                container.innerHTML = '';
                bar.style.display = 'none';
            }
        });
    }
</script>

<?php require_once __DIR__ . '/../template/footer.php'; ?>
