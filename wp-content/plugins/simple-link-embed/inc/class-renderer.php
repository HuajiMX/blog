<?php
/**
 * Renderer
 *
 * ブログカードHTMLを生成するクラス
 *
 * @package Slemb
 */

namespace Slemb;

// 万全の防御策
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Renderer
 */
class Renderer {

    /**
     * インスタンス
     *
     * @var Renderer|null
     */
    private static $instance = null;

    /**
     * コンストラクタ
     */
    private function __construct() {
    }

    /**
     * インスタンスを取得
     *
     * @return Renderer
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * ブログカードをレンダリング
     *
     * @param array $attributes ブロック属性
     * @return string レンダリングされたHTML
     */
    public function render( $attributes ) {
        if ( empty( $attributes['url'] ) ) {
            return '';
        }

        // 属性の検証とサニタイズ
        $attributes = wp_parse_args( $attributes, array(
            'url'             => '',
            'ogpData'         => null,
            'imagePosition'   => 'left',
            'showSiteName'    => true,
            'showDescription' => true,
            'openInNewTab'    => true,
        ) );

        // URLのサニタイズ
        $attributes['url'] = esc_url_raw( $attributes['url'] );
        if ( empty( $attributes['url'] ) ) {
            return '';
        }

        // imagePositionのホワイトリスト検証
        $allowed_positions = array( 'left', 'right', 'hide' );
        if ( ! in_array( $attributes['imagePosition'], $allowed_positions, true ) ) {
            $attributes['imagePosition'] = 'left';
        }

        // ブール値の型変換
        $attributes['showSiteName']    = (bool) $attributes['showSiteName'];
        $attributes['showDescription'] = (bool) $attributes['showDescription'];
        $attributes['openInNewTab']    = (bool) $attributes['openInNewTab'];

        // 設定を取得
        $image_position  = $attributes['imagePosition'];
        $show_site_name  = $attributes['showSiteName'];
        $show_description = $attributes['showDescription'];
        $open_in_new_tab = $attributes['openInNewTab'];

        // OGPデータがある場合は使用、なければ取得
        $ogp_data = isset( $attributes['ogpData'] ) ? $attributes['ogpData'] : null;

        if ( empty( $ogp_data ) || ! is_array( $ogp_data ) ) {
            // キャッシュから取得
            $cache_manager = Cache_Manager::get_instance();
            $cached_data = $cache_manager->get( $attributes['url'] );

            if ( false !== $cached_data ) {
                $ogp_data = $cached_data;
            } else {
                // サーバーサイドでOGP取得（エディターで未取得の場合）
                $ogp_fetcher = OGP_Fetcher::get_instance();
                $result = $ogp_fetcher->fetch( $attributes['url'] );

                if ( ! is_wp_error( $result ) ) {
                    $ogp_data = $result;
                }
            }
        }

        // OGPデータがまだない場合は最小限のデータで表示
        if ( empty( $ogp_data ) ) {
            $parsed_url = wp_parse_url( $attributes['url'] );
            $ogp_data = array(
                'title'       => $attributes['url'],
                'description' => '',
                'image'       => '',
                'url'         => $attributes['url'],
                'site_name'   => '',
                'domain'      => isset( $parsed_url['host'] ) ? $parsed_url['host'] : '',
                'favicon'     => '',
            );
        }

        return $this->generate_html( $ogp_data, $image_position, $show_site_name, $show_description, $open_in_new_tab );
    }

    /**
     * HTMLを生成
     *
     * @param array  $ogp_data        OGPデータ
     * @param string $image_position  画像の位置 (left|right|hide)
     * @param bool   $show_site_name  サイト名を表示するか
     * @param bool   $show_description ディスクリプションを表示するか
     * @param bool   $open_in_new_tab 新しいタブで開くか
     * @return string HTML
     */
    private function generate_html( $ogp_data, $image_position = 'left', $show_site_name = true, $show_description = true, $open_in_new_tab = true ) {
        $url         = esc_url( $ogp_data['url'] ?? '' );
        $title       = esc_html( $ogp_data['title'] ?? '' );
        $description = esc_html( trim( (string) ( $ogp_data['description'] ?? '' ) ) );
        $image       = esc_url( $ogp_data['image'] ?? '' );
        $domain      = esc_html( $ogp_data['domain'] ?? '' );
        $site_name   = esc_html( $ogp_data['site_name'] ?? '' );
        $fallback_icon = esc_url( SLEMB_PLUGIN_URL . 'assets/images/site-placeholder.svg' );
        $favicon     = esc_url( $ogp_data['favicon'] ?? '' );
        $site_icon   = '' !== $favicon ? $favicon : $fallback_icon;

        // 計測用データ属性（Analyticsクラスから設定取得）
        $analytics_enabled = false;
        if ( class_exists( 'Slemb\Analytics' ) ) {
            $analytics = \Slemb\Analytics::get_instance();
            $analytics_enabled = $analytics->is_tracking_active();
        }

        $data_track_attrs = '';
        if ( $analytics_enabled ) {
            $data_track_attrs = sprintf(
                ' data-track-click="1" data-track-url="%s" data-track-title="%s" data-track-domain="%s"',
                esc_attr( $url ),
                esc_attr( $title ),
                esc_attr( $domain )
            );
        }

        // 表示用サイト名（site_nameがなければdomainを使用）
        $display_site = ! empty( $site_name ) ? $site_name : $domain;

        // カードのクラス名を生成
        $card_classes = array( 'slemb-card' );
        if ( $image_position === 'right' && ! empty( $image ) ) {
            $card_classes[] = 'slemb-card--image-right';
        } elseif ( $image_position === 'hide' || empty( $image ) ) {
            $card_classes[] = 'slemb-card--no-image';
        }

        $html = sprintf(
            '<div class="wp-block-simple-link-embed" data-url="%s"%s>',
            esc_attr( $url ),
            $data_track_attrs
        );

        // リンクの属性を構築
        $link_attrs = array(
            'href="' . $url . '"',
            'class="' . esc_attr( implode( ' ', $card_classes ) ) . '"',
        );
        if ( $open_in_new_tab ) {
            $link_attrs[] = 'target="_blank"';
            $link_attrs[] = 'rel="noopener noreferrer"';
        }

        // アクセシビリティ：aria-labelを追加
        $aria_label = sprintf(
            /* translators: %s: Linked page title. */
            __( 'Link card: %s', 'simple-link-embed' ),
            $title
        );
        $link_attrs[] = 'aria-label="' . esc_attr( $aria_label ) . '"';

        $html .= '<a ' . implode( ' ', $link_attrs ) . '>';

        // 画像部分（非表示でない場合のみ）
        if ( ! empty( $image ) && $image_position !== 'hide' ) {
            $html .= sprintf(
                '<div class="slemb-card__image"><img src="%s" alt="%s" loading="lazy" /></div>',
                $image,
                esc_attr( $title )
            );
        }

        // コンテンツ部分
        $html .= '<div class="slemb-card__content">';

        // タイトル
        $html .= sprintf( '<div class="slemb-card__title">%s</div>', $title );

        // 説明（表示設定がオンの場合のみ）
        if ( $show_description && ! empty( $description ) ) {
            $html .= sprintf( '<p class="slemb-card__description">%s</p>', $description );
        }

        // サイト情報（表示設定がオンの場合のみ）
        if ( $show_site_name && ! empty( $display_site ) ) {
            $html .= '<div class="slemb-card__site">';
            $html .= sprintf(
                '<img class="slemb-card__favicon" src="%1$s" data-fallback-icon="%2$s" alt="" aria-hidden="true" onerror="this.onerror=null;this.src=this.dataset.fallbackIcon;" />',
                $site_icon,
                $fallback_icon
            );
            $html .= sprintf( '<span class="slemb-card__domain">%s</span>', $display_site );
            $html .= '</div>';
        }

        $html .= '</div>'; // .slemb-card__content

        $html .= '</a>'; // .slemb-card
        $html .= '</div>'; // .wp-block-simple-link-embed

        /**
         * カードHTMLフィルター
         *
         * @param string $html      レンダリングされたHTML
         * @param array  $ogp_data  OGPデータ
         * @param array  $attributes ブロック属性（元の属性）
         */
        return apply_filters( 'slemb_card_html', $html, $ogp_data, array(
            'image_position'  => $image_position,
            'show_site_name'  => $show_site_name,
            'show_description' => $show_description,
            'open_in_new_tab' => $open_in_new_tab,
        ) );
    }

    /**
     * プレースホルダーHTMLを生成（エディター用）
     *
     * @return string HTML
     */
    public function render_placeholder() {
        return '<div class="wp-block-simple-link-embed slemb-placeholder">' .
               '<p>' . esc_html__( 'Enter a URL or search posts.', 'simple-link-embed' ) . '</p>' .
               '</div>';
    }

    /**
     * エラーHTMLを生成
     *
     * @param string $message エラーメッセージ
     * @return string HTML
     */
    public function render_error( $message ) {
        return '<div class="wp-block-simple-link-embed slemb-error">' .
               '<p>' . esc_html( $message ) . '</p>' .
               '</div>';
    }

    /**
     * ローディングHTMLを生成
     *
     * @return string HTML
     */
    public function render_loading() {
        return '<div class="wp-block-simple-link-embed slemb-loading">' .
               '<div class="slemb-spinner"></div>' .
               '<p>' . esc_html__( 'Fetching OGP data...', 'simple-link-embed' ) . '</p>' .
               '</div>';
    }
}
