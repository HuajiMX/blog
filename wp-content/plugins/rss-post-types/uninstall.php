<?php
/**
 * 卸载插件时清理设置。
 *
 * @package RSS_Post_Types
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'rpt_settings' );
