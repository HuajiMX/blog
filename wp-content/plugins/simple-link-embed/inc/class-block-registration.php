<?php
/**
 * Block Registration
 *
 * ブロックタイプの登録を管理するクラス
 *
 * @package Slemb
 */

namespace Slemb;

// 万全の防御策
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Block_Registration
 */
class Block_Registration {

    /**
     * インスタンス
     *
     * @var Block_Registration|null
     */
    private static $instance = null;

    /**
     * コンストラクタ
     */
    private function __construct() {
        add_action( 'init', array( $this, 'register_block' ) );
        add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
    }

    /**
     * インスタンスを取得
     *
     * @return Block_Registration
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * ブロックタイプを登録
     */
    public function register_block() {
        // block.jsonから登録
        $block_json_path = SLEMB_PLUGIN_DIR . 'assets/block.json';

        if ( ! file_exists( $block_json_path ) ) {
            return;
        }

        register_block_type_from_metadata(
            $block_json_path,
            array(
                'render_callback' => array( $this, 'render_block' ),
                'editor_script'   => 'simple-link-embed-editor-script',
            )
        );
    }

    /**
     * エディターアセットのエンキュー
     */
    public function enqueue_editor_assets() {
        // エディター用JavaScript
        $block_js_path = SLEMB_PLUGIN_DIR . 'assets/block.js';

        if ( file_exists( $block_js_path ) ) {
            wp_enqueue_script(
                'simple-link-embed-editor-script',
                SLEMB_PLUGIN_URL . 'assets/block.js',
                array( 'wp-blocks', 'wp-i18n', 'wp-element', 'wp-block-editor', 'wp-components' ),
                SLEMB_VERSION,
                true
            );

            // JavaScript翻訳の有効化
            wp_set_script_translations(
                'simple-link-embed-editor-script',
                'simple-link-embed',
                SLEMB_PLUGIN_DIR . 'languages'
            );

            // REST API設定をインラインスクリプトとして追加
            wp_add_inline_script(
                'simple-link-embed-editor-script',
                sprintf(
                    'const slembData = %s;',
                    wp_json_encode(
                        array(
                            'apiUrl'   => rest_url( 'simple-link-embed/v1/fetch' ),
                            'cacheClearUrl' => rest_url( 'simple-link-embed/v1/cache/clear' ),
                            'nonce'    => wp_create_nonce( 'wp_rest' ),
                            'pluginUrl' => SLEMB_PLUGIN_URL,
                        )
                    )
                ),
                'before'
            );
        }
    }

    /**
     * ブロックのレンダリング
     *
     * @param array    $attributes ブロック属性
     * @param string   $content    ブロックコンテンツ（非推奨）
     * @param WP_Block $block      ブロックインスタンス
     * @return string レンダリングされたHTML
     */
    public function render_block( $attributes, $content, $block ) {
        if ( empty( $attributes['url'] ) ) {
            return '';
        }

        $renderer = Renderer::get_instance();
        return $renderer->render( $attributes );
    }

    /**
     * 管理画面用スクリプトのエンキュー
     *
     * @param string $hook 現在のページフック
     */
    public function enqueue_admin_scripts( $hook ) {
        // アナリティクス設定ページのみ
        if ( 'settings_page_simple-link-embed-analytics' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'slemb-admin-analytics',
            SLEMB_PLUGIN_URL . 'assets/admin-analytics.css',
            array(),
            SLEMB_VERSION
        );
    }
}
