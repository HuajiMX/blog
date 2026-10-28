<?php
/**
 * 卸载：只有设置里勾了「删除全部数据」才会清空表与选项。
 *
 * @package Post_Subscribe_Notifier
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/inc/class-psn-i18n.php';
require_once __DIR__ . '/inc/class-psn-settings.php';
require_once __DIR__ . '/inc/class-psn-install.php';

PSN_Install::uninstall();
