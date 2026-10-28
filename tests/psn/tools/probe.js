/**
 * 用 CDP 对元素做紧密截图 + 打印计算样式，用于验证组件视觉。
 *
 * 用法：node tools/probe.js <url> <selector> <out.png> [--width=1440] [--height=1000] [--dark] [--mobile]
 */
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = path.join(
	os.homedir(),
	'AppData/Local/ms-playwright/chromium_headless_shell-1243/chrome-headless-shell-win64/chrome-headless-shell.exe'
);

const url = process.argv[2];
const selector = process.argv[3];
const out = process.argv[4];
const width = parseInt((process.argv.find((a) => a.startsWith('--width=')) || '--width=1440').split('=')[1], 10);
const height = parseInt((process.argv.find((a) => a.startsWith('--height=')) || '--height=1000').split('=')[1], 10);
const dark = process.argv.includes('--dark');
const mobile = process.argv.includes('--mobile');

(async () => {
	const port = 9800 + Math.floor(Math.random() * 100);
	const proc = spawn(CHROME, [`--remote-debugging-port=${port}`, '--headless', '--no-sandbox', '--disable-gpu', '--hide-scrollbars', 'about:blank'], { stdio: 'ignore' });
	const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

	let version = null;
	for (let i = 0; i < 100; i++) {
		try {
			version = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json();
			break;
		} catch (e) {
			await sleep(100);
		}
	}
	if (!version) { console.error('cdp not ready'); proc.kill(); process.exit(1); }

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
	const send = (method, params = {}, sessionId) =>
		new Promise((resolve, reject) => { const mid = ++id; pending.set(mid, { resolve, reject }); ws.send(JSON.stringify({ id: mid, method, params, sessionId })); });
	await new Promise((r) => (ws.onopen = r));

	const target = await send('Target.createTarget', { url: 'about:blank' });
	const session = (await send('Target.attachToTarget', { targetId: target.targetId, flatten: true })).sessionId;

	await send('Page.enable', {}, session);
	await send('Runtime.enable', {}, session);
	await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 2, mobile }, session);
	if (dark) await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: 'dark' }] }, session);

	await send('Page.navigate', { url }, session);
	await sleep(4000);
	await send('Runtime.evaluate', {
		expression: dark
			? `localStorage.setItem('dark','1');document.body&&document.body.classList.add('dark');`
			: `localStorage.setItem('dark','0');document.body&&document.body.classList.remove('dark');`,
	}, session);
	await sleep(900);

	const info = await send('Runtime.evaluate', {
		returnByValue: true,
		expression: `(function(){
			var el=document.querySelector(${JSON.stringify(selector)});
			if(!el) return {found:false};
			el.scrollIntoView({block:'center'});
			var r=el.getBoundingClientRect();
			var cs=getComputedStyle(el);
			var input=el.querySelector('input.psn-input');
			var btn=el.querySelector('.psn-submit');
			var ics=input?getComputedStyle(input):null;
			var bcs=btn?getComputedStyle(btn):null;
			return {
				found:true,
				rect:{x:r.x,y:r.y,w:r.width,h:r.height},
				card:{bg:cs.backgroundColor,radius:cs.borderRadius,border:cs.border,shadow:cs.boxShadow},
				hasInput:!!input,
				input: ics?{h:ics.height,bg:ics.backgroundColor,border:ics.border,radius:ics.borderRadius}:null,
				hasBtn:!!btn,
				btn: bcs?{h:bcs.height,bg:bcs.backgroundColor,radius:bcs.borderRadius,color:bcs.color}:null
			};
		})()`,
	}, session);

	console.log(JSON.stringify(info.result.value, null, 2));

	await sleep(600);
	const shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }, session);
	fs.writeFileSync(out, Buffer.from(shot.data, 'base64'));
	console.log('saved ' + out);

	ws.close(); proc.kill(); process.exit(0);
})().catch((e) => { console.error(e); process.exit(1); });
