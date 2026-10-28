<?php
/**
 * 评论 → WebHook 入队。
 *
 * 关键点：wp_insert_comment() 不会触发 comment_post，而段评插件正是直接调用它，
 * 所以这里同时挂 wp_insert_comment（新评论）与 transition_comment_status（审核通过）。
 *
 * @package Post_Subscribe_Notifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 评论侧的事件挂载。
 */
class PSN_Comments {

	/**
	 * 挂载钩子。
	 *
	 * @return void
	 */
	public static function init() {
		// 优先级 20：晚于主题 sql_insert_qq_field(10) 写入 QQ meta。
		add_action( 'wp_insert_comment', array( __CLASS__, 'on_insert' ), 20, 2 );
		add_action( 'transition_comment_status', array( __CLASS__, 'on_transition' ), 10, 3 );
		add_action( 'edit_comment', array( __CLASS__, 'on_edit' ), 10, 2 );
	}

	/**
	 * 新评论入库。
	 *
	 * @param int        $comment_id 评论 ID.
	 * @param WP_Comment $comment    评论对象.
	 * @return void
	 */
	public static function on_insert( $comment_id, $comment ) {
		$settings = PSN_Settings::get();

		if ( empty( $settings['webhook_enabled'] ) ) {
			return;
		}

		$comment = $comment instanceof WP_Comment ? $comment : get_comment( $comment_id );
		if ( ! $comment ) {
			return;
		}

		$approved = (int) $comment->comment_approved === 1;

		// 未审核通过：默认不推；只有显式开启「待审核也推」才入队。
		if ( ! $approved && empty( $settings['notify_pending'] ) ) {
			return;
		}

		if ( ! PSN_Webhook::should_notify( $comment, $approved ? 'comment.approved' : 'comment.new' ) ) {
			return;
		}

		PSN_Queue::enqueue_webhook( (int) $comment->comment_ID, $approved ? 'comment.approved' : 'comment.new' );
	}

	/**
	 * 审核状态变化。
	 *
	 * @param string     $new_status 新状态.
	 * @param string     $old_status 旧状态.
	 * @param WP_Comment $comment    评论.
	 * @return void
	 */
	public static function on_transition( $new_status, $old_status, $comment ) {
		if ( $new_status === $old_status ) {
			return;
		}

		$settings = PSN_Settings::get();
		if ( empty( $settings['webhook_enabled'] ) ) {
			return;
		}

		// 核心在不同路径上会给出 'approved' / 'approve' / '1'。
		$is_approved = in_array( (string) $new_status, array( 'approved', 'approve', '1' ), true );

		if ( ! $is_approved ) {
			return;
		}

		$comment = $comment instanceof WP_Comment ? $comment : get_comment( (int) ( $comment->comment_ID ?? 0 ) );
		if ( ! $comment ) {
			return;
		}

		if ( ! PSN_Webhook::should_notify( $comment, 'comment.approved' ) ) {
			return;
		}

		PSN_Queue::enqueue_webhook( (int) $comment->comment_ID, 'comment.approved' );
	}

	/**
	 * 后台编辑评论后（内容被改、或状态被改成通过）。
	 *
	 * @param int   $comment_id 评论 ID.
	 * @param array $data       提交的数据.
	 * @return void
	 */
	public static function on_edit( $comment_id, $data = array() ) {
		$settings = PSN_Settings::get();
		if ( empty( $settings['webhook_enabled'] ) ) {
			return;
		}

		$comment = get_comment( $comment_id );
		if ( ! $comment || (int) $comment->comment_approved !== 1 ) {
			return;
		}

		// 已经有推送记录就不再重复入队（唯一键本身也能挡住）。
		if ( PSN_Queue::count_webhook_for_comment( (int) $comment_id ) > 0 ) {
			return;
		}

		if ( ! PSN_Webhook::should_notify( $comment, 'comment.approved' ) ) {
			return;
		}

		PSN_Queue::enqueue_webhook( (int) $comment_id, 'comment.approved' );
	}
}
