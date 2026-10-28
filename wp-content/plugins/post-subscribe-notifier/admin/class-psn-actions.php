<?php
/**
 * 后台动作（删除订阅者、CSV 导出、日志重发）。
 *
 * 删除与导出挂在 load-{page} 上：这时后台页头还没输出，302 与文件下载都能正常发头，
 * 同时可以直接复用 WP_List_Table 自带的 action 下拉（它会覆盖表单里同名的隐藏字段，
 * 所以不能靠隐藏字段派发）。
 *
 * @package Post_Subscribe_Notifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 动作处理。
 */
class PSN_Actions {

	const ACTION_EXPORT = 'psn_export';
	const ACTION_RESEND = 'psn_resend';

	/**
	 * 挂载钩子。
	 *
	 * 用 admin_init 而不是 load-{hook}：本页是自定义顶级菜单下的子页，
	 * load- 钩子名会随菜单结构变化；admin_init 一定在页头输出之前触发，
	 * 302 与文件下载都能正常发头。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'handle_subscriber_actions' ) );
		add_action( 'admin_post_' . self::ACTION_RESEND, array( __CLASS__, 'handle_resend' ) );
	}

	/**
	 * 订阅者列表页地址。
	 *
	 * @param array $args 额外参数.
	 * @return string
	 */
	public static function subscribers_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => PSN_Admin::PAGE_SUBSCRIBERS ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * 日志页地址。
	 *
	 * @param array $args 额外参数.
	 * @return string
	 */
	public static function jobs_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => PSN_Admin::PAGE_JOBS ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * 当前列表的筛选条件（回跳时原样带上）。
	 *
	 * @param string[] $allowed 允许的键.
	 * @return array
	 */
	private static function keep_filters( $allowed ) {
		$keep = array();

		foreach ( $allowed as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只透传筛选条件。
			if ( isset( $_REQUEST[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$keep[ $key ] = sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) );
			}
		}

		return $keep;
	}

	/**
	 * 订阅者页的动作：删除 / 导出。
	 *
	 * @return void
	 */
	public static function handle_subscriber_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 下面每个分支都校验 nonce。
		$page = isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : '';

		if ( PSN_Admin::PAGE_SUBSCRIBERS !== $page ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		if ( self::ACTION_EXPORT === $action ) {
			self::export_csv();

			return;
		}

		if ( 'delete' !== $action ) {
			return;
		}

		check_admin_referer( 'bulk-psn-subscribers' );

		if ( ! isset( $_REQUEST['id'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 已过 nonce 校验。
		$ids = array_map( 'intval', (array) wp_unslash( $_REQUEST['id'] ) );

		$deleted = PSN_Subscribers::delete( $ids );

		self::redirect(
			self::subscribers_url(
				array_merge(
					self::keep_filters( array( 'status', 's' ) ),
					array( 'psn_deleted' => $deleted )
				)
			)
		);
	}

	/**
	 * CSV 导出。
	 *
	 * @return void
	 */
	private static function export_csv() {
		check_admin_referer( 'psn_export' );

		$filters = self::keep_filters( array( 'status', 's' ) );
		$rows    = PSN_Subscribers::for_export( $filters );

		$filename = 'psn-subscribers-' . current_time( 'Ymd-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$output = fopen( 'php://output', 'w' );

		// UTF-8 BOM，Excel 才不会把中文显示成乱码。
		fwrite( $output, "\xEF\xBB\xBF" );

		fputcsv( $output, array( 'email', 'name', 'status', 'source', 'created_at', 'confirmed_at', 'unsubscribed_at', 'last_mail_at' ) );

		foreach ( $rows as $row ) {
			fputcsv(
				$output,
				array(
					self::csv_cell( $row->email ),
					self::csv_cell( $row->name ),
					self::csv_cell( $row->status ),
					self::csv_cell( $row->source ),
					self::csv_cell( $row->created_at ),
					self::csv_cell( $row->confirmed_at ),
					self::csv_cell( $row->unsubscribed_at ),
					self::csv_cell( $row->last_mail_at ),
				)
			);
		}

		fclose( $output );
		exit;
	}

	/**
	 * 日志重发。
	 *
	 * @return void
	 */
	public static function handle_resend() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'post-subscribe-notifier' ), 403 );
		}

		check_admin_referer( self::ACTION_RESEND );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 已过 nonce 校验。
		$job_id = isset( $_REQUEST['job'] ) ? (int) $_REQUEST['job'] : 0;

		$ok = PSN_Queue::requeue( $job_id );

		self::redirect(
			self::jobs_url(
				array_merge(
					self::keep_filters( array( 'type', 'status' ) ),
					array( 'psn_resent' => $ok ? 1 : 0 )
				)
			)
		);
	}

	/**
	 * 防公式注入：以 = + - @ 开头的值前置单引号。
	 *
	 * @param mixed $value 值.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		$value = (string) $value;

		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * 302 回跳。
	 *
	 * @param string $url 目标.
	 * @return void
	 */
	private static function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}
}
