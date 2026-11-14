<?php
// Sakurairo古腾堡编辑器支持

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_enqueue_scripts', 'iro_editor_vars' );
function iro_editor_vars( $hook ) {
    // 仅在编辑页注入
    if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) {
        return;
    }

    // 准备信息
    $data = [
        'siteTitle' => get_bloginfo( 'name' ),
        'language' => get_locale(),
        'user' => wp_get_current_user()->user_login,
    ];

    // 传递变量
    wp_add_inline_script(
        'wp-blocks',
        'window.iroBlockEditor = ' . json_encode( $data ) . ';',
        'before'
    );

    // 关闭区块编辑器的 iframe 画布。
    //
    // WordPress 7.0 起，编辑器把正文画布放进一个 blob: URL 的 iframe；只要文章里
    // 所有区块的 apiVersion >= 3，就一定会用 iframe。部分手机浏览器（QQ 浏览器、
    // 华为/荣耀浏览器等 Chromium 内核）会拦截 blob: 帧，结果是顶栏正常、正文永远
    // 停在 #ddd 灰底。srcdoc 和同源 iframe 在这些浏览器里都正常，只有 blob: 被拦。
    //
    // WP 判断是否 iframe 的条件之一是“当前文章里所有区块 apiVersion >= 3”，这里把
    // 注册时的版本统一降到 2，让编辑器退回非 iframe 渲染（内容直接渲染在主文档里）。
    // apiVersion 2 与 3 的序列化行为一致，3 只是声明“兼容 iframe”，所以降级不影响
    // 已存内容的解析；副作用仅是控制台多出 apiVersion 弃用提示。
    wp_add_inline_script(
        'wp-blocks',
        "window.wp&&wp.hooks&&wp.hooks.addFilter('blocks.registerBlockType','iro/disable-iframe-canvas',function(settings){if(settings&&settings.apiVersion>=3){settings=Object.assign({},settings,{apiVersion:2});}return settings;});",
        'before'
    );
}

/**
 * 新建文章时预置一个空段落区块。
 *
 * WP 判断是否启用 iframe 画布的另一个条件是“当前文章里所有区块 apiVersion >= 3”；
 * 空文章没有任何区块，空数组让这个条件恒为真，于是仍然会走 blob: iframe。
 * 预置一个空段落可以让条件恒为假，编辑器稳定走非 iframe 渲染。
 *
 * 只对不使用 Githuber Markdown 的文章类型生效，避免污染 Markdown 文章的内容。
 *
 * @param string  $content 默认正文。
 * @param WP_Post $post    当前文章对象。
 * @return string
 */
function iro_block_editor_default_paragraph($content, $post)
{
    if (!is_admin() || !($post instanceof WP_Post)) {
        return $content;
    }

    if ('' !== trim((string) $content)) {
        return $content;
    }

    if (!post_type_supports($post->post_type, 'editor') || post_type_supports($post->post_type, 'githuber_markdown')) {
        return $content;
    }

    return "<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->";
}
add_filter('default_content', 'iro_block_editor_default_paragraph', 10, 2);

add_action('enqueue_block_editor_assets', 'sakurairo_editor_styles');
function sakurairo_editor_styles() {
    global $core_lib_basepath;
    wp_enqueue_style('fontawesome-icons',iro_opt('fontawesome_source','https://s4.zstatic.net/ajax/libs/font-awesome/6.7.2/css/all.min.css'),array(),null);
    wp_enqueue_style('iro-codes', $core_lib_basepath . '/css/shortcodes.css', array(), IRO_VERSION);
}

function iro_load_editor_block() {
    // 加载编辑器脚本
    wp_enqueue_script(
        'iroBlockEditor',
        get_theme_file_uri( '/inc/blocks/build/index.js' ),
        [
            'wp-hooks',
            'wp-i18n',
            'wp-element',
            'wp-components',
            'wp-block-editor',
        ]
    );
}
add_action( 'enqueue_block_editor_assets', 'iro_load_editor_block' );

// 为代码块添加hljs语言标记language-支持
add_filter( 'render_block', function( $block_content, $block ) {
	if ( $block['blockName'] === 'core/code' && ! empty( $block['attrs']['language'] ) ) {
		$block_content = preg_replace(
			'/<code(.*?)>/',
			'<code$1 class="language-' . esc_attr( $block['attrs']['language'] ) . '">',
			$block_content
		);
	}
	return $block_content;
}, 10, 2 );