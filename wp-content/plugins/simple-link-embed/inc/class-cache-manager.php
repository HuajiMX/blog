<?php
/**
 * Cache Manager
 *
 * キャッシュ管理クラス
 *
 * @package Slemb
 */

namespace Slemb;

// 万全の防御策
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Cache_Manager
 */
class Cache_Manager {

    /**
     * インスタンス
     *
     * @var Cache_Manager|null
     */
    private static $instance = null;

    /**
     * キャッシュプレフィックス
     *
     * @var string
     */
    private $prefix = 'slemb_ogp_';

    /**
     * デフォルトのキャッシュ有効期限（秒）
     *
     * @var int
     */
    private $expiration;

    /**
     * キャッシュキー管理用オプション名
     *
     * @var string
     */
    private $index_option = 'slemb_cache_index_keys';

    /**
     * コンストラクタ
     */
    private function __construct() {
        $this->expiration = get_option( 'slemb_cache_expiration', SLEMB_CACHE_EXPIRATION );
    }

    /**
     * インスタンスを取得
     *
     * @return Cache_Manager
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * キャッシュを取得
     *
     * @param string $url URL
     * @return array|false キャッシュデータまたはfalse
     */
    public function get( $url ) {
        $key = $this->get_cache_key( $url );
        $data = get_transient( $key );

        if ( false !== $data && is_array( $data ) ) {
            /**
             * キャッシュヒット時のアクション
             *
             * @param string $url  リクエストされたURL
             * @param string $key  キャッシュキー
             */
            do_action( 'slemb_cache_hit', $url, $key );
            return $data;
        }

        /**
         * キャッシュミス時のアクション
         *
         * @param string $url  リクエストされたURL
         * @param string $key  キャッシュキー
         */
        $this->forget_cache_key( $key );
        do_action( 'slemb_cache_miss', $url, $key );
        return false;
    }

    /**
     * キャッシュを設定
     *
     * @param string $url        URL
     * @param array  $data       OGPデータ
     * @param int    $expiration 有効期限（秒）
     * @return bool 成功した場合true
     */
    public function set( $url, $data, $expiration = null ) {
        $key = $this->get_cache_key( $url );

        if ( null === $expiration ) {
            $expiration = $this->expiration;
        }

        $result = set_transient( $key, $data, $expiration );

        if ( $result ) {
            $this->remember_cache_key( $key );
            /**
             * キャッシュ保存時のアクション
             *
             * @param string $url        リクエストされたURL
             * @param string $key        キャッシュキー
             * @param array  $data       保存されたOGPデータ
             * @param int    $expiration 有効期限（秒）
             */
            do_action( 'slemb_cache_set', $url, $key, $data, $expiration );
        }

        return $result;
    }

    /**
     * キャッシュを削除
     *
     * @param string $url URL
     * @return bool 成功した場合true
     */
    public function delete( $url ) {
        $key = $this->get_cache_key( $url );
        $result = delete_transient( $key );
        $this->forget_cache_key( $key );

        if ( $result ) {
            /**
             * キャッシュ削除時のアクション
             *
             * @param string $url  削除されたURL
             * @param string $key  キャッシュキー
             */
            do_action( 'slemb_cache_deleted', $url, $key );
        }

        return $result;
    }

    /**
     * 全キャッシュをクリア
     *
     * @return int クリアされたキャッシュ数
     */
    public function clear_all() {
        $cache_keys = $this->get_cache_keys();
        $count = 0;

        foreach ( $cache_keys as $key ) {
            if ( delete_transient( $key ) ) {
                $count++;
            }
        }
        update_option( $this->index_option, array() );

        /**
         * 全キャッシュクリア時のアクション
         *
         * @param int $count クリアされたキャッシュ数
         */
        do_action( 'slemb_cache_cleared', $count );

        return $count;
    }

    /**
     * キャッシュキーを生成
     *
     * @param string $url URL
     * @return string キャッシュキー
     */
    private function get_cache_key( $url ) {
        return $this->prefix . md5( $url );
    }

    /**
     * 登録済みキャッシュキー一覧を取得
     *
     * @return array<string>
     */
    private function get_cache_keys() {
        $cache_keys = get_option( $this->index_option, array() );
        if ( ! is_array( $cache_keys ) ) {
            return array();
        }

        $cache_keys = array_filter(
            $cache_keys,
            static function ( $cache_key ) {
                return is_string( $cache_key ) && '' !== $cache_key;
            }
        );

        return array_values( array_unique( $cache_keys ) );
    }

    /**
     * キャッシュキーを管理対象へ追加
     *
     * @param string $key キャッシュキー
     * @return void
     */
    private function remember_cache_key( $key ) {
        $cache_keys = $this->get_cache_keys();
        if ( in_array( $key, $cache_keys, true ) ) {
            return;
        }

        $cache_keys[] = $key;
        update_option( $this->index_option, $cache_keys );
    }

    /**
     * キャッシュキーを管理対象から削除
     *
     * @param string $key キャッシュキー
     * @return void
     */
    private function forget_cache_key( $key ) {
        $cache_keys = $this->get_cache_keys();
        if ( empty( $cache_keys ) ) {
            return;
        }

        $new_cache_keys = array_values( array_diff( $cache_keys, array( $key ) ) );
        if ( $new_cache_keys === $cache_keys ) {
            return;
        }

        update_option( $this->index_option, $new_cache_keys );
    }

    /**
     * キャッシュ有効期限を取得
     *
     * @return int 有効期限（秒）
     */
    public function get_expiration() {
        return $this->expiration;
    }

    /**
     * キャッシュ有効期限を設定
     *
     * @param int $expiration 有効期限（秒）
     */
    public function set_expiration( $expiration ) {
        $this->expiration = (int) $expiration;
        update_option( 'slemb_cache_expiration', $this->expiration );
    }

    /**
     * キャッシュ統計情報を取得
     *
     * @return array 統計情報
     */
    public function get_stats() {
        $total = count( $this->get_cache_keys() );

        return array(
            'total_cache_count' => (int) $total,
            'expiration_seconds' => $this->expiration,
            'expiration_days' => round( $this->expiration / DAY_IN_SECONDS, 1 ),
        );
    }
}
