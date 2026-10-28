# AGENTS.md

## 项目概述

本项目是个人博客，基于 WordPress 系统搭建，采用 Sakurairo 二次元风格主题，插件化拓展功能。

## 测试与验证

- 本机 WSL 中部署了博客的本地 Web 服务，在 WSL 中使用 `systemctl start nginx` 启动 Nginx，访问 <http://localhost/> 即可（站点根目录和 MySQL 均在本机，`php8.4-fpm` + `mysql` 需处于运行状态）
- 可以按需使用 `chrome-headless-shell` 截图或者使用 `browser-skill` 操作真实浏览器
- 远程网站部署在 <https://blog.huajimc.cn>，**经 DNS 解析到 Clash fake-ip（198.18.0.x），直连必然 SSL 超时**；命令行访问需走 `http_proxy=http://127.0.0.1:7897`，浏览器/截图工具需允许系统代理

### 本地环境的特有配置

以下几项都是本地测试环境与线上部署的差异，改动代码前先确认，否则会误判为代码 bug：

- **`css/theme.css` 重写**：主题样式地址是 `/wp-content/themes/Sakurairo/css/theme.css`，由 `css/index.php` 动态生成。线上由又拍云/CDN 侧做了重写，本地必须在 `/etc/nginx/sites-available/default` 中保留等价规则，否则整站无 CSS：
  ```nginx
  location = /wp-content/themes/Sakurairo/css/theme.css {
      rewrite ^ /wp-content/themes/Sakurairo/css/index.php last;
  }
  ```
- **opcache 关闭时间戳校验**：项目位于 `/mnt/e`（9p 挂载），每次 stat 约 1ms，而单次请求要 include 1100+ 个 PHP 文件。已在 `/etc/php/8.4/fpm/conf.d/99-local-dev.ini` 中设置 `opcache.validate_timestamps=0`、`opcache.revalidate_freq=0`、`opcache.enable_file_override=1`，把首页 TTFB 从 5–14s 降到约 0.5s。**代价是改了 PHP 源码后 opcache 不会自动失效，改完必须 `systemctl restart php8.4-fpm`。**
- **本地已停用 `wpforms-lite`**：该插件零表单、零短代码引用，但前台引导会额外加载 409 个文件，是本地延迟的主要来源。`active_plugins` 中已移除；如需在本地测试表单请自行启用。同时 `wp-paragraph-comments` 的目录名已更正为 `wp-paragraph-comments-main`。
- **本地 SMTP 发不出信**：`wp_mail` 走 WP Mail SMTP → `smtp.126.com:465`，但在 WSL 里实测 `wp_mail()` 返回 false（`Could not connect to SMTP host`），这是本地网络/认证限制，不是代码 bug。验证与邮件相关的逻辑请用 `pre_wp_mail` 拦截（`tests/psn/run.php` 就是这么做的），不要靠「真发一封」来判断成败。
- **本地管理员密码未知**：不要为一个截图去改账号密码。需要后台截图时用 `php tests/psn/tools/mint-cookie.php` 生成登录 cookie，再用 `tests/psn/tools/admin-shot-cookie.js` 打开页面——它只创建 session token，不动账号数据。

### 本地已知的非故障现象

- **首页背景图不显示**：`iro_options` 使用外部随机图 API（`api.fuukei.org` → `imgres.foxwaf.com`），该图床当前返回 404；本地 `wp-content/uploads/iro-image-pool/` 各分类的 `index.json` 里 `items` 为空，只会回退到这个外部接口。这是第三方图源问题，不影响本地 UI 验证。
- **页面中文乱码**：本地库数据本身是以错误编码导入的（`wp_options.blogname`、`wp_posts.post_title` 中存储的字节即为坏值），与渲染无关，本地测试不修。
- **首次请求慢**：FPM 重启后的第一个请求需要重新编译并填充 opcache，约 5s，之后稳定在 0.5s。自动化脚本的超时要按首请求留足余量。

## 注意事项

- 我们不会主动更新 Sakurairo 主题与第三方插件，因此开发中若设计主题或插件，不需要顾及以后可能的更新导致的代码覆盖，按最简洁、最匹配、最可维护、开销最小的方法开发即可
- UI 开发需要与整站的风格匹配：二次元个人博客风、Glassmorphism、Card-based UI、ACG (Anime, Comic, Games) Aesthetic、Soft & Rounded；但“匹配”不等于堆叠主题色和装饰，具体按下方「UI 设计判断」区分组件类型
- 博客文章基本上都使用 Markdown 编写，wp-githuber-md 插件提供文章撰写的 MD 编辑器，katex 插件提供数学公式渲染（数学公式架构见下方「数学公式渲染约定」）
- 当你发现本文档中的约定有变更、不足、过时或需要纠正时，在任务完成后主动将 `AGENTS.md` 修正建议提请我审阅

## 数学公式渲染约定

- 语法规则表只有一份：`wp-content/plugins/katex/scripts/grammar.php` 的 `katex_math_rules()`。新增数学定界符（如 `\(x\)` / `\[x\]`）只需往该表加一条规则（PHP 与 JS 通用的正则子集），保存、前台、编辑器预览三处即同时生效，无需改其他文件
- 数据契约：文章正文（`post_content`）以 HTML 存储，公式是 `<span|div class="katex-eq" data-katex-display="true|false" data-katex-tex="TeX源码">` 元素；独占段落的展示公式用 `<div>`，行内及句中公式用 `<span>`。TeX 源码同时放属性与文本（换行写成 `&#10;`），保证 RSS/摘要/无 JS 时可读
- `wp-githuber-md` 不再自带 KaTeX（原 `Modules/KaTeX.php` 已停用），保存时经 katex 插件的 `katex_markdown_protect()` 保护公式不受 Parsedown 破坏；前台由 katex 插件 `the_content` 优先级 9 的过滤器和 `assets/render.js` 渲染，全站统一 KaTeX 0.16.22
- Sakurairo 主题的 MathJax 已固定关闭（`inc/swicher.php` 中 `theme_mathjax => false`），避免与 KaTeX 双重渲染；PJAX 场景由 katex 插件的 `__iroKatexLoader` 桥按需补载资源
- 共享测试用例：`tests/math-cases.json`，用 `php tests/run-php.php` 与 `node tests/run-js.js` 双端运行，改动语法规则后必须两边全绿

## 访客 IP 解析约定

- **站点在又拍云 CDN 之后，但源站 nginx 没有 `ngx_http_realip_module` 规则**（`/www/server/panel/vhost/nginx/blog.huajimc.cn.conf` 里没有 `set_real_ip_from` / `real_ip_header`）。因此 PHP 看到的 `$_SERVER['REMOTE_ADDR']` 是**回源节点地址**（实测固定为 `36.150.185.23`–`36.150.185.27`，江苏南京移动），不是访客地址。
- 又拍云回源时会用真实访客 IP **覆写** `X-Real-IP`、`Client-IP`，并写入标记头 `X-Via: shanks/<版本>`；但 `X-Forwarded-For` 在部分回源模式下会**原样透传客户端自带值**，可以伪造，不能单独当作真实 IP。
- **唯一取值入口是 `wp-content/mu-plugins/iro-real-ip.php`**（解析逻辑在同目录 `iro-real-ip/resolver.php`）。它在请求早期把 `REMOTE_ADDR` 回填为真实访客 IP，并接管 `pre_comment_user_ip` / `wpb_get_ip`。取值优先级为 `X-Real-IP` → `Client-IP` → `X-Forwarded-For` → `REMOTE_ADDR`；新代码需要 IP 时调用 `iro_get_client_ip()`，**不要**再自己解析转发头。
- 主题 `get_the_user_ip()`（`functions.php`）已改为复用该入口，只为兼容旧调用点保留。
- **源站可被绕过 CDN 直连**（`47.117.246.218:443` 直接可达，实测带伪造头也能打进来）。因此转发头只在「确认请求来自边缘节点」时才采信：配置了常量 `IRO_REAL_IP_TRUSTED_CIDRS` 时校验回源网段；未配置时要求 `X-Via` 标记存在且 `X-Real-IP` 与 `Client-IP` 一致。**注意这只是软防护**——要真正杜绝伪造，需在 nginx 侧只放行 CDN 回源段并删掉直连入口；改完这条之前不要在 IP 上做封禁、限流或风控决策。
- 历史数据：本次修复前的评论 `comment_author_IP` 全部是节点地址（`36.150.185.*`），无法事后还原真实 IP；`wp_commentmeta` 里的 `iro_ip_location` 也是基于节点 IP 解析出来的「江苏 南京」，需要在修复后按需重建。
- **修复只作用于 PHP 层**：`/www/wwwlogs/blog.huajimc.cn.log` 里的 `$remote_addr` 记录的仍是回源节点 IP（nginx 在 PHP 之前就写完了日志）。排查访客来源要看 `$http_x_real_ip`，或者用下面那条临时探针；不要拿访问日志首列当真实访客 IP。
- 回归测试：`php tests/run-ip.php`（纯函数，覆盖取值优先级、伪造拒绝、CIDR 匹配）与 `php tests/run-ip-wp.php`（走 WordPress 真实过滤器链，用 `IRO_EXPECT_IP` 指定期望值）。

### IP 归属（评论「来自」）的数据源与失败处理

- 展示数据存在 `wp_commentmeta.iro_ip_location`（序列化数组），首次展示时才查、查到即缓存；`save_location` 与 `comment_location` 均为 `1`。
- 取源主用 **ipwho.is**（HTTPS、支持中文与 IPv6、实测源站 20/20 成功约 0.7s），兜底 ip-api.com（HTTP，实测约 40% 超时，超时压到 1.5s）。改数据源只需改 `IpLocation::getIpLocationFromIpWhois()` / `getIpLocationByIpApi()`，两者都要归一化成 `country/region/city`。
- **不要把 `trigger_error(E_USER_WARNING)` 用在面向访客的代码路径上**：线上 php.ini 是 `display_errors=On`，而 `wp-config.php` 的 `WP_DEBUG=false` 使 WordPress 不执行 `ini_set('display_errors', 0)`，警告会被直接印在页面上（本项目已因此泄漏过 `IpLocation.php` 的 cURL 超时警告）。改用 `error_log()`。
- 失败要区分「取源连不上」与「该 IP 查不到」：只有前者才开 10 分钟负缓存（`ip_location_outage`），否则一个查不到的 IP 会让全站位置查询停摆。状态常量见 `IpLocation::LOOKUP_*`，回归测试 `php tests/run-geo.php`（用假 HTTP 层，不联网）。

## 资源缓存失效与 PJAX 注意

- 可变的前端资源（`katex-core.js`、`render.js` 等会被重构的文件）在输出 URL 时必须带上按文件修改时间生成的版本参数（`filemtime`），而不是固定的插件版本号常量。磁盘文件更新后若 `?ver` 不变，nginx/边缘缓存会对同一 URL 持续返回旧字节，导致线上加载到旧脚本；主题 `sakura_scripts` 的灯箱桥与 katex 插件的 `katex_asset_version()`（`scripts/resource.php`）均已按此处理，新增类似资源沿用同一做法。
- 不可变的 vendor 文件（`katex.min.js/css`）可继续用固定静态版本，无需 filemtime。
- 主题整站样式 `css/theme.css` 由 `css/index.php` 动态拼接，本身不能带 `filemtime`，改由 `functions.php` 的 `sakura_scripts` 把拼接源文件（含 `css/content-style/*.css`）的**最大** `filemtime` 作为 `cb=` 参数加入 URL，且该 URL 带 `Cache-Control: max-age=86400`。因此改完主题 CSS 无需手动清缓存或等过期，`cb` 会自动变化。**注意仅在 `dev_mode` 关闭时走这条路径**；开启后改走逐文件 `wp_enqueue_style`，其中只有 `style.css` 与 `css/content-style/*.css` 用 `filemtime`，其余（`shortcodes`/`dark`/`responsive`/`animation`/`templates`）仍是固定 `IRO_VERSION`，改这些文件在 `dev_mode` 下不会换 URL，需自行加 `?ver` 或临时关闭 `dev_mode`。
- 改完 `render.js` / `katex-core.js` 后需在线上验证「从首页经 PJAX 进入文章页」场景能正常渲染公式，而不只是整页刷新：

  - Sakurairo 的 PJAX（`9308.js`）只替换 `#page` / `title` / `.footer-content` / `#app-js-before`，并把这些容器里的内联脚本重新执行；**新页面 footer 的脚本不会执行**。因此依赖 `DOMContentLoaded` 单次渲染的脚本在 PJAX 导航后不会重跑（该事件在首页就早已触发）。调试时若开启 `dev_mode`，选择器列表还会追加 `#entry-content-css`（源码中为 `1==_iro.dev_mode&&selectors.push("#entry-content-css")`），排查时不要把它当成常规容器。
  - 需要跨 PJAX 生效的逻辑要放进每次导航都会重新执行的 `#app-js-before` 内联脚本（如 `__iroKatexLoader`、主题的 `__iroLbLoader`），或监听主题派发的 `pjax:complete`（CustomEvent `pjax:send`/`pjax:complete`，首页与文章页都会触发）。
  - 判断补载是否完成以「真正能触发渲染」为信号（如 render.js 暴露的 `iroKatexRender`），不能只看 `katex` 全局是否存在——半加载状态下 `katex` 可能已定义而核心/渲染脚本缺失，会导致 PJAX 下公式停留在原始 TeX。
  - 本地站几乎只有纯文本文章，无法复现含公式文章的真实 PJAX；需用 `chrome-headless-shell` 对线上含公式文章做真实点击导航来验证。

## UI 设计判断

### 组件类型

- 品牌/氛围表面：首页 hero、导航、封面卡片等。可以使用主题色、玻璃拟态、圆角、光晕和柔和动效。
- 内容表面：正文、图片、截图、代码块、表格等。以还原内容为主，不使用裁切性圆角、覆盖式滤镜、彩色光晕或装饰阴影。
- 工具表面：灯箱、搜索、播放器、弹窗、确认框等。默认使用中性色与低干扰布局，主题色只用于必要的 focus/active 等状态。

### 视觉原则

- 一致性首先来自字体、间距、圆角策略、图标线宽和动效节奏，而不是把主题色应用到所有元素。
- 主题色来自封面动态提取，可能随文章变化；不要让它成为工具组件的固定依赖。
- 每个装饰效果都应能说明它帮助用户完成什么任务；没有明确作用的阴影、光晕、渐变、模糊应删除。
- 内容媒体保持直角、完整显示，不用 `border-radius` 裁掉截图或图片角落。
- 工具组件应快、轻、清晰：短过渡、明确状态、足够对比度，避免与内容争夺注意力。

### 实现与验证

- 新组件必须检查并覆盖主题全局 `button`、`img`、`a` 等基础样式，避免主题阴影、圆角或背景泄漏。
- 工具组件需处理默认、hover、focus-visible、active、loading、error、disabled 状态，并保证 `Esc`、`Tab`、方向键等键盘操作可用。
- UI 改动至少用本地站点截图验证深色/浅色模式与移动端；涉及布局或交互时，再检查计算样式或实际操作真实浏览器。

## 排查与验证方法

这一节的规则都来自真实踩坑：一次 IP 修复里，一半时间浪费在可控性没固定、探针反复重建、以及追查自己制造的假象上。

### 先固定变量，再测量

- **涉及网络的对比实验，第一步固定出口 IP 与路径**。本机同时存在「直连」与「Clash 代理」两条路径（且代理按规则分流：国内目标直连、境外目标走节点），另有运营商侧出口池会自行轮换。未固定出口时，两次测量结果不可比，据此得出的结论会误导后续排查。
- 记录测量前提：每次给出「A 比 B 快」「X 返回 Y」的结论时，同时写明当时的出口/路径/时间，否则该结论不可复现，也就不该作为证据。
- 同理，改动前后对比要保证**只变了一个变量**；同时改代码和换数据源时，失败原因无法归因。

### 探针：一个脚本，多个场景

- 需要临时诊断时，写**一个**接受参数（`?case=xxx`，或读环境变量）的脚本，放在项目根目录、用完立即删除；不要为每个场景新建一个文件。本项目实践中，9 个一次性探针中有大半可以合并，每次上传/请求/删除都是一轮完整的 SSH 往返开销。
- 探针必须显式输出判定依据（当前的 `REMOTE_ADDR`、请求头、实际命中的配置值），而不是只输出结论。否则结果异常时仍需再加一个探针。
- 上线验证脚本会通过公网暴露，**用完必须删除并复查**（`ls` 确认不存在），不要留在站点根目录。

### 结论明确就停止

- 一个实验足以区分假设后立即收手。重复同一实验只增加耗时，不增加证据。
- 当测得的结果互相矛盾时，正确反应是**回到「前提是否可控」**，而不是继续加测。本例中「直连源站却看到不同的 `REMOTE_ADDR`」反复出现多轮，真相是代理出口在轮换——一旦固定出口，矛盾立刻消失。

### 本地与线上各验什么

- **只在确实存在差异时才两边都跑**：PHP 业务逻辑改动验证线上即可；涉及本地特有配置（`theme.css` 重写、`opcache`、停用的插件、编码坏值数据）的问题才必须在本地复现。
- 本地改完 PHP 需 `systemctl restart php8.4-fpm`（opcache 不校验时间戳），线上改完需确认文件确实更新（`git pull` 后再 `php -l`）。
- 涉及「访客看到什么」的验证（警告泄漏、渲染结果），必须用真实 HTTP 请求取页面并检索标记，不要只看数据库或日志字段。

### 区分「代码 bug」与「环境/第三方现象」

- 测到异常先问：这个值是否来自本项目代码？本例中「评论 IP 都一样」看着像主题 bug，实际是 CDN 未配置 realip；「归属到孝感」看着像代码 bug，实际是旧数据源的历史缓存。
- 定位后仍要说明**哪一层**的问题（应用 / 反向代理 / CDN / 运营商 / 数据源），否则后续修复会打错层。

### 汇报纪律

- 报告中的每个数字都要能复现；若某个数字来自会漂移的测量（出口 IP、随机图源、限流响应），必须标注「会变化」或改用可重复的判据（如「落在节点段之外」而非某个具体 IP）。
- 不要把动态测量的结果写成固定结论。

## 规划与实施节奏

这一节来自一次 2 小时 36 分、约 395 步的会话复盘（新文章订阅 + 评论 WebHook 插件）：其中勘察与写方案占 63 分钟 / 70 步，实施与验证占 93 分钟 / 325 步。真正不可省的勘察（见下）约 20 分钟，其余是流程问题。

### 规划：先发问题，再勘察

- **产品决策类问题在第一次回复就发出**（插件形态、入口位置、发信策略、载荷格式、触发范围、后台范围）。这类问题与代码勘察互不依赖，让用户思考的时间正好覆盖自己读代码的时间。本次是勘察十几分钟后才问，等于把两段串行相加。
- **勘察设 15 分钟预算**，超时先写一版粗计划，边等回答边补细节，不追求一次写成完美方案。
- 只有「猜错会导致设计重做」的事实才值得花时间确认。本次真正关键的是四条：`wp_insert_comment()` 不触发 `comment_post`、段评在入库后才写 meta、主题 PJAX 只替换 `#page`/`title`/`.footer-content`/`#app-js-before`、本地 `wp_mail` 不可达。其余（SMTP 参数、表结构、版本号）属于「顺手记下」，不必逐个单跑。

### 勘察：一个脚本，一次跑完

- 「探针：一个脚本，多个场景」同样适用于**PHP 探针**。需要一堆运行时事实时，写**一个** dump 脚本一次输出全部所需值，不要用十几条 `php -r` 单行命令——加载 WordPress 是成本大头，每条单行命令都付一遍。本次探针二十余次，多数可以合并成一次。
- 读文件前先 `grep -n` 定位行号，只读需要的区间；同一个文件不要先整段读、再回头补第二次。本次 `footer.php`、`header.php` 各读了两次，`functions.php` 分了四段。

### 实施：每写完一个模块立刻冒烟

本次五个自造 bug（CSS 首行误带 `<?php`、静态方法里用 `$this->`、正则定界符冲突、nonce 动作名不匹配、后台动作在渲染期发不出头）都是「先写完整个插件、最后才端到端验证」放大的：其中后三个本身写错，与顺序无关；但五者的**发现成本**都被拖成一轮「改代码 → lint → 重启 php-fpm → 等首请求 → 重新探测」。按模块冒烟能把它们压缩到几十秒内暴露。

写完即验的最小清单：

| 写完什么 | 立刻做什么 |
| --- | --- |
| 任何 `.php` | `php -l`，再用 CLI 直调一次核心方法（不走浏览器） |
| 前台入口（表单/短代码） | `curl -X POST` 一次，看 302 与 `Location`，而不是只渲染页面 |
| 静态资源（`.css`/`.js`） | `curl -D-` 看 `Content-Type` 与首字节，别让 `<?php` 之类的头混进纯静态文件 |
| 后台动作（删除/导出/重发） | 真点一次，检查 302/文件头；渲染期处理动作一定发不出头 |
| 任意 UI 组件 | 取计算样式（颜色、圆角、`margin`），不要只靠截图肉眼判断 |

### 几个「静态检查即可发现」的自造 bug

- **静态方法里出现 `$this->`**：`add_action(..., 'Class::method')` 注册的必然是静态上下文，直接 500。改完把文件里 `$this->` 全部 `grep` 一遍。
- **正则定界符与内容冲突**：`preg_match('#^#[0-9a-f]{3,8}$#', ...)` 会因为内容里的 `#` 提前截断，报 `Unknown modifier`。含 `#` 的内容一律用 `/.../` 或转义。
- **nonce 动作名前后不一致**：`wp_nonce_url(..., 'A')` 与 `check_admin_referer('B')` 写成两个名字时，只表现为「链接已过期」的 403，很难一眼看出。**把动作名提成常量，生成与校验共用同一个**。
- **后台动作的时机**：需要 302 或文件下载的动作必须早于后台页头输出（`admin_init` 里按 `page` 精确匹配，或走 `admin-post.php`），不能写在页面渲染回调里。
- **`WP_List_Table` 自带的 `action` 下拉会覆盖表单里同名的隐藏字段**，所以批量操作不能靠隐藏字段派发，要走 WP 标准的 `action=delete` + `id[]` + `bulk-{复数}` nonce。
- **CSS 特异性**：`.psn-card button.psn-submit`（0,2,1）会压过 `.psn-form .psn-submit`（0,2,0）。改间距无效时先数特异性，不要反复改数值。

## Git

- 提交信息遵循 Conventional Commits