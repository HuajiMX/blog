<?php
/**
 * 订阅者列表表格：筛选、搜索、删除、CSV 导出。
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
 * 订阅者 WP_List_Table。
 */
class PSN_Subscribers_Table extends WP_List_Table {

	const PER_PAGE = 20;
	const NONCE    = 'psn_delete_subscribers';

	/**
	 * 构造。
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'psn-subscriber',
				'plural'   => 'psn-subscribers',
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
			'cb'         => '<input type="checkbox" />',
			'email'      => psn_t( 'col_email' ),
			'name'       => psn_t( 'col_name' ),
			'status'     => psn_t( 'col_status' ),
			'source'     => psn_t( 'col_source' ),
			'created_at' => psn_t( 'col_created' ),
			'confirmed_at' => psn_t( 'col_confirmed' ),
			'last_mail_at' => psn_t( 'col_last_mail' ),
		);
	}

	/**
	 * 可排序的列。只声明界面上的排序指示，实际排序固定在 SQL 里。
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array();
	}

	/**
	 * 定义支持排序的列（界面不显示排序箭头，SQL 固定按 id DESC）。
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'delete' => '删除',
		);
	}

	/**
	 * 表格样式类。不用 fixed：邮箱列在固定布局下会被挤成多行。
	 *
	 * @return array
	 */
	protected function get_table_classes() {
		return array( 'wp-list-table', 'widefat', 'striped', 'table-view-list', 'psn-subscribers' );
	}

	/**
	 * 顶部筛选链接。
	 *
	 * @return array
	 */
	protected function get_views() {
		$counts = PSN_Subscribers::counts();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$views   = array();

		$views['all'] = sprintf(
			'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
			esc_url( $this->base_url() ),
			'' === $current ? 'current' : '',
			esc_html( psn_t( 'filter_all' ) ),
			(int) $counts['all']
		);

		foreach ( PSN_Subscribers::statuses() as $status ) {
			$views[ $status ] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'status', $status, $this->base_url() ) ),
				$current === $status ? 'current' : '',
				esc_html( PSN_Subscribers::status_label( $status ) ),
				(int) $counts[ $status ]
			);
		}

		return $views;
	}

	/**
	 * 列表页基础地址。
	 *
	 * @return string
	 */
	private function base_url() {
		return admin_url( 'admin.php?page=' . PSN_Admin::PAGE_SUBSCRIBERS );
	}

	/**
	 * 当前筛选条件。
	 *
	 * @return array
	 */
	private function filters() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		return array(
			'status' => in_array( $status, PSN_Subscribers::statuses(), true ) ? $status : '',
			'search' => $search,
		);
	}

	/**
	 * 准备数据。
	 *
	 * 删除与导出走 admin-post.php（见 PSN_Actions），这里只负责读。
	 *
	 * @return void
	 */
	public function prepare_items() {
		$filters  = $this->filters();
		$page     = $this->get_pagenum();
		$per_page = self::PER_PAGE;

		$result = PSN_Subscribers::query(
			array(
				'status'   => $filters['status'],
				'search'   => $filters['search'],
				'page'     => $page,
				'per_page' => $per_page,
			)
		);

		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);
	}

	/**
	 * 勾选列。
	 *
	 * @param object $item 行.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="id[]" value="%d" />', (int) $item->id );
	}

	/**
	 * 邮箱列（含行内删除）。
	 *
	 * 删除链接走 WP 标准的 bulk 动作：action=delete + id[]=N + _wpnonce=bulk-psn-subscribers。
	 *
	 * @param object $item 行.
	 * @return string
	 */
	public function column_email( $item ) {
		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => PSN_Admin::PAGE_SUBSCRIBERS,
					'action' => 'delete',
					'id'     => array( (int) $item->id ),
				),
				admin_url( 'admin.php' )
			),
			'bulk-psn-subscribers'
		);

		$actions = array(
			'delete' => sprintf( '<a href="%s" onclick="return confirm(\'确定删除这个订阅者？\');">删除</a>', esc_url( $delete_url ) ),
		);

		return sprintf(
			'<strong>%s</strong>%s',
			esc_html( $item->email ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * 默认列输出。
	 *
	 * @param object $item 行.
	 * @param string $column_name 列.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'name':
				return esc_html( (string) $item->name );
			case 'status':
				return sprintf(
					'<span class="psn-badge psn-badge-%s">%s</span>',
					esc_attr( $item->status ),
					esc_html( PSN_Subscribers::status_label( $item->status ) )
				);
			case 'source':
				$map = array(
					'home_card' => 'source_home_card',
					'shortcode' => 'source_shortcode',
					'test'      => 'source_test',
				);
				return esc_html( psn_t( $map[ $item->source ] ?? 'source_home_card' ) );
			case 'created_at':
			case 'confirmed_at':
			case 'last_mail_at':
				return esc_html( (string) $item->$column_name );
		}

		return '';
	}

	/**
	 * 空状态。
	 *
	 * @return void
	 */
	public function no_items() {
		echo esc_html( psn_t( 'empty_subscribers' ) );
	}

	/**
	 * 导出地址（沿用当前筛选条件）。
	 *
	 * @return string
	 */
	public static function export_url() {
		$args = array(
			'page'     => PSN_Admin::PAGE_SUBSCRIBERS,
			'action'   => PSN_Actions::ACTION_EXPORT,
			'_wpnonce' => wp_create_nonce( 'psn_export' ),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只透传筛选条件。
		foreach ( array( 'status', 's' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * 操作结果提示。
	 *
	 * @return void
	 */
	public function render_notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$deleted = isset( $_GET['psn_deleted'] ) ? (int) $_GET['psn_deleted'] : -1;

		if ( $deleted >= 0 ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf( '已删除 %d 条订阅记录。', $deleted ) )
			);
		}
	}
}
