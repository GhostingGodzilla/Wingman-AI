const { test } = require('node:test');
const assert = require('node:assert/strict');
const { Capture, mount } = require('../../resources/js/startup-monitor.js');

test('initial offline state is not a failed startup; timeout stays inconclusive', () => {
    const capture = new Capture({ watch_seconds: 30 }, 0);
    capture.observe('status', ['offline'], 100);
    capture.begin({ action: 'started', initial_state: 'offline' }, 200);
    assert.equal(capture.outcome(10000), null);
    assert.equal(capture.outcome(30000), 'timeout');
});

test('captures a fast crash before the backend start response arrives', () => {
    const capture = new Capture({}, 0);
    capture.observe('status', ['offline'], 10);
    capture.observe('status', ['starting'], 20);
    capture.observe('console output', ['Error: missing server.jar'], 30);
    capture.observe('status', ['offline'], 40);
    assert.equal(capture.outcome(2000), null, 'wait for backend admission before analysis');
    capture.begin({ action: 'started', initial_state: 'offline' }, 2500);
    assert.equal(capture.outcome(2500), 'failed');
    assert.match(capture.snapshot('failed').logs, /missing server.jar/);
});

test('running is observed for a settling period and quick crashes remain failures', () => {
    const capture = new Capture({}, 0);
    capture.begin({ action: 'started', initial_state: 'offline' }, 0);
    capture.observe('status', ['running'], 1000);
    assert.equal(capture.outcome(20999), null);
    capture.observe('status', ['offline'], 21000);
    capture.observe('daemon error', ['process exited with code 1'], 22000);
    assert.equal(capture.outcome(22499), null);
    assert.equal(capture.outcome(22500), 'failed');
});

test('watching an already running server never requires a transition to running', () => {
    const capture = new Capture({}, 0);
    capture.begin({ action: 'watching', initial_state: 'running' }, 100);
    assert.equal(capture.outcome(20099), null);
    assert.equal(capture.outcome(20100), 'running');
});

test('restarts reset the running settling timer', () => {
    const capture = new Capture({}, 0);
    capture.begin({ action: 'watching', initial_state: 'running' }, 0);
    capture.observe('status', ['restarting'], 19000);
    capture.observe('status', ['running'], 19500);
    assert.equal(capture.outcome(20000), null);
    assert.equal(capture.outcome(39500), 'running');
});

test('existing logs wait for a quiet period and never assert a fresh-start failure', () => {
    const capture = new Capture({ mode: 'existing' }, 0);
    capture.begin({ action: 'existing', initial_state: 'offline' }, 0);
    capture.observe('console output', ['Old crash'], 1900);
    assert.equal(capture.outcome(2000), null);
    assert.equal(capture.outcome(3100), 'observed');
});

test('bounded capture handles ANSI, multibyte text, malformed frames and safe stats', () => {
    const capture = new Capture({ max_log_bytes: 4096 }, 0);
    capture.observe('console output', ['\u001b[31m' + 'é'.repeat(6000) + '\u001b[0m'], 1);
    capture.observe('stats', ['{broken'], 2);
    capture.observe('status', ['invented'], 3);
    capture.observe('stats', [JSON.stringify({ memory_bytes: 1024, cpu_absolute: 25, oom_killed: true })], 4);
    const snapshot = capture.snapshot('timeout');
    assert.ok(Buffer.byteLength(snapshot.logs) <= 4096);
    assert.equal(snapshot.observations.log_truncated, true);
    assert.equal(snapshot.observations.peak_memory_bytes, 1024);
    assert.equal(snapshot.observations.oom_killed, true);
    assert.equal(snapshot.observed_state, 'unknown');
    assert.doesNotMatch(snapshot.logs, /\u001b/);
});

test('truncation retains whole lines instead of orphaning a secret value', () => {
    const capture = new Capture({ max_log_bytes: 4096 }, 0);
    capture.append('password=' + 's'.repeat(6000), 1);
    capture.append('Useful recent error', 2);
    assert.equal(capture.lines, 'Useful recent error\n');
    assert.equal(capture.truncated, true);
});

function fakeElement() {
    const parts = new Map();
    for (const name of ['start', 'existing', 'cancel', 'status', 'badge']) {
        parts.set(`[data-ai-${name}]`, { disabled: false, hidden: false, textContent: '', addEventListener() {}, removeEventListener() {} });
    }
    return { querySelector: key => parts.get(key), setAttribute() {} };
}

test('controller authenticates before backend start and never fetches historical logs for startup', async () => {
    const calls = [];
    let socket;
    class Socket {
        constructor(url) { this.url = url; this.sent = []; socket = this; }
        send(message) { this.sent.push(JSON.parse(message)); }
        close() { this.closed = true; }
    }
    const wire = {
        async prepareDiagnosis() { calls.push('prepare'); return { run_id: 'run', socket: 'wss://node/ws', token: 'token', mode: 'startup' }; },
        async beginCapture() { calls.push('begin'); return { action: 'started', initial_state: 'offline' }; },
        async cancelDiagnosis() { calls.push('cancel'); return {}; },
    };
    const controller = mount(fakeElement(), wire, { WebSocket: Socket, now: () => 0 });
    await controller.start();
    assert.deepEqual(calls, ['prepare']);
    socket.onopen();
    assert.deepEqual(socket.sent, [{ event: 'auth', args: ['token'] }]);
    assert.deepEqual(calls, ['prepare']);
    await socket.onmessage({ data: JSON.stringify({ event: 'auth success', args: null }) });
    assert.deepEqual(calls, ['prepare', 'begin']);
    assert.equal(socket.sent.some(message => message.event === 'send logs'), false);
    controller.destroy();
    assert.equal(socket.closed, true);
});

test('a socket closure during the backend start request produces a connection diagnosis', async () => {
    let socket;
    let allowStart;
    const captures = [];
    class Socket {
        constructor() { socket = this; }
        send() {}
        close() {}
    }
    const wire = {
        async prepareDiagnosis() { return { run_id: 'run', socket: 'wss://node/ws', token: 'token', mode: 'startup' }; },
        beginCapture() { return new Promise(resolve => { allowStart = resolve; }); },
        async completeDiagnosis(id, capture) { captures.push(capture); return { complete: true }; },
        async cancelDiagnosis() { return {}; },
    };
    const controller = mount(fakeElement(), wire, { WebSocket: Socket, now: () => 0 });
    await controller.start();
    const authenticating = socket.onmessage({ data: JSON.stringify({ event: 'auth success', args: [] }) });
    socket.onclose();
    allowStart({ action: 'started', initial_state: 'offline' });
    await authenticating;
    assert.equal(captures.length, 1);
    assert.equal(captures[0].outcome, 'connection_error');
    controller.destroy();
});

test('existing history reads backend REST output while offline without opening a browser socket', async () => {
    const calls = [];
    const captures = [];
    class Socket {
        constructor() { throw new Error('History must not open a WebSocket.'); }
    }
    const wire = {
        async prepareDiagnosis(mode) { calls.push(['prepare', mode]); return { run_id: 'history-run', mode: 'existing' }; },
        async beginCapture(id) { calls.push(['begin', id]); return { action: 'existing', initial_state: 'offline', logs: 'Error: Unable to access jarfile server.jar', log_truncated: true }; },
        async completeDiagnosis(id, capture) { calls.push(['complete', id]); captures.push(capture); return { complete: true }; },
        async cancelDiagnosis() { calls.push(['cancel']); return {}; },
    };
    const controller = mount(fakeElement(), wire, { WebSocket: Socket, now: () => 100 });
    await controller.start('existing');
    assert.deepEqual(calls, [['prepare', 'existing'], ['begin', 'history-run'], ['complete', 'history-run']]);
    assert.equal(captures.length, 1);
    assert.equal(captures[0].outcome, 'observed');
    assert.equal(captures[0].observed_state, 'offline');
    assert.match(captures[0].logs, /Unable to access jarfile/);
    assert.equal(captures[0].observations.log_truncated, true);
    controller.destroy();
});

test('a rejected existing-history request releases its run and never submits a fabricated diagnosis', async () => {
    const calls = [];
    const wire = {
        async prepareDiagnosis() { return { run_id: 'history-run', mode: 'existing' }; },
        async beginCapture() { return { error: 'Recent history is unavailable.' }; },
        async completeDiagnosis() { calls.push('complete'); return {}; },
        async cancelDiagnosis(id) { calls.push(['cancel', id]); return {}; },
    };
    const element = fakeElement();
    const controller = mount(element, wire, { WebSocket: class {}, now: () => 0 });
    await controller.start('existing');
    assert.deepEqual(calls, [['cancel', 'history-run']]);
    assert.equal(element.querySelector('[data-ai-status]').textContent, 'Recent history is unavailable.');
    controller.destroy();
});

test('existing REST history reaches backend redaction before the smaller provider log budget is applied', async () => {
    const history = '-----BEGIN PRIVATE KEY-----\n' + 'a'.repeat(70000) + '\n-----END PRIVATE KEY-----\nRecent error';
    const captures = [];
    const wire = {
        async prepareDiagnosis() { return { run_id: 'history-run', mode: 'existing', max_log_bytes: 48000 }; },
        async beginCapture() { return { action: 'existing', initial_state: 'offline', logs: history, log_truncated: false }; },
        async completeDiagnosis(id, capture) { captures.push(capture); return { complete: true }; },
        async cancelDiagnosis() { return {}; },
    };
    const controller = mount(fakeElement(), wire, { WebSocket: class {}, now: () => 0 });
    await controller.start('existing');
    assert.equal(captures[0].logs, history);
    assert.ok(Buffer.byteLength(captures[0].logs) > 48000);
    assert.equal(captures[0].observations.log_truncated, false);
    controller.destroy();
});
