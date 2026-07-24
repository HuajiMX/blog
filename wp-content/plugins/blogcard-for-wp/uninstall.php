<?php
/**
 * アンインストール時の処理
 */

// WP_UNINSTALL_PLUGIN定数が定義されていない場合は終了
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// データ削除設定を確認
$options = get_option( 'wpbc_settings' );

if ( isset( $options['delete_data'] ) && $options['delete_data'] ) {
	global $wpdb;

	// 1. プラグイン設定の削除
	delete_option( 'wpbc_settings' );

	// 2. キャッシュ（Transient）の削除
	// _transient_wpbc_metadata_% と _transient_timeout_wpbc_metadata_% を削除
	$wpdb->query(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpbc_metadata_%' OR option_name LIKE '_transient_timeout_wpbc_metadata_%'"
	);

	// オブジェクトキャッシュもクリア（念のため）
	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}
}
