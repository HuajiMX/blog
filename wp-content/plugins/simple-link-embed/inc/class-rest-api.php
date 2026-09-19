<?php
/**
 * REST API
 *
 * REST APIエンドポイントを管理するクラス
 *
 * @package Slemb
 */

namespace Slemb;

// 万全の防御策
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class REST_API
 */
class REST_API {

    /**
     * インスタンス
     *
     * @var REST_API|null
     */
    private static $instance = null;

    /**
     * ネームスペース
     *
     * @var string
     */
    private $namespace = 'simple-link-embed/v1';

    /**
     * コンストラクタ
     */
    private function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * インスタンスを取得
     *
     * @return REST_API
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * RESTルートを登録
     */
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/fetch',
            array(
                array(
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'fetch_ogp' ),
                    'permission_callback' => array( $this, 'check_permission' ),
                    'args'                => array(
                        'url' => array(
                            'required'          => true,
                            'validate_callback' => array( $this, 'validate_url' ),
                            'sanitize_callback' => 'esc_url_raw',
                        ),
                    ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/cache/clear',
            array(
                array(
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'clear_cache' ),
                    'permission_callback' => array( $this, 'check_permission' ),
                    'args'                => array(
                        'url' => array(
                            'required'          => true,
                            'validate_callback' => array( $this, 'validate_url' ),
                            'sanitize_callback' => 'esc_url_raw',
                        ),
                    ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/cache/stats',
            array(
                array(
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_cache_stats' ),
                    'permission_callback' => array( $this, 'check_admin_permission' ),
                ),
            )
        );
    }

    /**
     * パーミッションチェック
     *
     * @return true|\WP_Error
     */
    public function check_permission() {
        // 投稿者以上のみ許可
        if ( ! current_user_can( 'publish_posts' ) ) {
            return new \WP_Error(
                'rest_forbidden',
                __( 'You are not allowed to fetch OGP data.', 'simple-link-embed' ),
                array( 'status' => 403 )
            );
        }
        return true;
    }

    /**
     * レート制限チェック
     *
     * ユーザーごとに1分間30リクエストの制限を適用
     *
     * @param int $user_id ユーザーID
     * @return true|\WP_Error
     */
    private function check_rate_limit( $user_id ) {
        $transient_key = 'slemb_rate_limit_' . $user_id;
        $requests = get_transient( $transient_key );

        if ( false === $requests ) {
            $requests = 0;
        }

        if ( $requests >= 30 ) {
            return new \WP_Error(
                'rate_limit_exceeded',
                __( 'Rate limit exceeded. Please wait and try again.', 'simple-link-embed' ),
                array( 'status' => 429 )
            );
        }

        set_transient( $transient_key, $requests + 1, MINUTE_IN_SECONDS );
        return true;
    }

    /**
     * 管理者パーミッションチェック
     *
     * @return true|\WP_Error
     */
    public function check_admin_permission() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new \WP_Error(
                'rest_forbidden',
                __( 'You are not allowed to perform this action.', 'simple-link-embed' ),
                array( 'status' => 403 )
            );
        }
        return true;
    }

    /**
     * URLバリデーション
     *
     * @param string $url URL
     * @return bool
     */
    public function validate_url( $url ) {
        return ! empty( $url ) && filter_var( $url, FILTER_VALIDATE_URL );
    }

    /**
     * OGPデータを取得
     *
     * @param \WP_REST_Request $request リクエストオブジェクト
     * @return \WP_REST_Response|\WP_Error
     */
    public function fetch_ogp( $request ) {
        // レート制限チェック
        $user_id = get_current_user_id();
        $rate_limit_check = $this->check_rate_limit( $user_id );

        if ( is_wp_error( $rate_limit_check ) ) {
            return $rate_limit_check;
        }

        $url = $request->get_param( 'url' );

        // デバッグ情報
        $debug = array(
            'requested_url' => $url,
            'timestamp'     => current_time( 'mysql' ),
        );

        // OGP取得
        $ogp_fetcher = OGP_Fetcher::get_instance();
        $result      = $ogp_fetcher->fetch( $url );

        if ( is_wp_error( $result ) ) {
            // デバッグモード時はエラー詳細も返す
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                return new \WP_Error(
                    'fetch_error',
                    $result->get_error_message(),
                    array(
                        'status' => 400,
                        'debug'  => array_merge( $debug, array( 'error_code' => $result->get_error_code() ) ),
                    )
                );
            }
            
            return new \WP_Error(
                'fetch_error',
                $result->get_error_message(),
                array( 'status' => 400 )
            );
        }

        // デバッグモード時はデバッグ情報を追加
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $result['debug'] = $debug;
        }

        return new \WP_REST_Response( $result, 200 );
    }

    /**
     * キャッシュをクリア（特定URLのみ、投稿者以上）
     *
     * @param \WP_REST_Request $request リクエストオブジェクト
     * @return \WP_REST_Response
     */
    public function clear_cache( $request ) {
        $url = $request->get_param( 'url' );
        
        if ( empty( $url ) ) {
            return new \WP_Error(
                'missing_url',
                __( 'No URL was provided.', 'simple-link-embed' ),
                array( 'status' => 400 )
            );
        }

        // 特定のURLのキャッシュのみ削除
        $cache_manager = Cache_Manager::get_instance();
        $cache_manager->delete( $url );
        
        return new \WP_REST_Response( array(
            'success' => true,
            /* translators: %s: Cleared cache target URL. */
            'message' => sprintf( __( 'Cleared the cache for %s.', 'simple-link-embed' ), $url ),
            'url'     => $url,
        ), 200 );
    }

    /**
     * キャッシュ統計情報を取得
     *
     * @param \WP_REST_Request $request リクエストオブジェクト
     * @return \WP_REST_Response
     */
    public function get_cache_stats( $request ) {
        $cache_manager = Cache_Manager::get_instance();
        $stats         = $cache_manager->get_stats();

        return new \WP_REST_Response( $stats, 200 );
    }
}
