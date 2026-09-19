<?php
/**
 * Plugin Name:       RSS 订阅类型管理
 * Plugin URI:        https://blog.huajimc.cn/
 * Description:       在后台选择哪些内容类型（文章、页面、主题或插件注册的自定义文章类型）会进入站点的 RSS / Atom Feed，并提供每种类型的独立订阅地址。
 * Version:           1.0.0
 * Author:            HuajiMC
 * Author URI:        https://blog.huajimc.cn/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rss-post-types
 * Requires at least: 5.6
 * Requires PHP:      7.0
 *
 * @package RSS_Post_Types
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RPT_VERSION', '1.0.0' );
define( 'RPT_FILE', __FILE__ );
define( 'RPT_DIR', plugin_dir_path( __FILE__ ) );
define( 'RPT_OPTION', 'rpt_settings' );

require_once RPT_DIR . 'inc/rpt-i18n.php';
require_once RPT_DIR . 'inc/class-rpt-options.php';
require_once RPT_DIR . 'inc/class-rpt-feed.php';
require_once RPT_DIR . 'inc/class-rpt-admin.php';

RPT_Feed::init();

if ( is_admin() ) {
	RPT_Admin::init();
}

register_activation_hook( __FILE__, array( 'RPT_Options', 'on_activate' ) );
