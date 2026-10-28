<?php
/**
 * 订阅者数据层：状态机、token、查询与清理。
 *
 * @package Post_Subscribe_Notifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 订阅者的增删改查。
 */
class PSN_Subscribers {

	const STATUS_PENDING      = 'pending';
	const STATUS_ACTIVE       = 'active';
	const STATUS_UNSUBSCRIBED = 'unsubscribed';
	const STATUS_BOUNCED      = 'bounced';

	/**
	 * 合法的状态值。
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array( self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_UNSUBSCRIBED, self::STATUS_BOUNCED );
	}

	/**
	 * 状态对应的界面文案。
	 *
	 * @param string $status 状态.
	 * @return string
	 */
	public static function status_label( $status ) {
		$map = array(
			self::STATUS_PENDING      => 'status_label_pending',
			self::STATUS_ACTIVE       => 'status_label_active',
			self::STATUS_UNSUBSCRIBED => 'status_label_unsubscribed',
			self::STATUS_BOUNCED      => 'status_label_bounced',
		);

		return psn_t( $map[ $status ] ?? 'status_label_pending' );
	}

	/**
	 * 按邮箱查一行。
	 *
	 * @param string $email 邮箱.
	 * @return object|null
	 */
	public static function find_by_email( $email ) {
		global $wpdb;

		$table = PSN_Install::subscribers_table();
		$email = strtolower( trim( (string) $email ) );

		if ( '' === $email ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s LIMIT 1", $email )
		);
	}

	/**
	 * 按 ID 查一行。
	 *
	 * @param int $id 订阅者 ID.
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;

		$table = PSN_Install::subscribers_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", (int) $id ) );
	}

	/**
	 * 按确认 token 查一行（只比对哈希）。
	 *
	 * @param string $token 明文 token.
	 * @return object|null
	 */
	public static function find_by_confirm_token( $token ) {
		global $wpdb;

		$token = (string) $token;
		if ( '' === $token || strlen( $token ) > 128 ) {
			return null;
		}

		$table = PSN_Install::subscribers_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE confirm_hash = %s LIMIT 1", self::hash( $token ) )
		);
	}

	/**
	 * 订阅者的退订 token。
	 *
	 * 用 id + 邮箱 + 站点 salt 派生，因此同一订阅者的退订链接永远稳定，
	 * 库里只保存它的哈希（revocable：把 unsub_hash 置空即可让旧链接失效）。
	 *
	 * @param object|int $row    订阅者行或 ID.
	 * @param string     $email  邮箱（传 ID 时必填）.
	 * @return string
	 */
	public static function unsub_token( $row, $email = '' ) {
		if ( is_object( $row ) ) {
			return self::unsub_token_for( (int) $row->id, (string) $row->email );
		}

		return self::unsub_token_for( (int) $row, (string) $email );
	}

	/**
	 * 退订 token 的实际派生。
	 *
	 * @param int    $id    订阅者 ID.
	 * @param string $email 邮箱.
	 * @return string
	 */
	private static function unsub_token_for( $id, $email ) {
		$material = 'psn_unsub|' . (int) $id . '|' . strtolower( (string) $email );

		return substr( hash_hmac( 'sha256', $material, wp_salt( 'auth' ) ), 0, 40 );
	}

	/**
	 * 按退订 token 查一行。
	 *
	 * @param int    $id    订阅者 ID.
	 * @param string $token 明文 token.
	 * @return object|null
	 */
	public static function find_by_unsub_token( $id, $token ) {
		$token = (string) $token;
		if ( '' === $token || strlen( $token ) > 128 ) {
			return null;
		}

		$row = self::find( (int) $id );
		if ( ! $row || empty( $row->unsub_hash ) ) {
			return null;
		}

		if ( ! hash_equals( (string) $row->unsub_hash, self::hash( $token ) ) ) {
			return null;
		}

		return $row;
	}

	/**
	 * token 的哈希（库中不存明文）。
	 *
	 * @param string $token 明文.
	 * @return string
	 */
	public static function hash( $token ) {
		return hash( 'sha256', (string) $token );
	}

	/**
	 * 生成随机 token。
	 *
	 * @return string
	 */
	public static function new_token() {
		return bin2hex( random_bytes( 20 ) );
	}

	/**
	 * 新建订阅者（pending）。
	 *
	 * @param array $args email/name/source/ip_hash.
	 * @return array{ok:bool,id:int,token:string,status:string}
	 */
	public static function create( $args ) {
		global $wpdb;

		$email = strtolower( trim( (string) ( $args['email'] ?? '' ) ) );
		if ( ! is_email( $email ) || strlen( $email ) > 254 ) {
			return array(
				'ok'     => false,
				'id'     => 0,
				'token'  => '',
				'status' => '',
			);
		}

		$table = PSN_Install::subscribers_table();
		$now   = PSN_Queue::now();

		$token   = self::new_token();
		$ttl     = (int) PSN_Settings::get()['confirm_ttl'];
		$expires = PSN_Queue::now( max( 1, $ttl ) * HOUR_IN_SECONDS );

		$name    = isset( $args['name'] ) ? sanitize_text_field( (string) $args['name'] ) : '';
		$name    = mb_substr( trim( $name ), 0, 100 );
		$source  = isset( $args['source'] ) ? sanitize_key( (string) $args['source'] ) : 'home_card';
		$ip_hash = isset( $args['ip_hash'] ) ? substr( (string) $args['ip_hash'], 0, 40 ) : '';

		$existing = self::find_by_email( $email );

		if ( $existing ) {
			// 已确认：不动，直接告知。
			if ( self::STATUS_ACTIVE === $existing->status ) {
				return array(
					'ok'     => true,
					'id'     => (int) $existing->id,
					'token'  => '',
					'status' => self::STATUS_ACTIVE,
				);
			}

			// 待确认 / 已退订 / 已退信：轮换确认 token 并回到 pending。
			$updated = $wpdb->update(
				$table,
				array(
					'status'          => self::STATUS_PENDING,
					'name'            => '' !== $name ? $name : $existing->name,
					'confirm_hash'    => self::hash( $token ),
					'unsub_hash'      => self::hash( self::unsub_token_for( (int) $existing->id, $email ) ),
					'token_expires'   => $expires,
					'source'          => $source,
					'ip_hash'         => $ip_hash,
					'fail_streak'     => 0,
					'confirmed_at'    => null,
					'unsubscribed_at' => null,
				),
				array( 'id' => (int) $existing->id ),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				return array(
					'ok'     => false,
					'id'     => 0,
					'token'  => '',
					'status' => '',
				);
			}

			return array(
				'ok'     => true,
				'id'     => (int) $existing->id,
				'token'  => $token,
				'status' => self::STATUS_PENDING,
			);
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'email'         => $email,
				'name'          => $name,
				'status'        => self::STATUS_PENDING,
				'confirm_hash'  => self::hash( $token ),
				'unsub_hash'    => '',
				'token_expires' => $expires,
				'source'        => $source,
				'ip_hash'       => $ip_hash,
				'fail_streak'   => 0,
				'created_at'    => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( ! $inserted ) {
			// 并发插入撞唯一键：退回重读一次。
			$again = self::find_by_email( $email );
			if ( $again && self::STATUS_PENDING === $again->status ) {
				return array(
					'ok'     => true,
					'id'     => (int) $again->id,
					'token'  => '',
					'status' => self::STATUS_PENDING,
				);
			}

			return array(
				'ok'     => false,
				'id'     => 0,
				'token'  => '',
				'status' => '',
			);
		}

		$id = (int) $wpdb->insert_id;

		// 退订 token 依赖 id，因此插入后再回填哈希。
		$wpdb->update(
			$table,
			array( 'unsub_hash' => self::hash( self::unsub_token_for( $id, $email ) ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		return array(
			'ok'     => true,
			'id'     => $id,
			'token'  => $token,
			'status' => self::STATUS_PENDING,
		);
	}

	/**
	 * 用确认 token 激活订阅。
	 *
	 * @param string $token 明文 token.
	 * @return array{ok:bool,reason:string,id:int}
	 */
	public static function confirm( $token ) {
		global $wpdb;

		$row = self::find_by_confirm_token( $token );

		if ( ! $row ) {
			return array(
				'ok'     => false,
				'reason' => 'unknown_token',
				'id'     => 0,
			);
		}

		$expires = strtotime( (string) $row->token_expires );
		if ( ! $expires || $expires < current_time( 'timestamp' ) ) {
			return array(
				'ok'     => false,
				'reason' => 'expired',
				'id'     => (int) $row->id,
			);
		}

		$table = PSN_Install::subscribers_table();

		// 确认成功后清空 confirm_hash：链接一次性。重复点击走 unknown_token。
		$updated = $wpdb->update(
			$table,
			array(
				'status'          => self::STATUS_ACTIVE,
				'confirmed_at'    => PSN_Queue::now(),
				'confirm_hash'    => null,
				'token_expires'   => null,
				'unsubscribed_at' => null,
			),
			array( 'id' => (int) $row->id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return array(
			'ok'     => false !== $updated,
			'reason' => false !== $updated ? 'confirmed' : 'error',
			'id'     => (int) $row->id,
		);
	}

	/**
	 * 退订（幂等）。token 无效时返回 false。
	 *
	 * @param int    $id    订阅者 ID.
	 * @param string $token 明文 token.
	 * @return bool
	 */
	public static function unsubscribe( $id, $token ) {
		global $wpdb;

		$row = self::find_by_unsub_token( $id, $token );
		if ( ! $row ) {
			return false;
		}

		if ( self::STATUS_UNSUBSCRIBED === $row->status ) {
			return true;
		}

		$table = PSN_Install::subscribers_table();

		$wpdb->update(
			$table,
			array(
				'status'          => self::STATUS_UNSUBSCRIBED,
				'unsubscribed_at' => PSN_Queue::now(),
			),
			array( 'id' => (int) $row->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return true;
	}

	/**
	 * 退订 token 对应的订阅者（用于二次确认页）。
	 *
	 * @param int    $id    订阅者 ID.
	 * @param string $token 明文 token.
	 * @return object|null
	 */
	public static function resolve_unsub_token( $id, $token ) {
		return self::find_by_unsub_token( $id, $token );
	}

	/**
	 * 所有 active 订阅者 ID（推送入队用）。
	 *
	 * @return int[]
	 */
	public static function active_ids() {
		global $wpdb;

		$table = PSN_Install::subscribers_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE status = %s ORDER BY id ASC", self::STATUS_ACTIVE )
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * 记录一次发送成功。
	 *
	 * @param int $id 订阅者 ID.
	 * @return void
	 */
	public static function mark_sent( $id ) {
		global $wpdb;

		$table = PSN_Install::subscribers_table();

		$wpdb->update(
			$table,
			array(
				'last_mail_at' => PSN_Queue::now(),
				'fail_streak'  => 0,
			),
			array( 'id' => (int) $id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * 记录一次发送失败；连续 3 次标为 bounced。
	 *
	 * @param int $id 订阅者 ID.
	 * @return string 更新后的状态
	 */
	public static function mark_failed( $id ) {
		global $wpdb;

		$table = PSN_Install::subscribers_table();
		$row   = self::find( $id );

		if ( ! $row ) {
			return '';
		}

		$streak = (int) $row->fail_streak + 1;
		$status = $row->status;

		if ( $streak >= 3 && self::STATUS_ACTIVE === $status ) {
			$status = self::STATUS_BOUNCED;
		}

		$wpdb->update(
			$table,
			array(
				'fail_streak' => $streak,
				'status'      => $status,
			),
			array( 'id' => (int) $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return $status;
	}

	/**
	 * 删除订阅者（连带其待发任务）。
	 *
	 * @param int[] $ids 订阅者 ID.
	 * @return int 删除条数
	 */
	public static function delete( $ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'intval', (array) $ids ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$table = PSN_Install::subscribers_table();
		$jobs  = PSN_Install::jobs_table();

		$in = implode( ',', $ids );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$jobs} WHERE type = 'mail' AND status = 'pending' AND target IN ({$in})" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$in})" );

		return (int) $deleted;
	}

	/**
	 * 过期未确认的订阅者转为 unsubscribed，避免长期占位。
	 *
	 * @return void
	 */
	public static function expire_stale_pending() {
		global $wpdb;

		$table = PSN_Install::subscribers_table();

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table}
				SET status = %s, confirm_hash = NULL, token_expires = NULL, unsubscribed_at = %s
				WHERE status = %s AND token_expires IS NOT NULL AND token_expires < %s AND created_at < %s",
				self::STATUS_UNSUBSCRIBED,
				PSN_Queue::now(),
				self::STATUS_PENDING,
				PSN_Queue::now(),
				PSN_Queue::now( -30 * DAY_IN_SECONDS )
			)
		);
	}

	/**
	 * 分页查询（后台列表用）。
	 *
	 * @param array $args status/search/page/per_page.
	 * @return array{items:object[],total:int}
	 */
	public static function query( $args = array() ) {
		global $wpdb;

		$table    = PSN_Install::subscribers_table();
		$status   = isset( $args['status'] ) ? (string) $args['status'] : '';
		$search   = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 20 ) ) );

		$where  = array( '1=1' );
		$params = array();

		if ( in_array( $status, self::statuses(), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		if ( '' !== $search ) {
			$where[]  = '(email LIKE %s OR name LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$offset    = ( $page - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

		$list_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$list     = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, $offset ) ) ) );

		return array(
			'items' => (array) $list,
			'total' => $total,
		);
	}

	/**
	 * 导出用：按筛选条件取全部行（不翻页）。
	 *
	 * @param array $args status/search.
	 * @return object[]
	 */
	public static function for_export( $args = array() ) {
		global $wpdb;

		$table  = PSN_Install::subscribers_table();
		$status = isset( $args['status'] ) ? (string) $args['status'] : '';
		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';

		$where  = array( '1=1' );
		$params = array();

		if ( in_array( $status, self::statuses(), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		if ( '' !== $search ) {
			$where[]  = '(email LIKE %s OR name LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (array) ( $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql ) );
	}

	/**
	 * 各状态计数（后台筛选下拉用）。
	 *
	 * @return array<string,int>
	 */
	public static function counts() {
		global $wpdb;

		$table = PSN_Install::subscribers_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status", ARRAY_A );

		$counts = array(
			'all' => 0,
		);
		foreach ( self::statuses() as $status ) {
			$counts[ $status ] = 0;
		}

		foreach ( (array) $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['c'];
			$counts['all']           += (int) $row['c'];
		}

		return $counts;
	}
}
