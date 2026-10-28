<?php
/**
 * 推送日志表格：筛选、查看载荷、手动重发。
 *
 * @package Post_Subscribe_Notifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * 任务 WP_List_Table。
 */
class PSN_Jobs_Table extends WP_List_Table {

	const PER_PAGE = 30;
	const NONCE    = 'psn_job_action';

	/**
	 * 构造。
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'psn-job',
				'plural'   => 'psn-jobs',
				'ajax'     => false,
			)
		);
	}

	/**
	 * 列定义。
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'id'            => 'ID',
			'type'          => psn_t( 'col_type' ),
			'target'        => psn_t( 'col_target' ),
			'status'        => psn_t( 'col_status' ),
			'attempts'      => psn_t( 'col_attempts' ),
			'response_code' => psn_t( 'col_response' ),
			'available_at'  => psn_t( 'col_available' ),
			'updated_at'    => psn_t( 'col_created' ),
			'last_error'    => psn_t( 'col_error' ),
			'actions'       => psn_t( 'col_actions' ),
		);
	}

	/**
	 * 顶部筛选。
	 *
	 * @return array
	 */
	protected function get_views() {
		$base   = $this->base_url();
		$current = $this->filter_type();

		$types = array(
			''        => '全部',
			'mail'    => psn_t( 'type_mail' ),
			'webhook' => psn_t( 'type_webhook' ),
		);

		$views = array();
		foreach ( $types as $key => $label ) {
			$url = '' === $key ? $base : add_query_arg( 'type', $key, $base );
			$views[ 'type_' . ( '' === $key ? 'all' : $key ) ] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				$current === $key ? 'current' : '',
				esc_html( $label ),
				(int) $this->count_for( $key )
			);
		}

		$statuses = array(
			''        => '全部状态',
			'pending' => psn_t( 'job_pending' ),
			'done'    => psn_t( 'job_done' ),
			'failed'  => psn_t( 'job_failed' ),
		);
		$current_status = $this->filter_status();

		foreach ( $statuses as $key => $label ) {
			$url = '' === $key ? $base : add_query_arg( 'status', $key, $base );
			$views[ 'status_' . ( '' === $key ? 'all' : $key ) ] = sprintf(
				'<a href="%s" class="%s">%s</a>',
				esc_url( $url ),
				$current_status === $key ? 'current' : '',
				esc_html( $label )
			);
		}

		return $views;
	}

	/**
	 * 列表基础地址。
	 *
	 * @return string
	 */
	private function base_url() {
		return admin_url( 'admin.php?page=' . PSN_Admin::PAGE_JOBS );
	}

	/**
	 * 当前类型筛选。
	 *
	 * @return string
	 */
	private function filter_type() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';

		return in_array( $type, array( 'mail', 'webhook' ), true ) ? $type : '';
	}

	/**
	 * 当前状态筛选。
	 *
	 * @return string
	 */
	private function filter_status() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		return in_array( $status, array( 'pending', 'done', 'failed', 'cancelled' ), true ) ? $status : '';
	}

	/**
	 * 各类型的任务总数。
	 *
	 * @param string $type 类型.
	 * @return int
	 */
	private function count_for( $type ) {
		global $wpdb;

		$table = PSN_Install::jobs_table();

		if ( '' === $type ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		return PSN_Queue::count( $type, 'pending' ) + PSN_Queue::count( $type, 'done' ) + PSN_Queue::count( $type, 'failed' ) + PSN_Queue::count( $type, 'cancelled' );
	}

	/**
	 * 准备数据。
	 *
	/**
	 * 可排序的列（界面不显示箭头，SQL 固定按 id DESC）。
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array();
	}

	/**
	 * 表格样式类。
	 *
	 * @return array
	 */
	protected function get_table_classes() {
		return array( 'wp-list-table', 'widefat', 'striped', 'table-view-list', 'psn-jobs' );
	}

	/**
	 * 准备数据。
	 *
	 * 重发走 admin-post.php（见 PSN_Actions），这里只负责读。
	 *
	 * @return void
	 */
	public function prepare_items() {
		global $wpdb;

		$table    = PSN_Install::jobs_table();
		$type     = $this->filter_type();
		$status   = $this->filter_status();
		$page     = $this->get_pagenum();
		$per_page = self::PER_PAGE;

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $type ) {
			$where[]  = 'type = %s';
			$params[] = $type;
		}

		if ( '' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

		$list_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$items    = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ) );

		$this->items           = (array) $items;
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
	}

	/**
	 * 默认列。
	 *
	 * @param object $item 行.
	 * @param string $column_name 列.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'id':
				return (string) (int) $item->id;
			case 'type':
				return esc_html( 'mail' === $item->type ? psn_t( 'type_mail' ) : psn_t( 'type_webhook' ) );
			case 'target':
				return esc_html( $this->describe_target( $item ) );
			case 'status':
				$map = array(
					'pending'   => array( 'job_pending', 'psn-badge-pending' ),
					'done'      => array( 'job_done', 'psn-badge-active' ),
					'failed'    => array( 'job_failed', 'psn-badge-bounced' ),
					'cancelled' => array( 'job_cancelled', 'psn-badge-unsubscribed' ),
				);
				$meta = $map[ $item->status ] ?? array( 'job_pending', '' );

				return sprintf( '<span class="psn-badge %s">%s</span>', esc_attr( $meta[1] ), esc_html( psn_t( $meta[0] ) ) );
			case 'attempts':
				return (string) (int) $item->attempts;
			case 'response_code':
				return null === $item->response_code ? '—' : (string) (int) $item->response_code;
			case 'available_at':
				return esc_html( (string) $item->available_at );
			case 'updated_at':
				return esc_html( (string) $item->updated_at );
			case 'last_error':
				return '<span class="psn-error">' . esc_html( mb_substr( (string) $item->last_error, 0, 120 ) ) . '</span>';
		}

		return '';
	}

	/**
	 * 对象的人类可读描述。
	 *
	 * @param object $item 行.
	 * @return string
	 */
	private function describe_target( $item ) {
		if ( 'mail' === $item->type ) {
			$post = get_post( (int) $item->ref );
			$title = $post ? get_the_title( $post ) : '#' . (int) $item->ref;

			return sprintf(
				'%s #%d · %s',
				psn_t( 'job_subscriber_label' ),
				(int) $item->target
			) . ' · ' . $title;
		}

		$comment = get_comment( (int) $item->ref );

		if ( ! $comment ) {
			return psn_t( 'job_comment_label' ) . ' #' . (int) $item->ref;
		}

		return sprintf(
			'%s #%d · %s',
			psn_t( 'job_comment_label' ),
			(int) $item->ref,
			mb_substr( PSN_Webhook::excerpt( (string) $comment->comment_content, 40 ), 0, 60 )
		);
	}

	/**
	 * 操作列。
	 *
	 * @param object $item 行.
	 * @return string
	 */
	public function column_actions( $item ) {
		$links = array();

		$resend_url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => PSN_Actions::ACTION_RESEND,
					'job'    => (int) $item->id,
				),
				admin_url( 'admin-post.php' )
			),
			PSN_Actions::ACTION_RESEND
		);

		$links[] = sprintf( '<a href="%s">%s</a>', esc_url( $resend_url ), esc_html( psn_t( 'action_resend' ) ) );

		if ( 'webhook' === $item->type && ! empty( $item->payload ) ) {
			$payload = json_decode( (string) $item->payload, true );
			if ( is_array( $payload ) ) {
				$links[] = sprintf(
					'<details><summary>%s</summary><pre class="psn-payload">%s</pre></details>',
					esc_html( psn_t( 'action_view' ) ),
					esc_html( (string) wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) )
				);
			}
		}

		return implode( '<br />', $links );
	}

	/**
	 * 空状态。
	 *
	 * @return void
	 */
	public function no_items() {
		echo esc_html( psn_t( 'empty_jobs' ) );
	}

	/**
	 * 操作结果提示。
	 *
	 * @return void
	 */
	public function render_notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['psn_resent'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ok = (int) $_GET['psn_resent'];

		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			$ok ? 'notice-success' : 'notice-warning',
			esc_html( $ok ? psn_t( 'resend_done' ) : '任务不存在或已处于待发送状态。' )
		);
	}
}
