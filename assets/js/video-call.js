/*
 * Voice and video calls on Messages (account/messages.php): one person to one person, and group calls in a
 * group chat or General (up to 8 people, everyone connected to everyone; see "Group calls" below).
 * A voice call is the same call with the microphone only (no camera, no video screen).
 *
 * The two browsers send sound and video straight to each other (WebRTC). account/call.php only passes
 * the call's state (ringing / answered / ended) and the two connection descriptions between them, so
 * nothing of the call itself goes through Mentora's server. A public STUN server helps the browsers
 * find each other's address; on networks that block direct connections a call may not connect.
 *
 *   Call:    the phone (voice) or camera (video) button in a conversation's header.
 *   Ringing: shows here while Messages is open (workspace pages show a toast that links here,
 *            includes/message_panel.php).
 * Browsers only allow the camera and microphone on https:// pages or on localhost.
 */
(() => {
    const root = document.querySelector('[data-chat]');
    const overlay = document.querySelector('[data-call]');
    const startButtons = [...document.querySelectorAll('[data-call-start]')];
    if (!root || !overlay || !startButtons.length) {
        return;
    }

    const ENDPOINT = 'call.php';
    const ICE_SERVERS = [{ urls: ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302'] }];
    const token = root.dataset.token;
    const ME = Number(root.dataset.user || 0);
    const pick = (selector) => overlay.querySelector(selector);
    const ui = {
        remote: pick('[data-call-remote]'),
        local: pick('[data-call-local]'),
        avatar: pick('[data-call-avatar]'),
        name: pick('[data-call-name]'),
        status: pick('[data-call-status]'),
        accept: pick('[data-call-accept]'),
        decline: pick('[data-call-decline]'),
        hangup: pick('[data-call-hangup]'),
        mute: pick('[data-call-mute]'),
        camera: pick('[data-call-camera]')
    };

    let call = null;              // the call in progress: { role, kind, id, peer, pc, stream, after, connected, ... }
    let ringing = null;           // a call ringing for you: { id, kind, callerId, callerName }
    let room = null;              // the group call you're in: { id, groupId, groupName, kind, stream, peers, after, ... }
    let groupRinging = null;      // a group call ringing for you: { id, groupId, groupName, kind, starterName }
    let groupCalls = [];          // group calls going on in your chats (the "Join" banner)
    const dismissedGroup = new Set();
    const busy = () => Boolean(call || room || ringing || groupRinging);
    const kindName = (kind) => (kind === 'voice' ? 'voice call' : 'video call');
    const dismissed = new Set();  // calls you declined (so a slow poll doesn't show them again)
    let hideTimer;
    let clockTimer;
    let incomingTimer;
    let lastFocus = null;

    const post = async (action, fields = {}) => {
        const form = new FormData();
        form.append('action', action);
        form.append('message_action_token', token);
        Object.entries(fields).forEach(([name, value]) => form.append(name, String(value)));
        try {
            const response = await fetch(ENDPOINT, { method: 'POST', body: form, credentials: 'same-origin', headers: { Accept: 'application/json' } });
            return await response.json();
        } catch (error) {
            return { ok: false, error: 'network' };
        }
    };

    // Once a call is over, its line ("📹 Video call · 4:05", "📹 Missed video call"…) goes into the conversation
    // right away (MentoraChat on the Messages page). The other side may still be closing it, so try a few times.
    const showCallLog = async (callId, tries = 5) => {
        if (!callId || !window.MentoraChat) {
            return;
        }
        const result = await post('log', { call_id: callId });
        if (result.ok && result.message) {
            window.MentoraChat.addMessage(result.message);
        } else if (tries > 1 && (result.error === 'pending' || result.error === 'network')) {
            setTimeout(() => showCallLog(callId, tries - 1), 1200);
        }
    };

    // ---------- The call screen ----------
    // A person's (u12) or a group's (g3) picture from the chat list, or initials.
    const avatarFor = (key, name) => {
        const known = document.querySelector(`.chat-conversation[data-conversation="${CSS.escape(key)}"] .avatar`);
        if (known) {
            return known.cloneNode(true);
        }
        const initials = document.createElement('span');
        initials.className = 'avatar avatar-initials';
        initials.textContent = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0].toUpperCase()).join('') || 'M';
        return initials;
    };

    // state: outgoing | incoming | connecting | connected | ended
    const show = (state, person, status) => {
        clearTimeout(hideTimer);
        overlay.dataset.state = state;
        if (person) {
            ui.name.textContent = person.name;
            ui.avatar.replaceChildren(avatarFor(person.key || `u${person.id}`, person.name));
        }
        ui.status.textContent = status;
        if (overlay.hidden) {
            lastFocus = document.activeElement;
            overlay.hidden = false;
        }
        if (state === 'incoming') {
            ui.accept.focus();
        } else if (state !== 'ended' && !overlay.contains(document.activeElement)) {
            ui.hangup.focus();
        }
    };

    const hide = () => {
        overlay.hidden = true;
        overlay.dataset.state = 'ended';
        ui.remote.srcObject = null;
        ui.local.srcObject = null;
        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus();
        }
    };

    const resetToggles = () => {
        ui.mute.setAttribute('aria-pressed', 'false');
        ui.mute.setAttribute('aria-label', 'Mute microphone');
        ui.mute.querySelector('span').textContent = 'Mute';
        ui.camera.setAttribute('aria-pressed', 'false');
        ui.camera.setAttribute('aria-label', 'Turn camera off');
        ui.camera.disabled = false;
    };

    const clockText = (current) => {
        const seconds = Math.max(0, Math.floor((Date.now() - current.connectedAt) / 1000));
        return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
    };

    // ---------- Ringtone (a soft double beep every 2 seconds, made by the browser) ----------
    let ringContext = null;
    let ringTimer = null;
    const stopRingtone = () => {
        clearInterval(ringTimer);
        ringTimer = null;
        if (ringContext) {
            ringContext.close().catch(() => {});
            ringContext = null;
        }
    };
    const startRingtone = () => {
        stopRingtone();
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) {
            return;
        }
        try {
            ringContext = new AudioContextClass();
            const beep = () => {
                if (!ringContext) {
                    return;
                }
                [0, 0.45].forEach((offset) => {
                    const oscillator = ringContext.createOscillator();
                    const gain = ringContext.createGain();
                    const start = ringContext.currentTime + offset;
                    oscillator.frequency.value = 480;
                    gain.gain.setValueAtTime(0.0001, start);
                    gain.gain.exponentialRampToValueAtTime(0.12, start + 0.03);
                    gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.35);
                    oscillator.connect(gain);
                    gain.connect(ringContext.destination);
                    oscillator.start(start);
                    oscillator.stop(start + 0.36);
                });
            };
            beep();
            ringTimer = setInterval(beep, 2000);
        } catch (error) {
            ringContext = null; // sound is a nice extra; the screen still shows the call
        }
    };

    // ---------- Camera and microphone (voice calls: microphone only) ----------
    const getMedia = async (kind) => {
        if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.RTCPeerConnection) {
            return { error: 'Calls need a secure page (https://) or localhost, in an up-to-date browser.' };
        }
        if (kind === 'voice') {
            try {
                return { stream: await navigator.mediaDevices.getUserMedia({ audio: true }) };
            } catch (error) {
                return {
                    error: error.name === 'NotAllowedError'
                        ? 'Allow microphone access for this site in your browser, then try again.'
                        : 'No microphone was found.'
                };
            }
        }
        try {
            return { stream: await navigator.mediaDevices.getUserMedia({ video: true, audio: true }) };
        } catch (error) {
            try {
                return { stream: await navigator.mediaDevices.getUserMedia({ audio: true }) }; // no camera: voice only
            } catch (second) {
                return {
                    error: error.name === 'NotAllowedError'
                        ? 'Allow camera and microphone access for this site in your browser, then try again.'
                        : 'No camera or microphone was found.'
                };
            }
        }
    };

    const useStream = (current, stream) => {
        current.stream = stream;
        ui.local.srcObject = stream;
        ui.camera.disabled = stream.getVideoTracks().length === 0;
    };

    // ---------- Connection ----------
    const createPeer = (current) => {
        const pc = new RTCPeerConnection({ iceServers: ICE_SERVERS });
        current.stream.getTracks().forEach((track) => pc.addTrack(track, current.stream));
        if (current.kind === 'video' && !current.stream.getVideoTracks().length) {
            pc.addTransceiver('video', { direction: 'recvonly' }); // no camera here: still see the other person
        }
        pc.addEventListener('track', (event) => {
            // Their sound (and picture): in a voice call this element is invisible but still plays the audio.
            ui.remote.srcObject = event.streams[0] || new MediaStream([event.track]);
            ui.remote.play().catch(() => {});
        });
        pc.addEventListener('connectionstatechange', () => {
            if (call !== current) {
                return;
            }
            if (pc.connectionState === 'connected') {
                connected(current);
            } else if (pc.connectionState === 'failed') {
                hangUp("The call couldn't connect. Your networks may block direct calls.");
            }
        });
        return pc;
    };

    // Wait until the browser has listed its addresses (or 3 seconds), so the whole description goes at once.
    const waitForAddresses = (pc) => new Promise((resolve) => {
        if (pc.iceGatheringState === 'complete') {
            resolve();
            return;
        }
        const check = () => {
            if (pc.iceGatheringState === 'complete') {
                pc.removeEventListener('icegatheringstatechange', check);
                resolve();
            }
        };
        pc.addEventListener('icegatheringstatechange', check);
        setTimeout(resolve, 3000);
    });

    const sendDescription = async (current, kind) => {
        const pc = current.pc;
        await waitForAddresses(pc);
        if (call !== current) {
            return false;
        }
        const result = await post('signal', {
            call_id: current.id,
            kind,
            payload: JSON.stringify({ type: pc.localDescription.type, sdp: pc.localDescription.sdp })
        });
        return result.ok === true;
    };

    const handleSignal = async (current, signal) => {
        let description;
        try {
            description = JSON.parse(signal.payload);
        } catch (error) {
            return;
        }
        try {
            if (signal.kind === 'answer' && current.role === 'caller' && !current.pc.currentRemoteDescription) {
                show('connecting', null, 'Connecting…');
                await current.pc.setRemoteDescription(description);
            } else if (signal.kind === 'offer' && current.role === 'callee' && !current.pc.currentRemoteDescription) {
                await current.pc.setRemoteDescription(description);
                await current.pc.setLocalDescription(await current.pc.createAnswer());
                if (!await sendDescription(current, 'answer')) {
                    hangUp("The call couldn't connect. Please try again.");
                }
            }
        } catch (error) {
            hangUp("The call couldn't connect. Please try again.");
        }
    };

    const endText = (current, status) => {
        const name = current.peer.name;
        if (status === 'declined') {
            return current.role === 'caller' ? `${name} declined the call.` : 'Call declined.';
        }
        if (status === 'missed') {
            return current.role === 'caller' ? `${name} didn't answer.` : 'Missed call.';
        }
        return current.connected ? `Call ended · ${clockText(current)}` : 'Call ended.';
    };

    // While a call is open: new signals from the other side, and whether they hung up.
    const poll = async (current) => {
        if (call !== current) {
            return;
        }
        const result = await post('poll', { call_id: current.id, after: current.after });
        if (call !== current) {
            return;
        }
        if (result.ok) {
            for (const signal of result.signals) {
                current.after = Math.max(current.after, signal.id);
                await handleSignal(current, signal);
                if (call !== current) {
                    return;
                }
            }
            if (result.status !== 'ringing' && result.status !== 'active') {
                end(current, endText(current, result.status));
                return;
            }
            if (current.role === 'caller' && result.status === 'active' && !current.connected && overlay.dataset.state === 'outgoing') {
                show('connecting', null, 'Connecting…');
            }
        } else if (['not_found', 'signed_out', 'session'].includes(result.error)) {
            end(current, 'Call ended.');
            return;
        }
        current.timer = setTimeout(() => poll(current), current.connected ? 2000 : 1000);
    };

    const connected = (current) => {
        if (current.connected) {
            return;
        }
        current.connected = true;
        current.connectedAt = Date.now();
        show('connected', null, '0:00');
        clearInterval(clockTimer);
        clockTimer = setInterval(() => {
            if (call === current) {
                ui.status.textContent = clockText(current);
            }
        }, 1000);
    };

    // Close everything on this side and say why (the server side is handled by the caller of this).
    const end = (current, text) => {
        if (call !== current) {
            return;
        }
        call = null;
        clearTimeout(current.timer);
        clearInterval(clockTimer);
        if (current.pc) {
            current.pc.close();
        }
        if (current.stream) {
            current.stream.getTracks().forEach((track) => track.stop());
        }
        ui.remote.srcObject = null;
        ui.local.srcObject = null;
        resetToggles();
        show('ended', null, text);
        hideTimer = setTimeout(hide, 2600);
        if (current.id && !current.hangingUp) {
            showCallLog(current.id); // the other side ended it (hangUp shows the line itself once its "end" is saved)
        }
    };

    const hangUp = (text) => {
        const current = call;
        if (!current) {
            return;
        }
        current.hangingUp = true;
        if (current.id) {
            post('end', { call_id: current.id }).then(() => showCallLog(current.id));
        }
        end(current, text || (current.connected ? `Call ended · ${clockText(current)}` : 'Call ended.'));
    };

    // ---------- Calling someone (the phone or camera button) ----------
    const startCall = async (kind) => {
        const item = document.querySelector('.chat-conversation.selected');
        if (busy() || !item || item.dataset.canCall !== '1') {
            return;
        }
        if (item.dataset.group) {
            startGroupCall(item, kind); // a group chat or General
            return;
        }
        const peer = { id: item.dataset.recipient, name: item.dataset.name };
        const current = { role: 'caller', kind, peer, id: null, pc: null, stream: null, after: 0, connected: false };
        call = current;
        overlay.dataset.mode = 'single';
        overlay.dataset.kind = kind;
        show('outgoing', peer, kind === 'voice' ? 'Starting your microphone…' : 'Starting your camera…');

        const media = await getMedia(kind);
        if (call !== current) {
            media.stream?.getTracks().forEach((track) => track.stop());
            return;
        }
        if (media.error) {
            end(current, media.error);
            return;
        }
        useStream(current, media.stream);
        ui.status.textContent = 'Calling…';

        const result = await post('start', { callee_id: peer.id, kind });
        if (call !== current) {
            if (result.ok) {
                post('end', { call_id: result.call.id }); // hung up while it was starting
            }
            return;
        }
        if (!result.ok) {
            end(current, result.error === 'busy' ? `${peer.name} is on another call. Try again later.` : "The call couldn't start. Please try again.");
            return;
        }
        current.id = result.call.id;
        current.pc = createPeer(current);
        try {
            await current.pc.setLocalDescription(await current.pc.createOffer());
            if (!await sendDescription(current, 'offer')) {
                hangUp("The call couldn't start. Please try again.");
                return;
            }
        } catch (error) {
            hangUp("The call couldn't start. Please try again.");
            return;
        }
        poll(current);
    };
    startButtons.forEach((button) => button.addEventListener('click', () => startCall(button.dataset.callStart === 'voice' ? 'voice' : 'video')));

    // ---------- Group calls (a group chat or General) ----------
    // Everyone in the call has a direct connection to everyone else. Whoever joins sends an offer to each
    // person already there; those people answer. Each person is a tile in the grid (video, or their picture).
    const grid = pick('[data-call-grid]');
    const node = (tag, className, text) => {
        const element = document.createElement(tag);
        if (className) {
            element.className = className;
        }
        if (text !== undefined) {
            element.textContent = text;
        }
        return element;
    };

    const roomStatus = (current) => {
        const count = current.peers.size + 1;
        const clock = clockText({ connectedAt: current.joinedAt });
        ui.status.textContent = current.peers.size
            ? `${count} in the call · ${clock}`
            : `Waiting for others to join… · ${clock}`;
        grid.dataset.count = String(current.peers.size);
    };

    const addTile = (current, person) => {
        const tile = node('div', 'chat-call-tile');
        const video = node('video');
        video.autoplay = true;
        video.playsInline = true;
        const picture = node('span', 'chat-call-tile-avatar');
        picture.append(avatarFor(`u${person.id}`, person.name));
        const label = node('span', 'chat-call-tile-name', person.name);
        const state = node('small', 'chat-call-tile-state', 'Connecting…');
        tile.append(video, picture, label, state);
        grid.append(tile);
        return { tile, video, state };
    };

    const removePeer = (current, personId) => {
        const peer = current.peers.get(personId);
        if (!peer) {
            return;
        }
        peer.pc.close();
        peer.tile.tile.remove();
        current.peers.delete(personId);
        roomStatus(current);
    };

    const groupPeer = (current, person) => {
        removePeer(current, person.id); // someone who rejoined starts fresh
        const pc = new RTCPeerConnection({ iceServers: ICE_SERVERS });
        current.stream.getTracks().forEach((track) => pc.addTrack(track, current.stream));
        if (current.kind === 'video' && !current.stream.getVideoTracks().length) {
            pc.addTransceiver('video', { direction: 'recvonly' });
        }
        const peer = { id: person.id, name: person.name, pc, tile: addTile(current, person) };
        pc.addEventListener('track', (event) => {
            peer.tile.video.srcObject = event.streams[0] || new MediaStream([event.track]);
            peer.tile.video.play().catch(() => {});
            if (event.track.kind === 'video') {
                peer.tile.tile.classList.add('has-video');
            }
        });
        pc.addEventListener('connectionstatechange', () => {
            if (room !== current) {
                return;
            }
            peer.tile.state.textContent = pc.connectionState === 'connected' ? ''
                : pc.connectionState === 'failed' ? 'Couldn’t connect' : 'Connecting…';
        });
        current.peers.set(person.id, peer);
        roomStatus(current);
        return peer;
    };

    const sendGroupDescription = async (current, peer, kind) => {
        await waitForAddresses(peer.pc);
        if (room !== current || current.peers.get(peer.id) !== peer) {
            return;
        }
        await post('group_signal', {
            call_id: current.id,
            to_user: peer.id,
            kind,
            payload: JSON.stringify({ type: peer.pc.localDescription.type, sdp: peer.pc.localDescription.sdp })
        });
    };

    const offerTo = async (current, person) => {
        const peer = groupPeer(current, person);
        try {
            await peer.pc.setLocalDescription(await peer.pc.createOffer());
            await sendGroupDescription(current, peer, 'offer');
        } catch (error) {
            peer.tile.state.textContent = 'Couldn’t connect';
        }
    };

    const handleGroupSignal = async (current, signal, names) => {
        let description;
        try {
            description = JSON.parse(signal.payload);
        } catch (error) {
            return;
        }
        try {
            if (signal.kind === 'offer') {
                // Two people who joined at the same moment may call each other at once: the lower id's offer wins.
                const pending = current.peers.get(signal.from);
                if (pending && pending.pc.signalingState === 'have-local-offer' && ME < signal.from) {
                    return;
                }
                // Someone joined after you: answer them.
                const peer = groupPeer(current, { id: signal.from, name: names.get(signal.from) || 'Someone' });
                await peer.pc.setRemoteDescription(description);
                await peer.pc.setLocalDescription(await peer.pc.createAnswer());
                await sendGroupDescription(current, peer, 'answer');
            } else {
                const peer = current.peers.get(signal.from);
                if (peer && !peer.pc.currentRemoteDescription) {
                    await peer.pc.setRemoteDescription(description);
                }
            }
        } catch (error) {
            const peer = current.peers.get(signal.from);
            if (peer) {
                peer.tile.state.textContent = 'Couldn’t connect';
            }
        }
    };

    const groupPoll = async (current) => {
        if (room !== current) {
            return;
        }
        const result = await post('group_poll', { call_id: current.id, after: current.after });
        if (room !== current) {
            return;
        }
        if (result.ok) {
            if (result.status !== 'active') {
                endRoom(current, 'The call has ended.');
                return;
            }
            const names = new Map(result.people.map((person) => [person.id, person.name]));
            for (const signal of result.signals) {
                current.after = Math.max(current.after, signal.id);
                await handleGroupSignal(current, signal, names);
                if (room !== current) {
                    return;
                }
            }
            // People who left (or dropped out): their tile goes.
            [...current.peers.keys()].filter((id) => !names.has(id)).forEach((id) => removePeer(current, id));
        } else if (['not_found', 'signed_out', 'session'].includes(result.error)) {
            endRoom(current, 'The call has ended.');
            return;
        }
        current.timer = setTimeout(() => groupPoll(current), 1500);
    };

    // Close everything on this side.
    const endRoom = (current, text) => {
        if (room !== current) {
            return;
        }
        room = null;
        clearTimeout(current.timer);
        clearInterval(clockTimer);
        current.peers.forEach((peer) => peer.pc.close());
        current.peers.clear();
        grid.replaceChildren();
        if (current.stream) {
            current.stream.getTracks().forEach((track) => track.stop());
        }
        ui.local.srcObject = null;
        resetToggles();
        show('ended', null, text);
        hideTimer = setTimeout(hide, 2600);
        updateBanner();
    };

    const leaveRoom = (text) => {
        const current = room;
        if (!current) {
            return;
        }
        if (current.joined) {
            post('group_leave', { call_id: current.id });
        }
        endRoom(current, text || `You left the call · ${clockText({ connectedAt: current.joinedAt })}`);
    };

    const joinGroupCall = async (callId, kind, groupId, groupName) => {
        if (call || room) {
            return;
        }
        const current = { id: callId, groupId, groupName, kind, stream: null, peers: new Map(), after: 0, joined: false, joinedAt: Date.now() };
        room = current;
        overlay.dataset.mode = 'group';
        overlay.dataset.kind = kind;
        show('connecting', { key: `g${groupId}`, name: groupName }, kind === 'voice' ? 'Starting your microphone…' : 'Starting your camera…');

        const media = await getMedia(kind);
        if (room !== current) {
            media.stream?.getTracks().forEach((track) => track.stop());
            post('group_leave', { call_id: callId }); // a starter is already counted in the call
            return;
        }
        if (media.error) {
            post('group_leave', { call_id: callId });
            endRoom(current, media.error);
            return;
        }
        useStream(current, media.stream);
        const result = await post('group_join', { call_id: callId });
        if (room !== current) {
            if (result.ok) {
                post('group_leave', { call_id: callId });
            }
            return;
        }
        if (!result.ok) {
            endRoom(current, result.error === 'full' ? 'This call is full (8 people at most).' : 'This call has already ended.');
            return;
        }
        current.joined = true;
        current.joinedAt = Date.now();
        show('connected', null, '');
        roomStatus(current);
        clearInterval(clockTimer);
        clockTimer = setInterval(() => {
            if (room === current) {
                roomStatus(current);
            }
        }, 1000);
        updateBanner();
        // You joined last: you call everyone already there.
        result.people.forEach((person) => offerTo(current, person));
        groupPoll(current);
    };

    async function startGroupCall(item, kind) {
        const groupId = Number(item.dataset.conversation.slice(1));
        const result = await post('group_start', { group_id: groupId, kind });
        if (!result.ok) {
            overlay.dataset.mode = 'group';
            show('ended', { key: item.dataset.conversation, name: item.dataset.name }, "The call couldn't start. Please try again.");
            hideTimer = setTimeout(hide, 2600);
            return;
        }
        // If a call is already going on in the group, this joins it (in its own kind: voice or video).
        joinGroupCall(result.call.id, result.call.kind, groupId, item.dataset.name);
    }

    // "Join" banner in a group chat with a call going on.
    const banner = document.querySelector('[data-group-call-banner]');
    function updateBanner() {
        if (!banner) {
            return;
        }
        const item = document.querySelector('.chat-conversation.selected');
        const groupId = item && item.dataset.group ? Number(item.dataset.conversation.slice(1)) : 0;
        const ongoing = groupCalls.find((groupCall) => groupCall.groupId === groupId);
        const here = room && room.groupId === groupId;
        banner.hidden = !ongoing || Boolean(here);
        if (banner.hidden) {
            return;
        }
        const people = ongoing.people;
        banner.querySelector('[data-group-call-text]').textContent = `${ongoing.kind === 'voice' ? '📞 Voice' : '📹 Video'} call going on · `
            + `${people} ${people === 1 ? 'person' : 'people'} in it`;
        const join = banner.querySelector('[data-group-call-join]');
        join.disabled = Boolean(call || room);
        join.onclick = () => joinGroupCall(ongoing.id, ongoing.kind, groupId, item.dataset.name);
    }
    document.addEventListener('click', (event) => {
        if (event.target.closest('.chat-conversation')) {
            setTimeout(updateBanner, 0); // another chat opened
        }
    });

    // ---------- Someone calling you ----------
    const checkIncoming = async () => {
        clearTimeout(incomingTimer);
        let incoming = ringing;
        let incomingGroup = groupRinging;
        try {
            const response = await fetch(`${ENDPOINT}?action=incoming`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const result = await response.json();
            if (result.ok) {
                incoming = result.call;
                incomingGroup = result.groupCall;
                groupCalls = result.groupCalls || [];
                updateBanner();
            }
        } catch (error) {
            // network hiccup: keep showing what we had
        }

        // A group call ringing for you (only while you're not in a call or being called).
        if (!call && !room && !ringing) {
            if (incomingGroup && !dismissedGroup.has(incomingGroup.id) && (!groupRinging || groupRinging.id !== incomingGroup.id)) {
                groupRinging = incomingGroup;
                overlay.dataset.mode = 'group';
                overlay.dataset.kind = incomingGroup.kind === 'voice' ? 'voice' : 'video';
                show('incoming', { key: `g${incomingGroup.groupId}`, name: incomingGroup.groupName },
                    `${incomingGroup.starterName} started a group ${kindName(incomingGroup.kind)}…`);
                startRingtone();
            } else if (!incomingGroup && groupRinging) {
                groupRinging = null;
                stopRingtone();
                hide();
            }
        }

        // A one-to-one call ringing for you.
        if (!call && !room && !groupRinging) {
            if (incoming && !dismissed.has(incoming.id) && (!ringing || ringing.id !== incoming.id)) {
                ringing = incoming;
                overlay.dataset.mode = 'single';
                overlay.dataset.kind = incoming.kind === 'voice' ? 'voice' : 'video';
                show('incoming', { id: incoming.callerId, name: incoming.callerName }, `Incoming ${kindName(incoming.kind)}…`);
                startRingtone();
            } else if (!incoming && ringing) {
                const { callerName, id, kind } = ringing;
                ringing = null;
                stopRingtone();
                show('ended', null, `Missed ${kindName(kind)} from ${callerName}.`);
                hideTimer = setTimeout(hide, 3000);
                showCallLog(id);
            }
        }
        incomingTimer = setTimeout(checkIncoming, ringing || groupRinging ? 1500 : 3000);
    };

    ui.accept.addEventListener('click', async () => {
        if (groupRinging && !call && !room) {
            const incomingGroup = groupRinging;
            groupRinging = null;
            stopRingtone();
            joinGroupCall(incomingGroup.id, incomingGroup.kind, incomingGroup.groupId, incomingGroup.groupName);
            return;
        }
        const incoming = ringing;
        if (!incoming || call || room) {
            return;
        }
        ringing = null;
        stopRingtone();
        const peer = { id: incoming.callerId, name: incoming.callerName };
        const kind = incoming.kind === 'voice' ? 'voice' : 'video';
        const current = { role: 'callee', kind, peer, id: incoming.id, pc: null, stream: null, after: 0, connected: false };
        call = current;
        show('connecting', peer, kind === 'voice' ? 'Starting your microphone…' : 'Starting your camera…');

        const media = await getMedia(kind);
        if (call !== current) {
            media.stream?.getTracks().forEach((track) => track.stop());
            return;
        }
        if (media.error) {
            post('decline', { call_id: current.id });
            end(current, media.error);
            return;
        }
        useStream(current, media.stream);
        const result = await post('answer', { call_id: current.id });
        if (call !== current) {
            return;
        }
        if (!result.ok) {
            end(current, 'This call has already ended.');
            return;
        }
        ui.status.textContent = 'Connecting…';
        current.pc = createPeer(current);
        poll(current); // the caller's offer arrives here, then we answer it
    });

    ui.decline.addEventListener('click', () => {
        if (groupRinging) {
            // Not now: it stops ringing here; the call goes on, and the chat's "Join" banner can still get you in.
            dismissedGroup.add(groupRinging.id);
            groupRinging = null;
            stopRingtone();
            hide();
            return;
        }
        const incoming = ringing;
        if (!incoming) {
            return;
        }
        ringing = null;
        stopRingtone();
        dismissed.add(incoming.id);
        post('decline', { call_id: incoming.id }).then(() => showCallLog(incoming.id));
        hide();
    });

    ui.hangup.addEventListener('click', () => (room ? leaveRoom() : hangUp()));

    // ---------- Mute and camera (one-to-one or group call) ----------
    const activeStream = () => (call || room)?.stream || null;
    ui.mute.addEventListener('click', () => {
        const tracks = activeStream()?.getAudioTracks() || [];
        if (!tracks.length) {
            return;
        }
        const muted = ui.mute.getAttribute('aria-pressed') !== 'true';
        tracks.forEach((track) => { track.enabled = !muted; });
        ui.mute.setAttribute('aria-pressed', String(muted));
        ui.mute.setAttribute('aria-label', muted ? 'Unmute microphone' : 'Mute microphone');
        ui.mute.querySelector('span').textContent = muted ? 'Unmute' : 'Mute';
    });

    ui.camera.addEventListener('click', () => {
        const tracks = activeStream()?.getVideoTracks() || [];
        if (!tracks.length) {
            return;
        }
        const off = ui.camera.getAttribute('aria-pressed') !== 'true';
        tracks.forEach((track) => { track.enabled = !off; });
        ui.camera.setAttribute('aria-pressed', String(off));
        ui.camera.setAttribute('aria-label', off ? 'Turn camera on' : 'Turn camera off');
    });

    // Leaving the page during a call: ask first, and hang up if they leave anyway.
    window.addEventListener('beforeunload', (event) => {
        if (call || room) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
    window.addEventListener('pagehide', () => {
        const id = call?.id || (room?.joined ? room.id : null);
        if (id && navigator.sendBeacon) {
            const form = new FormData();
            form.append('action', call ? 'end' : 'group_leave');
            form.append('message_action_token', token);
            form.append('call_id', String(id));
            navigator.sendBeacon(ENDPOINT, form);
        }
    });

    checkIncoming();
})();
