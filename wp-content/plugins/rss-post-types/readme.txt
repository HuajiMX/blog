=== RSS 订阅类型管理 ===
Contributors: huajimc
Tags: rss, feed, custom post type, page, subscription
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

在后台选择哪些内容类型进入站点的 RSS / Atom Feed。

== Description ==

WordPress 的 `/feed/` 默认只输出「文章」（post），页面和主题/插件注册的自定义文章类型都不会出现在订阅里。

本插件在后台「设置 → RSS 订阅类型」提供类型勾选，勾选后：

* 站点总 Feed（`/feed/`）会同时输出所选类型，按发布时间统一排序；
* 可选让分类、标签、作者、日期等归档 Feed 使用同一套设置；
* 每种类型都有独立订阅地址，例如自定义类型（Sakurairo 的「说说」）是 `/shuoshuo/feed/`，页面是 `/feed/?post_type=page`；
* 包含页面时可自动排除用作「首页」「文章页」的页面。

不勾选任何类型时，Feed 行为与 WordPress 默认一致（仅文章）。每条 Feed 的条数仍由「设置 → 阅读」控制。

== Installation ==

1. 将 `rss-post-types` 文件夹上传到 `/wp-content/plugins/`（或直接在后台安装后启用）；
2. 在「插件」页面启用；
3. 打开「设置 → RSS 订阅类型」，勾选需要订阅的内容类型并保存。

== Frequently Asked Questions ==

= 保存后订阅源还是旧内容？ =

插件在保存时会自动尝试清理 WP Super Cache、W3 Total Cache、WP Rocket、LiteSpeed Cache、WP Fastest Cache 的缓存。如果订阅器或 CDN 仍有缓存，请再手动刷新一次。

= 想让「说说」之类的内容只进自己的 Feed？ =

不勾选它，使用它自己的地址（如 `/shuoshuo/feed/`）即可；独立地址始终有效。

= 代码里怎么覆盖？ =

使用 `rpt_feed_post_types` 过滤最终写入 Feed 的类型，`rpt_available_post_types` 过滤后台可选项。

== Changelog ==

= 1.0.0 =

* 首个版本：后台选择 Feed 内容类型、独立类型订阅地址、归档 Feed 开关、首页页面排除、缓存清理。
