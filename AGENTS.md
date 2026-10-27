# AGENTS.md

## 项目概述

本项目是个人博客，基于 WordPress 系统搭建，采用 Sakurairo 二次元风格主题，插件化拓展功能。

## 测试与验证

- 本机 WSL 中部署了博客的本地 Web 服务，在 WSL 中使用 `systemctl start nginx` 启动 Nginx，访问 <http://localhost/> 即可
- 可以按需使用 `chrome-headless-shell` 截图或者使用 `browser-skill` 操作真实浏览器
- 远程网站部署在 <https://blog.huajimc.cn>

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

## Git

- 提交信息遵循 Conventional Commits