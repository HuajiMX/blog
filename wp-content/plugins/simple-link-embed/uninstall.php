<?php
/**
 * Simple Link Embed アンインストール
 *
 * @package Slemb
 */

// 万全の防御策
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * プラグインデータのクリーンアップ
 *
 * @return void
 */
function slemb_uninstall_cleanup() {
    $cache_keys = get_option( 'slemb_cache_index_keys', array() );
    if ( is_array( $cache_keys ) ) {
        foreach ( $cache_keys as $cache_key ) {
            if ( is_string( $cache_key ) && '' !== $cache_key ) {
                delete_transient( $cache_key );
            }
        }
    }

    delete_option( 'slemb_cache_index_keys' );
    delete_option( 'slemb_cache_expiration' );
}

slemb_uninstall_cleanup();
