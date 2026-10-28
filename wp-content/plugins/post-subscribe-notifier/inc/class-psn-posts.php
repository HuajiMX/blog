<?php
/**
 * 文章发布 → 邮件任务入队。
 *
 * 只在「状态真正变为 publish」时触发：草稿/待审/定时 → publish 会推，
 * publish → publish（更新已发布文章）默认不推。
 *
 * @package Post_Subscribe_Notifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 文章侧的事件挂载。
 */
class PSN_Posts {

	/**
	 * 视为「首次发布」的旧状态。
	 *
	 * @return string[]
	 */
	private static function first_publish_states() {
		return array( 'draft', 'pending', 'future', 'auto-draft', 'new', '' );
	}

	/**
	 * 挂载钩子。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 20, 3 );
	}

	/**
	 * 发布状态变化。
	 *
	 * @param string  $new_status 新状态.
	 * @param string  $old_status 旧状态.
	 * @param WP_Post $post       文章.
	 * @return void
	 */
	public static function on_transition( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status ) {
			// 发布后又撤回：把还没发出的任务取消掉。
			if ( $post instanceof WP_Post ) {
				$was_published = in_array( (string) $old_status, array( 'publish', 'private' ), true );
				if ( $was_published && 'publish' !== $new_status ) {
					PSN_Queue::cancel_mail_for_post( (int) $post->ID );
				}
			}

			return;
		}

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$settings = PSN_Settings::get();

		if ( empty( $settings['mail_enabled'] ) ) {
			return;
		}

		$is_republish = 'publish' === $old_status;

		if ( $is_republish && empty( $settings['republish'] ) ) {
			return;
		}

		if ( ! $is_republish && ! in_array( (string) $old_status, self::first_publish_states(), true ) ) {
			return;
		}

		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, PSN_Settings::post_types(), true ) ) {
			return;
		}

		if ( post_password_required( $post ) ) {
			return;
		}

		// 同一篇只推一次；显式开启「重新发布也推」时跳过该限制。
		if ( ! $is_republish ) {
			$notified = (int) get_post_meta( $post->ID, 'psn_notified_at', true );
			if ( $notified > 0 ) {
				return;
			}
		}

		/**
		 * 过滤是否为一篇文章触发推送。
		 *
		 * @param bool    $should     是否推送.
		 * @param WP_Post $post       文章.
		 * @param string  $old_status 旧状态.
		 */
		$should = (bool) apply_filters( 'psn_should_notify_post', true, $post, $old_status );
		if ( ! $should ) {
			return;
		}

		$subscribers = PSN_Subscribers::active_ids();

		if ( empty( $subscribers ) ) {
			update_post_meta( $post->ID, 'psn_notified_at', current_time( 'timestamp' ) );

			return;
		}

		$added = PSN_Queue::enqueue_mail( $post->ID, $subscribers );

		update_post_meta( $post->ID, 'psn_notified_at', current_time( 'timestamp' ) );

		/**
		 * 一篇新文章完成入队后触发。
		 *
		 * @param int      $post_id     文章 ID.
		 * @param int      $added       新增任务数.
		 * @param int[]    $subscribers 订阅者 ID.
		 */
		do_action( 'psn_post_enqueued', (int) $post->ID, (int) $added, $subscribers );
	}
}
