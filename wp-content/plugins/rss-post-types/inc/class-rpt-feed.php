<?php
/**
 * 把选定的内容类型写进 Feed 主查询。
 *
 * @package RSS_Post_Types
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feed 查询改造。
 */
class RPT_Feed {

	/**
	 * 挂在最后执行，保证其它主题/插件（例如 Sakurairo 的 pre_get_posts）不会覆盖本插件的结果。
	 */
	public static function init() {
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_feed_query' ), 99 );
	}

	/**
	 * @param WP_Query $query 当前查询。
	 * @return void
	 */
	public static function filter_feed_query( $query ) {
		if ( ! $query instanceof WP_Query ) {
			return;
		}

		if ( is_admin() || ! $query->is_main_query() || ! $query->is_feed() ) {
			return;
		}

		// 评论 Feed 保持原样（只输出评论）。
		if ( $query->is_comment_feed() ) {
			return;
		}

		// 请求里已经明确指定类型时（/feed/?post_type=shuoshuo、/shuoshuo/feed/）不干预。
		if ( '' !== self::requested_post_type() ) {
			return;
		}

		$settings = RPT_Options::get();
		$types    = RPT_Options::feed_post_types();

		// 没勾选任何类型时回落到 WordPress 默认行为（仅文章）。
		if ( empty( $types ) ) {
			return;
		}

		// 搜索结果 Feed 交给搜索逻辑自己处理，避免互相覆盖。
		if ( $query->is_search() ) {
			return;
		}

		if ( $query->is_archive() && empty( $settings['archives'] ) ) {
			return;
		}

		$query->set( 'post_type', $types );

		$exclude = self::excluded_ids( $types, $settings );
		if ( ! empty( $exclude ) ) {
			$existing = $query->get( 'post__not_in' );
			if ( ! is_array( $existing ) ) {
				$existing = empty( $existing ) ? array() : array( $existing );
			}
			$query->set( 'post__not_in', array_values( array_unique( array_merge( $existing, $exclude ) ) ) );
		}
	}

	/**
	 * 请求中显式声明的 post_type（取自 parse_request 的结果，不受其它 pre_get_posts 回调影响）。
	 *
	 * @return string
	 */
	private static function requested_post_type() {
		$value = '';

		if ( isset( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->query_vars['post_type'] ) ) {
			$value = $GLOBALS['wp']->query_vars['post_type'];
		}

		if ( is_array( $value ) ) {
			$value = implode( ',', array_map( 'strval', $value ) );
		}

		return trim( (string) $value );
	}

	/**
	 * 需要从 Feed 中排除的文章 ID。
	 *
	 * @param string[] $types    本次 Feed 的类型.
	 * @param array    $settings 插件设置.
	 * @return int[]
	 */
	private static function excluded_ids( $types, $settings ) {
		$exclude = array();

		if ( empty( $settings['skip_front'] ) || ! in_array( 'page', $types, true ) ) {
			return $exclude;
		}

		$front = (int) get_option( 'page_on_front' );
		$posts = (int) get_option( 'page_for_posts' );

		if ( $front > 0 ) {
			$exclude[] = $front;
		}
		if ( $posts > 0 && $posts !== $front ) {
			$exclude[] = $posts;
		}

		return $exclude;
	}
}
