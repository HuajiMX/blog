/**
 * 抓取订阅表单提交时的网络请求，确认是否被主题 PJAX 拦截。
 *
 * 用法：node tests/psn/tools/submit-net.js <url>
 */
const { spawn } = require('child_process');
const path = require('path');
const os = require('os');

const CHROME = path.join(
	os.homedir(),
	'AppData/Local/ms-playwright/chromium_headless_shell-1243/chrome-headless-shell-win64/chrome-headless-shell.exe'
);

const url = process.argv[2] || 'http://localhost/';

(async () => {
	const port = 9450 + Math.floor(Math.random() * 50);
	const proc = spawn(CHROME, [`--remote-debugging-port=${port}`, '--headless', '--no-sandbox', '--disable-gpu', '--hide-scrollbars', 'about:blank'], { stdio: 'ignore' });
	const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

	let version = null;
	for (let i = 0; i < 100; i++) {
		try { version = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); break; } catch (e) { await sleep(100); }
	}

	const ws = new WebSocket(version.webSocketDebuggerUrl);
	let id = 0;
	const pending = new Map();
	const reqs = [];
	const consoleMsgs = [];
	ws.onmessage = (ev) => {
		const msg = JSON.parse(ev.data);
		if (msg.method === 'Network.requestWillBeSent') {
			const r = msg.params.request;
			if (r.method !== 'GET' || (r.url || '').includes('psn_status')) {
				reqs.push(`${r.method} ${r.url} ${r.postData ? '| ' + r.postData.slice(0, 120) : ''}`);
			}
		}
		if (msg.method === 'Network.responseReceived') {
			const r = msg.params.response;
			if ((r.url || '').includes('localhost') && msg.params.type === 'Document') {
				reqs.push(`  RESP ${r.status} ${r.url}`);
			}
		}
		if (msg.method === 'Runtime.consoleAPICalled') {
			consoleMsgs.push(msg.params.args.map((a) => a.value || a.description || '').join(' ').slice(0, 200));
		}
		if (msg.id && pending.has(msg.id)) {
			const { resolve, reject } = pending.get(msg.id);
			pending.delete(msg.id);
			msg.error ? reject(new Error(JSON.stringify(msg.error))) : resolve(msg.result);
		}
	};
	const send = (m, p = {}, s) => new Promise((res, rej) => { const i = ++id; pending.set(i, { resolve: res, reject: rej }); ws.send(JSON.stringify({ id: i, method: m, params: p, sessionId: s })); });
	await new Promise((r) => (ws.onopen = r));

	const target = await send('Target.createTarget', { url: 'about:blank' });
	const session = (await send('Target.attachToTarget', { targetId: target.targetId, flatten: true })).sessionId;
	await send('Page.enable', {}, session);
	await send('Runtime.enable', {}, session);
	await send('Network.enable', {}, session);
	await send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false }, session);

	await send('Page.navigate', { url }, session);
	await sleep(4000);
	const initial = await send('Runtime.evaluate', { expression: 'location.href', returnByValue: true }, session);
	reqs.length = 0;
	consoleMsgs.length = 0;

	await send('Runtime.evaluate', {
		expression: `(function(){
			var f = document.querySelector('#psn-subscribe form');
			if (!f) return 'no form';
			f.querySelector('input[name="psn_email"]').value = 'net-probe-' + Date.now() + '@example.com';
			f.querySelector('button[type=submit]').click();
			return 'clicked';
		})()`,
		returnByValue: true,
	}, session);

	await sleep(5000);

	const after = await send('Runtime.evaluate', {
		returnByValue: true,
		expression: `({ href: location.href, notice: (document.querySelector('#psn-subscribe .psn-notice')||{}).textContent || '', hasCard: !!document.getElementById('psn-subscribe'), pjaxy: (window._iro||{}).poi_pjax })`,
	}, session);

	console.log('initial :', initial.result.value);
	console.log('after   :', JSON.stringify(after.result.value));
	console.log('\n--- 非 GET 或带 psn_status 的请求 ---');
	reqs.forEach((r) => console.log(r));
	console.log('\n--- console ---');
	consoleMsgs.slice(-8).forEach((c) => console.log(c));

	ws.close(); proc.kill(); process.exit(0);
})().catch((e) => { console.error(e); process.exit(1); });
