# RSS 订阅类型管理

WordPress 的订阅源（`/feed/`）默认只查 `post` 这一种类型，所以主题注册的自定义类型（例如 Sakurairo 的「说说」/ `shuoshuo`）和页面都不会出现在 RSS 里。这个插件让你在后台决定 Feed 到底输出哪些类型。

## 使用

进入 **设置 → RSS 订阅类型**：

- 「Feed 包含的内容类型」表格列出所有公开可查询的类型（文章、页面、说说等），勾选后保存即可。表格里同时显示每个类型已发布的条数和它的独立订阅地址，可直接复制。
- 「应用范围」默认让分类、标签、作者、日期等归档 Feed 也使用同一套类型设置；关掉后只有站点总 Feed 受影响。
- 「包含页面时排除首页/文章页」默认开启，避免订阅者收到一条指向站点首页的条目。

一个类型都不勾选时，Feed 回到 WordPress 默认行为（仅文章），所以随时可以安全地退回原状。

## 订阅地址

| 地址 | 内容 |
| --- | --- |
| `/feed/` | 总 Feed，输出上面勾选的所有类型，按发布时间排序 |
| `/feed/?post_type=page` | 只输出页面 |
| `/feed/?post_type=shuoshuo` | 只输出「说说」 |
| `/shuoshuo/feed/` | 自定义类型带归档时的专用地址（`has_archive` 为真时可用） |

独立地址与勾选状态无关，任何时候都能用。

## 实现要点

- 入口：`pre_get_posts`（优先级 99）。只处理主查询且 `is_feed()` 为真、非评论 Feed 的请求，把 `post_type` 换成勾选的类型数组。
- 显式指定类型时不干预：从 `$GLOBALS['wp']->query_vars['post_type']`（`parse_request` 的原始结果）判断，因此 `/feed/?post_type=xxx`、`/shuoshuo/feed/` 以及主题在 `pre_get_posts` 里改过的值都不会被误覆盖；优先级 99 保证本插件最后生效。
- 搜索结果 Feed 与评论 Feed 不参与改写，保持 WordPress 与主题原有逻辑。
- 页面排除通过给 Feed 查询追加 `post__not_in`（首页、文章页的 ID）实现。
- 保存设置时清理常见缓存插件的缓存，避免订阅源长时间停留在旧内容。

## 可用的过滤器

```php
// 追加一个类型（例如插件注册的 note）。
add_filter( 'rpt_feed_post_types', function ( $types ) {
	$types[] = 'note';
	return $types;
} );

// 调整后台可勾选的类型列表。
add_filter( 'rpt_available_post_types', function ( $types ) {
	unset( $types['attachment'] );
	return $types;
} );
```

## 说明

- 每个 Feed 的条数由 **设置 → 阅读 → Feed 中显示最近 N 项** 决定。
- 插件不修改主题或核心文件，停用后 Feed 立即回到默认行为；卸载时删除 `rpt_settings` 选项。
