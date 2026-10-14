/* Local, dependency-free monitor. The CommonJS export is only for node --test. */
(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.PelicanAiHelpAssistant = api;
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const STATES = new Set(['created', 'starting', 'running', 'restarting', 'exited', 'paused', 'dead', 'removing', 'stopping', 'offline', 'missing']);
    const OFFLINE = new Set(['offline', 'exited', 'dead']);
    const LIVE = new Set(['starting', 'running', 'restarting', 'stopping']);

    class Capture {
        constructor(config = {}, now = 0) {
            this.mode = config.mode === 'existing' ? 'existing' : 'startup';
            this.maxBytes = Math.max(4096, Math.min(64000, config.max_log_bytes || 48000));
            this.watchMs = Math.max(30, Math.min(600, config.watch_seconds || 180)) * 1000;
            this.startedAt = now;
            this.lastLogAt = now;
            this.lines = '';
            this.state = 'unknown';
            this.admitted = false;
            this.closed = false;
            this.sawTransition = false;
            this.runningSince = null;
            this.offlineSince = null;
            this.truncated = false;
            this.peakMemory = 0;
            this.peakCpu = 0;
            this.oomKilled = false;
        }

        begin(info, now) {
            this.admitted = true;
            this.action = info.action;
            // Preserve transitions received while the HTTP power request was in flight.
            if (this.state === 'unknown' && STATES.has(info.initial_state)) this.state = info.initial_state;
            if (info.action === 'watching' && LIVE.has(this.state)) {
                this.sawTransition = true;
                if (this.state === 'running' && this.runningSince === null) this.runningSince = now;
            }
            if (this.mode === 'existing') this.startedAt = now;
        }

        append(value, now) {
            if (typeof value !== 'string') return;
            // Remove terminal controls; reports are escaped again by Blade.
            const line = value.replace(/\x1b(?:\[[0-?]*[ -/]*[@-~]|\][^\x07]*(?:\x07|\x1b\\))/g, '')
                .replace(/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/g, '');
            // Retain complete lines. Slicing bytes could sever a secret's label from
            // its value before backend redaction; omit oversized lines instead.
            const encoder = new TextEncoder();
            const rows = (this.lines + line + '\n').split('\n');
            let bytes = encoder.encode(rows.join('\n')).length;
            while (bytes > this.maxBytes && rows.length > 1) {
                bytes -= encoder.encode(rows.shift()).length + 1;
                this.truncated = true;
            }
            this.lines = rows.join('\n');
            this.lastLogAt = now;
        }

        observe(event, args, now) {
            if (this.closed || !Array.isArray(args)) return;
            if (event === 'console output' || event === 'daemon error') {
                this.append(args[0], now);
            } else if (event === 'status' && STATES.has(args[0])) {
                this.setState(args[0], now);
            } else if (event === 'stats') {
                try {
                    const stats = typeof args[0] === 'string' ? JSON.parse(args[0]) : args[0];
                    if (!stats || typeof stats !== 'object') return;
                    if (STATES.has(stats.state)) this.setState(stats.state, now);
                    const memory = Number(stats.memory_bytes);
                    const cpu = Number(stats.cpu_absolute);
                    if (Number.isFinite(memory) && memory >= 0) this.peakMemory = Math.max(this.peakMemory, memory);
                    if (Number.isFinite(cpu) && cpu >= 0) this.peakCpu = Math.max(this.peakCpu, cpu);
                    if (stats.oom_killed === true) this.oomKilled = true;
                } catch (_) { /* A malformed optional sample cannot interrupt capture. */ }
            }
        }

        setState(state, now) {
            if (LIVE.has(state)) this.sawTransition = true;
            if (state === 'running') {
                if (this.state !== 'running') this.runningSince = now;
            } else this.runningSince = null;
            if (OFFLINE.has(state) && this.sawTransition) {
                if (!OFFLINE.has(this.state)) this.offlineSince = now;
            } else this.offlineSince = null;
            this.state = state;
        }

        outcome(now) {
            if (!this.admitted || this.closed) return null;
            const elapsed = now - this.startedAt;
            if (this.mode === 'existing') {
                if ((elapsed >= 2000 && now - this.lastLogAt >= 1200) || elapsed >= 8000) return 'observed';
                return null;
            }
            // First offline status is usually the pre-start snapshot, not failure.
            if (this.offlineSince !== null && now - this.offlineSince >= 1500) return 'failed';
            // Running is observed for a settling period to catch quick crash loops.
            if (this.runningSince !== null && now - this.runningSince >= 20000) return 'running';
            if (elapsed >= this.watchMs) return 'timeout';
            return null;
        }

        snapshot(outcome, reason = '') {
            return {
                logs: this.lines,
                outcome,
                observed_state: this.state,
                observations: {
                    log_truncated: this.truncated,
                    saw_transition: this.sawTransition,
                    oom_killed: this.oomKilled,
                    peak_memory_bytes: this.peakMemory,
                    peak_cpu_percent: this.peakCpu,
                    ...(reason ? { disconnect_reason: reason } : {}),
                },
            };
        }
    }

    function mount(element, wire, dependencies = {}) {
        const WS = dependencies.WebSocket || globalThis.WebSocket;
        const now = dependencies.now || (() => performance.now());
        const startButton = element.querySelector('[data-ai-start]');
        const existingButton = element.querySelector('[data-ai-existing]');
        const cancelButton = element.querySelector('[data-ai-cancel]');
        const status = element.querySelector('[data-ai-status]');
        const badge = element.querySelector('[data-ai-badge]');
        let active = null;
        let generation = 0;
        let destroyed = false;

        function display(message, busy, kind = 'Ready') {
            if (destroyed) return;
            status.textContent = message;
            badge.textContent = kind;
            startButton.disabled = busy;
            existingButton.disabled = busy;
            cancelButton.hidden = !busy;
            cancelButton.disabled = kind === 'Analyzing';
            element.setAttribute('aria-busy', busy ? 'true' : 'false');
        }

        function close(run) {
            clearTimeout(run.authTimer);
            clearInterval(run.tick);
            if (!run.socket) return;
            run.socket.onclose = null;
            run.socket.onerror = null;
            run.socket.onmessage = null;
            run.socket.close();
        }

        async function release(run) {
            try { await wire.cancelDiagnosis(run.config.run_id); } catch (_) { /* Run also expires on the server. */ }
        }

        async function fail(run, message) {
            if (active !== run || run.finishing) return;
            run.finishing = true;
            close(run);
            await release(run);
            if (active !== run) return;
            active = null;
            display(message, false, 'Could not continue');
        }

        async function finish(run, outcome, reason = '') {
            if (active !== run || run.finishing) return;
            if (!run.capture.admitted) {
                run.pendingDisconnect = reason || 'The diagnostic socket closed before startup could be confirmed.';
                return;
            }
            run.finishing = true;
            const capture = run.capture.snapshot(outcome, reason);
            run.capture.closed = true;
            close(run);
            display('Preparing your diagnosis and recommended next steps…', true, 'Analyzing');
            try {
                const result = await wire.completeDiagnosis(run.config.run_id, capture);
                if (active !== run || destroyed) return;
                active = null;
                if (result?.error) {
                    await release(run);
                    display(result.error, false, 'Could not continue');
                } else display('Diagnosis ready. Review the evidence and suggested steps below.', false, 'Complete');
            } catch (_) {
                await release(run);
                if (active !== run || destroyed) return;
                active = null;
                display('The panel connection was interrupted while preparing the diagnosis. Please try again.', false, 'Connection issue');
            }
        }

        async function start(mode = 'startup') {
            if (active || destroyed || startButton.disabled) return;
            const mine = ++generation;
            display(mode === 'existing' ? 'Fetching recent console history through the panel…' : 'Connecting to Wings before collecting server output…', true, mode === 'existing' ? 'Reading logs' : 'Connecting');
            let config;
            try { config = await wire.prepareDiagnosis(mode); }
            catch (_) {
                display('Could not reach the panel. Reload this page and try again.', false, 'Connection issue');
                return;
            }
            if (destroyed || mine !== generation) {
                if (config?.run_id) await release({ config });
                return;
            }
            if (config?.error) { display(config.error, false, 'Could not continue'); return; }
            if (mode === 'existing') {
                const run = { config, socket: null, capture: new Capture({ ...config, mode: 'existing' }, now()), finishing: false, beginning: true };
                active = run;
                let info;
                try { info = await wire.beginCapture(config.run_id); }
                catch (_) { await fail(run, 'The panel could not retrieve recent console history. Check the node connection and try again.'); return; }
                if (active !== run || run.finishing || destroyed) return;
                if (info?.error) { await fail(run, info.error); return; }
                run.capture.begin(info, now());
                // REST history is already bounded by the backend. Preserve it
                // until backend redaction, including multiline secret headers.
                run.capture.lines = typeof info.logs === 'string' ? info.logs : '';
                run.capture.truncated = info.log_truncated === true;
                run.beginning = false;
                await finish(run, 'observed');
                return;
            }
            let socket;
            try { socket = new WS(config.socket); }
            catch (_) {
                await release({ config });
                display('This browser could not open the Wings connection. Check the node address and TLS configuration.', false, 'Connection issue');
                return;
            }
            const run = { config, socket, capture: new Capture(config, now()), authenticated: false, finishing: false, beginning: false };
            active = run;
            run.authTimer = setTimeout(() => fail(run, 'Wings did not authenticate the diagnostic connection. Check the node connection and try again.'), 15000);
            socket.onopen = () => socket.send(JSON.stringify({ event: 'auth', args: [config.token] }));
            socket.onerror = () => {
                if (!run.beginning && !run.capture.admitted) fail(run, 'Could not connect to Wings. Check the node connection, HTTPS certificate, and browser network settings.');
                else finish(run, 'connection_error', 'The Wings diagnostic connection was interrupted.');
            };
            socket.onclose = () => {
                if (!run.beginning && !run.capture.admitted) fail(run, 'The Wings connection closed before authentication. Check the node connection and try again.');
                else finish(run, 'connection_error', 'The Wings diagnostic connection closed during capture.');
            };
            socket.onmessage = async ({ data }) => {
                if (active !== run || run.finishing) return;
                let message;
                try { message = JSON.parse(data); } catch (_) { return; }
                // Wings' auth-success frame has no arguments (often JSON null).
                if (!message || typeof message.event !== 'string') return;
                if (message.event === 'auth success') {
                    if (run.authenticated) return;
                    run.authenticated = true;
                    run.beginning = true;
                    clearTimeout(run.authTimer);
                    // Capture is already attached. Never request backlog for a fresh start.
                    display('Checking current state and starting the server if it is stopped…', true, 'Checking');
                    let info;
                    try { info = await wire.beginCapture(config.run_id); }
                    catch (_) { await fail(run, 'The panel could not confirm startup. Check the console before trying again.'); return; }
                    if (active !== run || run.finishing || destroyed) return;
                    if (info?.error) { await fail(run, info.error); return; }
                    run.capture.begin(info, now());
                    run.beginning = false;
                    if (run.pendingDisconnect) { await finish(run, 'connection_error', run.pendingDisconnect); return; }
                    display(info.action === 'watching' ? 'The server is already active. Watching live output without restarting it…' : 'Start requested. Watching live output and server state…', true, 'Watching');
                    run.tick = setInterval(() => {
                        const outcome = run.capture.outcome(now());
                        if (outcome) finish(run, outcome);
                    }, 250);
                } else if (message.event === 'jwt error' || message.event === 'token expired') {
                    if (!run.capture.admitted && !run.beginning) fail(run, 'Wings rejected the diagnostic credentials. Reload the page and try again.');
                    else finish(run, 'connection_error', 'The Wings authentication expired or was rejected.');
                } else if (run.authenticated) run.capture.observe(message.event, message.args, now());
            };
        }

        async function cancel() {
            const run = active;
            if (run?.finishing) return;
            ++generation;
            active = null;
            if (run) { close(run); await release(run); }
            display('Capture cancelled. Any server start already requested will continue.', false, 'Cancelled');
        }

        const startListener = () => start('startup');
        const existingListener = () => start('existing');
        startButton.addEventListener('click', startListener);
        existingButton.addEventListener('click', existingListener);
        cancelButton.addEventListener('click', cancel);

        return {
            start,
            cancel,
            destroy() {
                destroyed = true;
                ++generation;
                if (active) { close(active); if (!active.finishing) release(active); active = null; }
                startButton.removeEventListener('click', startListener);
                existingButton.removeEventListener('click', existingListener);
                cancelButton.removeEventListener('click', cancel);
            },
        };
    }

    return { Capture, mount };
}));
