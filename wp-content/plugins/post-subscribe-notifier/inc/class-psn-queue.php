<?php
/**
 * 推送队列：入队、抢占、成功/失败/重试、取消与清理。
 *
 * 时区约定：所有 datetime 列都写 WordPress 本地时间（current_time('mysql')），
 * 比较也在 PHP 里算好后作为参数传入，避免 MySQL NOW() 与站点时区不一致。
 *
 * @package Post_Subscribe_Notifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 队列读写。
 */
class PSN_Queue {

	const TYPE_MAIL    = 'mail';
	const TYPE_WEBHOOK = 'webhook';

	const STATUS_PENDING   = 'pending';
	const STATUS_DONE      = 'done';
	const STATUS_FAILED    = 'failed';
	const STATUS_CANCELLED = 'cancelled';

	/**
	 * 抢占锁的最长持有时间（秒）。
	 */
	const LOCK_SECONDS = 120;

	/**
	 * 当前本地时间（mysql 格式）。
	 *
	 * @param int $offset 相对现在偏移的秒数.
	 * @return string
	 */
	public static function now( $offset = 0 ) {
		$timestamp = current_time( 'timestamp' ) + (int) $offset;

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * 邮件任务批量入队。已存在的 (type, ref, target) 会因唯一键被忽略。
	 *
	 * @param int   $post_id        文章 ID.
	 * @param int[] $subscriber_ids 订阅者 ID 列表.
	 * @return int 实际新增行数
	 */
	public static function enqueue_mail( $post_id, $subscriber_ids ) {
		global $wpdb;

		$post_id = (int) $post_id;
		if ( $post_id <= 0 || empty( $subscriber_ids ) ) {
			return 0;
		}

		$table = PSN_Install::jobs_table();
		$now   = self::now();
		$added = 0;

		foreach ( array_chunk( array_map( 'intval', $subscriber_ids ), 200 ) as $chunk ) {
			$placeholders = array();
			$values       = array();

			foreach ( $chunk as $subscriber_id ) {
				$placeholders[] = "('%s', %d, '%s', '%s', %d, %s, %s, %s)";
				$values[]       = self::TYPE_MAIL;
				$values[]       = $post_id;
				$values[]       = (string) $subscriber_id;
				$values[]       = self::STATUS_PENDING;
				$values[]       = 0;
				$values[]       = $now;
				$values[]       = $now;
				$values[]       = $now;
			}

			// 列顺序：type, ref, target, status, attempts, available_at, created_at, updated_at
			$sql = "INSERT IGNORE INTO {$table} (type, ref, target, status, attempts, available_at, created_at, updated_at) VALUES " . implode( ', ', $placeholders );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 占位符在循环内拼装，值全部走 prepare。
			$prepared = $wpdb->prepare( $sql, $values );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->query( $prepared );

			if ( $result > 0 ) {
				$added += (int) $result;
			}
		}

		if ( $added > 0 ) {
			PSN_Cron::schedule_tick_soon();
		}

		return $added;
	}

	/**
	 * 一条评论的 WebHook 任务入队。
	 *
	 * @param int         $comment_id 评论 ID.
	 * @param string|null $event      事件名，默认按评论状态推断.
	 * @return int 新增行数（0 表示已入过队）
	 */
	public static function enqueue_webhook( $comment_id, $event = null ) {
		global $wpdb;

		$comment_id = (int) $comment_id;
		if ( $comment_id <= 0 ) {
			return 0;
		}

		$table = PSN_Install::jobs_table();
		$now   = self::now();

		$payload = array( 'event' => $event ? (string) $event : '' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (type, ref, target, status, payload, attempts, available_at, created_at, updated_at)
				VALUES (%s, %d, '', %s, %s, 0, %s, %s, %s)",
				self::TYPE_WEBHOOK,
				$comment_id,
				self::STATUS_PENDING,
				wp_json_encode( $payload ),
				$now,
				$now,
				$now
			)
		);

		$added = (int) $result;
		if ( $added > 0 ) {
			PSN_Cron::schedule_tick_soon();
		}

		return $added;
	}

	/**
	 * 抢占下一条可执行任务。
	 *
	 * 用「条件更新 + 影响行数校验」实现锁：多进程同时跑 cron 时只有一个能拿到。
	 *
	 * @param string $type 任务类型.
	 * @param string $now  当前时间.
	 * @return object|null
	 */
	public static function claim( $type, $now = null ) {
		global $wpdb;

		$now   = null === $now ? self::now() : $now;
		$table = PSN_Install::jobs_table();
		$lock  = self::now( self::LOCK_SECONDS );

		for ( $try = 0; $try < 5; $try++ ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table}
					WHERE type = %s AND status = %s AND available_at <= %s
						AND (locked_until IS NULL OR locked_until < %s)
					ORDER BY id ASC LIMIT 1",
					$type,
					self::STATUS_PENDING,
					$now,
					$now
				)
			);

			if ( ! $row ) {
				return null;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET locked_until = %s, updated_at = %s
					WHERE id = %d AND status = %s AND (locked_until IS NULL OR locked_until < %s)",
					$lock,
					$now,
					$row->id,
					self::STATUS_PENDING,
					$now
				)
			);

			if ( 1 === (int) $updated ) {
				$row->locked_until = $lock;

				return $row;
			}
		}

		return null;
	}

	/**
	 * 标记成功。
	 *
	 * @param int      $job_id 任务 ID.
	 * @param int|null $code   HTTP 状态码（邮件为空）.
	 * @return void
	 */
	public static function complete( $job_id, $code = null ) {
		global $wpdb;

		$table = PSN_Install::jobs_table();

		$wpdb->update(
			$table,
			array(
				'status'        => self::STATUS_DONE,
				'response_code' => null === $code ? null : (int) $code,
				'last_error'    => null,
				'locked_until'  => null,
				'updated_at'    => self::now(),
			),
			array( 'id' => (int) $job_id ),
			array( '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * 标记失败；未达上限时按退避重排。
	 *
	 * @param int      $job_id   任务 ID.
	 * @param string   $error    错误信息.
	 * @param int|null $code     HTTP 状态码.
	 * @param bool     $retry    是否允许重试.
	 * @param int      $delay    退避秒数.
	 * @return string 任务最终状态
	 */
	public static function fail( $job_id, $error, $code = null, $retry = false, $delay = 60 ) {
		global $wpdb;

		$table = PSN_Install::jobs_table();

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table}
				SET attempts = attempts + 1,
					response_code = %s,
					last_error = %s,
					locked_until = NULL,
					updated_at = %s,
					status = %s,
					available_at = %s
				WHERE id = %d",
				null === $code ? null : (int) $code,
				mb_substr( (string) $error, 0, 1000 ),
				self::now(),
				$retry ? self::STATUS_PENDING : self::STATUS_FAILED,
				self::now( max( 0, (int) $delay ) ),
				(int) $job_id
			)
		);

		return $retry ? self::STATUS_PENDING : self::STATUS_FAILED;
	}

	/**
	 * 重启一条失败任务（后台「重发」）。
	 *
	 * @param int $job_id 任务 ID.
	 * @return bool
	 */
	public static function requeue( $job_id ) {
		global $wpdb;

		$table = PSN_Install::jobs_table();

		$updated = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table}
				SET status = %s, attempts = 0, available_at = %s, locked_until = NULL, last_error = NULL, updated_at = %s
				WHERE id = %d",
				self::STATUS_PENDING,
				self::now(),
				self::now(),
				(int) $job_id
			)
		);

		if ( (int) $updated > 0 ) {
			PSN_Cron::schedule_tick_soon();

			return true;
		}

		return false;
	}

	/**
	 * 把某篇文章剩余的邮件任务整批取消。
	 *
	 * @param int $post_id 文章 ID.
	 * @return int 取消条数
	 */
	public static function cancel_mail_for_post( $post_id ) {
		global $wpdb;

		$table = PSN_Install::jobs_table();

		$updated = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table}
				SET status = %s, locked_until = NULL, updated_at = %s
				WHERE type = %s AND ref = %d AND status = %s",
				self::STATUS_CANCELLED,
				self::now(),
				self::TYPE_MAIL,
				(int) $post_id,
				self::STATUS_PENDING
			)
		);

		return (int) $updated;
	}

	/**
	 * 统计某状态的任务数。
	 *
	 * @param string $type   任务类型.
	 * @param string $status 状态.
	 * @return int
	 */
	public static function count( $type, $status = self::STATUS_PENDING ) {
		global $wpdb;

		$table = PSN_Install::jobs_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE type = %s AND status = %s", $type, $status )
		);
	}

	/**
	 * 今日已发出的邮件数（用于每日上限）。
	 *
	 * @return int
	 */
	public static function sent_today() {
		$counter = get_option( 'psn_daily_counter', array() );
		$today   = current_time( 'Y-m-d' );

		if ( ! is_array( $counter ) || ( $counter['date'] ?? '' ) !== $today ) {
			return 0;
		}

		return (int) ( $counter['count'] ?? 0 );
	}

	/**
	 * 记录今日发出一封邮件。
	 *
	 * @return int 记录后的计数
	 */
	public static function record_sent() {
		$today   = current_time( 'Y-m-d' );
		$counter = get_option( 'psn_daily_counter', array() );

		if ( ! is_array( $counter ) || ( $counter['date'] ?? '' ) !== $today ) {
			$counter = array(
				'date'  => $today,
				'count' => 0,
			);
		}

		$counter['count'] = (int) $counter['count'] + 1;
		update_option( 'psn_daily_counter', $counter, false );

		return $counter['count'];
	}

	/**
	 * 某条评论已有的 WebHook 任务数（任何状态）。
	 *
	 * @param int $comment_id 评论 ID.
	 * @return int
	 */
	public static function count_webhook_for_comment( $comment_id ) {
		global $wpdb;

		$table = PSN_Install::jobs_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE type = %s AND ref = %d", self::TYPE_WEBHOOK, (int) $comment_id )
		);
	}

	/**
	 * 清理过期记录与卡住的任务。
	 *
	 * @return void
	 */
	public static function cleanup() {
		global $wpdb;

		$table = PSN_Install::jobs_table();
		$now   = self::now();

		// 已完成的保留 30 天，失败的保留 90 天，取消的保留 1 天。
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$table} WHERE
					(status = %s AND updated_at < %s)
					OR (status = %s AND updated_at < %s)
					OR (status = %s AND updated_at < %s)",
				self::STATUS_DONE,
				self::now( -30 * DAY_IN_SECONDS ),
				self::STATUS_FAILED,
				self::now( -90 * DAY_IN_SECONDS ),
				self::STATUS_CANCELLED,
				self::now( -DAY_IN_SECONDS )
			)
		);

		// 释放长时间未归还的锁（进程被 PHP 超时打断时会留下）。
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET locked_until = NULL WHERE locked_until IS NOT NULL AND locked_until < %s",
				$now
			)
		);

		PSN_Subscribers::expire_stale_pending();
	}
}
