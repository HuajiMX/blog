<?php
/**
 * 安装、升级与卸载时的数据库生命周期。
 *
 * @package Post_Subscribe_Notifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 建表与清理。
 */
class PSN_Install {

	/**
	 * 订阅者表名。
	 *
	 * @return string
	 */
	public static function subscribers_table() {
		global $wpdb;
		return $wpdb->prefix . PSN_TABLE_SUBSCRIBERS;
	}

	/**
	 * 任务表名。
	 *
	 * @return string
	 */
	public static function jobs_table() {
		global $wpdb;
		return $wpdb->prefix . PSN_TABLE_JOBS;
	}

	/**
	 * 激活：建表、写默认设置、注册 cron。
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();

		if ( false === get_option( PSN_OPTION, false ) ) {
			add_option( PSN_OPTION, PSN_Settings::defaults() );
		}

		update_option( 'psn_db_version', PSN_DB_VERSION );

		PSN_Cron::schedule();
	}

	/**
	 * 停用：清掉定时任务，但保留数据。
	 *
	 * @return void
	 */
	public static function deactivate() {
		PSN_Cron::unschedule();
	}

	/**
	 * 版本变化时补建/升级表结构。
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$current = get_option( 'psn_db_version', '' );
		if ( PSN_DB_VERSION === $current ) {
			return;
		}

		self::create_tables();
		update_option( 'psn_db_version', PSN_DB_VERSION );

		PSN_Cron::schedule();
	}

	/**
	 * 用 dbDelta 建表。
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$subscribers     = self::subscribers_table();
		$jobs            = self::jobs_table();

		// dbDelta 对格式敏感：两个空格缩进 PRIMARY KEY，字段用小写。
		$sql_subscribers = "CREATE TABLE {$subscribers} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(254) NOT NULL,
			name varchar(100) DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			confirm_hash char(64) DEFAULT NULL,
			unsub_hash char(64) DEFAULT NULL,
			token_expires datetime DEFAULT NULL,
			source varchar(32) DEFAULT NULL,
			ip_hash char(40) DEFAULT NULL,
			fail_streak smallint(5) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			confirmed_at datetime DEFAULT NULL,
			unsubscribed_at datetime DEFAULT NULL,
			last_mail_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_email (email),
			UNIQUE KEY uniq_confirm (confirm_hash),
			UNIQUE KEY uniq_unsub (unsub_hash),
			KEY idx_status (status)
		) {$charset_collate};";

		$sql_jobs = "CREATE TABLE {$jobs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(16) NOT NULL,
			ref bigint(20) unsigned NOT NULL,
			target varchar(100) NOT NULL DEFAULT '',
			status varchar(16) NOT NULL DEFAULT 'pending',
			payload longtext DEFAULT NULL,
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			available_at datetime NOT NULL,
			locked_until datetime DEFAULT NULL,
			response_code smallint(5) DEFAULT NULL,
			last_error text DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_job (type,ref,target),
			KEY idx_pending (status,available_at),
			KEY idx_ref (type,ref)
		) {$charset_collate};";

		dbDelta( $sql_subscribers );
		dbDelta( $sql_jobs );
	}

	/**
	 * 卸载时按设置删除数据。
	 *
	 * @return void
	 */
	public static function uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			return;
		}

		$settings = get_option( PSN_OPTION, array() );
		$delete   = is_array( $settings ) && ! empty( $settings['delete_data'] );

		if ( ! $delete ) {
			return;
		}

		global $wpdb;

		$subscribers = self::subscribers_table();
		$jobs        = self::jobs_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 表名由本插件拼装。
		$wpdb->query( "DROP TABLE IF EXISTS {$subscribers}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$jobs}" );

		delete_option( PSN_OPTION );
		delete_option( 'psn_db_version' );
		delete_option( 'psn_daily_counter' );

		PSN_Cron::unschedule();
	}
}
