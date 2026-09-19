<?php
/**
 * 极简双语支持：后台界面按当前语言返回中文或英文文案。
 * Tiny bilingual helper: returns Chinese or English strings based on the current locale.
 *
 * @package RSS_Post_Types
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 全部界面文案。
 *
 * @return array
 */
function rpt_strings() {
	static $strings = null;

	if ( null !== $strings ) {
		return $strings;
	}

	$strings = array(
		'menu_title'          => array(
			'zh' => 'RSS 订阅类型',
			'en' => 'RSS Subscription Types',
		),
		'page_title'          => array(
			'zh' => 'RSS 订阅类型',
			'en' => 'RSS Subscription Types',
		),
		'intro'               => array(
			'zh' => 'WordPress 默认只在 Feed 中输出「文章」。勾选下面的内容类型后，页面、主题或插件注册的自定义类型（例如「说说」）也会一起进入订阅。',
			'en' => 'WordPress only outputs "Posts" in feeds by default. Tick a content type below to also include pages and custom post types registered by your theme or plugins.',
		),
		'section_status'      => array(
			'zh' => '当前状态',
			'en' => 'Current status',
		),
		'site_feed'           => array(
			'zh' => '站点总 Feed',
			'en' => 'Site-wide feed',
		),
		'current_types'       => array(
			'zh' => '总 Feed 正在输出的类型',
			'en' => 'Content types currently in the site-wide feed',
		),
		'none_selected'       => array(
			'zh' => '未勾选任何类型，Feed 使用 WordPress 默认行为（仅文章）',
			'en' => 'Nothing selected, so the feed falls back to the WordPress default (posts only)',
		),
		'section_types'       => array(
			'zh' => 'Feed 包含的内容类型',
			'en' => 'Content types in the feed',
		),
		'col_type'            => array(
			'zh' => '内容类型',
			'en' => 'Content type',
		),
		'col_slug'            => array(
			'zh' => '标识 (slug)',
			'en' => 'Slug',
		),
		'col_count'           => array(
			'zh' => '已发布',
			'en' => 'Published',
		),
		'col_feed'            => array(
			'zh' => '该类型的独立订阅地址',
			'en' => 'Standalone feed URL',
		),
		'col_actions'         => array(
			'zh' => '操作',
			'en' => 'Actions',
		),
		'preview'             => array(
			'zh' => '预览',
			'en' => 'Preview',
		),
		'copy'                => array(
			'zh' => '复制',
			'en' => 'Copy',
		),
		'copied'              => array(
			'zh' => '已复制',
			'en' => 'Copied',
		),
		'builtin'             => array(
			'zh' => '内置',
			'en' => 'Built-in',
		),
		'custom'              => array(
			'zh' => '自定义',
			'en' => 'Custom',
		),
		'no_public_types'     => array(
			'zh' => '当前没有可用的公开内容类型。',
			'en' => 'No public content types available.',
		),
		'feed_hint'           => array(
			'zh' => '每种类型都有独立的订阅地址，即使没有在上方勾选，订阅器也可以直接使用。没有「自定义链接」结构的站点会自动使用 ? 参数形式。',
			'en' => 'Every content type has its own feed URL. These work even when the type is not ticked above.',
		),
		'section_options'     => array(
			'zh' => '其它选项',
			'en' => 'Other options',
		),
		'opt_archives'        => array(
			'zh' => '分类、标签、作者、日期等归档 Feed 也使用同样的类型设置',
			'en' => 'Apply the same types to category, tag, author and date archive feeds',
		),
		'opt_archives_desc'   => array(
			'zh' => '关闭后，只有站点总 Feed（/feed/）包含上面勾选的类型，各类归档 Feed 仍只输出文章。',
			'en' => 'When disabled, only the site-wide feed (/feed/) includes the selected types. Archive feeds keep outputting posts only.',
		),
		'opt_skip_front'      => array(
			'zh' => '包含页面时，排除用作「首页」和「文章页」的页面',
			'en' => 'When pages are included, exclude the pages used as front page and posts page',
		),
		'opt_skip_front_desc' => array(
			'zh' => '避免订阅者收到一条指向站点首页的条目。',
			'en' => 'Keeps subscribers from receiving an item that links to the site home page.',
		),
		'count_hint'          => array(
			'zh' => '每个 Feed 输出的条数由「设置 → 阅读 → Feed 中显示最近 %d 项」控制。',
			'en' => 'The number of items per feed is controlled by Settings → Reading → "Syndication feeds show the most recent %d items".',
		),
		'cache_hint'          => array(
			'zh' => '保存时插件会自动尝试清理缓存。如果订阅器里仍是旧内容，请再手动清空一次缓存插件的缓存。',
			'en' => 'The plugin tries to purge caches on save. If subscribers still see old content, purge your cache plugin manually.',
		),
		'settings_link'       => array(
			'zh' => '设置',
			'en' => 'Settings',
		),
	);

	return $strings;
}

/**
 * 当前界面语言：'zh' 或 'en'。
 *
 * @return string
 */
function rpt_current_lang() {
	static $lang = null;

	if ( null !== $lang ) {
		return $lang;
	}

	$locale = '';
	if ( function_exists( 'get_user_locale' ) && is_admin() ) {
		$locale = (string) get_user_locale();
	} elseif ( function_exists( 'get_locale' ) ) {
		$locale = (string) get_locale();
	}

	$lang = ( 0 === strpos( $locale, 'zh' ) ) ? 'zh' : 'en';

	return $lang;
}

/**
 * 取一条界面文案。
 *
 * @param string $key 文案键名.
 * @return string
 */
function rpt_t( $key ) {
	$strings = rpt_strings();
	$lang    = rpt_current_lang();

	if ( isset( $strings[ $key ][ $lang ] ) ) {
		return $strings[ $key ][ $lang ];
	}

	if ( isset( $strings[ $key ]['en'] ) ) {
		return $strings[ $key ]['en'];
	}

	return $key;
}
