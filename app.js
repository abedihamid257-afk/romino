'use strict';

const CFG = window.ROOM_CONFIG;
const ROOM = CFG.room;
const ROOM_TYPE = CFG.roomType;
const CLIENT_ID = CFG.clientId;
const $ = s => document.querySelector(s);
const $$ = s => document.querySelectorAll(s);

/* State */
let me = localStorage.getItem('chat_name') || '';
let sinceId = 0;
let polling = false;
let hasBeenInRoom = false;
let isAdmin = false;
let chatLocked = false;
let activeTalk = null;
let hasPassword = false;
let roomName = CFG.roomName || '';
let peerName = '';

/* 🆕 جلوگیری از رندر تکراری پیام‌ها */
const displayedIds = new Set();
const removedIds = new Set();

/* Talk state */
let inTalk = false;
let myMuted = true;
let myForcedMute = false;
let myHandRaised = false;
let talkExpanded = false;
let talkMinimized = false;
let talkPeers = {};
let localStream = null;
let myAudioTrack = null;
let currentPeers = [];

/* Personal call state */
let pc = null;
let pendingOffer = null;
let inCall = false;
let iceQueue = [];

/* Helpers */
const escHtml = s => String(s).replace(/[&<>"']/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
}[c]));
const fmtTime = iso => {
    try { const d = new Date(iso); return String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0'); }
    catch(e) { return ''; }
};

async function api(action, extra) {
    const data = Object.assign({ action, room: ROOM, client_id: CLIENT_ID }, extra || {});
    const r = await fetch('api.php', { method: 'POST', body: new URLSearchParams(data) });
    const text = await r.text();
    try { return JSON.parse(text); }
    catch(e) { throw new Error('Invalid JSON: ' + text.slice(0,150)); }
}

function setStatus(text, cls) {
    const el = $('#connState');
    if (!el) return;
    el.textContent = text;
    el.className = 'state' + (cls ? ' ' + cls : '');
}

/* ═══ Message rendering ═══ */
function addBubble({ id, user, type, content, mine, time }) {
    // 🆕 جلوگیری از رندر تکراری
    if (id && (displayedIds.has(id) || removedIds.has(id))) return;
    if (id) displayedIds.add(id);

    const empty = $('#emptyState'); if (empty) empty.remove();
    const row = document.createElement('div');
    row.className = 'msg ' + (type === 'system' ? 'system' : (mine ? 'mine' : 'them'));
    if (id) row.dataset.msgId = id;

    let inner;
    if (type === 'image') {
        inner = `<img src="${escHtml(content)}" alt="image" onclick="window.open(this.src,'_blank')">`;
    } else if (type === 'link') {
        try {
            const d = JSON.parse(content);
            inner = `<a href="${escHtml(d.url)}" target="_blank" rel="noopener">
                <span class="link-title">🔗 ${escHtml(d.title)}</span>
                <span class="url">${escHtml(d.url)}</span></a>`;
        } catch(e) { inner = escHtml(content); }
    } else {
        inner = escHtml(content);
    }

    // 🆕 دکمه حذف بعد از meta (زیر پیام)
    const delBtn = id
        ? `<button class="msg-del" data-id="${id}" title="حذف پیام">🗑 حذف</button>`
        : '';
    row.innerHTML = `<div class="bubble">${inner}</div>` + 
        (type !== 'system' ? `<div class="meta">${escHtml(user)} · ${time || fmtTime(new Date().toISOString())}</div>` : '') +
        delBtn;
    const box = $('#messages');
    box.appendChild(row);
    box.scrollTop = box.scrollHeight;
}

function removeMessageEl(el, id) {
    if (id) removedIds.add(id);
    if (!el || el.dataset.removing) return;
    el.dataset.removing = '1';
    el.style.transition = 'opacity .2s, transform .2s';
    el.style.opacity = '0';
    el.style.transform = 'scale(.9)';
    setTimeout(() => el.remove(), 200);
}

/* ═══ Participants ═══ */
function updateParticipants(peers) {
    const list = $('#participantsList');
    if (!list) return;
    if (!peers || !peers.length) {
        list.innerHTML = '<p class="muted small">هیچ‌کس آنلاین نیست</p>';
        return;
    }
    list.innerHTML = peers.map(p => {
        const you = p.is_me ? ' (شما)' : '';
        const tags = [];
        if (p.is_admin) tags.push('<span class="tag admin">👑 مدیر</span>');
        if (p.in_talk) tags.push('<span class="tag talk">🎙️ گفتگو</span>');
        const hand = p.hand_raised ? ' ✋' : '';
        return `<div class="participant">
            <span class="dot on"></span>
            <span class="n">${escHtml(p.name)}${you}${hand}</span>
            ${tags.join('')}
        </div>`;
    }).join('');
}

/* ═══ Talk UI sync ═══ */
function updateTalkUI(peers, talk) {
    const overlay = $('#talkOverlay');
    const mini = $('#talkMini');
    const notif = $('#talkNotif');
    const activeTalkCard = $('#activeTalkCard');
    const mePeer = peers.find(p => p.is_me);
    const talkUsers = peers.filter(p => p.in_talk);

    inTalk = !!(mePeer && mePeer.in_talk);
    myMuted = mePeer ? !!mePeer.muted : true;
    myForcedMute = mePeer ? !!mePeer.forced_mute : false;
    myHandRaised = mePeer ? !!mePeer.hand_raised : false;

    if (ROOM_TYPE === 'public') {
        const displayName = roomName || 'گفتگوی عمومی';
        $('#peerName').textContent = displayName;
        $('#avatar').textContent = displayName.charAt(0).toUpperCase();
        setStatus('🟢 ' + peers.length + ' نفر آنلاین' + (talk ? ' — گفتگو فعال' : ''), 'online');
    }

    if (talk && !inTalk) {
        notif.classList.add('show');
        $('#talkNotifTxt').textContent = '🎙️ گفتگوی «' + (talk.title || 'عمومی') + '» — ' + talkUsers.length + ' نفر در حال صحبت';
    } else {
        notif.classList.remove('show');
    }

    if (talk && !inTalk) {
        activeTalkCard.style.display = 'flex';
        $('#activeTalkInfo').textContent = talkUsers.length + ' نفر در گفتگو — ' + (talk.title || 'گفتگوی عمومی');
    } else {
        activeTalkCard.style.display = 'none';
    }

    const box = $('#talkUsers');
    if (box) {
        box.innerHTML = talkUsers.map(p => {
            const me = p.is_me ? 'me' : '';
            const raised = p.hand_raised ? 'raised' : '';
            const muted = p.muted ? 'muted' : '';
            const adminLabel = p.is_admin 
                ? '<span class="role admin">👑 مدیر</span>' 
                : '<span class="role">شنونده</span>';
            const adminBtn = (isAdmin && !p.is_me && p.in_talk) ?
                `<button class="admin-btn" data-target="${p.client_id}" data-muted="${p.muted?1:0}">${p.muted?'🔊 باز کردن صدا':'🔇 بستن صدا'}</button>` : '';
            const hand = p.hand_raised ? '<span class="hand-icon">✋</span>' : '';
            return `<div class="talk-user ${me} ${raised}">
                ${hand}
                <div class="talk-avatar ${muted}">${escHtml(p.name.charAt(0).toUpperCase())}</div>
                <span class="name">${escHtml(p.name)}${p.is_me?' (شما)':''}</span>
                ${adminLabel}
                ${adminBtn}
            </div>`;
        }).join('');

        box.querySelectorAll('.admin-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const target = btn.dataset.target;
                const wasMuted = btn.dataset.muted === '1';
                await api('talk-set-mute', { target, muted: wasMuted ? 0 : 1 });
            });
        });
    }

    if (myAudioTrack) myAudioTrack.enabled = !myMuted;

    const micBtn = $('#micToggle');
    if (micBtn) {
        if (myForcedMute) {
            micBtn.className = 'talk-ctrl mute locked';
            micBtn.textContent = '🔇';
            micBtn.title = 'مدیر میکروفن شما را بسته است';
        } else if (myMuted) {
            micBtn.className = 'talk-ctrl mute';
            micBtn.textContent = '🔇';
            micBtn.title = 'میکروفن خاموش — کلیک کنید';
        } else {
            micBtn.className = 'talk-ctrl mute on';
            micBtn.textContent = '🎙️';
            micBtn.title = 'میکروفن روشن — کلیک کنید';
        }
    }

    const handBtn = $('#handToggle');
    if (handBtn) {
        handBtn.className = 'talk-ctrl hand' + (myHandRaised ? ' raised' : '');
        handBtn.textContent = myHandRaised ? '✋' : '✋';
        handBtn.title = myHandRaised ? 'درخواست داده‌اید — کلیک برای لغو' : 'درخواست صحبت';
    }

    if (!talk) {
        overlay.classList.remove('show');
        if (mini) mini.classList.remove('show');
        talkExpanded = false;
        talkMinimized = false;
    } else if (inTalk) {
        if (talkExpanded) {
            overlay.classList.add('show');
            if (mini) mini.classList.remove('show');
        } else {
            overlay.classList.remove('show');
            if (mini) {
                mini.classList.add('show');
                const mt = document.getElementById('talkMiniTxt');
                const mp = document.getElementById('talkMiniPeople');
                if (mt) mt.textContent = talk.title || 'گفتگوی عمومی';
                if (mp) mp.textContent = talkUsers.length;
            }
            talkMinimized = true;
        }
    } else {
        overlay.classList.remove('show');
        if (mini) mini.classList.remove('show');
    }

    if ($('#endTalkBtn')) $('#endTalkBtn').style.display = talk ? 'inline-flex' : 'none';
    if ($('#startTalkBtn')) $('#startTalkBtn').style.display = talk ? 'none' : 'inline-flex';
    if ($('#muteAllBtn')) $('#muteAllBtn').style.display = talk ? 'inline-flex' : 'none';

    if (talk) $('#talkTitle').textContent = talk.title || 'گفتگوی عمومی';
}

/* ═══ Room UI sync ═══ */
function syncRoomUI() {
    if ($('#publicAdminPanel')) $('#publicAdminPanel').style.display =
        (isAdmin && ROOM_TYPE === 'public') ? 'flex' : 'none';
    if ($('#adminLinkCard')) $('#adminLinkCard').style.display = isAdmin ? 'flex' : 'none';
    if ($('#personalCallCard')) $('#personalCallCard').style.display =
        (ROOM_TYPE === 'personal') ? 'flex' : 'none';
    if ($('#callIconBtn')) $('#callIconBtn').style.display =
        (ROOM_TYPE === 'personal') ? 'grid' : 'none';
    if ($('#adminLoginLink')) $('#adminLoginLink').style.display =
        (!isAdmin && hasPassword && ROOM_TYPE === 'public') ? 'inline-flex' : 'none';

    const banner = $('#lockBanner');
    const canChat = !chatLocked || isAdmin;
    if (banner) banner.style.display = (chatLocked && !isAdmin) ? 'block' : 'none';

    ['#msgInput','#sendBtn','#imgBtn','#searchBtn','#searchInput'].forEach(sel => {
        const el = $(sel);
        if (!el) return;
        el.disabled = !me || !canChat;
    });

    if ($('#lockChatBtn')) {
        $('#lockChatBtn').textContent = chatLocked ? '🔓 باز کردن چت' : '🔒 قفل چت';
        $('#lockChatBtn').className = 'btn full ' + (chatLocked ? 'success' : '');
    }

    if ($('#searchInput')) $('#searchInput').style.display = (ROOM_TYPE === 'personal') ? 'block' : 'none';
    if ($('#searchBtn')) $('#searchBtn').style.display = (ROOM_TYPE === 'personal') ? 'inline-flex' : 'none';

    if ($('#muteAllBtn')) {
        const allMuted = currentPeers.filter(p => !p.is_me && p.in_talk).every(p => p.muted || p.forced_mute);
        const hasInTalk = currentPeers.some(p => !p.is_me && p.in_talk);
        $('#muteAllBtn').textContent = (allMuted && hasInTalk) ? '🔊 باز کردن میکروفن همه' : '🔇 قطع میکروفن همه';
    }
}

/* ═══ Poll ═══ */
async function poll() {
    if (!me) { setTimeout(poll, 1300); return; }
    try {
        const f = await api('fetch', { since: sinceId, name: me });
        if (!f || !f.ok) throw new Error(f?.error || 'bad response');

        if (f.room_exists === false) {
            if (hasBeenInRoom) {
                setStatus('⚠️ اتاق حذف شده', 'error');
                setTimeout(() => location.href = 'index.php', 1500);
                return;
            }
            setTimeout(poll, 1300);
            return;
        }

        hasBeenInRoom = true;
        isAdmin = !!f.is_admin;
        $('#messages')?.classList.toggle('admin-mode', isAdmin);
        chatLocked = !!f.chat_locked;
        hasPassword = !!f.has_password;
        activeTalk = f.active_talk;
        roomName = f.room_name || roomName;
        currentPeers = f.peers || [];

        for (const m of f.messages) {
            if (m.id > sinceId) sinceId = m.id;
            handleIncoming(m);
        }

        // حذف پیام‌های حذف‌شده (شامل لاگ‌های سیستمی)
        if (Array.isArray(f.deleted_ids) && f.deleted_ids.length) {
            const box = $('#messages');
            if (box) {
                for (const id of f.deleted_ids) {
                    if (removedIds.has(id)) continue;
                    const el = box.querySelector(`.msg[data-msg-id="${id}"]`);
                    if (el) removeMessageEl(el, id);
                    else removedIds.add(id); // اگر در DOM نبود، ذخیره کن تا دوباره چک نشود
                }
            }
        }

        updateParticipants(currentPeers);
        syncRoomUI();
        updateTalkUI(currentPeers, activeTalk);

        if (ROOM_TYPE === 'personal') {
            const other = currentPeers.find(p => !p.is_me);
            peerName = other ? other.name : '';
            if (other) {
                $('#peerName').textContent = other.name;
                $('#avatar').textContent = other.name.charAt(0).toUpperCase();
                setStatus('🟢 در حال گفتگو', 'online');
            } else {
                $('#peerName').textContent = 'در انتظار طرف مقابل…';
                $('#avatar').textContent = '?';
                setStatus('🟢 متصل — منتظر طرف مقابل', 'online');
            }
            const disabled = !other || inCall;
            if ($('#callBtn')) $('#callBtn').disabled = disabled;
            if ($('#callIconBtn')) $('#callIconBtn').disabled = disabled;
        }

        syncTalkConnections(currentPeers);
    } catch(e) {
        console.error('poll error:', e);
        setStatus('⚠️ خطا: ' + (e.message || ''), 'error');
    }
    setTimeout(poll, 1300);
}

function handleIncoming(m) {
    const mine = (m.client_id === CLIENT_ID);
    switch(m.type) {
        case 'text':
        case 'image':
            addBubble({ id: m.id, user: m.user, type: m.type, content: m.content, mine, time: fmtTime(m.created_at) });
            break;
        case 'system':
            addBubble({ id: m.id, user: m.user, type: 'system', content: m.content, mine: false });
            break;
        case 'link':
            addBubble({ id: m.id, user: m.user, type: 'link', content: m.content, mine, time: fmtTime(m.created_at) });
            break;
        case 'talk-offer': handleTalkOffer(m); break;
        case 'talk-answer': handleTalkAnswer(m); break;
        case 'talk-ice': handleTalkIce(m); break;
        case 'call-offer':
            if (!mine && !inCall && !pendingOffer) {
                pendingOffer = JSON.parse(m.content);
                $('#incomingFrom').textContent = m.user;
                $('#incoming').classList.add('show');
            }
            break;
        case 'call-answer':
            if (!mine && pc) pc.setRemoteDescription(new RTCSessionDescription(JSON.parse(m.content))).then(flushIce).catch(()=>{});
            break;
        case 'call-ice':
            if (!mine) {
                const c = JSON.parse(m.content);
                if (pc && pc.remoteDescription && pc.remoteDescription.type) pc.addIceCandidate(c).catch(()=>{});
                else iceQueue.push(c);
            }
            break;
        case 'call-end':
            if (!mine) endPersonalCall(false);
            break;
    }
}

/* ═══ Talk WebRTC ═══ */
async function joinTalkVoice() {
    if (localStream) return;
    try {
        localStream = await navigator.mediaDevices.getUserMedia({
            audio: { echoCancellation: true, noiseSuppression: true },
            video: false
        });
        myAudioTrack = localStream.getAudioTracks()[0];
        myAudioTrack.enabled = !myMuted;
    } catch(e) {
        console.warn('mic unavailable:', e);
        alert('دسترسی به میکروفون ممکن نشد. ممکن است نتوانید صحبت کنید.');
    }
}

function leaveTalkVoice() {
    Object.keys(talkPeers).forEach(id => {
        try { talkPeers[id].close(); } catch(e) {}
        const a = document.getElementById('audio_' + id);
        if (a) a.remove();
    });
    talkPeers = {};
    if (localStream) {
        localStream.getTracks().forEach(t => t.stop());
        localStream = null;
        myAudioTrack = null;
    }
}

async function ensureTalkPeer(peerId, initiator) {
    if (talkPeers[peerId]) return talkPeers[peerId];
    const conn = new RTCPeerConnection({
        iceServers: [
            { urls: 'stun:stun.l.google.com:19302' },
            { urls: 'stun:stun1.l.google.com:19302' }
        ]
    });
    talkPeers[peerId] = conn;

    if (localStream) localStream.getTracks().forEach(t => conn.addTrack(t, localStream));

    conn.onicecandidate = e => {
        if (e.candidate) {
            api('send', {
                user: me, type: 'talk-ice',
                content: JSON.stringify({ to: peerId, ice: e.candidate })
            }).catch(()=>{});
        }
    };

    conn.ontrack = e => {
        let audio = document.getElementById('audio_' + peerId);
        if (!audio) {
            audio = document.createElement('audio');
            audio.id = 'audio_' + peerId;
            audio.autoplay = true;
            audio.playsInline = true;
            document.body.appendChild(audio);
        }
        audio.srcObject = e.streams[0];
        audio.play().catch(()=>{});
    };

    conn.onconnectionstatechange = () => {
        if (['failed','closed'].includes(conn.connectionState)) {
            delete talkPeers[peerId];
            const a = document.getElementById('audio_' + peerId);
            if (a) a.remove();
        }
    };

    if (initiator) {
        const offer = await conn.createOffer();
        await conn.setLocalDescription(offer);
        await api('send', {
            user: me, type: 'talk-offer',
            content: JSON.stringify({ to: peerId, sdp: offer })
        });
    }

    return conn;
}

async function handleTalkOffer(m) {
    try {
        const data = JSON.parse(m.content);
        if (data.to !== CLIENT_ID) return;
        if (!inTalk) return;
        const conn = await ensureTalkPeer(m.client_id, false);
        await conn.setRemoteDescription(new RTCSessionDescription(data.sdp));
        const answer = await conn.createAnswer();
        await conn.setLocalDescription(answer);
        await api('send', {
            user: me, type: 'talk-answer',
            content: JSON.stringify({ to: m.client_id, sdp: answer })
        });
    } catch(e) { console.error('offer error:', e); }
}

function handleTalkAnswer(m) {
    try {
        const data = JSON.parse(m.content);
        if (data.to !== CLIENT_ID) return;
        const conn = talkPeers[m.client_id];
        if (conn) conn.setRemoteDescription(new RTCSessionDescription(data.sdp)).catch(()=>{});
    } catch(e) {}
}

function handleTalkIce(m) {
    try {
        const data = JSON.parse(m.content);
        if (data.to !== CLIENT_ID) return;
        const conn = talkPeers[m.client_id];
        if (conn) conn.addIceCandidate(new RTCIceCandidate(data.ice)).catch(()=>{});
    } catch(e) {}
}

async function syncTalkConnections(peers) {
    if (!activeTalk || !inTalk) {
        if (!inTalk && Object.keys(talkPeers).length > 0) leaveTalkVoice();
        return;
    }
    if (!localStream) await joinTalkVoice();

    const others = peers.filter(p => p.in_talk && !p.is_me);

    for (const p of others) {
        if (!talkPeers[p.client_id]) {
            try { await ensureTalkPeer(p.client_id, CLIENT_ID < p.client_id); } catch(e) { console.error(e); }
        }
    }

    const stillIn = new Set(others.map(p => p.client_id));
    for (const id of Object.keys(talkPeers)) {
        if (!stillIn.has(id)) {
            try { talkPeers[id].close(); } catch(e) {}
            delete talkPeers[id];
            const a = document.getElementById('audio_' + id);
            if (a) a.remove();
        }
    }
}

/* ═══ Personal call ═══ */
async function startPersonalCall() {
    if (!me) return alert('اول نام خود را وارد کنید');
    if (inCall) return;
    try { localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false }); }
    catch(e) { return alert('دسترسی به میکروفون ممکن نشد'); }

    pc = new RTCPeerConnection({ iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] });
    localStream.getTracks().forEach(t => pc.addTrack(t, localStream));
    pc.onicecandidate = e => {
        if (e.candidate) api('send', { user: me, type: 'call-ice', content: JSON.stringify(e.candidate) }).catch(()=>{});
    };
    pc.ontrack = e => {
        const a = $('#remoteAudio');
        a.srcObject = e.streams[0]; a.play().catch(()=>{});
    };
    pc.onconnectionstatechange = () => {
        if (['failed','disconnected','closed'].includes(pc.connectionState)) endPersonalCall(true);
    };
    const offer = await pc.createOffer();
    await pc.setLocalDescription(offer);
    await api('send', { user: me, type: 'call-offer', content: JSON.stringify(offer) });

    inCall = true;
    $('#callBtn').disabled = true;
    $('#callIconBtn').disabled = true;
    $('#hangBtn').style.display = 'inline-flex';
    $('#callStatus').textContent = 'در حال تماس…';
}

async function acceptPersonalCall() {
    $('#incoming').classList.remove('show');
    if (!pendingOffer) return;
    try { localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false }); }
    catch(e) { return alert('دسترسی به میکروفون ممکن نشد'); }

    pc = new RTCPeerConnection({ iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] });
    localStream.getTracks().forEach(t => pc.addTrack(t, localStream));
    pc.onicecandidate = e => {
        if (e.candidate) api('send', { user: me, type: 'call-ice', content: JSON.stringify(e.candidate) }).catch(()=>{});
    };
    pc.ontrack = e => {
        const a = $('#remoteAudio');
        a.srcObject = e.streams[0]; a.play().catch(()=>{});
    };
    await pc.setRemoteDescription(new RTCSessionDescription(pendingOffer));
    const answer = await pc.createAnswer();
    await pc.setLocalDescription(answer);
    await api('send', { user: me, type: 'call-answer', content: JSON.stringify(answer) });

    pendingOffer = null;
    inCall = true;
    $('#callBtn').disabled = true;
    $('#callIconBtn').disabled = true;
    $('#hangBtn').style.display = 'inline-flex';
    $('#callStatus').textContent = 'در حال تماس…';
}

function flushIce() {
    while (iceQueue.length) {
        try { pc.addIceCandidate(iceQueue.shift()).catch(()=>{}); } catch(e) {}
    }
}

function endPersonalCall(notify) {
    if (notify && me) api('send', { user: me, type: 'call-end', content: '' }).catch(()=>{});
    if (localStream) { localStream.getTracks().forEach(t => t.stop()); localStream = null; }
    if (pc) { try { pc.close(); } catch(e) {} pc = null; }
    iceQueue = []; inCall = false;
    $('#incoming').classList.remove('show');
    $('#hangBtn').style.display = 'none';
    $('#callStatus').textContent = 'تماس پایان یافت';
    $('#remoteAudio').srcObject = null;
    $('#callBtn').disabled = !peerName;
    $('#callIconBtn').disabled = !peerName;
}

/* ═══ Actions ═══ */
async function sendMessage(text) {
    if (!me) return alert('اول نام خود را وارد کنید');
    if (!text.trim()) return;
    const r = await api('send', { user: me, type: 'text', content: text.trim() });
    if (r.ok) {
        sinceId = Math.max(sinceId, r.id);
        addBubble({ id: r.id, user: me, type: 'text', content: text.trim(), mine: true, time: fmtTime(new Date().toISOString()) });
    } else alert(r.error || 'خطا');
}

async function doSearch() {
    const q = $('#searchInput').value.trim();
    if (!q || !me) return;
    const url = 'https://www.google.com/search?q=' + encodeURIComponent(q);
    window.open(url, '_blank', 'noopener');
    const r = await api('send', { user: me, type: 'link', content: JSON.stringify({url, title: q}) });
    if (r.ok) {
        sinceId = Math.max(sinceId, r.id);
        addBubble({ id: r.id, user: me, type: 'link', content: JSON.stringify({url, title: q}), mine: true, time: fmtTime(new Date().toISOString()) });
        $('#searchInput').value = '';
    }
}

async function doImageUpload(file) {
    if (!me || !file) return;
    const fd = new FormData();
    fd.append('action', 'upload');
    fd.append('room', ROOM);
    fd.append('client_id', CLIENT_ID);
    fd.append('user', me);
    fd.append('file', file);
    try {
        const r = await fetch('api.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.ok) {
            sinceId = Math.max(sinceId, j.id);
            addBubble({ id: j.id, user: me, type: 'image', content: j.url, mine: true, time: fmtTime(new Date().toISOString()) });
        } else alert(j.error || 'خطا در آپلود');
    } catch(e) { alert('خطا: ' + e.message); }
}

async function deleteMessage(id, btnEl) {
    if (!isAdmin) return;
    if (!confirm('این پیام حذف شود؟')) return;
    const r = await api('message-delete', { msg_id: id });
    if (r.ok) {
        const row = btnEl?.closest('.msg');
        removeMessageEl(row, id);
    } else {
        alert(r.error || 'خطا در حذف پیام');
    }
}

function doEnter(n) {
    me = n;
    localStorage.setItem('chat_name', n);
    $('#nameInput').value = n;
    $('#nameInput').disabled = true;
    $('#enterBtn').textContent = 'نام ثبت شده ✓';
    $('#enterBtn').disabled = true;
    $('#nameGate').classList.remove('show');
    setStatus('🟢 متصل', 'online');
    syncRoomUI();
    if (!polling) { polling = true; poll(); }
}

/* ═══ Drawer ═══ */
function openDrawer() {
    $('#sidebar').classList.add('open');
    $('#drawerOverlay').classList.add('show');
}
function closeDrawer() {
    $('#sidebar').classList.remove('open');
    $('#drawerOverlay').classList.remove('show');
}

/* ═══ Bindings ═══ */
document.addEventListener('DOMContentLoaded', () => {
    $('#hamburgerBtn')?.addEventListener('click', openDrawer);
    $('#drawerOverlay')?.addEventListener('click', closeDrawer);

    $('#enterBtn')?.addEventListener('click', () => {
        const n = $('#nameInput').value.trim();
        if (!n) return alert('نام را وارد کنید');
        doEnter(n);
    });
    $('#gateEnterBtn')?.addEventListener('click', () => {
        const n = $('#gateNameInput').value.trim();
        if (!n) return alert('نام را وارد کنید');
        doEnter(n);
    });
    $('#gateNameInput')?.addEventListener('keypress', e => {
        if (e.key === 'Enter') $('#gateEnterBtn').click();
    });

    $('#composer')?.addEventListener('submit', e => {
        e.preventDefault();
        const v = $('#msgInput').value;
        if (v.trim()) { sendMessage(v); $('#msgInput').value = ''; }
    });

    $('#searchBtn')?.addEventListener('click', doSearch);
    $('#imgBtn')?.addEventListener('click', () => $('#imgInput').click());
    $('#imgInput')?.addEventListener('change', e => {
        const f = e.target.files[0];
        if (f) doImageUpload(f);
        e.target.value = '';
    });

    $('#copyBtn')?.addEventListener('click', async () => {
        const el = $('#shareInput'); el.select();
        try { await navigator.clipboard.writeText(el.value); $('#copyBtn').textContent = 'کپی شد ✓'; setTimeout(() => $('#copyBtn').textContent = 'کپی', 1500); }
        catch(e) { document.execCommand('copy'); }
    });

    // 🗑 حذف پیام (event delegation)
    $('#messages')?.addEventListener('click', (e) => {
        const btn = e.target.closest('.msg-del');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        const id = parseInt(btn.dataset.id, 10);
        if (!id) return;
        deleteMessage(id, btn);
    });

    $('#callBtn')?.addEventListener('click', startPersonalCall);
    $('#callIconBtn')?.addEventListener('click', startPersonalCall);
    $('#hangBtn')?.addEventListener('click', () => endPersonalCall(true));
    $('#acceptBtn')?.addEventListener('click', acceptPersonalCall);
    $('#rejectBtn')?.addEventListener('click', () => {
        $('#incoming').classList.remove('show');
        pendingOffer = null;
        api('send', { user: me, type: 'call-end', content: '' }).catch(()=>{});
    });

    $('#adminLoginLink')?.addEventListener('click', () => {
        $('#adminLoginModal').classList.add('show');
        setTimeout(() => $('#adminPassInput').focus(), 100);
    });
    $('#adminLoginCancel')?.addEventListener('click', () => {
        $('#adminLoginModal').classList.remove('show');
        $('#adminPassInput').value = '';
    });
    $('#adminLoginSubmit')?.addEventListener('click', async () => {
        const pass = $('#adminPassInput').value;
        if (!pass) return alert('رمز را وارد کنید');
        const r = await api('admin-login', { password: pass });
        if (r.ok) {
            $('#adminLoginModal').classList.remove('show');
            alert('✓ اکنون مدیر هستید');
            location.reload();
        } else alert(r.error || 'رمز اشتباه');
    });

    $('#startTalkBtn')?.addEventListener('click', async () => {
        const title = prompt('نام گفتگو:', 'گفتگوی عمومی');
        if (!title) return;
        const r = await api('talk-start', { title });
        if (!r.ok) return alert(r.error);
        await api('talk-join');
        talkExpanded = true;
        talkMinimized = false;
        await joinTalkVoice();
        $('#talkOverlay').classList.add('show');
        $('#talkMini')?.classList.remove('show');
        closeDrawer();
    });

    $('#endTalkBtn')?.addEventListener('click', async () => {
        if (!confirm('گفتگو پایان یابد؟')) return;
        await api('talk-end');
        leaveTalkVoice();
        talkExpanded = false;
        talkMinimized = false;
        $('#talkOverlay').classList.remove('show');
        $('#talkMini')?.classList.remove('show');
    });

    $('#muteAllBtn')?.addEventListener('click', async () => {
        const allMuted = currentPeers.filter(p => !p.is_me && p.in_talk).every(p => p.muted || p.forced_mute);
        const hasInTalk = currentPeers.some(p => !p.is_me && p.in_talk);
        if (!hasInTalk) return alert('کسی در گفتگو نیست');
        const newMuted = !(allMuted && hasInTalk);
        await api('talk-mute-all', { muted: newMuted ? 1 : 0 });
    });

    $('#joinTalkBtn')?.addEventListener('click', async () => {
        const r = await api('talk-join');
        if (!r.ok) return alert(r.error);
        talkExpanded = true;
        talkMinimized = false;
        await joinTalkVoice();
        $('#talkOverlay').classList.add('show');
        $('#talkMini')?.classList.remove('show');
        closeDrawer();
    });

    $('#talkNotifJoin')?.addEventListener('click', async () => {
        const r = await api('talk-join');
        if (!r.ok) return alert(r.error);
        talkExpanded = true;
        talkMinimized = false;
        await joinTalkVoice();
        $('#talkOverlay').classList.add('show');
        $('#talkMini')?.classList.remove('show');
    });

    $('#talkCollapseBtn')?.addEventListener('click', () => {
        talkExpanded = false;
        talkMinimized = true;
        $('#talkOverlay').classList.remove('show');
        const mini = $('#talkMini');
        if (mini) {
            mini.classList.add('show');
            const title = $('#talkTitle')?.textContent || 'گفتگوی عمومی';
            const mt = document.getElementById('talkMiniTxt');
            if (mt) mt.textContent = title;
            const count = document.querySelectorAll('#talkUsers .talk-user').length;
            const mp = document.getElementById('talkMiniPeople');
            if (mp) mp.textContent = count;
        }
    });

    $('#talkMiniExpand')?.addEventListener('click', () => {
        talkExpanded = true;
        talkMinimized = false;
        $('#talkMini')?.classList.remove('show');
        $('#talkOverlay').classList.add('show');
    });

    $('#micToggle')?.addEventListener('click', async () => {
        if (myForcedMute) {
            alert('میکروفن شما توسط مدیر بسته شده است. برای صحبت، دست خود را بالا ببرید.');
            return;
        }
        const r = await api('talk-toggle-self-mute');
        if (r.ok) {
            myMuted = !!r.muted;
            if (myAudioTrack) myAudioTrack.enabled = !myMuted;
            const btn = $('#micToggle');
            btn.className = 'talk-ctrl mute' + (!myMuted ? ' on' : '');
            btn.textContent = myMuted ? '🔇' : '🎙️';
        } else {
            alert(r.error || 'خطا');
        }
    });

    $('#handToggle')?.addEventListener('click', async () => {
        const r = await api('talk-toggle-hand');
        if (r.ok) {
            myHandRaised = !!r.raised;
            const btn = $('#handToggle');
            btn.className = 'talk-ctrl hand' + (myHandRaised ? ' raised' : '');
        } else {
            alert(r.error || 'خطا');
        }
    });

    $('#talkLeaveBtn')?.addEventListener('click', async () => {
        if (!confirm('از گفتگو خارج شوید؟')) return;
        await api('talk-leave');
        leaveTalkVoice();
        talkExpanded = false;
        talkMinimized = false;
        $('#talkOverlay').classList.remove('show');
        $('#talkMini')?.classList.remove('show');
    });

    $('#lockChatBtn')?.addEventListener('click', async () => {
        await api('chat-lock', { locked: chatLocked ? 0 : 1 });
    });

    $('#deleteRoomBtn')?.addEventListener('click', async () => {
        if (!confirm('کل اتاق حذف شود؟ این عمل غیرقابل بازگشت است!')) return;
        await api('room-delete');
        location.href = 'index.php';
    });

    if (me) {
        $('#nameInput').value = me;
        $('#nameInput').disabled = true;
        $('#enterBtn').textContent = 'نام ثبت شده ✓';
        $('#enterBtn').disabled = true;
        setStatus('🟢 متصل', 'online');
        syncRoomUI();
        polling = true;
        poll();
    } else {
        $('#nameGate').classList.add('show');
        setTimeout(() => $('#gateNameInput').focus(), 200);
    }
});