<?php

/**
 * Plugin Name: SU Blocks - Blogcard
 * Plugin URI: https://wordpress.org/plugins/blogcard-for-wp/
 * Description: A block plugin that automatically generates a beautiful blog card just by pasting a URL.
 * Version: 2.3.7
 * Author: Takashi Fujisaki
 * License: GPL v2 or later
 * Text Domain: wpbc
 */

// 直接アクセスを防ぐ
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// プラグインの定数
define( 'WPBC_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

// 管理画面設定の読み込み
require_once WPBC_PLUGIN_PATH . 'includes/admin-settings.php';

/**
 * カスタムブロックカテゴリーを追加
 */
function wpbc_block_categories( $categories, $editor_context ) {
	if ( ! empty( $editor_context->post ) ) {
		// 既存のカテゴリーが存在するかチェック
		$exists = wp_list_pluck( $categories, 'slug' );
		if ( ! in_array( 'su-blocks', $exists, true ) ) {
			array_push(
				$categories,
				array(
					'slug'  => 'su-blocks',
					'title' => 'SU Blocks',
				)
			);
		}
	}
	return $categories;
}
add_filter( 'block_categories_all', 'wpbc_block_categories', 10, 2 );

/**
 * プラグインの初期化
 */
function wpbc_init() {
	// テキストドメインの読み込み
	load_plugin_textdomain( 'wpbc', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// ブロックの登録
	register_block_type( WPBC_PLUGIN_PATH . 'build/block.json' );

	// 設定をブロックエディタに渡す
	add_action( 'enqueue_block_editor_assets', 'wpbc_enqueue_editor_assets' );
}
add_action( 'init', 'wpbc_init' );

/**
 * ブロックエディタ用アセットのエンキュー
 */
function wpbc_enqueue_editor_assets() {
	$options  = get_option( 'wpbc_settings' );
	$settings = array(
		'external_target' => isset( $options['external_target'] ) ? $options['external_target'] : '_blank',
		'internal_target' => isset( $options['internal_target'] ) ? $options['internal_target'] : '_self',
		'home_url'        => home_url(),
		'site_favicon'    => wpbc_get_site_favicon(),
		'site_og_image'   => wpbc_get_site_og_image(),
	);

	wp_add_inline_script(
		'wp-blocks',
		'window.wpbcSettings = ' . json_encode( $settings ) . ';',
		'before'
	);
}

/**
 * REST APIエンドポイントを追加
 */
function wpbc_rest_api_init() {
	register_rest_route(
		'wpbc/v1',
		'/metadata',
		array(
			'methods'             => 'POST',
			'callback'            => 'wpbc_get_metadata',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'args'                => array(
				'url' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
			),
		)
	);

	register_rest_route(
		'wpbc/v1',
		'/internal-metadata',
		array(
			'methods'             => 'GET',
			'callback'            => 'wpbc_get_internal_metadata',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'args'                => array(
				'url' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
			),
		)
	);

	register_rest_route(
		'wpbc/v1',
		'/search',
		array(
			'methods'             => 'GET',
			'callback'            => 'wpbc_search_posts',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'args'                => array(
				'q' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	register_rest_route(
		'wpbc/v1',
		'/clear-cache',
		array(
			'methods'             => 'POST',
			'callback'            => 'wpbc_clear_cache',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'args'                => array(
				'type' => array(
					'type'    => 'string',
					'default' => 'plugin',
					'enum'    => array( 'plugin', 'all' ),
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'wpbc_rest_api_init' );

/**
 * メタデータ取得のREST APIコールバック
 */
function wpbc_get_metadata( $request ) {
	$url = $request->get_param( 'url' );

	if ( empty( $url ) ) {
		return new WP_Error( 'missing_url', 'URLが指定されていません。', array( 'status' => 400 ) );
	}

	$metadata = wpbc_fetch_metadata( $url );

	// WP_Errorオブジェクトの場合はそのまま返す
	if ( is_wp_error( $metadata ) ) {
		return $metadata;
	}

	// falseの場合は一般的なエラー
	if ( false === $metadata ) {
		return new WP_Error( 'metadata_fetch_failed', 'メタデータの取得に失敗しました。', array( 'status' => 500 ) );
	}

	return array(
		'success' => true,
		'data'    => $metadata,
	);
}

/**
 * 内部メタデータ取得のREST APIコールバック
 */
function wpbc_get_internal_metadata( $request ) {
	$url = $request->get_param( 'url' );

	if ( empty( $url ) ) {
		return new WP_Error( 'missing_url', 'URLが指定されていません。', array( 'status' => 400 ) );
	}

	$metadata = wpbc_fetch_internal_metadata( $url );

	if ( is_wp_error( $metadata ) ) {
		return $metadata;
	}

	return array(
		'success' => true,
		'data'    => $metadata,
	);
}

/**
 * URLが内部サイトのURLかどうかを判定する
 */
function wpbc_is_internal_url( $url ) {
	if ( empty( $url ) ) {
		return false;
	}

	$site_url        = get_site_url();
	$parsed_site_url = parse_url( $site_url );
	$parsed_url      = parse_url( $url );

	if ( ! $parsed_site_url || ! $parsed_url ) {
		return false;
	}

	$site_host = $parsed_site_url['host'];
	$url_host  = $parsed_url['host'];

	// 完全一致
	if ( $site_host === $url_host ) {
		return true;
	}

	// サブドメインのチェック
	// 例：www.example.com は example.com のサブドメイン
	$site_suffix = '.' . $url_host;
	$url_suffix  = '.' . $site_host;
	if (
	substr( $site_host, -strlen( $site_suffix ) ) === $site_suffix ||
	substr( $url_host, -strlen( $url_suffix ) ) === $url_suffix
	) {
		return true;
	}

	return false;
}

/**
 * メタデータを取得する関数
 */
function wpbc_fetch_metadata( $url ) {
	// キャッシュキーを生成
	$cache_key = 'wpbc_metadata_' . md5( $url );

	// キャッシュから取得を試行
	$cached_data = get_transient( $cache_key );
	if ( false !== $cached_data ) {
		// キャッシュから取得した場合はcachedフラグを追加
		$cached_data['cached'] = true;
		return $cached_data;
	}

	// URLの検証
	if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
		return new WP_Error( 'invalid_url', '無効なURL形式です。', array( 'status' => 400 ) );
	}

	// 内部URLの場合は内部メタデータ取得関数を使用
	if ( wpbc_is_internal_url( $url ) ) {
		$metadata = wpbc_fetch_internal_metadata( $url );
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}

		// キャッシュ期間を取得（デフォルト24時間）
		$options        = get_option( 'wpbc_settings' );
		$cache_duration = isset( $options['cache_duration'] ) ? (int) $options['cache_duration'] : 24;
		$expiration     = $cache_duration * HOUR_IN_SECONDS;

		// キャッシュに保存
		set_transient( $cache_key, $metadata, $expiration );

		return $metadata;
	}

	$metadata = array(
		'title'       => '',
		'description' => '',
		'thumbnail'   => '',
		'domain'      => parse_url( $url, PHP_URL_HOST ),
		'final_url'   => $url, // リダイレクト後の最終URL
	);

	// HTTPリクエストでメタデータを取得（リダイレクトを追跡）
	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 20, // タイムアウトを20秒に延長
			'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
			'redirection' => 5, // 最大5回までリダイレクトを追跡
			'sslverify'   => false, // SSL証明書の検証を無効化（テスト用）
			'headers'     => array(
				'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
				'Accept-Language'           => 'ja-JP,ja;q=0.9,en;q=0.8',

				'Connection'                => 'keep-alive',
				'Upgrade-Insecure-Requests' => '1',
				'Sec-Fetch-Dest'            => 'document',
				'Sec-Fetch-Mode'            => 'navigate',
				'Sec-Fetch-Site'            => 'none',
				'Cache-Control'             => 'no-cache',
			),
		)
	);

	// ネットワークエラーの場合
	if ( is_wp_error( $response ) ) {
		$error_message = $response->get_error_message();
		$error_code    = 'network_error';
		$status_code   = 500;

		// タイムアウトエラーの場合は特別な処理
		if ( false !== strpos( $error_message, 'timeout' ) || false !== strpos( $error_message, 'timed out' ) ) {
			$error_code    = 'timeout_error';
			$error_message = 'サーバーの応答が遅すぎます。しばらく時間をおいてから再度お試しください。';
			$status_code   = 408; // Request Timeout
		} elseif ( false !== strpos( $error_message, 'Connection refused' ) ) {
			$error_code    = 'connection_refused';
			$error_message = 'サーバーに接続できませんでした。';
			$status_code   = 503; // Service Unavailable
		} elseif ( false !== strpos( $error_message, 'SSL' ) || false !== strpos( $error_message, 'certificate' ) ) {
			$error_code    = 'ssl_error';
			$error_message = 'SSL証明書の検証に失敗しました。';
			$status_code   = 495; // SSL Certificate Error
		}

		// デバッグ用ログ
		error_log( 'wpbc_fetch_metadata network error for URL: ' . $url . ' - Error: ' . $error_message . ' - Code: ' . $error_code );
		return new WP_Error( $error_code, $error_message, array( 'status' => $status_code ) );
	}

	// HTTPステータスコードを取得
	$response_code = wp_remote_retrieve_response_code( $response );

	// 200以外の場合はエラーを返す
	if ( 200 !== $response_code ) {
		$error_message = 'HTTPエラーが発生しました';
		switch ( $response_code ) {
			case 404:
				$error_message = 'ページが見つかりません (404)';
				break;
			case 403:
				$error_message = 'アクセスが拒否されました (403)';
				break;
			case 500:
				$error_message = 'サーバーエラーが発生しました (500)';
				break;
			case 503:
				$error_message = 'サービスが利用できません (503)';
				break;
			default:
				$error_message = "HTTPエラーが発生しました ({$response_code})";
				break;
		}
		// デバッグ用ログ
		error_log( 'wpbc_fetch_metadata HTTP error for URL: ' . $url . ' - Status: ' . $response_code . ' - Message: ' . $error_message );
		return new WP_Error( 'http_error', $error_message, array( 'status' => $response_code ) );
	}

	if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
		$body = wp_remote_retrieve_body( $response );

		// リダイレクト後の最終URLを取得
		// wp_remote_get()は自動的にリダイレクトを追跡するので、
		// レスポンスのURLを取得する
		$response_url = wp_remote_retrieve_header( $response, 'url' );
		if ( $response_url ) {
			$final_url = $response_url;
		} else {
			// ヘッダーから取得できない場合は元のURLを使用
			$final_url = $url;
		}

		// 最終URLのドメインとURLを更新
		$metadata['domain']    = parse_url( $final_url, PHP_URL_HOST );
		$metadata['final_url'] = $final_url;

		// HTMLをパースしてメタデータを抽出
		$dom                   = new DOMDocument();
		$libxml_previous_state = libxml_use_internal_errors( true );
		$dom->loadHTML( mb_convert_encoding( $body, 'HTML-ENTITIES', 'UTF-8' ) );
		libxml_clear_errors();
		libxml_use_internal_errors( $libxml_previous_state );
		$xpath = new DOMXPath( $dom );

		// タイトルを取得
		$title_nodes = $xpath->query( '//title' );
		if ( $title_nodes->length > 0 ) {
			$metadata['title'] = html_entity_decode( trim( $title_nodes->item( 0 )->textContent ), ENT_QUOTES, 'UTF-8' );
		}

		// OGPメタタグを取得
		$og_title = $xpath->query( '//meta[@property="og:title"]/@content' );
		if ( $og_title->length > 0 ) {
			$metadata['title'] = html_entity_decode( trim( $og_title->item( 0 )->textContent ), ENT_QUOTES, 'UTF-8' );
		}

		// 説明文を取得
		$description_nodes = $xpath->query( '//meta[@name="description"]/@content' );
		if ( $description_nodes->length > 0 ) {
			$metadata['description'] = html_entity_decode( trim( $description_nodes->item( 0 )->textContent ), ENT_QUOTES, 'UTF-8' );
		}

		$og_description = $xpath->query( '//meta[@property="og:description"]/@content' );
		if ( $og_description->length > 0 ) {
			$metadata['description'] = html_entity_decode( trim( $og_description->item( 0 )->textContent ), ENT_QUOTES, 'UTF-8' );
		}

		// サムネイル画像を取得
		$og_image = $xpath->query( '//meta[@property="og:image"]/@content' );
		if ( $og_image->length > 0 ) {
			$metadata['thumbnail'] = trim( $og_image->item( 0 )->textContent );
		}

		// Faviconを取得
		$favicon_url = wpbc_get_favicon_url( $xpath, $url );
		if ( $favicon_url ) {
			$metadata['favicon'] = $favicon_url;
		}
	}

	// 新しく取得した場合はcachedフラグをfalseに設定
	$metadata['cached'] = false;

	// キャッシュ期間を取得（デフォルト24時間）
	$options        = get_option( 'wpbc_settings' );
	$cache_duration = isset( $options['cache_duration'] ) ? (int) $options['cache_duration'] : 24;
	$expiration     = $cache_duration * HOUR_IN_SECONDS;

	// キャッシュに保存
	set_transient( $cache_key, $metadata, $expiration );

	return $metadata;
}

/**
 * サイト内検索のREST APIコールバック
 */
function wpbc_search_posts( $request ) {
	$query = $request->get_param( 'q' );

	if ( empty( $query ) ) {
		return new WP_Error( 'missing_query', '検索クエリが指定されていません。', array( 'status' => 400 ) );
	}

	// 投稿と固定ページを検索
	$args = array(
		'post_type'      => array( 'post', 'page' ),
		'post_status'    => 'publish',
		's'              => $query,
		'posts_per_page' => -1,
		'orderby'        => 'relevance',
		'order'          => 'DESC',
	);

	$posts   = get_posts( $args );
	$results = array();

	// 投稿を先に並べる
	$posts_sorted = array();
	$pages_sorted = array();

	foreach ( $posts as $post ) {
		if ( 'post' === get_post_type( $post->ID ) ) {
			$posts_sorted[] = $post;
		} else {
			$pages_sorted[] = $post;
		}
	}

	// 投稿と固定ページを結合（投稿を優先）
	$sorted_posts = array_merge( $posts_sorted, $pages_sorted );

	// サイト共通のFaviconを取得（キャッシュ対応）
	$favicon_url = wpbc_get_site_favicon();

	foreach ( $sorted_posts as $post ) {
		$thumbnail_id = get_post_thumbnail_id( $post->ID );
		$medium_src   = wp_get_attachment_image_src( $thumbnail_id, 'medium' );
		// is_intermediate ( $medium_src[3] ) が false の場合、指定サイズが存在せずオリジナルが返されている
		$thumbnail_url = ( $medium_src && $medium_src[3] ) ? $medium_src[0] : get_the_post_thumbnail_url( $post->ID, 'thumbnail' );

		$results[] = array(
			'id'              => $post->ID,
			'title'           => html_entity_decode( get_the_title( $post->ID ), ENT_QUOTES, 'UTF-8' ),
			'url'             => get_permalink( $post->ID ),
			'type'            => get_post_type( $post->ID ),
			'date'            => get_the_date( 'Y-m-d', $post->ID ),
			'thumbnail'       => $thumbnail_url,
			'thumbnail_large' => get_the_post_thumbnail_url( $post->ID, 'large' ),
			'description'     => html_entity_decode( get_the_excerpt( $post->ID ), ENT_QUOTES, 'UTF-8' ),
			'favicon'         => $favicon_url,
		);
	}

	return array(
		'success' => true,
		'data'    => $results,
	);
}

/**
 * 内部サイトのメタデータを取得する
 */
function wpbc_fetch_internal_metadata( $url ) {
	// URLの正規化（末尾のスラッシュを削除して比較）
	$normalized_url = rtrim( $url, '/' );
	$home_url       = rtrim( home_url(), '/' );
	$site_url       = rtrim( site_url(), '/' );

	// ホームURLかどうかの判定
	if ( $normalized_url === $home_url || $normalized_url === $site_url ) {
		$title         = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' );
		$description   = html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES, 'UTF-8' );
		$thumbnail_url = '';

		// フロントページが固定ページに設定されているか確認
		$front_page_id = get_option( 'page_on_front' );
		if ( $front_page_id ) {
			// 固定ページのメタデータを優先
			$front_page_title = get_the_title( $front_page_id );
			if ( $front_page_title ) {
				$title = html_entity_decode( $front_page_title, ENT_QUOTES, 'UTF-8' );
			}

			$front_page_excerpt = get_the_excerpt( $front_page_id );
			if ( $front_page_excerpt ) {
				$description = html_entity_decode( $front_page_excerpt, ENT_QUOTES, 'UTF-8' );
			}

			$thumbnail_url = get_the_post_thumbnail_url( $front_page_id, 'large' );
		}

		// Faviconの取得処理
		$favicon_url = wpbc_get_site_favicon();

		return array(
			'title'       => $title,
			'description' => $description,
			'thumbnail'   => $thumbnail_url,
			'favicon'     => $favicon_url,
			'url'         => $url,
			'cached'      => false,
		);
	}

	// URLから投稿IDを取得
	$post_id = url_to_postid( $url );

	if ( ! $post_id ) {
		return new WP_Error( 'post_not_found', '指定されたURLの投稿が見つかりません。', array( 'status' => 404 ) );
	}

	// 投稿のタイトルを取得
	$title = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );

	// 投稿の抜粋を取得
	$excerpt = html_entity_decode( get_the_excerpt( $post_id ), ENT_QUOTES, 'UTF-8' );

	// サムネイル画像を取得（largeサイズ）
	$thumbnail_url = get_the_post_thumbnail_url( $post_id, 'large' );

	// Faviconの取得処理
	$favicon_url = wpbc_get_site_favicon();

	return array(
		'title'       => $title,
		'description' => $excerpt,
		'thumbnail'   => $thumbnail_url,
		'favicon'     => $favicon_url,
		'url'         => $url,
		'cached'      => false,
	);
}

/**
 * キャッシュクリアのREST APIコールバック
 */
function wpbc_clear_cache( $request ) {
	global $wpdb;

	$type          = $request->get_param( 'type' );
	$cleared_count = 0;

	if ( 'all' === $type ) {
		// 全てのWordPressキャッシュをクリア
		$result        = $wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_transient_timeout_%'"
		);
		$cleared_count = $result;

		// サイト内 Favicon / OG Image キャッシュも明示的に削除
		delete_transient( 'wpbc_site_favicon' );
		delete_transient( 'wpbc_site_og_image' );

		// オブジェクトキャッシュもクリア
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		$message = '全てのキャッシュをクリアしました。';
	} else {
		// プラグインのキャッシュのみをクリア
		$result        = $wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpbc_metadata_%' OR option_name LIKE '_transient_timeout_wpbc_metadata_%'"
		);
		$cleared_count = $result;

		$message = 'ブログカードのキャッシュをクリアしました。';
	}

	return array(
		'success'       => true,
		'message'       => $message,
		'cleared_count' => $cleared_count,
	);
}

/**
 * Faviconを取得する関数
 */
function wpbc_get_favicon_url( $xpath, $base_url ) {
	$favicon_url = null;

	// FaviconのURLを取得するクエリ（優先順位順）
	$queries = array(
		"//link[@rel='icon' and @type='image/svg+xml']",
		"//link[@rel='icon' and @type='image/png']",
		"//link[@rel='shortcut icon']",
		"//link[contains(@rel, 'icon')]",
		"//link[@rel='apple-touch-icon']",
		"//meta[@name='msapplication-TileImage']",
	);

	foreach ( $queries as $query ) {
		$results = $xpath->query( $query );
		foreach ( $results as $item ) {
			if ( $item instanceof DOMElement ) {
				$attr = $item->getAttribute( 'href' );
				if ( empty( $attr ) ) {
					$attr = $item->getAttribute( 'content' );
				}
				$temp_url = $attr;

				// URLを絶対URLに変換
				$temp_url = wpbc_convert_to_absolute_url( $temp_url, $base_url );

				// FaviconのURLが有効かチェック
				if ( wpbc_check_favicon_exists( $temp_url ) ) {
					return $temp_url;
				}
			}
		}
	}

	// ドメイン直下のFaviconを探す
	$default_favicons = array(
		wpbc_convert_to_absolute_url( '/favicon.svg', $base_url ),
		wpbc_convert_to_absolute_url( '/favicon.ico', $base_url ),
	);

	foreach ( $default_favicons as $favicon_url ) {
		if ( wpbc_check_favicon_exists( $favicon_url ) ) {
			return $favicon_url;
		}
	}

	return null;
}

/**
 * 相対URLを絶対URLに変換する
 */
function wpbc_convert_to_absolute_url( $url, $base_url ) {
	if ( preg_match( '/^https?:\/\//', $url ) ) {
		return $url;
	}

	$parts  = parse_url( $base_url );
	$scheme = $parts['scheme'];
	$host   = $parts['host'];

	if ( 0 === strpos( $url, '/' ) ) {
		return "$scheme://$host$url";
	}

	$base_path = isset( $parts['path'] ) ? dirname( $parts['path'] ) : '';
	return "$scheme://$host" . rtrim( $base_path, '/' ) . '/' . ltrim( $url, '/' );
}

/**
 * Faviconが存在するかチェックする
 */
function wpbc_check_favicon_exists( $url ) {
	// ローカル環境などにおいて `get_headers()` が失敗するケース（DNS解決やSSL周り）に対応するため、
	// まずは WordPress 標準の HTTP API (cURLベース) を用いて存在確認を試みる
	$response = wp_remote_head(
		$url,
		array(
			'timeout'     => 5,
			'redirection' => 5,
			'sslverify'   => false,
		)
	);

	if ( ! is_wp_error( $response ) ) {
		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 === $status_code ) {
			return true;
		}
	}

	// wp_remote_head が想定外のヘッダーを返したり失敗した場合は、従来の get_headers にフォールバックする
	$headers = @get_headers( $url, 1 );

	// ========= デバッグここから =========
	if ( strpos( $url, 'favicon.svg' ) !== false || strpos( $url, 'lovemacjp-2026-haku-child' ) !== false ) {
		error_log( 'wpbc_check_favicon_exists (' . $url . ') -> wp_remote_head status: ' . ( isset( $status_code ) ? $status_code : 'error' ) );
		error_log( 'wpbc_check_favicon_exists (' . $url . ') -> get_headers: ' . print_r( $headers, true ) );
	}
	// ========= デバッグここまで =========

	return $headers && false !== strpos( $headers[0], '200 OK' );
}

/**
 * サイト共通のFaviconを取得し、長期間キャッシュする
 */
function wpbc_get_site_favicon() {
	$cache_key   = 'wpbc_site_favicon';
	$favicon_url = get_transient( $cache_key );

	if ( false !== $favicon_url ) {
		return $favicon_url;
	}

	$home_url    = home_url();
	$favicon_url = '';

	// Home URL からメタタグをパースして取得を試みる
	$body = _wpbc_fetch_home_page_html();

	if ( ! empty( $body ) ) {
		$dom                   = new DOMDocument();
		$libxml_previous_state = libxml_use_internal_errors( true );
		$dom->loadHTML( mb_convert_encoding( $body, 'HTML-ENTITIES', 'UTF-8' ) );
		libxml_clear_errors();
		libxml_use_internal_errors( $libxml_previous_state );
		$xpath = new DOMXPath( $dom );

		$parsed_favicon = wpbc_get_favicon_url( $xpath, $home_url );
		if ( $parsed_favicon ) {
			$favicon_url = $parsed_favicon;
		}
	}

	// パースで取得できなかった場合は get_site_icon_url() を試す
	if ( empty( $favicon_url ) ) {
		$favicon_url = get_site_icon_url();
	}

	// 1年間保存
	set_transient( $cache_key, $favicon_url, YEAR_IN_SECONDS );

	return $favicon_url;
}

/**
 * サイト共通の OG Image を取得し、長期間キャッシュする
 */
function wpbc_get_site_og_image() {
	$cache_key    = 'wpbc_site_og_image';
	$og_image_url = get_transient( $cache_key );

	if ( false !== $og_image_url ) {
		return $og_image_url;
	}

	$og_image_url = '';

	$body = _wpbc_fetch_home_page_html();

	if ( ! empty( $body ) ) {
		$dom                   = new DOMDocument();
		$libxml_previous_state = libxml_use_internal_errors( true );
		$dom->loadHTML( mb_convert_encoding( $body, 'HTML-ENTITIES', 'UTF-8' ) );
		libxml_clear_errors();
		libxml_use_internal_errors( $libxml_previous_state );
		$xpath = new DOMXPath( $dom );

		$og_image = $xpath->query( '//meta[@property="og:image"]/@content' );
		if ( $og_image->length > 0 ) {
			$og_image_url = trim( $og_image->item( 0 )->textContent );
		}
	}

	// 1年間保存
	set_transient( $cache_key, $og_image_url, YEAR_IN_SECONDS );

	return $og_image_url;
}

/**
 * サイトの Home Page の HTML を取得し、同一リクエスト内でキャッシュする
 */
function _wpbc_fetch_home_page_html() {
	static $html_content = null;

	if ( null !== $html_content ) {
		return $html_content;
	}

	$home_url = home_url();
	$response = wp_remote_get(
		$home_url,
		array(
			'timeout'     => 10,
			'redirection' => 5,
			'sslverify'   => false,
			'user-agent'  => 'Mozilla/5.0 (WordPress/Blogcard-For-WP)',
		)
	);

	if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
		$html_content = wp_remote_retrieve_body( $response );
	} else {
		$html_content = ''; // エラー時は空文字をキャッシュして再試行を防ぐ
	}

	return $html_content;
}
