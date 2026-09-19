<?php
/**
 * 选项读写、可用类型枚举与缓存清理。
 *
 * @package RSS_Post_Types
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 插件选项。
 */
class RPT_Options {

	/**
	 * 默认设置。
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'post_types' => array( 'post' ),
			'archives'   => 1,
			'skip_front' => 1,
		);
	}

	/**
	 * 读取设置（补齐默认值并做一次规范化）。
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( RPT_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$settings = wp_parse_args( $saved, self::defaults() );

		if ( ! is_array( $settings['post_types'] ) ) {
			$settings['post_types'] = array();
		}
		$settings['post_types'] = array_values( array_filter( array_map( 'strval', $settings['post_types'] ) ) );
		$settings['archives']   = empty( $settings['archives'] ) ? 0 : 1;
		$settings['skip_front'] = empty( $settings['skip_front'] ) ? 0 : 1;

		return $settings;
	}

	/**
	 * 所有可供订阅的公开内容类型。
	 *
	 * @return array slug => WP_Post_Type
	 */
	public static function available_post_types() {
		$types = get_post_types( array( 'publicly_queryable' => true ), 'objects' );

		if ( ! is_array( $types ) ) {
			return array();
		}

		/**
		 * 过滤后台可选的内容类型列表。
		 *
		 * @param array $types slug => WP_Post_Type
		 */
		$types = apply_filters( 'rpt_available_post_types', $types );

		// 文章、页面排在最前，其余按名称排序。
		$order = array( 'post' => 0, 'page' => 1 );
		uksort(
			$types,
			static function ( $a, $b ) use ( $order ) {
				$oa = isset( $order[ $a ] ) ? $order[ $a ] : 10;
				$ob = isset( $order[ $b ] ) ? $order[ $b ] : 10;
				if ( $oa === $ob ) {
					return strcasecmp( $a, $b );
				}
				return $oa < $ob ? -1 : 1;
			}
		);

		return $types;
	}

	/**
	 * 最终进入 Feed 的内容类型。主题/插件可用 rpt_feed_post_types 覆盖。
	 *
	 * @return string[]
	 */
	public static function feed_post_types() {
		$settings  = self::get();
		$available = array_keys( self::available_post_types() );
		$types     = array_values( array_intersect( $settings['post_types'], $available ) );

		/**
		 * 过滤真正写入 Feed 查询的内容类型。
		 *
		 * @param string[] $types    内容类型标识.
		 * @param array    $settings 插件设置.
		 */
		return apply_filters( 'rpt_feed_post_types', $types, $settings );
	}

	/**
	 * 保存时的清洗。
	 *
	 * @param mixed $input 表单原始数据.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$available = array_keys( self::available_post_types() );
		$output    = self::defaults();

		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$picked = array();
		if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			foreach ( $input['post_types'] as $type ) {
				if ( is_scalar( $type ) ) {
					$picked[] = sanitize_key( (string) $type );
				}
			}
		}
		$output['post_types'] = array_values( array_intersect( array_unique( $picked ), $available ) );
		$output['archives']   = empty( $input['archives'] ) ? 0 : 1;
		$output['skip_front'] = empty( $input['skip_front'] ) ? 0 : 1;

		return $output;
	}

	/**
	 * 启用插件时写入默认值。
	 *
	 * @return void
	 */
	public static function on_activate() {
		if ( false === get_option( RPT_OPTION, false ) ) {
			add_option( RPT_OPTION, self::defaults() );
		}

		self::flush_caches();
	}

	/**
	 * 设置变更后清理各类缓存，避免订阅源仍是旧内容。
	 *
	 * @return void
	 */
	public static function flush_caches() {
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		// WP Super Cache.
		if ( function_exists( 'wp_cache_clean_cache' ) ) {
			global $file_prefix;
			wp_cache_clean_cache( isset( $file_prefix ) ? $file_prefix : 'wp-cache-', true );
		}

		// WP Rocket.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		// LiteSpeed Cache.
		foreach ( array( 'litespeed_purge_all', 'litespeed_control_flush_all' ) as $hook ) {
			if ( has_action( $hook ) ) {
				do_action( $hook );
			}
		}

		// WP Fastest Cache.
		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			wpfc_clear_all_cache( true );
		}
	}
}

add_action( 'update_option_' . RPT_OPTION, array( 'RPT_Options', 'flush_caches' ), 10, 0 );
add_action( 'add_option_' . RPT_OPTION, array( 'RPT_Options', 'flush_caches' ), 10, 0 );
