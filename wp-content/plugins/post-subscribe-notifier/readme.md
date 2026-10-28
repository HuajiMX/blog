# Post Subscribe Notifier

一个插件、两个模块：

1. **新文章邮件订阅**：访客留邮箱 → 验证邮件 → 确认订阅 → 新文章发布时按队列分批推送，带一键退订。
2. **评论 WebHook 推送**：新评论（含段评、「说说」下的评论、待审转通过）产生时，把评论、评论人、被评论对象、文章等信息以 JSON POST 推给外部地址，供 QQ 机器人端消费。

评论邮箱提醒仍由 Sakurairo 主题内置实现负责；本插件只管 WebHook。

## 前台

- **首页底部订阅卡片**：只在首页第一页输出（`is_home() && !is_paged()`），挂在 `get_footer`，因此落在主题 PJAX 容器 `#page` 内，PJAX 导航回首页时会被一起重建。
- **短代码 `[psn_subscribe]`**：放在任意页面，可用 `title` / `desc` 覆盖文案：
  ```
  [psn_subscribe title="订阅更新" desc="留下邮箱，新文章发布时通知你。"]
  ```
- 表单是**纯服务端 POST → 302 回跳**，不依赖 JS、不依赖 nonce，所以页面被 W3TC / CDN 缓存、或主题 PJAX 只重放 `#app-js-before` 脚本时都不会失效。结果通过 `?psn_status=xxx` 白名单状态回显，不反射任何用户输入。

### 订阅状态机

```
（提交）→ pending ──确认链接──→ active ──退订──→ unsubscribed
             │                     │
             └── 48h 未确认 ────────┴── 连续 3 次发信失败 → bounced
```

- 邮箱统一转小写后存；库里只保存 token 的 SHA-256，明文 token 只出现在邮件里。
- 确认链接一次性：确认成功后清空 `confirm_hash`，重复点击提示「链接无效或已被使用」。
- 退订 token 由 `id + 邮箱 + 站点 salt` 派生，因此每封推送邮件里的退订链接都稳定可用；`unsub_hash` 置空即可让旧链接失效。
- 防护：蜜罐字段 + 同 IP 10 分钟最多 3 次 + 同邮箱 1 分钟不重复发信 + 双重确认。

### 邮件

- 走站点现有的 `wp_mail`（本机为 WP Mail SMTP → smtp.126.com）。若 WP Mail SMTP 勾了「强制发件人」，以它为准。
- 推送邮件带 `List-Unsubscribe` 与 `List-Unsubscribe-Post: List-Unsubscribe=One-Click`。
- 邮件正文使用行内样式；主色取自主题的 `theme_skin_matching`，并**按 WCAG 计算对比度**（`PSN_Color`），必要时把文字换成深色或把背景往黑/白推，保证至少 4.5:1。主题色常常是很浅的蓝（本机 `#a4cdf6`），直接配白字只有 1.7:1。

## 后台

顶级菜单「订阅推送」，下分三页：

| 页面 | 能做什么 |
| --- | --- |
| 设置 | 模块开关、内容类型、发件人/主题模板、批量与限速、每日上限、确认链接有效期、WebHook 地址/密钥/附加头/超时/重试、摘要模板、两个测试按钮 |
| 订阅者 | 按状态筛选、搜索邮箱/昵称、单条与批量删除、CSV 导出（UTF-8 BOM，`=+-@` 开头加 `'` 防公式注入） |
| 推送日志 | 按类型/状态筛选、查看 WebHook 载荷、手动重发 |

设置页顶部会显示「待发邮件 / 待推 WebHook / 今日已发」计数。

## WebHook 契约

请求头：

```
Content-Type: application/json; charset=utf-8
User-Agent: PostSubscribeNotifier/1.0.0; <站点地址>
X-PSN-Event: comment.new | comment.approved | webhook.test
X-PSN-Delivery: <任务 ID>
X-PSN-Signature: sha256=<hmac_sha256(密钥, 原始请求体)>   # 仅在设置了密钥时
```

正文（字段稳定，可直接按这些键取值）：

```json
{
  "event": "comment.approved",
  "delivery_id": 128,
  "sent_at": "2026-10-29T01:57:59+08:00",
  "site": { "name": "站点名", "url": "https://example.com/", "version": "1.0.0" },
  "comment": {
    "id": 136,
    "type": "comment",
    "url": "https://example.com/post/#comment-136",
    "content": "原始 HTML/文本（最多 2000 字符）",
    "content_text": "纯文本：去标签、去表情语法、压缩空白",
    "content_excerpt": "纯文本前 120 字",
    "approved": "1",
    "is_private": false,
    "is_paragraph": false,
    "paragraph_index": null,
    "created_at": "2026-10-28 17:57:53",
    "created_at_iso": "2026-10-29T01:57:53+08:00",
    "author": {
      "id": 0, "name": "评论人", "email": "a@b.c", "url": "",
      "qq": "10001", "avatar": "https://...", "ip_location": ""
    },
    "parent": {
      "id": 29,
      "author": { "id": 0, "name": "被回复者", "email": "...", "url": "", "qq": "", "avatar": "...", "ip_location": "" },
      "content_excerpt": "被回复的原文…"
    },
    "post": {
      "id": 44, "type": "post", "title": "标题", "url": "...",
      "author": { "id": 1, "name": "作者", "email": "...", "qq": "" }
    }
  },
  "targets": ["post_author", "parent_author"],
  "summary": "【新评论】评论人 评论了《标题》：内容摘要 … https://..."
}
```

- `parent` 在非回复时为 `null`；`targets` 列出应被提醒的角色，机器人端自行决定 @ 谁。
- `qq` 来自评论 meta `new_field_qq`（主题的 QQ 输入框）或用户 meta `qq`，没有就是空串。
- `ip_location` 只读取已缓存的 `iro_ip_location`，不会触发外部查询。
- `summary` 是单行纯文本，由设置里的模板渲染（`{author}` `{post_title}` `{post_url}` `{comment_excerpt}` `{parent_author}` `{comment_url}` `{site_name}`）。

机器人端最小处理示例（伪代码）：

```
on POST /hook:
    verify hmac(body, X-PSN-Signature, secret)
    e = json
    send_to_group(e["summary"])
    if e["comment"]["parent"]: mention(e["comment"]["parent"]["author"]["qq"])
```

## 队列与定时任务

- 发布文章时只**入队**，不在请求里发信；`wp-cron` 每 1 分钟跑 `psn_tick`，先推 WebHook（每条评论 1 个任务），再发邮件（默认每轮 20 封、间隔 300 ms、每日上限 300）。
- 新任务入队后会再排一个 5 秒后的单次事件，尽快发出，不必等到下一分钟。
- 并发安全：抢占用「条件 UPDATE + 影响行数校验」，多进程同时跑 cron 只有一个能拿到任务；`(type, ref, target)` 唯一键保证同文/同评论不会重复入队。
- 重试：超时、连接失败、5xx、408、425、429 会退避重试（60s → 300s → 900s，带抖动，尊重 `Retry-After`）；其他 4xx 直接失败，可在后台手动重发。
- 邮件连续失败 3 次会把订阅者标为 `bounced` 并停止发送；文章从「发布」撤回时，未发出的任务整批取消。

> **生产部署建议**：`wp-cron` 依赖访问流量。站点流量低时请配系统 cron 定时访问 `wp-cron.php`（例如每 5 分钟一次），否则队列会积压。

## 数据表

`{prefix}psn_subscribers`：订阅者（email / name / status / confirm_hash / unsub_hash / token_expires / source / ip_hash / fail_streak / 各时间戳）。

`{prefix}psn_jobs`：任务队列与日志（type / ref / target / status / payload / attempts / available_at / locked_until / response_code / last_error / 时间戳）。

- `ip_hash` 是访客 IP 的 SHA-1（带站点 salt），不留明文 IP；IP 取值走全站唯一入口 `iro_get_client_ip()`（`wp-content/mu-plugins/iro-real-ip.php`）。
- 默认卸载**保留**数据；在设置里勾「卸载插件时删除全部数据」后卸载才会删表。

## 过滤器

```php
// 只在某些文章上推送。
add_filter( 'psn_should_notify_post', function ( $should, $post, $old_status ) {
    return $should && ! has_tag( 'no-mail', $post );
}, 10, 3 );

// 追加可订阅的内容类型（默认 post，可勾 shuoshuo）。
add_filter( 'psn_notify_post_types', function ( $types ) {
    $types[] = 'note';
    return $types;
} );

// 每条评论都推（覆盖后台开关）。
add_filter( 'psn_should_notify_comment', function ( $notify, $comment, $event ) {
    return true;
}, 10, 3 );

// 改写载荷 / 摘要 / 按钮配色 / 邮件主色。
add_filter( 'psn_webhook_payload', fn( $p, $c ) => $p, 10, 2 );
add_filter( 'psn_webhook_summary', fn( $line, $p ) => $line, 10, 2 );
add_filter( 'psn_button_palette', fn( $palette, $accent ) => $palette, 10, 2 );
add_filter( 'psn_mail_accent', fn( $color ) => $color );
```

## 测试

```bash
# 订阅 / 发布推送 / 评论 WebHook 的集成测试（拦截 wp_mail 与 wp_remote_post，不发真实邮件）
php tests/psn/run.php

# 后台页面渲染、设置清洗、CSV 转义、配色对比度
php tests/psn/admin-smoke.php
```

两个脚本都会自建自清测试数据，退出码 0 表示全绿。

手工端到端验证 WebHook：

```bash
# 起一个本地接收器（会把请求写进 tests/psn/_received.jsonl）
PSN_SINK_SECRET=你的密钥 php -S 127.0.0.1:8899 tests/psn/sink.php
# 后台把 WebHook 地址设为 http://127.0.0.1:8899/、密钥设为同一个值，然后点「发送测试 WebHook」
```

## 实现提示

- `wp_insert_comment()` **不触发** `comment_post`，而段评插件直接调用它。所以评论入队挂在 `wp_insert_comment`（优先级 20，晚于主题写 QQ meta 的 10）与 `transition_comment_status`（用来补推「待审 → 通过」）。
- 段评的 `paragraph_index`、主题的 `new_field_qq` 都在评论入库**之后**才写入 meta，因此载荷要到**发送时**才构建。
- 后台删除/导出/重发都走 `admin_init`（`page` 精确匹配）或 `admin-post.php`，在页头输出前处理，302 与文件下载才发得出头。
