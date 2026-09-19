<?php
/**
 * Plugin Name: Simple Link Embed
 * Description: Automatically fetch OGP data from a URL and display a beautiful blog card. Includes post search support for easy internal linking.
 * Version: 1.0.4
 * Author: MONOKURO DESIGN
 * Author URI: https://monokuro.design
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: simple-link-embed
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package Slemb
 */

// 万全の防御策
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * プラグイン定数
 */
if ( ! defined( 'SLEMB_VERSION' ) ) {
    define( 'SLEMB_VERSION', '1.0.4' );
}
if ( ! defined( 'SLEMB_PLUGIN_FILE' ) ) {
    define( 'SLEMB_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'SLEMB_PLUGIN_DIR' ) ) {
    define( 'SLEMB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'SLEMB_PLUGIN_URL' ) ) {
    define( 'SLEMB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'SLEMB_CACHE_EXPIRATION' ) ) {
    define( 'SLEMB_CACHE_EXPIRATION', WEEK_IN_SECONDS ); // 7日間キャッシュ
}

/**
 * クラスオートローダー
 *
 * @param string $class クラス名
 */
function slemb_autoloader( $class ) {
    // 名前空間プレフィックスのチェック
    $prefix = 'Slemb\\';
    $base_dir = SLEMB_PLUGIN_DIR . 'inc/';

    $len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }

    // 相対クラス名の取得
    $relative_class = substr( $class, $len );

    // クラスファイル名の変換
    $file = $base_dir . 'class-' . str_replace( '_', '-', strtolower( str_replace( '\\', '-', $relative_class ) ) ) . '.php';

    // ファイルが存在すれば読み込み
    if ( file_exists( $file ) ) {
        require_once $file;
    }
}
spl_autoload_register( 'slemb_autoloader' );

/**
 * プラグイン初期化
 */
function slemb_init() {
    // キャッシュマネージャーの初期化
    Slemb\Cache_Manager::get_instance();

    // REST API の初期化
    Slemb\REST_API::get_instance();

    // ブロック登録の初期化
    Slemb\Block_Registration::get_instance();

    // レンダラーの初期化
    Slemb\Renderer::get_instance();

    // アナリティクス機能の初期化
    Slemb\Analytics::get_instance();
}
add_action( 'plugins_loaded', 'slemb_init' );

/**
 * プラグイン有効化時の処理
 */
function slemb_activate() {
    // デフォルトオプションの設定
    add_option( 'slemb_cache_expiration', SLEMB_CACHE_EXPIRATION );
    add_option( 'slemb_cache_index_keys', array() );
}
register_activation_hook( __FILE__, 'slemb_activate' );

/**
 * プラグイン無効化時の処理
 */
function slemb_deactivate() {
    // OGPキャッシュのクリア
    Slemb\Cache_Manager::get_instance()->clear_all();
}
register_deactivation_hook( __FILE__, 'slemb_deactivate' );
