/**
 * 测量卡片内各元素的实际盒模型，用于排查间距问题。
 *
 * 用法：node tools/measure.js <url> [--width=390] [--height=844] [--mobile]
 */
const { spawn } = require('child_process');
const path = require('path');
const os = require('os');

const CHROME = path.join(
	os.homedir(),
	'AppData/Local/ms-playwright/chromium_headless_shell-1243/chrome-headless-shell-win64/chrome-headless-shell.exe'
);

const url = process.argv[2];
const width = parseInt((process.argv.find((a) => a.startsWith('--width=')) || '--width=1280').split('=')[1], 10);
const height = parseInt((process.argv.find((a) => a.startsWith('--height=')) || '--height=900').split('=')[1], 10);
const mobile = process.argv.includes('--mobile');

(async () => {
	const port = 9700 + Math.floor(Math.random() * 100);
	const proc = spawn(CHROME, [`--remote-debugging-port=${port}`, '--headless', '--no-sandbox', '--disable-gpu', '--hide-scrollbars', 'about:blank'], { stdio: 'ignore' });
	const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

	let version = null;
	for (let i = 0; i < 100; i++) {
		try { version = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); break; } catch (e) { await sleep(100); }
	}
	const ws = new WebSocket(version.webSocketDebuggerUrl);
	let id = 0;
	const pending = new Map();
	ws.onmessage = (ev) => {
		const msg = JSON.parse(ev.data);
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
	await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile }, session);
	await send('Page.navigate', { url }, session);
	await sleep(3500);

	const out = await send('Runtime.evaluate', {
		returnByValue: true,
		expression: `(function(){
			var card=document.querySelector('#psn-subscribe');
			if(!card) return {found:false};
			function box(sel,label){
				var el=card.querySelector(sel);
				if(!el) return null;
				var r=el.getBoundingClientRect(); var cs=getComputedStyle(el);
				return {label:label,top:Math.round(r.top),bottom:Math.round(r.bottom),h:Math.round(r.height),
					mt:cs.marginTop,mb:cs.marginBottom,pt:cs.paddingTop,pb:cs.paddingBottom,gap:cs.gap,display:cs.display};
			}
			var items=[box('.psn-card-inner','inner'),box('.psn-form','form'),box('.psn-fields','fields'),
				box('.psn-field','field-email'),box('.psn-field-name','field-name'),
				box('.psn-form .psn-submit','submit')];
			return {found:true, width:Math.round(card.getBoundingClientRect().width), items:items};
		})()`,
	}, session);

	console.log(JSON.stringify(out.result.value, null, 2));
	ws.close(); proc.kill(); process.exit(0);
})().catch((e) => { console.error(e); process.exit(1); });
