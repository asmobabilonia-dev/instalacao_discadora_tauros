(function () {
    const SIPLib = window.SIP;

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function setText(root, selector, text) {
        const node = root.querySelector(selector);
        if (node) node.textContent = text;
    }

    function statusTextPt(text) {
        return String(text || '')
            .replace(/^Started$/, 'SIP conectado')
            .replace(/^Starting$/, 'Conectando SIP')
            .replace(/^Stopped$/, 'SIP desconectado')
            .replace(/^Stopping$/, 'Desconectando SIP')
            .replace('WebSocket closed', 'WebSocket desconectado')
            .replace('code:', 'codigo:')
            .replace('Transport error', 'Erro de transporte')
            .replace('Connection unavailable', 'Conexao indisponivel');
    }

    function setCallEvent(root, text) {
        const node = root.querySelector('[data-call-title]');
        if (!node) return;
        node.textContent = text;
        const value = String(text || '').toLowerCase();
        let tone = 'idle';
        if (value.includes('chamando') || value.includes('dtmf')) tone = 'warn';
        if (value.includes('atendida') || value.includes('microfone ativo')) tone = 'ok';
        if (value.includes('erro') || value.includes('mudo')) tone = 'error';
        if (value.includes('encerrada') || value.includes('finalizada') || value.includes('desligada')) tone = 'ended';
        node.dataset.tone = tone;
    }

    function setStatus(root, text, tone) {
        const node = root.querySelector('[data-softphone-status]');
        const nodes = node ? [node] : [];
        if (root.matches('[data-agent-phone]')) {
            document.querySelectorAll('[data-agent-top-status]').forEach((item) => nodes.push(item));
        }
        nodes.forEach((item) => {
            item.textContent = statusTextPt(text);
            item.dataset.tone = tone || 'idle';
        });
    }

    function logCall(root, text, tone) {
        const items = root.querySelector('[data-call-log-items]');
        if (!items) return;
        const row = document.createElement('div');
        row.className = 'call-log-row';
        row.dataset.tone = tone || 'idle';
        row.innerHTML = `<time>${new Date().toLocaleTimeString()}</time><span></span>`;
        row.querySelector('span').textContent = text;
        items.prepend(row);
    }

    function setHangupEnabled(root, enabled) {
        root.querySelectorAll('[data-softphone-hangup]').forEach((button) => {
            button.disabled = !enabled;
            button.textContent = enabled ? 'Desligar' : 'Sem chamada';
        });
    }

    function setTransferEnabled(root, enabled) {
        root.querySelectorAll('[data-softphone-transfer]').forEach((button) => {
            button.disabled = !enabled;
        });
    }

    function resetRemoteAudio(audio) {
        if (!audio) return;
        window.clearTimeout(audio.playTimer);
        audio.pause();
        if (audio.srcObject instanceof MediaStream) {
            audio.srcObject.getTracks().forEach((track) => track.stop());
        }
        audio.srcObject = null;
        audio.removeAttribute('src');
        audio.load();
    }

    function describeTrack(track) {
        if (!track) return 'sem track';
        return `${track.kind}:${track.label || 'sem nome'} ${track.enabled ? 'ativo' : 'desativado'} ${track.muted ? 'mudo' : 'com sinal'} ${track.readyState}`;
    }

    async function ensureMicrophoneReady(root) {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            throw new Error('Este navegador nao oferece captura de microfone.');
        }
        const stream = await navigator.mediaDevices.getUserMedia({
            audio: {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true
            },
            video: false
        });
        const tracks = stream.getAudioTracks();
        logCall(root, `Microfone liberado: ${tracks.map(describeTrack).join(', ') || 'sem track de audio'}`, tracks.length ? 'ok' : 'error');
        stream.getTracks().forEach((track) => track.stop());
    }

    function attachAudioStream(root, audio, stream, label) {
        if (!audio || !stream) return;
        const hasStream = audio.srcObject instanceof MediaStream;
        const current = hasStream ? audio.srcObject : new MediaStream();
        let added = false;
        stream.getAudioTracks().forEach((track) => {
            if (!current.getTracks().some((item) => item.id === track.id)) {
                current.addTrack(track);
                added = true;
                logCall(root, `${label}: ${describeTrack(track)}`, 'ok');
            }
        });
        if (!hasStream) audio.srcObject = current;
        audio.autoplay = true;
        audio.playsInline = true;
        audio.muted = false;
        audio.volume = 1;
        if (added || !hasStream) {
            window.clearTimeout(audio.playTimer);
            audio.playTimer = window.setTimeout(() => {
                audio.play().catch((error) => {
                    logCall(root, `Clique no controle de audio remoto se o navegador bloquear: ${error.message}`, 'warn');
                });
            }, 150);
        }
    }

    function inspectPeerConnection(root, session, label) {
        if (!session || !session.sessionDescriptionHandler) {
            logCall(root, `${label}: sem sessionDescriptionHandler`, 'warn');
            return;
        }
        const pc = session.sessionDescriptionHandler.peerConnection;
        if (!pc) {
            logCall(root, `${label}: sem peerConnection`, 'warn');
            return;
        }
        const senders = pc.getSenders().filter((sender) => sender.track && sender.track.kind === 'audio');
        const receivers = pc.getReceivers().filter((receiver) => receiver.track && receiver.track.kind === 'audio');
        const localSdp = pc.localDescription && pc.localDescription.sdp ? pc.localDescription.sdp : '';
        const remoteSdp = pc.remoteDescription && pc.remoteDescription.sdp ? pc.remoteDescription.sdp : '';
        const localCandidates = (localSdp.match(/^a=candidate:/gm) || []).length;
        const remoteCandidates = (remoteSdp.match(/^a=candidate:/gm) || []).length;
        const localDirection = (localSdp.match(/^a=(sendrecv|sendonly|recvonly|inactive)$/m) || [])[1] || 'n/a';
        const remoteDirection = (remoteSdp.match(/^a=(sendrecv|sendonly|recvonly|inactive)$/m) || [])[1] || 'n/a';
        logCall(root, `${label}: ICE=${pc.iceConnectionState} Conn=${pc.connectionState || 'n/a'} Sig=${pc.signalingState}`, 'idle');
        logCall(root, `${label}: SDP local cand=${localCandidates} dir=${localDirection}; remoto cand=${remoteCandidates} dir=${remoteDirection}`, localCandidates && remoteCandidates ? 'ok' : 'warn');
        logCall(root, `${label}: audio enviado ${senders.length ? senders.map((sender) => describeTrack(sender.track)).join(' | ') : 'nenhum'}`, senders.length ? 'ok' : 'error');
        logCall(root, `${label}: audio recebido ${receivers.length ? receivers.map((receiver) => describeTrack(receiver.track)).join(' | ') : 'nenhum'}`, receivers.length ? 'ok' : 'warn');
    }

    async function inspectPeerStats(root, session, label) {
        if (!session || !session.sessionDescriptionHandler) return;
        const pc = session.sessionDescriptionHandler.peerConnection;
        if (!pc || !pc.getStats) return;
        try {
            const stats = await pc.getStats();
            const audioOut = [];
            const audioIn = [];
            const transports = [];
            stats.forEach((item) => {
                if (item.type === 'outbound-rtp' && (item.kind === 'audio' || item.mediaType === 'audio')) {
                    audioOut.push(`enviado bytes=${item.bytesSent || 0} pacotes=${item.packetsSent || 0}`);
                }
                if (item.type === 'inbound-rtp' && (item.kind === 'audio' || item.mediaType === 'audio')) {
                    audioIn.push(`recebido bytes=${item.bytesReceived || 0} pacotes=${item.packetsReceived || 0} perdidos=${item.packetsLost || 0}`);
                }
                if (item.type === 'transport') {
                    transports.push(`dtls=${item.dtlsState || 'n/a'} ice=${item.iceState || 'n/a'}`);
                }
                if (item.type === 'candidate-pair' && item.state === 'succeeded' && item.nominated) {
                    transports.push(`par=${item.protocol || 'udp'} ${item.localCandidateId || '?'}>${item.remoteCandidateId || '?'} bytes ${item.bytesSent || 0}/${item.bytesReceived || 0}`);
                }
            });
            logCall(root, `${label} stats: ${audioOut.join(' | ') || 'sem saida RTP'}; ${audioIn.join(' | ') || 'sem entrada RTP'}`, audioOut.length && audioIn.length ? 'ok' : 'warn');
            logCall(root, `${label} transporte: ${transports.join(' | ') || 'sem transporte'}`, transports.some((item) => item.includes('dtls=connected')) ? 'ok' : 'warn');
        } catch (error) {
            logCall(root, `${label} stats erro: ${error.message}`, 'error');
        }
    }

    const wiredPeerConnections = new WeakSet();

    function wirePeerDiagnostics(root, session, audio) {
        if (!session || !session.sessionDescriptionHandler) return;
        const handler = session.sessionDescriptionHandler;
        const pc = handler.peerConnection;
        if (!pc || wiredPeerConnections.has(pc)) return;
        wiredPeerConnections.add(pc);

        if (handler.remoteMediaStream) {
            attachAudioStream(root, audio, handler.remoteMediaStream, 'Stream remoto SIP.js');
        }

        pc.addEventListener('track', (event) => {
            event.streams.forEach((stream) => attachAudioStream(root, audio, stream, 'Track remoto WebRTC'));
            if (!event.streams.length && event.track) {
                const stream = new MediaStream([event.track]);
                attachAudioStream(root, audio, stream, 'Track remoto WebRTC');
            }
        });
        pc.addEventListener('iceconnectionstatechange', () => {
            logCall(root, `ICE WebRTC: ${pc.iceConnectionState}`, pc.iceConnectionState === 'connected' || pc.iceConnectionState === 'completed' ? 'ok' : 'warn');
        });
        pc.addEventListener('connectionstatechange', () => {
            logCall(root, `Conexao WebRTC: ${pc.connectionState}`, pc.connectionState === 'connected' ? 'ok' : 'warn');
        });
        pc.addEventListener('signalingstatechange', () => {
            logCall(root, `Sinalizacao WebRTC: ${pc.signalingState}`, 'idle');
        });
    }

    function attachRemoteAudio(root, session, audio) {
        if (!audio || !session || !session.sessionDescriptionHandler) return;
        wirePeerDiagnostics(root, session, audio);
        const handler = session.sessionDescriptionHandler;
        if (handler.remoteMediaStream) {
            attachAudioStream(root, audio, handler.remoteMediaStream, 'Stream remoto SIP.js');
        }
        const pc = handler.peerConnection;
        const stream = new MediaStream();
        pc.getReceivers().forEach((receiver) => {
            if (receiver.track && receiver.track.kind === 'audio') stream.addTrack(receiver.track);
        });
        attachAudioStream(root, audio, stream, 'Receiver remoto WebRTC');
    }

    function createPhoneAudio() {
        let context = null;
        let ringTimer = null;
        let busyTimer = null;
        let incomingTimer = null;
        let ringNodes = [];
        let busyNodes = [];

        function ctx() {
            context = context || new (window.AudioContext || window.webkitAudioContext)();
            if (context.state === 'suspended') context.resume();
            return context;
        }

        function tone(freqs, duration, volume) {
            const audio = ctx();
            const gain = audio.createGain();
            gain.gain.setValueAtTime(volume || 0.08, audio.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + duration);
            gain.connect(audio.destination);
            freqs.forEach((freq) => {
                const osc = audio.createOscillator();
                osc.type = 'sine';
                osc.frequency.value = freq;
                osc.connect(gain);
                osc.start();
                osc.stop(audio.currentTime + duration);
            });
        }

        function key(digit) {
            const map = {
                '1': [697, 1209], '2': [697, 1336], '3': [697, 1477],
                '4': [770, 1209], '5': [770, 1336], '6': [770, 1477],
                '7': [852, 1209], '8': [852, 1336], '9': [852, 1477],
                '*': [941, 1209], '0': [941, 1336], '#': [941, 1477]
            };
            tone(map[digit] || [440], 0.12, 0.07);
        }

        function startRingback() {
            stopBusy();
            stopRingback();
            const play = () => tone([425], 0.9, 0.05);
            play();
            ringTimer = window.setInterval(play, 2000);
        }

        function stopRingback() {
            if (ringTimer) window.clearInterval(ringTimer);
            ringTimer = null;
            ringNodes.forEach((node) => node.stop && node.stop());
            ringNodes = [];
        }

        function startBusy() {
            stopRingback();
            stopIncoming();
            stopBusy();
            let count = 0;
            const play = () => {
                if (count >= 6) {
                    stopBusy();
                    return;
                }
                tone([480, 620], 0.25, 0.06);
                count++;
            };
            play();
            busyTimer = window.setInterval(play, 500);
        }

        function stopBusy() {
            if (busyTimer) window.clearInterval(busyTimer);
            busyTimer = null;
            busyNodes.forEach((node) => node.stop && node.stop());
            busyNodes = [];
        }

        function startIncoming() {
            stopRingback();
            stopBusy();
            stopIncoming();
            const play = () => {
                tone([440, 480], 0.5, 0.07);
                window.setTimeout(() => tone([440, 480], 0.5, 0.07), 650);
            };
            play();
            incomingTimer = window.setInterval(play, 2300);
        }

        function stopIncoming() {
            if (incomingTimer) window.clearInterval(incomingTimer);
            incomingTimer = null;
        }

        return {
            key,
            connect: () => tone([880], 0.09, 0.05),
            hangup: () => tone([350], 0.12, 0.05),
            startRingback,
            stopRingback,
            startBusy,
            stopBusy,
            startIncoming,
            stopIncoming
        };
    }

    const phoneAudio = createPhoneAudio();

    async function fetchAccount(accountId) {
        const response = await fetch(`?page=api_webrtc_config&account_id=${encodeURIComponent(accountId || 0)}`);
        const data = await response.json();
        if (!data.ok || !data.account || !data.account.websocketUrl) {
            throw new Error('Este tronco esta como MicroSIP/UDP ou sem WebSocket. Para o softphone do navegador use um tronco WebRTC com wss://host:8089/ws.');
        }
        return data.account;
    }

    async function fetchExtensionAccount(credentials) {
        const response = await fetch(`?page=api_extension_config&extension=${encodeURIComponent(credentials.extension || '')}&password=${encodeURIComponent(credentials.password || '')}`);
        const data = await response.json();
        if (!data.ok || !data.account || !data.account.websocketUrl) {
            throw new Error('Ramal/senha invalido ou tronco sem WebSocket WSS. MicroSIP/UDP nao conecta no navegador.');
        }
        return data.account;
    }

    function normalizeIceServers(servers) {
        return (servers || []).map((server) => {
            if (typeof server === 'string') return { urls: server };
            if (server && typeof server === 'object') return server;
            return null;
        }).filter(Boolean);
    }

    async function requestServerTransfer(root, source, target, meta = {}) {
        const csrf = root.dataset.csrf || '';
        let lastError = null;
        for (let attempt = 0; attempt < 5; attempt++) {
            const form = new FormData();
            form.append('csrf', csrf);
            form.append('source', source || '');
            form.append('target', target || '');
            form.append('dialed_number', meta.dialedNumber || '');
            form.append('caller_id', meta.callerId || '');
            const response = await fetch('?page=api_server_transfer', { method: 'POST', body: form });
            const data = await response.json();
            if (data.ok) return data.result;
            lastError = new Error(data.message || 'Falha na transferencia pelo servidor.');
            if (!String(lastError.message).includes('chamada ativa')) break;
            await new Promise((resolve) => window.setTimeout(resolve, 600));
        }
        throw lastError || new Error('Falha na transferencia pelo servidor.');
    }

    function createSipController(root, accountIdProvider, configFetcher, options = {}) {
        let userAgent = null;
        let registerer = null;
        let activeSession = null;
        let pendingInvitation = null;
        let account = null;
        let muted = false;
        let lastHangupByUser = false;
        let cleanupPromise = Promise.resolve();
        let callTimers = [];
        let sipStartedAt = 0;
        let activeDialedNumber = '';
        const incomingAnswered = new WeakSet();
        const audio = options.silent ? null : root.querySelector('[data-remote-audio]');

        function remoteLabel(session) {
            const identity = session && session.remoteIdentity;
            const user = identity && identity.uri ? identity.uri.user : '';
            const display = identity && identity.displayName ? identity.displayName : '';
            return [display, user].filter(Boolean).join(' - ') || 'Chamada recebida';
        }

        function remoteNumber(session) {
            const identity = session && session.remoteIdentity;
            const user = identity && identity.uri ? String(identity.uri.user || '') : '';
            const display = identity && identity.displayName ? String(identity.displayName || '') : '';
            const source = user || display;
            return source.replace(/[^\d+]/g, '') || source || 'desconhecido';
        }

        function setOnline(online) {
            root.querySelectorAll('[data-agent-online]').forEach((node) => {
                node.dataset.online = online ? '1' : '0';
                node.textContent = online ? 'Online' : 'Offline';
            });
        }

        function showIncoming(invitation) {
            const modal = root.querySelector('[data-incoming-modal]');
            if (!modal) return;
            const caller = remoteLabel(invitation);
            setText(modal, '[data-incoming-caller]', caller);
            modal.hidden = false;
            modal.dataset.ringing = '1';
            setCallTitle(`Recebendo chamada: ${caller}`);
        }

        function hideIncoming() {
            const modal = root.querySelector('[data-incoming-modal]');
            if (!modal) return;
            modal.hidden = true;
            modal.dataset.ringing = '0';
        }

        function clearCallTimers() {
            callTimers.forEach((timer) => window.clearTimeout(timer));
            callTimers = [];
        }

        function resetMuteState() {
            muted = false;
            root.querySelectorAll('[data-softphone-mute]').forEach((button) => button.classList.remove('active'));
        }

        function cleanupSession(session, label) {
            clearCallTimers();
            resetMuteState();
            resetRemoteAudio(audio);
            activeDialedNumber = '';
            if (session && session.sessionDescriptionHandler) {
                const pc = session.sessionDescriptionHandler.peerConnection;
                if (pc) {
                    pc.getSenders().forEach((sender) => {
                        if (sender.track) sender.track.stop();
                    });
                    pc.getReceivers().forEach((receiver) => {
                        if (receiver.track) receiver.track.stop();
                    });
                    if (pc.signalingState !== 'closed') pc.close();
                }
            }
            if (!session || activeSession === session) activeSession = null;
            if (label) logCall(root, label, 'idle');
        }

        async function connect() {
            if (!SIPLib) {
                throw new Error('SIP.js nao carregou. Verifique a internet ou hospede o arquivo localmente.');
            }
            account = await (configFetcher || fetchAccount)(accountIdProvider());
            if (userAgent) return account;

            const iceServers = normalizeIceServers(account.iceServers);
            logCall(root, `ICE servidores: ${iceServers.length ? iceServers.map((item) => Array.isArray(item.urls) ? item.urls.join(', ') : item.urls).join(', ') : 'nenhum'}`, 'idle');
            userAgent = new SIPLib.UserAgent({
                uri: SIPLib.UserAgent.makeURI(account.uri),
                transportOptions: { server: account.websocketUrl },
                authorizationUsername: account.authorizationUsername,
                authorizationPassword: account.authorizationPassword,
                displayName: account.displayName,
                sessionDescriptionHandlerFactoryOptions: {
                    constraints: { audio: options.noMicrophone ? false : true, video: false },
                    iceGatheringTimeout: 10000,
                    peerConnectionConfiguration: {
                        iceServers,
                        iceCandidatePoolSize: iceServers.length ? 2 : 0
                    }
                },
                delegate: {
                    onInvite(invitation) {
                        const caller = remoteLabel(invitation);
                        if (options.paused) {
                            logCall(root, `Chamada recusada por pausa: ${caller}`, 'warn');
                            if (options.onCallEvent) options.onCallEvent({ type: 'missed', number: caller, status: 'Pausado' });
                            invitation.reject().catch((error) => logCall(root, `Erro ao recusar por pausa: ${error.message}`, 'error'));
                            return;
                        }
                        activeSession = invitation;
                        pendingInvitation = invitation;
                        const callerNumber = remoteNumber(invitation);
                        setStatus(root, 'Recebendo chamada', 'warn');
                        logCall(root, `Chamada recebida de ${caller}`, 'warn');
                        if (options.onCallEvent) options.onCallEvent({ type: 'incoming', number: callerNumber, caller_id: callerNumber, status: 'Pendente' });
                        showIncoming(invitation);
                        phoneAudio.startIncoming();
                        setHangupEnabled(root, true);
                        invitation.stateChange.addListener((state) => {
                            logCall(root, `Estado SIP recebido: ${state}`, 'idle');
                            if (state === SIPLib.SessionState.Established) {
                                incomingAnswered.add(invitation);
                                hideIncoming();
                                phoneAudio.stopIncoming();
                                attachRemoteAudio(root, invitation, audio);
                                inspectPeerConnection(root, invitation, 'Chamada recebida');
                                setStatus(root, 'Em chamada', 'ok');
                                setHangupEnabled(root, true);
                                setTransferEnabled(root, true);
                                logCall(root, 'Chamada recebida atendida', 'ok');
                                if (options.onCallEvent) options.onCallEvent({ type: 'answered', number: callerNumber, caller_id: callerNumber, status: 'Atendido' });
                            }
                            if (state === SIPLib.SessionState.Terminated) {
                                const wasAnswered = incomingAnswered.has(invitation);
                                hideIncoming();
                                phoneAudio.stopIncoming();
                                setHangupEnabled(root, false);
                                setTransferEnabled(root, false);
                                pendingInvitation = null;
                                cleanupPromise = new Promise((resolve) => {
                                    window.setTimeout(() => {
                                        cleanupSession(invitation, 'Midia da chamada recebida liberada');
                                        resolve();
                                    }, 450);
                                });
                                if (options.onCallEvent) {
                                    options.onCallEvent({
                                        type: wasAnswered ? 'ended' : 'missed',
                                        number: callerNumber,
                                        caller_id: callerNumber,
                                        status: wasAnswered ? 'Finalizado' : 'Nao atendido'
                                    });
                                }
                            }
                        });
                        if (options.autoAnswerIncoming !== false) {
                            answerIncoming().catch((error) => logCall(root, `Erro ao atender automaticamente: ${error.message}`, 'error'));
                        }
                    }
                }
            });

            userAgent.stateChange.addListener((state) => {
                const label = {
                    Started: 'SIP conectado',
                    Starting: 'Conectando SIP',
                    Stopped: 'SIP desconectado',
                    Stopping: 'Desconectando SIP'
                }[state] || state;
                setStatus(root, label, state === 'Started' ? 'ok' : 'idle');
                if (state === 'Started') {
                    sipStartedAt = Date.now();
                }
                if (state === 'Stopped') {
                    userAgent = null;
                    registerer = null;
                    sipStartedAt = 0;
                    setOnline(false);
                }
            });

            try {
                await userAgent.start();
                const registerExpires = options.maxRegisterExpires
                    ? Math.min(account.registerExpires || options.maxRegisterExpires, options.maxRegisterExpires)
                    : (account.registerExpires || 300);
                registerer = new SIPLib.Registerer(userAgent, { expires: Math.max(60, registerExpires) });
                await registerer.register();
            } catch (error) {
                try {
                    if (registerer && registerer.unregister) await registerer.unregister();
                    if (userAgent && userAgent.stop) await userAgent.stop();
                } catch (_) {}
                userAgent = null;
                registerer = null;
                setOnline(false);
                throw error;
            }
            setStatus(root, 'Registrado', 'ok');
            setOnline(true);
            if (!options.silent) phoneAudio.connect();
            logCall(root, `Registrado como ${account.uri}`, 'ok');
            return account;
        }

        async function answerIncoming() {
            const invitation = pendingInvitation || activeSession;
            if (!invitation || invitation.state === SIPLib.SessionState.Terminated) {
                throw new Error('Nao ha chamada recebida para atender.');
            }
            if (!options.noMicrophone) await ensureMicrophoneReady(root);
            hideIncoming();
            phoneAudio.stopIncoming();
            await invitation.accept({
                sessionDescriptionHandlerOptions: {
                    iceGatheringTimeout: 10000,
                    constraints: { audio: true, video: false }
                }
            });
            incomingAnswered.add(invitation);
            attachRemoteAudio(root, invitation, audio);
            inspectPeerConnection(root, invitation, 'Chamada recebida');
            setStatus(root, 'Em chamada', 'ok');
            setHangupEnabled(root, true);
            setTransferEnabled(root, true);
            logCall(root, 'Chamada recebida atendida', 'ok');
            pendingInvitation = null;
        }

        async function rejectIncoming() {
            const invitation = pendingInvitation || activeSession;
            if (!invitation) return;
            hideIncoming();
            phoneAudio.stopIncoming();
            lastHangupByUser = true;
            if (invitation.reject) {
                await invitation.reject();
            } else {
                await hangup(invitation);
            }
            pendingInvitation = null;
            setCallTitle('Chamada recusada');
            logCall(root, 'Chamada recebida recusada', 'warn');
        }

        async function call(number, onState) {
            await connect();
            await cleanupPromise;
            if (!options.allowConcurrentOutbound && activeSession && activeSession.state !== SIPLib.SessionState.Terminated) {
                throw new Error('Ainda existe uma chamada em encerramento. Aguarde um instante e tente de novo.');
            }
            if (!options.allowConcurrentOutbound) cleanupSession(activeSession);
            if (!options.noMicrophone) await ensureMicrophoneReady(root);
            const domain = account.uri.split('@')[1];
            const target = number.includes('@') ? number : `sip:${number}@${domain}`;
            const targetUri = SIPLib.UserAgent.makeURI(target);
            if (!targetUri) throw new Error('Destino SIP invalido.');

            lastHangupByUser = false;
            activeDialedNumber = number;
            const callerId = String(options.callerIdOverride || account.callerId || '').replace(/[^\d+]/g, '');
            const inviter = new SIPLib.Inviter(userAgent, targetUri, {
                extraHeaders: callerId ? [
                    `X-Caller-ID: ${callerId}`,
                    `P-Preferred-Identity: <sip:${callerId}@${domain}>`,
                    `Remote-Party-ID: <sip:${callerId}@${domain}>;party=calling;privacy=off;screen=no`
                ] : [],
                sessionDescriptionHandlerOptions: {
                    iceGatheringTimeout: 10000,
                    constraints: { audio: options.noMicrophone ? false : true, video: false }
                }
            });
            if (!options.allowConcurrentOutbound) activeSession = inviter;
            inviter.stateChange.addListener((state) => {
                logCall(root, `Estado SIP: ${state}`, 'idle');
                wirePeerDiagnostics(root, inviter, audio);
                if (state === SIPLib.SessionState.Established) {
                    if (!options.silent) {
                        attachRemoteAudio(root, inviter, audio);
                        inspectPeerConnection(root, inviter, 'Chamada atendida');
                        inspectPeerStats(root, inviter, 'Chamada atendida');
                        callTimers.push(window.setTimeout(() => inspectPeerConnection(root, inviter, 'Audio apos 2s'), 2000));
                        callTimers.push(window.setTimeout(() => inspectPeerStats(root, inviter, 'Stats apos 2s'), 2000));
                        callTimers.push(window.setTimeout(() => inspectPeerConnection(root, inviter, 'Audio apos 5s'), 5000));
                        callTimers.push(window.setTimeout(() => inspectPeerStats(root, inviter, 'Stats apos 5s'), 5000));
                    }
                    setStatus(root, 'Em chamada', 'ok');
                    phoneAudio.stopRingback();
                    if (!options.silent) phoneAudio.connect();
                    setHangupEnabled(root, true);
                    setTransferEnabled(root, true);
                    setCallTitle(`Atendida: ${number}`);
                    logCall(root, `Chamada atendida por ${number}`, 'ok');
                }
                if (state === SIPLib.SessionState.Terminated) {
                    setStatus(root, 'Registrado', 'ok');
                    phoneAudio.stopRingback();
                    setHangupEnabled(root, false);
                    setTransferEnabled(root, false);
                    if (lastHangupByUser) {
                        setCallTitle(`Desligada por voce: ${number}`);
                        logCall(root, `Voce desligou a chamada para ${number}`, 'warn');
                    } else {
                        setCallTitle(`Chamada finalizada: ${number}`);
                        logCall(root, `Chamada finalizada: ${number}`, 'idle');
                    }
                    cleanupPromise = new Promise((resolve) => {
                        window.setTimeout(() => {
                            cleanupSession(inviter, 'Midia da chamada anterior liberada');
                            resolve();
                        }, 450);
                    });
                }
                if (onState) onState(state);
            });
            await inviter.invite();
            wirePeerDiagnostics(root, inviter, audio);
            if (!options.silent) {
                inspectPeerConnection(root, inviter, 'Chamada criada');
                callTimers.push(window.setTimeout(() => inspectPeerConnection(root, inviter, 'Audio apos chamar 2s'), 2000));
            }
            setStatus(root, 'Chamando', 'warn');
            setHangupEnabled(root, true);
            setTransferEnabled(root, false);
            setCallTitle(`Chamando ${number}`);
            logCall(root, `Chamando ${number}`, 'warn');
            if (!options.silent) phoneAudio.startRingback();
            return inviter;
        }

        function isInCall() {
            return activeSession && activeSession.state === SIPLib.SessionState.Established;
        }

        function hasLiveSession() {
            return activeSession && activeSession.state !== SIPLib.SessionState.Terminated;
        }

        function connectionAgeMs() {
            return sipStartedAt ? Date.now() - sipStartedAt : 0;
        }

        function setCallTitle(text) {
            setCallEvent(root, text);
        }

        function sendDtmf(digit) {
            if (!isInCall() || !activeSession.sessionDescriptionHandler) return false;
            const pc = activeSession.sessionDescriptionHandler.peerConnection;
            const sender = pc.getSenders().find((item) => item.track && item.track.kind === 'audio' && item.dtmf);
            if (sender && sender.dtmf) {
                sender.dtmf.insertDTMF(digit);
                setCallTitle(`DTMF ${digit}`);
                return true;
            }
            if (activeSession.info) {
                activeSession.info({
                    requestOptions: {
                        body: {
                            contentDisposition: 'render',
                            contentType: 'application/dtmf-relay',
                            content: `Signal=${digit}\r\nDuration=160`
                        }
                    }
                });
                setCallTitle(`DTMF ${digit}`);
                return true;
            }
            return false;
        }

        function toggleMute() {
            if (!activeSession || !activeSession.sessionDescriptionHandler) return muted;
            muted = !muted;
            const pc = activeSession.sessionDescriptionHandler.peerConnection;
            pc.getSenders().forEach((sender) => {
                if (sender.track && sender.track.kind === 'audio') {
                    sender.track.enabled = !muted;
                }
            });
            setCallTitle(muted ? 'Microfone mudo' : 'Microfone ativo');
            return muted;
        }

        async function transfer(target, session, dialedNumberOverride) {
            if (!target) throw new Error('Escolha um ramal ou fila para transferir.');
            const current = session || activeSession;
            if (!current || current.state !== SIPLib.SessionState.Established) {
                throw new Error('So e possivel transferir uma chamada atendida.');
            }
            const source = (account.uri.split(':')[1] || '').split('@')[0] || account.authorizationUsername || '';
            const dialInput = root.querySelector('[data-dial-number]');
            const dialedNumber = dialedNumberOverride || activeDialedNumber || (dialInput ? dialInput.value.trim() : '');
            setCallTitle(`Transferindo para ${target}`);
            logCall(root, `Transferencia solicitada para ${target}`, 'warn');
            const result = await requestServerTransfer(root, source, target, {
                dialedNumber,
                callerId: account.callerId || ''
            });
            logCall(root, `Chamada enviada para fila ${result.redirectTarget || result.target}; discador liberado`, 'ok');
            if (result.historyWarning) {
                logCall(root, result.historyWarning, 'warn');
            }
            setTransferEnabled(root, false);
        }

        async function hangup(session) {
            const current = session || activeSession;
            if (!current) return;
            lastHangupByUser = true;
            logCall(root, 'Comando de desligar enviado', 'warn');
            if (current.state === SIPLib.SessionState.Established) {
                await current.bye();
            } else if (current.cancel) {
                await current.cancel();
            } else if (current.reject) {
                await current.reject();
            }
            phoneAudio.stopRingback();
            if (!options.silent) phoneAudio.hangup();
            setCallTitle('Chamada encerrada');
            setHangupEnabled(root, false);
            setTransferEnabled(root, false);
        }

        async function restart() {
            setStatus(root, 'Reiniciando SIP', 'warn');
            setCallTitle('Reiniciando conexao SIP');
            if (activeSession && activeSession.state !== SIPLib.SessionState.Terminated) {
                try { await hangup(activeSession); } catch (_) {}
            }
            try {
                if (registerer && registerer.unregister) await registerer.unregister();
            } catch (_) {}
            try {
                if (userAgent && userAgent.stop) await userAgent.stop();
            } catch (_) {}
            cleanupSession(null);
            registerer = null;
            userAgent = null;
            activeSession = null;
            pendingInvitation = null;
            setOnline(false);
            setHangupEnabled(root, false);
            setTransferEnabled(root, false);
            return connect();
        }

        return { connect, restart, call, hangup, sendDtmf, toggleMute, transfer, answerIncoming, rejectIncoming, isInCall, hasLiveSession, connectionAgeMs };
    }

    function initSoftphone() {
        const root = document.querySelector('[data-softphone]');
        if (!root) return;
        const accountSelect = root.querySelector('[data-account-select]');
        const numberInput = root.querySelector('[data-dial-number]');
        const controller = createSipController(root, () => accountSelect ? accountSelect.value : 0);

        root.querySelectorAll('[data-digit]').forEach((button) => {
            button.addEventListener('click', () => {
                const digit = button.dataset.digit;
                phoneAudio.key(digit);
                if (controller.isInCall()) {
                    controller.sendDtmf(digit);
                } else {
                    numberInput.value += digit;
                }
                numberInput.focus();
            });
        });
        root.querySelector('[data-dial-backspace]')?.addEventListener('click', () => {
            numberInput.value = numberInput.value.slice(0, -1);
            numberInput.focus();
        });
        root.querySelector('[data-dial-clear]')?.addEventListener('click', () => {
            numberInput.value = '';
            setCallEvent(root, 'Pronto para discar');
            numberInput.focus();
        });
        root.querySelectorAll('[data-softphone-connect]').forEach((button) => {
            button.addEventListener('click', async () => {
                try { await controller.connect(); } catch (error) { setStatus(root, error.message, 'error'); }
            });
        });
        root.querySelectorAll('[data-softphone-restart]').forEach((button) => {
            button.addEventListener('click', async () => {
                try { await controller.restart(); } catch (error) { setStatus(root, error.message, 'error'); }
            });
        });
        root.querySelector('[data-softphone-call]')?.addEventListener('click', async () => {
            try {
                const number = numberInput.value.trim();
                setCallEvent(root, number ? `Chamando ${number}` : 'Informe um numero');
                if (number) await controller.call(number);
            } catch (error) {
                phoneAudio.stopRingback();
                phoneAudio.startBusy();
                setHangupEnabled(root, false);
                setCallEvent(root, `Erro: ${error.message}`);
                logCall(root, `Erro ao chamar: ${error.message}`, 'error');
                setStatus(root, error.message, 'error');
            }
        });
        root.querySelector('[data-softphone-hangup]')?.addEventListener('click', () => controller.hangup());
        root.querySelector('[data-softphone-mute]')?.addEventListener('click', (event) => {
            const isMuted = controller.toggleMute();
            event.currentTarget.classList.toggle('active', isMuted);
        });
        root.querySelector('[data-softphone-transfer]')?.addEventListener('click', async () => {
            const target = root.querySelector('[data-transfer-target]')?.value.trim();
            try {
                await controller.transfer(target);
            } catch (error) {
                setCallEvent(root, `Erro transferencia: ${error.message}`);
                logCall(root, `Erro ao transferir: ${error.message}`, 'error');
            }
        });
        window.setTimeout(() => {
            controller.connect().catch((error) => {
                setStatus(root, error.message, 'error');
                logCall(root, `Falha na conexao automatica: ${error.message}`, 'error');
            });
        }, 500);
    }

    function wirePhoneUi(root, controller) {
        const numberInput = root.querySelector('[data-dial-number]');
        root.querySelectorAll('[data-digit]').forEach((button) => {
            button.addEventListener('click', () => {
                const digit = button.dataset.digit;
                phoneAudio.key(digit);
                if (controller.isInCall()) {
                    controller.sendDtmf(digit);
                } else if (numberInput) {
                    numberInput.value += digit;
                    numberInput.focus();
                }
            });
        });
        root.querySelector('[data-dial-backspace]')?.addEventListener('click', () => {
            if (!numberInput) return;
            numberInput.value = numberInput.value.slice(0, -1);
            numberInput.focus();
        });
        root.querySelector('[data-dial-clear]')?.addEventListener('click', () => {
            if (!numberInput) return;
            numberInput.value = '';
            setCallEvent(root, 'Pronto para discar');
            numberInput.focus();
        });
        root.querySelectorAll('[data-softphone-connect]').forEach((button) => {
            button.addEventListener('click', async () => {
                try { await controller.connect(); } catch (error) { setStatus(root, error.message, 'error'); }
            });
        });
        root.querySelectorAll('[data-softphone-restart]').forEach((button) => {
            button.addEventListener('click', async () => {
                try { await controller.restart(); } catch (error) { setStatus(root, error.message, 'error'); }
            });
        });
        root.querySelector('[data-softphone-call]')?.addEventListener('click', async () => {
            try {
                const number = numberInput ? numberInput.value.trim() : '';
                setCallEvent(root, number ? `Chamando ${number}` : 'Informe um numero');
                if (number) await controller.call(number);
            } catch (error) {
                phoneAudio.stopRingback();
                phoneAudio.startBusy();
                setHangupEnabled(root, false);
                setCallEvent(root, `Erro: ${error.message}`);
                logCall(root, `Erro ao chamar: ${error.message}`, 'error');
                setStatus(root, error.message, 'error');
            }
        });
        root.querySelector('[data-softphone-hangup]')?.addEventListener('click', () => controller.hangup());
        root.querySelector('[data-softphone-mute]')?.addEventListener('click', (event) => {
            const isMuted = controller.toggleMute();
            event.currentTarget.classList.toggle('active', isMuted);
        });
    }

    function initAgentPhone() {
        const root = document.querySelector('[data-agent-phone]');
        if (!root) return;
        const extension = root.dataset.agentExtension || '';
        const password = root.dataset.agentPassword || '';
        const csrf = root.dataset.csrf || '';
        const agentOptions = { autoAnswerIncoming: false, paused: false, maxRegisterExpires: 90, onCallEvent: recordAgentCall };
        const historyRows = document.querySelector('[data-agent-history-rows]');
        const historySearch = document.querySelector('[data-agent-history-search]');
        const stats = { total: 0, pending: 0, missed: 0, answered: 0 };
        let historyItems = [];
        const activeCallIds = new Map();

        async function postAgent(endpoint, payload = {}) {
            const body = new URLSearchParams({ csrf, extension, password, ...payload });
            const response = await fetch(`?page=${endpoint}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body
            });
            const data = await response.json();
            if (!data.ok) throw new Error(data.message || 'Falha na API do atendente.');
            return data;
        }

        async function fetchHistory() {
            const response = await fetch(`?page=api_agent_history&extension=${encodeURIComponent(extension)}&password=${encodeURIComponent(password)}`, { cache: 'no-store' });
            const data = await response.json();
            if (!data.ok) throw new Error(data.message || 'Falha ao carregar historico.');
            historyItems = data.items || [];
            updateAgentStats();
            renderHistory();
        }

        function formatDurationSeconds(value) {
            const seconds = Math.max(0, Number(value || 0));
            return new Date(seconds * 1000).toISOString().slice(11, 19);
        }

        function renderHistory() {
            const items = historyItems;
            const term = (historySearch?.value || '').trim().toLowerCase();
            if (!historyRows) return;
            historyRows.innerHTML = '';
            let visible = 0;
            items.forEach((item) => {
                const date = item.started_at ? new Date(`${item.started_at}Z`).toLocaleString() : '-';
                const haystack = `${item.number} ${item.status} ${item.extension} ${date}`.toLowerCase();
                if (term && !haystack.includes(term)) return;
                visible++;
                const row = document.createElement('tr');
                row.innerHTML = '<td></td><td></td><td></td><td></td><td></td>';
                row.children[0].textContent = item.number || '-';
                row.children[1].innerHTML = `<span class="status-chip ${item.status === 'Atendido' || item.status === 'Finalizado' ? 'ok' : item.status === 'Pendente' ? '' : 'off'}">${item.status}</span>`;
                row.children[2].textContent = item.extension || extension;
                row.children[3].textContent = formatDurationSeconds(item.duration_seconds);
                row.children[4].textContent = date;
                historyRows.appendChild(row);
            });
            if (!visible) {
                const row = document.createElement('tr');
                row.innerHTML = '<td colspan="5" class="muted">Nenhuma chamada encontrada.</td>';
                historyRows.appendChild(row);
            }
        }

        function updateAgentStats() {
            const items = historyItems;
            stats.total = items.length;
            stats.pending = items.filter((item) => item.status === 'Pendente').length;
            stats.missed = items.filter((item) => item.status === 'Nao atendido' || item.status === 'Pausado').length;
            stats.answered = items.filter((item) => item.status === 'Atendido' || item.status === 'Finalizado').length;
            Object.entries(stats).forEach(([key, value]) => {
                document.querySelectorAll(`[data-agent-stat="${key}"]`).forEach((node) => { node.textContent = String(value); });
            });
        }

        function recordAgentCall(event) {
            const payload = { type: event.type, number: event.number || 'desconhecido', caller_id: event.caller_id || event.callerId || '', status: event.status || 'Evento' };
            const knownId = activeCallIds.get(payload.number);
            if (knownId) payload.id = knownId;
            upsertLocalHistory(payload, knownId);
            postAgent('api_agent_call_event', payload)
                .then((data) => {
                    if (data.id) activeCallIds.set(payload.number, data.id);
                    if (event.type === 'ended' || event.type === 'missed') activeCallIds.delete(payload.number);
                    return fetchHistory();
                })
                .catch((error) => logCall(root, `Historico: ${error.message}`, 'error'));
        }

        function upsertLocalHistory(payload, knownId) {
            const now = new Date().toISOString();
            let item = knownId ? historyItems.find((entry) => String(entry.id) === String(knownId)) : null;
            if (!item) {
                item = historyItems.find((entry) => entry.number === payload.number && !entry.ended_at);
            }
            if (!item && payload.type === 'incoming') {
                item = historyItems.find((entry) => entry.status === 'Pendente' && !entry.ended_at);
            }
            if (!item) {
                item = {
                    id: knownId || `local-${Date.now()}`,
                    extension,
                    number: payload.number,
                    status: payload.status,
                    started_at: now.slice(0, 19).replace('T', ' '),
                    answered_at: null,
                    ended_at: null,
                    duration_seconds: 0
                };
                historyItems.unshift(item);
            }
            item.number = payload.number;
            item.status = payload.status;
            if (payload.type === 'answered' && !item.answered_at) {
                item.answered_at = now.slice(0, 19).replace('T', ' ');
            }
            if ((payload.type === 'ended' || payload.type === 'missed') && !item.ended_at) {
                item.ended_at = now.slice(0, 19).replace('T', ' ');
                if (payload.type === 'missed' || !item.answered_at) {
                    item.duration_seconds = 0;
                } else {
                    const started = Date.parse(item.answered_at.replace(' ', 'T'));
                    item.duration_seconds = Number.isFinite(started) ? Math.max(0, Math.round((Date.now() - started) / 1000)) : 0;
                }
            }
            updateAgentStats();
            renderHistory();
        }

        const controller = createSipController(root, () => ({
            extension,
            password: root.dataset.agentPassword
        }), fetchExtensionAccount, agentOptions);
        wirePhoneUi(root, controller);
        fetchHistory().catch((error) => logCall(root, `Historico: ${error.message}`, 'error'));
        historySearch?.addEventListener('input', renderHistory);
        root.querySelector('[data-incoming-answer]')?.addEventListener('click', async () => {
            try {
                await controller.answerIncoming();
            } catch (error) {
                setCallEvent(root, `Erro ao atender: ${error.message}`);
                logCall(root, `Erro ao atender: ${error.message}`, 'error');
            }
        });
        root.querySelector('[data-incoming-reject]')?.addEventListener('click', async () => {
            try {
                await controller.rejectIncoming();
            } catch (error) {
                setCallEvent(root, `Erro ao recusar: ${error.message}`);
                logCall(root, `Erro ao recusar: ${error.message}`, 'error');
            }
        });
        root.querySelector('[data-incoming-hangup]')?.addEventListener('click', () => controller.hangup());
        root.querySelector('[data-incoming-transfer]')?.addEventListener('click', async () => {
            const target = root.querySelector('[data-agent-transfer-target]')?.value.trim();
            try {
                await controller.transfer(target);
            } catch (error) {
                setCallEvent(root, `Erro transferencia: ${error.message}`);
                logCall(root, `Erro ao transferir: ${error.message}`, 'error');
            }
        });
        root.querySelector('[data-agent-pause]')?.addEventListener('click', (event) => {
            agentOptions.paused = !agentOptions.paused;
            event.currentTarget.classList.toggle('active', agentOptions.paused);
            event.currentTarget.textContent = agentOptions.paused ? 'Retomar' : 'Pausar';
            setCallEvent(root, agentOptions.paused ? 'Atendimento pausado' : 'Pronto para atender');
        });
        root.querySelector('[data-agent-auto-answer]')?.addEventListener('click', (event) => {
            agentOptions.autoAnswerIncoming = !agentOptions.autoAnswerIncoming;
            event.currentTarget.classList.toggle('active', agentOptions.autoAnswerIncoming);
            event.currentTarget.textContent = agentOptions.autoAnswerIncoming ? 'Auto ligado' : 'Auto atender';
        });
        async function updatePing() {
            const node = document.querySelector('[data-agent-ping]');
            if (!node) return;
            const started = performance.now();
            try {
                await postAgent('api_agent_presence', { mode: 'online' });
                const ms = Math.round(performance.now() - started);
                node.textContent = `${ms}ms`;
                node.dataset.tone = ms < 180 ? 'ok' : ms < 400 ? 'warn' : 'error';
                root.querySelectorAll('[data-agent-online]').forEach((item) => {
                    item.dataset.online = '1';
                    item.textContent = 'Online';
                });
            } catch (_) {
                node.textContent = 'offline';
                node.dataset.tone = 'error';
                root.querySelectorAll('[data-agent-online]').forEach((item) => {
                    item.dataset.online = '0';
                    item.textContent = 'Offline';
                });
            }
        }
        updatePing();
        window.setInterval(updatePing, 5000);
        window.setInterval(() => {
            fetchHistory().catch(() => {});
        }, 2000);
        window.addEventListener('pagehide', () => {
            const body = new FormData();
            body.append('csrf', csrf);
            body.append('extension', extension);
            body.append('password', password);
            body.append('mode', 'offline');
            navigator.sendBeacon('?page=api_agent_presence', body);
        });
        function connectAgentWithRetry() {
            controller.connect().catch((error) => {
                setStatus(root, error.message, 'error');
                setCallEvent(root, `Erro: ${error.message}`);
                window.setTimeout(connectAgentWithRetry, 5000);
            });
        }
        window.setTimeout(connectAgentWithRetry, 500);
        window.setInterval(() => {
            if (controller.hasLiveSession()) return;
            if (controller.connectionAgeMs() < 240000) return;
            logCall(root, 'Renovando conexao SIP preventiva para manter o ramal tocando.', 'idle');
            controller.restart().catch((error) => {
                setStatus(root, error.message, 'error');
                window.setTimeout(connectAgentWithRetry, 5000);
            });
        }, 60000);
    }

    function initWebrtcCampaign() {
        const root = document.querySelector('.webrtc-dialer');
        if (!root) return;
        const campaignId = root.dataset.campaignId;
        const simultaneous = Math.max(1, Number(root.dataset.simultaneous || 1));
        const csrf = root.dataset.csrf;
        const grid = root.querySelector('[data-call-grid]');
        const controller = createSipController(root, () => root.dataset.accountId, null, {
            silent: true,
            allowConcurrentOutbound: true,
            noMicrophone: true,
            callerIdOverride: root.dataset.callerId || ''
        });
        const transferTarget = (root.dataset.transferTarget || '').trim();
        let running = false;
        let active = 0;

        async function updateJob(jobId, status, response) {
            const form = new FormData();
            form.append('csrf', csrf);
            form.append('job_id', jobId);
            form.append('status', status);
            form.append('response', response || '');
            await fetch('?page=api_call_job_update', { method: 'POST', body: form });
        }

        function callCard(job, text) {
            let card = grid.querySelector(`[data-job-id="${job.id}"]`);
            if (!card) {
                card = document.createElement('div');
                card.className = 'call-card';
                card.dataset.jobId = job.id;
                card.innerHTML = `<strong></strong><span></span>`;
                grid.prepend(card);
            }
            card.querySelector('strong').textContent = job.number;
            card.querySelector('span').textContent = text;
        }

        async function refreshStats() {
            const response = await fetch(`?page=api_campaign_stats&id=${encodeURIComponent(campaignId)}`);
            const data = await response.json();
            if (!data.stats) return;
            Object.entries(data.stats).forEach(([key, value]) => {
                const node = document.querySelector(`[data-stat="${key}"]`);
                if (node) node.textContent = value;
            });
        }

        async function waitTransferResult(job) {
            for (let attempt = 0; attempt < 8; attempt++) {
                await new Promise((resolve) => window.setTimeout(resolve, 5000));
                const response = await fetch(`?page=api_campaign_transfer_result&job_id=${encodeURIComponent(job.id)}`, { cache: 'no-store' });
                const data = await response.json();
                if (!data.ok || data.result === 'pending') {
                    callCard(job, data.message || 'Aguardando atendente');
                    continue;
                }
                if (data.result === 'answered') {
                    callCard(job, 'Atendida pelo atendente');
                    await updateJob(job.id, 'ended', data.message || 'Atendente atendeu');
                } else {
                    callCard(job, 'Rejeitada');
                    await updateJob(job.id, 'rejeitada', data.message || 'Atendente nao atendeu');
                }
                refreshStats();
                return;
            }
            callCard(job, 'Rejeitada');
            await updateJob(job.id, 'rejeitada', 'Atendente nao atendeu dentro do tempo');
            refreshStats();
        }

        async function launch(job) {
            active++;
            callCard(job, 'Chamando');
            let answered = false;
            let transferred = false;
            try {
                const session = await controller.call(job.number, async (state) => {
                    if (state === SIPLib.SessionState.Established) {
                        answered = true;
                        callCard(job, 'Atendida');
                        await updateJob(job.id, 'answered', 'Chamada atendida');
                        if (transferTarget) {
                            try {
                                callCard(job, `Transferindo para ${transferTarget}`);
                                await controller.transfer(transferTarget, session, job.number);
                                transferred = true;
                                callCard(job, `Transferida para ${transferTarget}`);
                                await updateJob(job.id, 'answered', `Transferida para ${transferTarget}`);
                                waitTransferResult(job);
                            } catch (error) {
                                callCard(job, 'Erro ao transferir');
                                await updateJob(job.id, 'rejeitada', `Erro ao transferir: ${error.message}`);
                            }
                        }
                    }
                    if (state === SIPLib.SessionState.Terminated) {
                        if (transferred) {
                            callCard(job, 'Aguardando atendente');
                        } else {
                            callCard(job, answered ? 'Finalizada' : 'Nao atendida');
                            await updateJob(job.id, answered ? 'ended' : 'nao_atendida', answered ? 'Chamada encerrada' : 'Pessoa nao atendeu');
                        }
                        active--;
                        refreshStats();
                        pump();
                    }
                });
                window.setTimeout(() => {
                    if (session.state !== SIPLib.SessionState.Terminated) controller.hangup(session);
                }, 45000);
            } catch (error) {
                callCard(job, 'Erro');
                await updateJob(job.id, 'error', error.message);
                active--;
                refreshStats();
                pump();
            }
        }

        async function pump() {
            if (!running) return;
            const slots = Math.max(0, simultaneous - active);
            if (slots <= 0) return;
            const response = await fetch(`?page=api_campaign_jobs&id=${encodeURIComponent(campaignId)}&limit=${encodeURIComponent(slots)}`);
            const data = await response.json();
            if (!data.jobs || data.jobs.length === 0) {
                if (active === 0) setStatus(root, 'Fila concluida', 'ok');
                return;
            }
            data.jobs.forEach((job) => launch(job));
        }

        root.querySelector('[data-webrtc-connect]')?.addEventListener('click', async () => {
            try { await controller.connect(); } catch (error) { setStatus(root, error.message, 'error'); }
        });
        root.querySelector('[data-webrtc-run]')?.addEventListener('click', async () => {
            running = true;
            try {
                await controller.connect();
                setStatus(root, 'Fila rodando', 'ok');
                pump();
            } catch (error) {
                setStatus(root, error.message, 'error');
            }
        });
        root.querySelector('[data-webrtc-stop]')?.addEventListener('click', () => {
            running = false;
            setStatus(root, 'Fila pausada', 'warn');
        });
        window.setTimeout(() => {
            controller.connect()
                .then(() => {
                    if (root.dataset.running === '1') {
                        running = true;
                        setStatus(root, 'Fila rodando', 'ok');
                        pump();
                    }
                })
                .catch((error) => setStatus(root, error.message, 'error'));
        }, 400);
    }

    function initAmiCampaignRunner() {
        const runner = document.querySelector('.campaign-runner');
        if (!runner || runner.dataset.running !== '1' || runner.dataset.dialerMode !== 'ami') return;

        const campaignId = runner.dataset.campaignId;
        const updateStats = (stats) => {
            Object.entries(stats).forEach(([key, value]) => {
                const node = runner.querySelector(`[data-stat="${key}"]`);
                if (node) node.textContent = value;
            });
        };

        const tick = async () => {
            try {
                const response = await fetch(`?page=api_campaign_run&id=${encodeURIComponent(campaignId)}`, {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await response.json();
                if (data.stats) updateStats(data.stats);
                if (data.stats && Number(data.stats.pending) > 0) {
                    window.setTimeout(tick, 1800);
                } else {
                    window.setTimeout(() => window.location.reload(), 1200);
                }
            } catch (error) {
                console.error(error);
                window.setTimeout(tick, 5000);
            }
        };

        window.setTimeout(tick, 600);
    }

    function initExtensionsPage() {
        const search = document.querySelector('[data-extension-search]');
        const rows = Array.from(document.querySelectorAll('[data-extension-row]'));
        const count = document.querySelector('[data-extension-count]');
        if (search && rows.length) {
            search.addEventListener('input', () => {
                const term = search.value.trim().toLowerCase();
                let visible = 0;
                rows.forEach((row) => {
                    const match = !term || (row.dataset.search || '').includes(term);
                    row.hidden = !match;
                    if (match) visible++;
                });
                if (count) count.textContent = String(visible);
            });
        }
        document.querySelectorAll('[data-copy-link]').forEach((button) => {
            button.addEventListener('click', async () => {
                const url = new URL(button.dataset.copyLink, window.location.href).toString();
                try {
                    await navigator.clipboard.writeText(url);
                    button.textContent = 'Copiado';
                    window.setTimeout(() => { button.textContent = 'Copiar link'; }, 1400);
                } catch (error) {
                    window.prompt('Copie o link do ramal:', url);
                }
            });
        });
        async function refreshPresence() {
            const trackedRows = Array.from(document.querySelectorAll('[data-extension-row][data-extension]'));
            if (!trackedRows.length) return;
            try {
                const response = await fetch('?page=api_transfer_targets', { cache: 'no-store' });
                const data = await response.json();
                if (!data.ok) return;
                const byExtension = new Map((data.targets || []).map((item) => [String(item.value), !!item.online]));
                trackedRows.forEach((row) => {
                    const online = byExtension.get(row.dataset.extension) || false;
                    const state = row.querySelector('[data-extension-state]');
                    const label = row.querySelector('[data-extension-online]');
                    if (state) state.dataset.state = online ? 'on' : 'off';
                    if (label) {
                        label.textContent = online ? 'Online' : 'Offline';
                        label.classList.toggle('ok', online);
                        label.classList.toggle('off', !online);
                    }
                });
            } catch (_) {}
        }
        refreshPresence();
        window.setInterval(refreshPresence, 5000);
    }

    function initOnlineCalls() {
        const root = document.querySelector('[data-online-calls]');
        if (!root) return;
        const rowsNode = root.querySelector('[data-online-rows]');
        const searchInput = root.querySelector('[data-online-search]');
        const pageSizeSelect = root.querySelector('[data-online-page-size]');
        const pageLabel = root.querySelector('[data-online-page-label]');
        const visibleNode = root.querySelector('[data-online-visible]');
        const totalNode = root.querySelector('[data-online-total]');
        const lastNode = root.querySelector('[data-online-last]');
        const historyRowsNode = root.querySelector('[data-online-history-rows]');
        const statusNode = document.querySelector('[data-online-refresh-status]');
        const refreshButton = document.querySelector('[data-online-refresh]');
        let calls = [];
        let filter = 'all';
        let page = 1;
        let loading = false;
        let lastSnapshotAt = Date.now();

        function formatDuration(value) {
            const seconds = Math.max(0, Number(value || 0));
            const h = String(Math.floor(seconds / 3600)).padStart(2, '0');
            const m = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');
            const s = String(seconds % 60).padStart(2, '0');
            return `${h}:${m}:${s}`;
        }

        function liveDuration(baseSeconds) {
            return Math.max(0, Number(baseSeconds || 0) + Math.floor((Date.now() - lastSnapshotAt) / 1000));
        }

        function typeClass(type) {
            return {
                active: 'ok',
                waiting: 'warn',
                audio: 'audio',
                routing: 'route'
            }[type] || 'route';
        }

        function typeLabel(type) {
            return {
                active: 'Em atendimento',
                waiting: 'Aguardando',
                audio: 'Audio',
                routing: 'Classificando'
            }[type] || 'Chamada';
        }

        function filteredCalls() {
            const term = (searchInput?.value || '').trim().toLowerCase();
            return calls.filter((call) => {
                const typeMatch = filter === 'all' || call.type === filter;
                const haystack = [
                    call.status,
                    call.channel,
                    call.context,
                    call.extension,
                    call.state,
                    call.application,
                    call.data,
                    call.callerid,
                    call.bridged_channel
                ].join(' ').toLowerCase();
                return typeMatch && (!term || haystack.includes(term));
            });
        }

        function setCounts(snapshot) {
            const types = snapshot.types || {};
            const total = Number(snapshot.total || 0);
            const counts = {
                all: total,
                active: Number(types.active || 0),
                waiting: Number(types.waiting || 0),
                audio: Number(types.audio || 0),
                routing: Number(types.routing || 0)
            };
            Object.entries(counts).forEach(([key, value]) => {
                const node = root.querySelector(`[data-count-${key}]`);
                if (node) node.textContent = String(value);
            });
            if (totalNode) totalNode.textContent = String(total);
            if (lastNode) lastNode.textContent = snapshot.generated_at
                ? `Atualizado ${new Date(snapshot.generated_at).toLocaleTimeString()}`
                : 'Atualizado agora';
        }

        function render() {
            if (!rowsNode) return;
            const pageSize = Number(pageSizeSelect?.value || 10);
            const items = filteredCalls();
            const pages = Math.max(1, Math.ceil(items.length / pageSize));
            page = Math.min(Math.max(1, page), pages);
            const start = (page - 1) * pageSize;
            const pageItems = items.slice(start, start + pageSize);
            if (visibleNode) visibleNode.textContent = String(items.length);
            if (pageLabel) pageLabel.textContent = `Pagina ${page} de ${pages}`;
            rowsNode.innerHTML = '';
            if (!pageItems.length) {
                const message = calls.length
                    ? 'Nenhuma ligação neste filtro. Clique em Todos para ver as ligações ativas.'
                    : 'Nenhuma ligação ativa agora. Veja o histórico recente abaixo.';
                rowsNode.innerHTML = `<tr><td colspan="7" class="muted empty-cell">${escapeHtml(message)}</td></tr>`;
                return;
            }
            pageItems.forEach((call, index) => {
                const row = document.createElement('tr');
                row.className = index % 2 ? 'row-alt' : '';
                row.innerHTML = `
                    <td><span class="call-type ${typeClass(call.type)}">${escapeHtml(typeLabel(call.type))}</span><small>${escapeHtml(call.state || '-')}</small></td>
                    <td><strong>${escapeHtml(call.callerid || call.extension || '-')}</strong><small>${escapeHtml(call.extension || '-')}</small></td>
                    <td><code>${escapeHtml(call.channel || '-')}</code></td>
                    <td><strong ${call.answered ? `data-live-duration="${escapeHtml(call.duration_seconds || 0)}"` : ''}>${escapeHtml(call.answered ? formatDuration(liveDuration(call.duration_seconds)) : '00:00:00')}</strong>${call.answered ? '' : `<small>Aguardando ${escapeHtml(formatDuration(liveDuration(call.wait_seconds || 0)))}</small>`}</td>
                    <td><strong>${escapeHtml(call.application || '-')}</strong><small>${escapeHtml(call.data || '')}</small></td>
                    <td><span>${escapeHtml(call.context || '-')}</span></td>
                    <td><code>${escapeHtml(call.bridged_channel || '-')}</code></td>
                `;
                rowsNode.appendChild(row);
            });
        }

        function historyStatusClass(status) {
            if (status === 'Atendido' || status === 'Finalizado') return 'ok';
            if (status === 'Pendente') return '';
            return 'off';
        }

        function renderHistory(history) {
            if (!historyRowsNode) return;
            historyRowsNode.innerHTML = '';
            if (!history || !history.length) {
                historyRowsNode.innerHTML = '<tr><td colspan="6" class="muted empty-cell">Nenhum historico de atendente encontrado.</td></tr>';
                return;
            }
            history.forEach((item) => {
                const row = document.createElement('tr');
                const shouldTick = item.status === 'Atendido' && !item.ended_at;
                const durationSeconds = Number(item.duration_seconds || 0);
                const durationText = shouldTick ? formatDuration(liveDuration(durationSeconds)) : formatDuration(durationSeconds);
                const durationAttr = shouldTick ? ` data-live-duration="${escapeHtml(durationSeconds)}"` : '';
                row.innerHTML = `
                    <td><strong>${escapeHtml(item.number || '-')}</strong></td>
                    <td><span class="status-chip ${historyStatusClass(item.status)}">${escapeHtml(item.status || '-')}</span></td>
                    <td>${escapeHtml(item.extension || '-')}</td>
                    <td>${escapeHtml(item.caller_id || '-')}</td>
                    <td><strong${durationAttr}>${escapeHtml(durationText)}</strong></td>
                    <td>${escapeHtml(item.started_at || '-')}</td>
                `;
                historyRowsNode.appendChild(row);
            });
        }

        async function load() {
            if (loading) return;
            loading = true;
            if (statusNode) {
                statusNode.textContent = 'Atualizando';
                statusNode.dataset.tone = 'warn';
            }
            try {
                const response = await fetch('?page=api_online_calls', { cache: 'no-store' });
                const data = await response.json();
                if (!data.ok) throw new Error(data.message || 'Falha ao consultar Asterisk.');
                lastSnapshotAt = Date.now();
                calls = data.snapshot.calls || [];
                setCounts(data.snapshot);
                renderHistory(data.snapshot.history || []);
                if (statusNode) {
                    statusNode.textContent = data.snapshot.message ? 'Desativado' : 'Online';
                    statusNode.dataset.tone = data.snapshot.message ? 'warn' : 'ok';
                }
                render();
                if (data.snapshot.message && rowsNode && !calls.length) {
                    rowsNode.innerHTML = `<tr><td colspan="7" class="muted empty-cell">${escapeHtml(data.snapshot.message)}</td></tr>`;
                }
            } catch (error) {
                if (statusNode) {
                    statusNode.textContent = 'Erro';
                    statusNode.dataset.tone = 'error';
                }
                if (rowsNode && !calls.length) {
                    rowsNode.innerHTML = `<tr><td colspan="7" class="muted empty-cell">${escapeHtml(error.message)}</td></tr>`;
                }
            } finally {
                loading = false;
            }
        }

        root.querySelectorAll('[data-call-type]').forEach((button) => {
            button.addEventListener('click', () => {
                filter = button.dataset.callType || 'all';
                page = 1;
                root.querySelectorAll('[data-call-type]').forEach((item) => item.classList.toggle('active', item === button));
                render();
            });
        });
        searchInput?.addEventListener('input', () => {
            page = 1;
            render();
        });
        pageSizeSelect?.addEventListener('change', () => {
            page = 1;
            render();
        });
        root.querySelector('[data-online-prev]')?.addEventListener('click', () => {
            page = Math.max(1, page - 1);
            render();
        });
        root.querySelector('[data-online-next]')?.addEventListener('click', () => {
            page += 1;
            render();
        });
        refreshButton?.addEventListener('click', load);
        load();
        window.setInterval(load, 5000);
        window.setInterval(() => {
            document.querySelectorAll('[data-live-duration]').forEach((node) => {
                node.textContent = formatDuration(liveDuration(node.dataset.liveDuration || 0));
            });
        }, 1000);
    }

    function initIvrDigitViewer() {
        const root = document.querySelector('[data-ivr-digit-viewer]');
        if (!root) return;
        const screen = root.querySelector('[data-ivr-digit-screen]');
        const ivrId = root.dataset.ivrId || '0';
        if (!screen) return;

        function render(items) {
            if (!items.length) {
                screen.innerHTML = '<p class="muted">Nenhum digito recebido ainda.</p>';
                return;
            }
            screen.innerHTML = items.slice(0, 12).map((item) => `
                <div>
                    <strong>${escapeHtml(item.digit || '-')}</strong>
                    <span>${escapeHtml((item.phone || '-') + ' -> ' + (item.destination || item.option_label || '-'))}</span>
                    <small>${escapeHtml(item.created_at || '')}</small>
                </div>
            `).join('');
        }

        async function load() {
            try {
                const response = await fetch(`?page=api_ivr_digits&ivr_id=${encodeURIComponent(ivrId)}`, { cache: 'no-store' });
                const data = await response.json();
                if (data.ok) render(data.items || []);
            } catch (_) {}
        }

        load();
        window.setInterval(load, 3000);
    }

    function initIvrCallMonitor() {
        const root = document.querySelector('[data-ivr-call-monitor]');
        if (!root) return;
        const screen = root.querySelector('[data-ivr-call-screen]');
        const ivrId = root.dataset.ivrId || '0';
        if (!screen) return;

        function render(items) {
            if (!items.length) {
                screen.innerHTML = '<p class="muted">Nenhum disparo registrado ainda.</p>';
                return;
            }
            screen.innerHTML = items.slice(0, 16).map((item) => `
                <div class="ivr-call-line">
                    <strong>${escapeHtml(item.phone || '-')}</strong>
                    <span>${escapeHtml(item.status || '-')}</span>
                    <small>Bina ${escapeHtml(item.caller_id || '-')} | ${escapeHtml(item.created_at || '')}</small>
                    <em>${escapeHtml(item.message || '')}</em>
                </div>
            `).join('');
        }

        async function load() {
            try {
                const response = await fetch(`?page=api_ivr_call_events&ivr_id=${encodeURIComponent(ivrId)}`, { cache: 'no-store' });
                const data = await response.json();
                if (data.ok) render(data.items || []);
            } catch (_) {}
        }

        load();
        window.setInterval(load, 2500);
    }

    function initIvrConfigModal() {
        const modal = document.querySelector('[data-ivr-config-modal]');
        if (!modal) return;
        const openers = document.querySelectorAll('[data-ivr-open]');
        const closers = modal.querySelectorAll('[data-ivr-close]');
        const currentParams = new URLSearchParams(window.location.search);
        const currentPage = currentParams.get('page') === 'reverse_ivrs' ? 'reverse_ivrs' : 'ivrs';
        const currentListUrl = `?page=${currentPage}`;
        openers.forEach((button) => {
            button.addEventListener('click', () => {
                modal.hidden = false;
            });
        });
        closers.forEach((button) => {
            button.addEventListener('click', () => {
                if (new URLSearchParams(window.location.search).has('edit')) {
                    window.location.href = currentListUrl;
                    return;
                }
                modal.hidden = true;
            });
        });
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                if (new URLSearchParams(window.location.search).has('edit')) {
                    window.location.href = currentListUrl;
                    return;
                }
                modal.hidden = true;
            }
        });

        const options = modal.querySelector('[data-ivr-options]');
        const addButton = modal.querySelector('[data-ivr-add-option]');
        const optionSources = {
            audio: JSON.parse(options?.dataset.ivrAudios || '[]'),
            fila: JSON.parse(options?.dataset.ivrQueues || '[]'),
            ramal: JSON.parse(options?.dataset.ivrExtensions || '[]'),
            desligar: [{ value: 'hangup', label: 'Desligar chamada' }],
        };
        function updateDestinationSelect(row) {
            const type = row.querySelector('select[name="option_type[]"]')?.value || 'fila';
            const destination = row.querySelector('[data-ivr-destination]');
            if (!destination) return;
            const current = destination.dataset.currentValue || destination.value || '';
            const items = optionSources[type] || [];
            destination.innerHTML = '<option value="">Selecione</option>' + items.map((item) => {
                const selected = String(item.value) === String(current) ? ' selected' : '';
                return `<option value="${escapeHtml(item.value)}"${selected}>${escapeHtml(item.label)}</option>`;
            }).join('');
            if (current && !items.some((item) => String(item.value) === String(current))) {
                destination.insertAdjacentHTML('beforeend', `<option value="${escapeHtml(current)}" selected>${escapeHtml(current)}</option>`);
            }
        }
        function renumberPlaceholders() {
            options?.querySelectorAll('[data-ivr-option-row]').forEach((row, index) => {
                const digit = row.querySelector('input[name="option_digit[]"]');
                if (digit && !digit.value) digit.placeholder = String(index + 1);
                const remove = row.querySelector('[data-ivr-remove-option]');
                if (remove) remove.disabled = options.querySelectorAll('[data-ivr-option-row]').length <= 2;
                updateDestinationSelect(row);
            });
        }
        addButton?.addEventListener('click', () => {
            const first = options?.querySelector('[data-ivr-option-row]');
            if (!options || !first) return;
            const clone = first.cloneNode(true);
            clone.querySelectorAll('input').forEach((input) => { input.value = ''; });
            clone.querySelectorAll('select').forEach((select) => { select.value = 'fila'; });
            clone.querySelectorAll('[data-ivr-destination]').forEach((select) => { select.dataset.currentValue = ''; });
            options.appendChild(clone);
            renumberPlaceholders();
        });
        options?.addEventListener('change', (event) => {
            const typeSelect = event.target.closest('select[name="option_type[]"]');
            if (!typeSelect) return;
            const row = typeSelect.closest('[data-ivr-option-row]');
            const destination = row?.querySelector('[data-ivr-destination]');
            if (destination) destination.dataset.currentValue = '';
            if (row) updateDestinationSelect(row);
        });
        options?.addEventListener('change', (event) => {
            const destination = event.target.closest('[data-ivr-destination]');
            if (destination) destination.dataset.currentValue = destination.value;
        });
        options?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-ivr-remove-option]');
            if (!button) return;
            const rows = options.querySelectorAll('[data-ivr-option-row]');
            if (rows.length <= 2) return;
            button.closest('[data-ivr-option-row]')?.remove();
            renumberPlaceholders();
        });
        renumberPlaceholders();
    }

    function initIvrSyncModal() {
        const modal = document.querySelector('[data-ivr-sync-modal]');
        if (modal) {
            const key = modal.dataset.modalKey || 'ivr-sync-modal-hidden';
            if (localStorage.getItem(key) !== '1') {
                modal.hidden = false;
            }
            const hide = modal.querySelector('[data-ivr-sync-hide]');
            const close = modal.querySelector('[data-ivr-sync-close]');
            const form = modal.querySelector('form');
            function rememberChoice() {
                if (hide?.checked) localStorage.setItem(key, '1');
            }
            close?.addEventListener('click', () => {
                rememberChoice();
                modal.hidden = true;
            });
            form?.addEventListener('submit', rememberChoice);
            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    rememberChoice();
                    modal.hidden = true;
                }
            });
        }

        document.querySelectorAll('[data-ivr-reset-sync-modal]').forEach((button) => {
            button.addEventListener('click', () => {
                const key = button.dataset.modalKey || 'ivr-sync-modal-hidden';
                localStorage.removeItem(key);
                const activeModal = document.querySelector(`[data-ivr-sync-modal][data-modal-key="${CSS.escape(key)}"]`);
                if (activeModal) activeModal.hidden = false;
            });
        });

        document.querySelectorAll('[data-push-notifications]').forEach((button) => {
            button.addEventListener('click', async () => {
                const status = document.querySelector('[data-push-status]');
                if (!('Notification' in window)) {
                    if (status) status.textContent = 'Este navegador nao suporta notificacao push.';
                    return;
                }
                const permission = await Notification.requestPermission();
                if (status) {
                    status.textContent = permission === 'granted'
                        ? 'Notificacoes push ativadas neste navegador.'
                        : 'Permissao de notificacao nao liberada pelo navegador.';
                }
                if (permission === 'granted') {
                    new Notification('Discadora SIP', { body: 'Notificacoes da URA ativadas.' });
                }
            });
        });
    }

    function initQueuesPage() {
        const modal = document.querySelector('[data-queue-modal]');
        const openButtons = Array.from(document.querySelectorAll('[data-queue-open]'));
        const tabs = modal ? Array.from(modal.querySelectorAll('[data-queue-tab]')) : [];
        const panels = modal ? Array.from(modal.querySelectorAll('[data-queue-panel]')) : [];
        const prev = modal?.querySelector('[data-queue-prev]');
        const next = modal?.querySelector('[data-queue-next]');
        const submit = modal?.querySelector('button[type="submit"]');
        const owner = modal?.querySelector('select[name="user_id"], input[name="user_id"]');
        const search = modal?.querySelector('[data-queue-agent-search]');
        const agentRows = modal ? Array.from(modal.querySelectorAll('[data-queue-agent-row]')) : [];
        let current = 0;

        function setTab(index) {
            current = Math.max(0, Math.min(index, tabs.length - 1));
            tabs.forEach((tab, idx) => tab.classList.toggle('active', idx === current));
            panels.forEach((panel, idx) => panel.classList.toggle('active', idx === current));
            if (prev) prev.hidden = current === 0;
            if (next) next.hidden = current === tabs.length - 1;
            if (submit) submit.hidden = current !== tabs.length - 1;
            updateReview();
            filterAgents();
        }

        function updateReview() {
            if (!modal) return;
            const name = modal.querySelector('input[name="name"]')?.value || '-';
            const number = modal.querySelector('input[name="queue_number"]')?.value || 'Automatico';
            const strategy = modal.querySelector('select[name="strategy"]');
            const overflow = modal.querySelector('select[name="overflow_type"]');
            const agents = modal.querySelectorAll('input[name="extension_ids[]"]:checked').length;
            const values = {
                name,
                queue_number: number,
                strategy: strategy?.selectedOptions?.[0]?.textContent || '-',
                agents,
                overflow: overflow?.selectedOptions?.[0]?.textContent || '-'
            };
            Object.entries(values).forEach(([key, value]) => {
                const node = modal.querySelector(`[data-queue-review="${key}"]`);
                if (node) node.textContent = String(value);
            });
        }

        function filterAgents() {
            const ownerId = owner?.value || '';
            const term = (search?.value || '').trim().toLowerCase();
            agentRows.forEach((row) => {
                const matchesOwner = !ownerId || row.dataset.ownerId === ownerId;
                const matchesSearch = !term || (row.dataset.search || '').includes(term);
                row.hidden = !(matchesOwner && matchesSearch);
                const input = row.querySelector('input[type="checkbox"]');
                if (input && !matchesOwner) input.checked = false;
            });
            updateReview();
        }

        openButtons.forEach((button) => button.addEventListener('click', () => {
            if (modal) modal.hidden = false;
            setTab(button.dataset.queueOpen === 'advanced' ? Math.max(0, tabs.length - 1) : 0);
        }));
        tabs.forEach((tab, index) => tab.addEventListener('click', () => setTab(index)));
        prev?.addEventListener('click', () => setTab(current - 1));
        next?.addEventListener('click', () => setTab(current + 1));
        owner?.addEventListener('change', filterAgents);
        search?.addEventListener('input', filterAgents);
        modal?.addEventListener('input', updateReview);
        modal?.addEventListener('change', updateReview);
        setTab(0);

    }

    function initShellUi() {
        const body = document.body;
        const savedTheme = localStorage.getItem('discadora-theme');
        if (savedTheme === 'dark' || savedTheme === 'light') {
            body.classList.toggle('theme-dark', savedTheme === 'dark');
            body.classList.toggle('theme-light', savedTheme === 'light');
        }

        function syncThemeIcon() {
            const dark = body.classList.contains('theme-dark');
            document.querySelectorAll('[data-theme-toggle] i').forEach((icon) => {
                icon.className = `bi ${dark ? 'bi-sun' : 'bi-moon-stars'}`;
            });
        }
        syncThemeIcon();

        document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
            button.addEventListener('click', () => {
                const nextDark = !body.classList.contains('theme-dark');
                body.classList.toggle('theme-dark', nextDark);
                body.classList.toggle('theme-light', !nextDark);
                localStorage.setItem('discadora-theme', nextDark ? 'dark' : 'light');
                syncThemeIcon();
            });
        });

        const backdrop = document.querySelector('[data-mobile-backdrop]');
        function closeMenu() {
            body.classList.remove('nav-open');
            if (backdrop) backdrop.hidden = true;
        }
        document.querySelector('[data-mobile-menu]')?.addEventListener('click', () => {
            body.classList.add('nav-open');
            if (backdrop) backdrop.hidden = false;
        });
        backdrop?.addEventListener('click', closeMenu);
        document.querySelectorAll('.sidebar a').forEach((link) => link.addEventListener('click', closeMenu));

        const collapsed = localStorage.getItem('discadora-sidebar-collapsed') === '1';
        body.classList.toggle('sidebar-collapsed', collapsed);
        document.querySelector('[data-sidebar-collapse]')?.addEventListener('click', () => {
            const next = !body.classList.contains('sidebar-collapsed');
            body.classList.toggle('sidebar-collapsed', next);
            localStorage.setItem('discadora-sidebar-collapsed', next ? '1' : '0');
        });

        const modal = document.querySelector('[data-ui-modal]');
        const titleNode = modal?.querySelector('[data-ui-modal-title]');
        const messageNode = modal?.querySelector('[data-ui-modal-message]');
        const okButton = modal?.querySelector('[data-ui-modal-ok]');
        const cancelButton = modal?.querySelector('[data-ui-modal-cancel]');
        let modalResolve = null;

        function openModal({ title = 'Aviso', message = '', confirm = false } = {}) {
            if (!modal || !titleNode || !messageNode || !okButton || !cancelButton) {
                return Promise.resolve(window.confirm(message || title));
            }
            titleNode.textContent = title;
            messageNode.textContent = message;
            cancelButton.hidden = !confirm;
            okButton.textContent = confirm ? 'Confirmar' : 'OK';
            modal.hidden = false;
            return new Promise((resolve) => {
                modalResolve = resolve;
            });
        }

        function closeModal(value) {
            if (!modal) return;
            modal.hidden = true;
            if (modalResolve) modalResolve(value);
            modalResolve = null;
        }
        okButton?.addEventListener('click', () => closeModal(true));
        cancelButton?.addEventListener('click', () => closeModal(false));
        modal?.addEventListener('click', (event) => {
            if (event.target === modal) closeModal(false);
        });

        window.discadoraModal = openModal;
        document.querySelectorAll('.flash-modal-source').forEach((node) => {
            openModal({
                title: node.dataset.flashType === 'error' ? 'Atencao' : 'Pronto',
                message: node.dataset.flashMessage || '',
                confirm: false,
            });
        });

        document.querySelectorAll('form[data-confirm]').forEach((form) => {
            form.addEventListener('submit', async (event) => {
                if (form.dataset.confirmed === '1') return;
                event.preventDefault();
                const ok = await openModal({ title: 'Confirmar acao', message: form.dataset.confirm || 'Deseja continuar?', confirm: true });
                if (ok) {
                    form.dataset.confirmed = '1';
                    form.requestSubmit();
                }
            });
        });
    }

    initSoftphone();
    initAgentPhone();
    initWebrtcCampaign();
    initAmiCampaignRunner();
    initExtensionsPage();
    initOnlineCalls();
    initIvrDigitViewer();
    initIvrCallMonitor();
    initIvrConfigModal();
    initIvrSyncModal();
    initQueuesPage();
    initShellUi();
})();
