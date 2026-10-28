/**
 * 用 CDP（Chrome DevTools Protocol）驱动 chrome-headless-shell 截图。
 *
 * 只依赖 Node 内置的 WebSocket（v22+），不需要 playwright。
 *
 * 用法：
 *   node tools/shot.js <url> <out.png> [--width=1440] [--height=1200] [--dark]
 *                               [--scroll=SELECTOR] [--mobile] [--full]
 */
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = path.join(
	os.homedir(),
	'AppData/Local/ms-playwright/chromium_headless_shell-1243/chrome-headless-shell-win64/chrome-headless-shell.exe'
);

function arg(name, def) {
	const hit = process.argv.find((a) => a.startsWith(`--${name}=`));
	return hit ? hit.slice(name.length + 3) : def;
}
function flag(name) {
	return process.argv.includes(`--${name}`);
}

const url = process.argv[2];
const out = process.argv[3];
const width = parseInt(arg('width', '1440'), 10);
const height = parseInt(arg('height', '1200'), 10);
const scroll = arg('scroll', '');
const mobile = flag('mobile');
const full = flag('full');
const dark = flag('dark');

(async () => {
	const port = 9300 + Math.floor(Math.random() * 500);
	const proc = spawn(
		CHROME,
		[
			`--remote-debugging-port=${port}`,
			'--headless',
			'--no-sandbox',
			'--disable-gpu',
			'--hide-scrollbars',
			'--force-device-scale-factor=1',
			'about:blank',
		],
		{ stdio: 'ignore' }
	);

	const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

	// 等调试端口就绪。
	let version = null;
	for (let i = 0; i < 100; i++) {
		try {
			const r = await fetch(`http://127.0.0.1:${port}/json/version`);
			version = await r.json();
			break;
		} catch (e) {
			await sleep(100);
		}
	}
	if (!version) {
		console.error('cdp not ready');
		proc.kill();
		process.exit(1);
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

	const send = (method, params = {}, sessionId) =>
		new Promise((resolve, reject) => {
			const mid = ++id;
			pending.set(mid, { resolve, reject });
			ws.send(JSON.stringify({ id: mid, method, params, sessionId }));
		});

	await new Promise((r) => (ws.onopen = r));

	const target = await send('Target.createTarget', { url: 'about:blank' });
	const attached = await send('Target.attachToTarget', { targetId: target.targetId, flatten: true });
	const session = attached.sessionId;

	await send('Page.enable', {}, session);
	await send('Runtime.enable', {}, session);
	await send('Emulation.setDeviceMetricsOverride', {
		width,
		height,
		deviceScaleFactor: 1,
		mobile,
	}, session);

	if (dark) {
		await send('Emulation.setEmulatedMedia', {
			features: [{ name: 'prefers-color-scheme', value: 'dark' }],
		}, session);
	}

	await send('Page.navigate', { url }, session);

	// 等待 load 事件。
	await new Promise((resolve) => {
		const t = setTimeout(resolve, 15000);
		const handler = (ev) => {
			const msg = JSON.parse(ev.data);
			if (msg.method === 'Page.loadEventFired') {
				clearTimeout(t);
				resolve();
			}
		};
		ws.addEventListener('message', handler);
	});

	// 站点自己的本地主题开关（Sakurairo 用 localStorage.dark）。
	await send('Runtime.evaluate', {
		expression: dark
			? `(function(){ localStorage.setItem('dark','1'); document.body.classList.add('dark'); })()`
			: `(function(){ localStorage.setItem('dark','0'); document.body.classList.remove('dark'); })()`,
	}, session);

	await sleep(1200);

	if (scroll) {
		await send('Runtime.evaluate', {
			expression: `(function(){var el=document.querySelector(${JSON.stringify(scroll)}); if(el){el.scrollIntoView({block:'center'});} return el?1:0;})()`,
		}, session);
		await sleep(700);
	}

	if (full) {
		const metrics = await send('Page.getLayoutMetrics', {}, session);
		const content = metrics.cssContentSize;
		await send('Emulation.setDeviceMetricsOverride', {
			width,
			height: Math.ceil(content.height),
			deviceScaleFactor: 1,
			mobile,
		}, session);
		await sleep(400);
	}

	const shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true }, session);
	fs.writeFileSync(out, Buffer.from(shot.data, 'base64'));

	console.log(`saved ${out}`);

	await send('Target.closeTarget', { targetId: target.targetId });
	ws.close();
	proc.kill();
	process.exit(0);
})().catch((e) => {
	console.error(e);
	process.exit(1);
});
