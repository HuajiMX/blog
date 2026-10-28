<?php
/**
 * Plugin Name:       Post Subscribe Notifier
 * Plugin URI:        https://blog.huajimc.cn/
 * Description:       邮箱订阅新文章推送，以及把新评论（含段评、说说）通过 WebHook 推送到 QQ 机器人端。
 * Version:           1.0.0
 * Author:            HuajiMC
 * Author URI:        https://blog.huajimc.cn/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       post-subscribe-notifier
 * Requires at least: 6.0
 * Requires PHP:      8.0
 *
 * @package Post_Subscribe_Notifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PSN_VERSION', '1.0.0' );
define( 'PSN_FILE', __FILE__ );
define( 'PSN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PSN_URL', plugin_dir_url( __FILE__ ) );
define( 'PSN_OPTION', 'psn_settings' );
define( 'PSN_DB_VERSION', '1.0.0' );
define( 'PSN_TABLE_SUBSCRIBERS', 'psn_subscribers' );
define( 'PSN_TABLE_JOBS', 'psn_jobs' );

require_once PSN_DIR . 'inc/class-psn-i18n.php';
require_once PSN_DIR . 'inc/class-psn-color.php';
require_once PSN_DIR . 'inc/class-psn-settings.php';
require_once PSN_DIR . 'inc/class-psn-install.php';
require_once PSN_DIR . 'inc/class-psn-queue.php';
require_once PSN_DIR . 'inc/class-psn-subscribers.php';
require_once PSN_DIR . 'inc/class-psn-mailer.php';
require_once PSN_DIR . 'inc/class-psn-webhook.php';
require_once PSN_DIR . 'inc/class-psn-comments.php';
require_once PSN_DIR . 'inc/class-psn-posts.php';
require_once PSN_DIR . 'inc/class-psn-signup.php';
require_once PSN_DIR . 'inc/class-psn-cron.php';
require_once PSN_DIR . 'inc/class-psn-diagnostics.php';

/**
 * 各模块的加载入口。
 *
 * 订阅/推送是前台行为，所以无条件注册；后台界面只在 admin 下加载。
 *
 * @return void
 */
function psn_init() {
	PSN_Install::maybe_upgrade();

	PSN_Signup::init();
	PSN_Posts::init();
	PSN_Comments::init();
	PSN_Cron::init();
	PSN_Diagnostics::init();

	if ( is_admin() ) {
		require_once PSN_DIR . 'admin/class-psn-admin.php';
		PSN_Admin::init();
	}
}
add_action( 'plugins_loaded', 'psn_init' );

register_activation_hook( __FILE__, array( 'PSN_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PSN_Install', 'deactivate' ) );
