# tests/psn/tools

本地验证用的小工具，均不参与插件运行。

| 文件 | 用途 |
| --- | --- |
| `shot.js` | 用 CDP 整页/滚动截图：`node tests/psn/tools/shot.js <url> <out.png> [--width=1440] [--dark] [--mobile] [--scroll=选择器]` |
| `probe.js` | 对某个元素做紧密截图 + 打印计算样式：`node tests/psn/tools/probe.js <url> <选择器> <out.png> [--dark] [--mobile]` |
| `measure.js` | 打印卡片内各元素的盒模型（排查间距）：`node tests/psn/tools/measure.js <url> [--mobile]` |
| `mint-cookie.php` | 生成管理员登录 cookie 到 `tests/psn/_cookies.json`，供后台截图用；不修改任何账号数据 |
| `admin-shot-cookie.js` | 用上面的 cookie 打开后台页面并截图：`node tests/psn/tools/admin-shot-cookie.js "/wp-admin/admin.php?page=psn-settings" <out.png>` |

依赖：本机 `chrome-headless-shell`（Playwright 自带的那份）与 Node 22+（用内置 `WebSocket` 直连 CDP）。

`_cookies.json` 与 `_received.jsonl` 是运行产物，不要提交。
