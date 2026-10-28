<?php
/**
 * 定时任务：队列分发与每日清理。
 *
 * @package Post_Subscribe_Notifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * cron 侧逻辑。
 */
class PSN_Cron {

	const HOOK_TICK    = 'psn_tick';
	const HOOK_CLEANUP = 'psn_daily_cleanup';
	const SCHEDULE     = 'psn_minute';

	/**
	 * 挂载钩子。
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( self::HOOK_TICK, array( __CLASS__, 'run_tick' ) );
		add_action( self::HOOK_CLEANUP, array( __CLASS__, 'run_cleanup' ) );

		// 补排：激活时若 cron_schedules 还没注册（插件在 plugins_loaded 之后才被包含），
		// wp_schedule_event 会因为间隔未知而失败，这里在正常请求里补上。
		if ( ! wp_next_scheduled( self::HOOK_TICK ) || ! wp_next_scheduled( self::HOOK_CLEANUP ) ) {
			self::schedule();
		}
	}

	/**
	 * 注册 1 分钟的调度间隔。
	 *
	 * @param array $schedules 已有调度.
	 * @return array
	 */
	public static function add_schedule( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}

		$schedules[ self::SCHEDULE ] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => '每 1 分钟（订阅推送）',
		);

		return $schedules;
	}

	/**
	 * 注册定时任务。
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK_TICK ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK_TICK );
		}

		if ( ! wp_next_scheduled( self::HOOK_CLEANUP ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK_CLEANUP );
		}
	}

	/**
	 * 清除定时任务。
	 *
	 * @return void
	 */
	public static function unschedule() {
		foreach ( array( self::HOOK_TICK, self::HOOK_CLEANUP ) as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			while ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
				$timestamp = wp_next_scheduled( $hook );
			}
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * 有新任务时尽快跑一次（不必等到下一分钟）。
	 *
	 * 用 single event 而不是直接同步执行，保证发布/评论请求不被发信阻塞。
	 *
	 * @return void
	 */
	public static function schedule_tick_soon() {
		if ( wp_next_scheduled( self::HOOK_TICK, array( 'soon' ) ) ) {
			return;
		}

		wp_schedule_single_event( time() + 5, self::HOOK_TICK, array( 'soon' ) );
	}

	/**
	 * 分发一轮。
	 *
	 * @param string $context 触发来源（'soon' 表示刚入队）.
	 * @return void
	 */
	public static function run_tick( $context = '' ) {
		self::dispatch_confirmations();
		self::dispatch_webhooks();
		self::dispatch_mail();
	}

	/**
	 * 发送待发的订阅确认邮件。
	 *
	 * 正常路径下这些任务在请求结束（响应已发出）后立刻被处理，
	 * 这里的兜底用于 cron 补发极端情况下遗漏的任务。
	 *
	 * @return int 处理条数
	 */
	public static function dispatch_confirmations() {
		$processed = 0;

		while ( $processed < 10 ) {
			$job = PSN_Queue::claim( PSN_Queue::TYPE_CONFIRM );

			if ( ! $job ) {
				break;
			}

			$processed++;

			if ( PSN_Queue::deliver_confirmation( $job ) ) {
				PSN_Queue::complete( (int) $job->id );
			} else {
				$attempts = (int) $job->attempts + 1;
				PSN_Queue::fail(
					(int) $job->id,
					'确认邮件发送失败',
					null,
					$attempts < 3,
					self::backoff( $attempts, 0 )
				);
			}
		}

		return $processed;
	}

	/**
	 * 发送待推送的 WebHook（数量小、时效要求高，先跑）。
	 *
	 * @return int 处理条数
	 */
	public static function dispatch_webhooks() {
		$settings = PSN_Settings::get();

		if ( empty( $settings['webhook_enabled'] ) ) {
			return 0;
		}

		$processed = 0;
		$max       = 20;

		while ( $processed < $max ) {
			$job = PSN_Queue::claim( PSN_Queue::TYPE_WEBHOOK );

			if ( ! $job ) {
				break;
			}

			$processed++;

			$result  = PSN_Webhook::deliver( $job );
			$retries = (int) $settings['webhook_retries'];

			if ( $result['ok'] ) {
				PSN_Queue::complete( (int) $job->id, (int) $result['code'] );
				continue;
			}

			$attempts = (int) $job->attempts + 1;
			$retry    = $result['retry'] && $attempts <= $retries;
			$delay    = $retry ? self::backoff( $attempts, (int) $result['delay'] ) : 0;

			PSN_Queue::fail( (int) $job->id, (string) $result['error'], (int) $result['code'], $retry, $delay );
		}

		return $processed;
	}

	/**
	 * 发送待推送的邮件（限速 + 每日上限）。
	 *
	 * @return int 处理条数
	 */
	public static function dispatch_mail() {
		$settings = PSN_Settings::get();

		if ( empty( $settings['mail_enabled'] ) ) {
			return 0;
		}

		$processed = 0;
		$max       = (int) $settings['batch'];
		$cap       = (int) $settings['daily_cap'];
		$interval  = (int) $settings['interval'];

		while ( $processed < $max ) {
			if ( $cap > 0 && PSN_Queue::sent_today() >= $cap ) {
				break;
			}

			$job = PSN_Queue::claim( PSN_Queue::TYPE_MAIL );

			if ( ! $job ) {
				break;
			}

			$processed++;

			$post = get_post( (int) $job->ref );

			// 发布后又撤回：不再发。
			if ( ! $post || 'publish' !== $post->post_status ) {
				PSN_Queue::fail( (int) $job->id, '文章已不是发布状态', null, false, 0 );

				continue;
			}

			$subscriber = PSN_Subscribers::find( (int) $job->target );

			if ( ! $subscriber || PSN_Subscribers::STATUS_ACTIVE !== $subscriber->status ) {
				PSN_Queue::fail( (int) $job->id, '订阅者不存在或已不在订阅状态', null, false, 0 );

				continue;
			}

			$sent = PSN_Mailer::send_post_notification( $subscriber, (int) $post->ID );

			if ( $sent ) {
				PSN_Queue::complete( (int) $job->id );
				PSN_Subscribers::mark_sent( (int) $subscriber->id );
				PSN_Queue::record_sent();
			} else {
				$attempts = (int) $job->attempts + 1;
				$retry    = $attempts < 3;
				PSN_Queue::fail( (int) $job->id, 'wp_mail 返回失败', null, $retry, self::backoff( $attempts, 0 ) );
				PSN_Subscribers::mark_failed( (int) $subscriber->id );
			}

			if ( $interval > 0 && $processed < $max ) {
				usleep( $interval * 1000 );
			}
		}

		return $processed;
	}

	/**
	 * 指数退避（秒），带随机抖动，避免同时重试。
	 *
	 * @param int $attempt 第几次失败（从 1 起）.
	 * @param int $min     外部给出的最小等待（如 Retry-After）.
	 * @return int
	 */
	private static function backoff( $attempt, $min = 0 ) {
		$steps = array( 60, 300, 900 );
		$base  = $steps[ min( count( $steps ), max( 1, (int) $attempt ) ) - 1 ] ?? 900;

		/**
		 * 过滤退避秒数。
		 *
		 * @param int $base    计算值.
		 * @param int $attempt 第几次失败.
		 */
		$base = (int) apply_filters( 'psn_retry_backoff', $base, (int) $attempt );

		return max( (int) $min, $base + wp_rand( 0, 30 ) );
	}

	/**
	 * 每日清理。
	 *
	 * @return void
	 */
	public static function run_cleanup() {
		PSN_Queue::cleanup();
	}
}
