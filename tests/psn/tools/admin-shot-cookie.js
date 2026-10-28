/**
 * 无密码进入后台并截图：用 WordPress 自己生成登录 cookie，再注入浏览器。
 *
 * 只在本地调试用。用法：
 *   php tests/psn/tools/mint-cookie.php            # 输出 name\tvalue 两行
 *   node tools/admin-shot-cookie.js <path> <out.png>
 */
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = path.join(
	os.homedir(),
	'AppData/Local/ms-playwright/chromium_headless_shell-1243/chrome-headless-shell-win64/chrome-headless-shell.exe'
);

const targetPath = process.argv[2] || '/wp-admin/admin.php?page=psn-settings';
const out = process.argv[3] || 'C:/Users/HuajiMC/AppData/Local/Temp/shots/admin.png';
const width = parseInt((process.argv.find((a) => a.startsWith('--width=')) || '--width=1440').split('=')[1], 10);
const height = parseInt((process.argv.find((a) => a.startsWith('--height=')) || '--height=1100').split('=')[1], 10);

// cookie 由 tests/psn/tools/mint-cookie.php 预先生成（避免在 Node 里跑 PHP）。
function mintCookies() {
	const tmp = path.join(__dirname, '..', '_cookies.json');
	if (!fs.existsSync(tmp)) {
		throw new Error('缺少 cookie 文件：先运行 php tests/psn/tools/mint-cookie.php');
	}
	const data = JSON.parse(fs.readFileSync(tmp, 'utf8'));
	return Object.keys(data).map((name) => ({ name, value: data[name] }));
}

(async () => {
	const cookies = mintCookies();
	if (!cookies.length) {
		console.error('cookie mint failed');
		process.exit(1);
	}

	const port = 9600 + Math.floor(Math.random() * 100);
	const proc = spawn(CHROME, [`--remote-debugging-port=${port}`, '--headless', '--no-sandbox', '--disable-gpu', '--hide-scrollbars', 'about:blank'], { stdio: 'ignore' });
	const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

	let version = null;
	for (let i = 0; i < 100; i++) {
		try { version = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); break; } catch (e) { await sleep(100); }
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
	const send = (m, p = {}, s) => new Promise((res, rej) => { const i = ++id; pending.set(i, { resolve: res, reject: rej }); ws.send(JSON.stringify({ id: i, method: m, params: p, sessionId: s })); });
	await new Promise((r) => (ws.onopen = r));

	const target = await send('Target.createTarget', { url: 'about:blank' });
	const session = (await send('Target.attachToTarget', { targetId: target.targetId, flatten: true })).sessionId;
	await send('Page.enable', {}, session);
	await send('Runtime.enable', {}, session);
	await send('Network.enable', {}, session);
	await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 2, mobile: false }, session);

	for (const c of cookies) {
		const set = await send('Network.setCookie', {
			name: c.name,
			value: c.value,
			url: 'http://localhost/',
			path: '/',
			httpOnly: true,
			secure: false,
		}, session);
		if (!set.success) {
			console.error('setCookie failed: ' + c.name + ' ' + JSON.stringify(set));
		}
	}

	await send('Page.navigate', { url: 'http://localhost' + targetPath }, session);
	await sleep(5000);

	const info = await send('Runtime.evaluate', {
		returnByValue: true,
		expression: `(function(){
			return {
				url: location.href,
				title: document.title,
				isLogin: !!document.getElementById('loginform'),
				h1: (document.querySelector('h1')||{}).textContent || '',
				notices: Array.from(document.querySelectorAll('.notice')).map(function(n){return n.textContent.trim().slice(0,160);}).slice(0,6),
				tables: document.querySelectorAll('table.wp-list-table').length,
				rows: document.querySelectorAll('table.wp-list-table tbody tr').length,
				phpError: /Fatal error|Warning:|Deprecated:|Notice:/i.test(document.body.innerText || '')
			};
		})()`,
	}, session);

	console.log(JSON.stringify(info.result.value, null, 2));

	const shot = await send('Page.captureScreenshot', { format: 'png' }, session);
	fs.writeFileSync(out, Buffer.from(shot.data, 'base64'));
	console.log('saved ' + out);

	ws.close(); proc.kill(); process.exit(0);
})().catch((e) => { console.error(e); process.exit(1); });
