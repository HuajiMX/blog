<?php
/**
 * 选项读写与清洗。
 *
 * @package Post_Subscribe_Notifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 插件选项。
 */
class PSN_Settings {

	/**
	 * 默认设置。
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'mail_enabled'      => 1,
			'webhook_enabled'   => 0,
			'delete_data'       => 0,

			'post_types'        => array( 'post' ),
			'from_name'         => '',
			'from_email'        => '',
			'reply_to'          => '',
			'subject_tpl'       => '[{site_name}] 新文章：{post_title}',
			'intro'             => '我刚刚发布了一篇新文章，希望你会喜欢。',
			'include_excerpt'   => 1,
			'include_cover'     => 1,
			'batch'             => 20,
			'interval'          => 300,
			'daily_cap'         => 300,
			'republish'         => 0,
			'confirm_ttl'       => 48,

			'webhook_url'       => '',
			'webhook_secret'    => '',
			'webhook_headers'   => '',
			'webhook_timeout'   => 5,
			'webhook_retries'   => 3,
			'notify_pending'    => 0,
			'include_paragraph' => 1,
			'include_shuoshuo'  => 1,
			'notify_self'       => 1,
			'summary_tpl'       => '【新评论】{author} 评论了《{post_title}》：{comment_excerpt} {comment_url}',
		);
	}

	/**
	 * 读取设置（补齐默认值并做一次规范化）。
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( PSN_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$settings = wp_parse_args( $saved, self::defaults() );

		if ( ! is_array( $settings['post_types'] ) ) {
			$settings['post_types'] = array();
		}
		$settings['post_types'] = array_values( array_filter( array_map( 'strval', $settings['post_types'] ) ) );

		foreach ( array( 'mail_enabled', 'webhook_enabled', 'delete_data', 'include_excerpt', 'include_cover', 'republish', 'notify_pending', 'include_paragraph', 'include_shuoshuo', 'notify_self' ) as $flag ) {
			$settings[ $flag ] = empty( $settings[ $flag ] ) ? 0 : 1;
		}

		$settings['batch']           = self::clamp( $settings['batch'], 1, 100, 20 );
		$settings['interval']        = self::clamp( $settings['interval'], 0, 2000, 300 );
		$settings['daily_cap']       = self::clamp( $settings['daily_cap'], 0, 10000, 300 );
		$settings['confirm_ttl']     = self::clamp( $settings['confirm_ttl'], 1, 720, 48 );
		$settings['webhook_timeout'] = self::clamp( $settings['webhook_timeout'], 1, 15, 5 );
		$settings['webhook_retries'] = self::clamp( $settings['webhook_retries'], 0, 5, 3 );

		foreach ( array( 'from_name', 'from_email', 'reply_to', 'subject_tpl', 'intro', 'webhook_url', 'webhook_secret', 'webhook_headers', 'summary_tpl' ) as $text ) {
			$settings[ $text ] = (string) $settings[ $text ];
		}

		return $settings;
	}

	/**
	 * 保存时的清洗。字段非法时回落到旧值，并把错误交给 settings_errors。
	 *
	 * @param mixed $input 表单原始数据.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$old    = self::get();
		$output = $old;

		if ( ! is_array( $input ) ) {
			return $output;
		}

		foreach ( array( 'mail_enabled', 'webhook_enabled', 'delete_data', 'include_excerpt', 'include_cover', 'republish', 'notify_pending', 'include_paragraph', 'include_shuoshuo', 'notify_self' ) as $flag ) {
			$output[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		$available             = self::available_post_types();
		$picked                = array();
		$input_post_types      = isset( $input['post_types'] ) && is_array( $input['post_types'] ) ? $input['post_types'] : array();
		foreach ( $input_post_types as $type ) {
			if ( is_scalar( $type ) ) {
				$picked[] = sanitize_key( (string) $type );
			}
		}
		$output['post_types'] = array_values( array_intersect( array_unique( $picked ), $available ) );
		if ( empty( $output['post_types'] ) ) {
			$output['post_types'] = array( 'post' );
		}

		$output['batch']           = self::clamp( $input['batch'] ?? $old['batch'], 1, 100, $old['batch'] );
		$output['interval']        = self::clamp( $input['interval'] ?? $old['interval'], 0, 2000, $old['interval'] );
		$output['daily_cap']       = self::clamp( $input['daily_cap'] ?? $old['daily_cap'], 0, 10000, $old['daily_cap'] );
		$output['confirm_ttl']     = self::clamp( $input['confirm_ttl'] ?? $old['confirm_ttl'], 1, 720, $old['confirm_ttl'] );
		$output['webhook_timeout'] = self::clamp( $input['webhook_timeout'] ?? $old['webhook_timeout'], 1, 15, $old['webhook_timeout'] );
		$output['webhook_retries'] = self::clamp( $input['webhook_retries'] ?? $old['webhook_retries'], 0, 5, $old['webhook_retries'] );

		foreach ( array( 'from_name', 'subject_tpl', 'intro', 'summary_tpl' ) as $text ) {
			if ( isset( $input[ $text ] ) ) {
				$output[ $text ] = sanitize_text_field( (string) $input[ $text ] );
			}
		}

		foreach ( array( 'from_email', 'reply_to' ) as $email ) {
			if ( isset( $input[ $email ] ) ) {
				$value = trim( (string) $input[ $email ] );
				if ( '' === $value ) {
					$output[ $email ] = '';
				} elseif ( is_email( $value ) ) {
					$output[ $email ] = strtolower( $value );
				} else {
					add_settings_error( PSN_OPTION, 'psn_' . $email, sprintf( '「%s」不是有效邮箱，已保留原值。', $email ) );
				}
			}
		}

		if ( isset( $input['webhook_url'] ) ) {
			$url = trim( (string) $input['webhook_url'] );
			if ( '' === $url ) {
				$output['webhook_url'] = '';
			} else {
				$safe = esc_url_raw( $url );
				$ok   = $safe && preg_match( '#^https?://#i', $safe );
				if ( $ok ) {
					$output['webhook_url'] = $safe;
				} else {
					$output['webhook_url'] = '';
					add_settings_error( PSN_OPTION, 'psn_webhook_url', '「WebHook 地址」必须是 http/https 地址，已清空。' );
				}
			}
		}

		if ( isset( $input['webhook_secret'] ) ) {
			$output['webhook_secret'] = sanitize_text_field( (string) $input['webhook_secret'] );
		}

		if ( isset( $input['webhook_headers'] ) ) {
			$raw = trim( (string) $input['webhook_headers'] );
			if ( '' === $raw ) {
				$output['webhook_headers'] = '';
			} else {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					$clean = array();
					foreach ( $decoded as $key => $value ) {
						if ( is_scalar( $value ) ) {
							$clean[ (string) $key ] = (string) $value;
						}
					}
					$output['webhook_headers'] = $clean ? wp_json_encode( $clean ) : '';
				} else {
					add_settings_error( PSN_OPTION, 'psn_webhook_headers', '「附加请求头」不是合法 JSON，已保留原值。' );
				}
			}
		}

		if ( $output['webhook_enabled'] && '' === $output['webhook_url'] ) {
			$output['webhook_enabled'] = 0;
			add_settings_error( PSN_OPTION, 'psn_webhook_url', '没有填写 WebHook 地址，评论推送已关闭。', 'warning' );
		}

		return $output;
	}

	/**
	 * 所有可作为推送触发条件的内容类型。
	 *
	 * @return string[]
	 */
	public static function available_post_types() {
		$types = get_post_types( array( 'publicly_queryable' => true ), 'objects' );
		$slugs = array();

		if ( is_array( $types ) ) {
			foreach ( $types as $slug => $type ) {
				if ( 'attachment' === $slug || ! $type->public ) {
					continue;
				}
				$slugs[] = $slug;
			}
		}

		/**
		 * 过滤后台可选的内容类型。
		 *
		 * @param string[] $slugs 类型 slug.
		 */
		return apply_filters( 'psn_available_post_types', $slugs );
	}

	/**
	 * 最终用于触发推送的内容类型。
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$settings = self::get();
		$types    = array_values( array_intersect( $settings['post_types'], self::available_post_types() ) );

		if ( empty( $types ) ) {
			$types = array( 'post' );
		}

		/**
		 * 过滤真正触发邮件推送的内容类型。
		 *
		 * @param string[] $types    类型 slug.
		 * @param array    $settings 插件设置.
		 */
		return apply_filters( 'psn_notify_post_types', $types, $settings );
	}

	/**
	 * 把任意输入钳制为整数。
	 *
	 * @param mixed $value 输入.
	 * @param int   $min   下限.
	 * @param int   $max   上限.
	 * @param int   $fallback 非法时使用.
	 * @return int
	 */
	private static function clamp( $value, $min, $max, $fallback ) {
		if ( ! is_numeric( $value ) ) {
			return (int) $fallback;
		}

		$value = (int) $value;

		return max( $min, min( $max, $value ) );
	}

	/**
	 * 解析附加请求头。
	 *
	 * @return array<string,string>
	 */
	public static function extra_headers() {
		$settings = self::get();
		$raw      = (string) $settings['webhook_headers'];

		if ( '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$headers = array();
		foreach ( $decoded as $key => $value ) {
			$key = trim( (string) $key );
			// 防止请求头注入。
			if ( '' === $key || preg_match( '#[\r\n:]#', $key ) || preg_match( '#[\r\n]#', (string) $value ) ) {
				continue;
			}
			$headers[ $key ] = (string) $value;
		}

		return $headers;
	}
}
