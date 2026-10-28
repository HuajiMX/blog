<?php
/**
 * 前台订阅接口：AJAX 提交、确认与退订端点。
 *
 * 本插件**不负责 UI**：卡片由 Sakurairo 主题在首页底部渲染（见主题
 * `inc/post-subscribe-card.php`），样式也在主题 CSS 里，这样外观天然与主题一致。
 * 插件只对外提供：
 *
 * - `POST admin-ajax.php?action=psn_subscribe` → JSON 状态（供卡片 JS 调用）；
 * - `GET ?psn_confirm=TOKEN` → 确认订阅，302 回首页；
 * - `GET ?psn_unsub=TOKEN&psn_uid=ID` → 退订二次确认；`POST psn_unsub_do=1` 执行退订；
 * - 短代码 `[psn_subscribe]` → 渲染主题的卡片模板（供任意页面复用）。
 *
 * 保留服务端 POST → 302 的兜底路径：若客户端 JS 未执行（或 AJAX 失败），
 * 直接提交表单也能完成订阅，只是会整页刷新。
 *
 * @package Post_Subscribe_Notifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 前台入口。
 */
class PSN_Signup {

	const FORM_ACTION = 'psn_subscribe';
	const AJAX_ACTION = 'psn_subscribe';

	/**
	 * 同一 IP 10 分钟内允许的提交次数。
	 */
	const RATE_LIMIT = 3;

	/**
	 * 允许回显的状态（白名单，不反射任何用户输入）。
	 *
	 * @return string[]
	 */
	public static function status_keys() {
		return array(
			'pending',
			'confirmed',
			'invalid_email',
			'already_active',
			'rate_limited',
			'mail_failed',
			'error',
			'expired',
			'unknown_token',
			'unsubscribed',
			'unsub_invalid',
			'unsub_confirm',
			'disabled',
		);
	}

	/**
	 * 挂载钩子。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'handle_requests' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'ajax_subscribe' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( __CLASS__, 'ajax_subscribe' ) );
		add_action( 'shutdown', array( __CLASS__, 'flush_confirmations_after_response' ) );
		add_shortcode( 'psn_subscribe', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * 在响应发完之后再发确认邮件。
	 *
	 * 顺序很关键：AJAX / 原生提交都会入队一条 confirm 任务，然后立刻返回，
	 * 用户马上看到「去邮箱确认」；真正的 SMTP 发送放在 shutdown 里，
	 * 这样即使 SMTP 慢 10 秒也不会把请求卡住。
	 *
	 * 用 fastcgi_finish_request() 先把响应刷给客户端；FPM 之外（CLI、其他 SAPI）
	 * 直接执行即可。
	 *
	 * @return void
	 */
	public static function flush_confirmations_after_response() {
		if ( 0 === PSN_Queue::count( PSN_Queue::TYPE_CONFIRM, PSN_Queue::STATUS_PENDING ) ) {
			return;
		}

		// 把已经生成好的响应发给客户端，PHP 进程继续在后台跑。
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}

		@ignore_user_abort( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_ignore_user_abort
		@set_time_limit( 30 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		PSN_Cron::dispatch_confirmations();
	}

	/**
	 * 短代码：直接复用主题的卡片模板。
	 *
	 * @param array $atts 属性.
	 * @return string
	 */
	public static function shortcode( $atts = array() ) {
		return self::render_card( 'shortcode', (array) $atts );
	}

	/**
	 * 渲染卡片：优先用主题模板，主题没提供时返回空串。
	 *
	 * @param string $source 来源标识.
	 * @param array  $args   模板参数.
	 * @return string
	 */
	public static function render_card( $source = 'home_card', $args = array() ) {
		$settings = PSN_Settings::get();

		if ( empty( $settings['mail_enabled'] ) ) {
			return '';
		}

		/**
		 * 过滤卡片渲染函数。
		 *
		 * 主题注册一个返回卡片 HTML 的回调即可；插件本身不包含任何 UI。
		 *
		 * @param callable|null $renderer 渲染回调.
		 * @param string        $source   来源.
		 * @param array         $args     参数.
		 */
		$renderer = apply_filters( 'psn_card_renderer', null, $source, $args );

		if ( is_callable( $renderer ) ) {
			return (string) call_user_func( $renderer, $source, $args );
		}

		return '';
	}

	/**
	 * 卡片是否可用（主题提供了渲染器且模块已开启）。
	 *
	 * @return bool
	 */
	public static function card_available() {
		$settings = PSN_Settings::get();

		if ( empty( $settings['mail_enabled'] ) ) {
			return false;
		}

		return is_callable( apply_filters( 'psn_card_renderer', null, '', array() ) );
	}

	/**
	 * 当前状态（只读 query，白名单校验）。
	 *
	 * @return string
	 */
	public static function current_status() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只读的白名单状态值。
		$status = isset( $_GET['psn_status'] ) ? sanitize_key( wp_unslash( $_GET['psn_status'] ) ) : '';

		return in_array( $status, self::status_keys(), true ) ? $status : '';
	}

	/**
	 * AJAX：提交订阅，返回 JSON。
	 *
	 * 复用 process_subscription() 的全部校验，只是把「302 回跳」换成 JSON。
	 *
	 * @return void
	 */
	public static function ajax_subscribe() {
		$input = array(
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- 蜜罐 + 频率限制 + 二次确认替代 nonce。
			'website' => isset( $_POST['psn_website'] ) ? (string) wp_unslash( $_POST['psn_website'] ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			'email'   => isset( $_POST['psn_email'] ) ? (string) wp_unslash( $_POST['psn_email'] ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			'name'    => isset( $_POST['psn_name'] ) ? (string) wp_unslash( $_POST['psn_name'] ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			'source'  => isset( $_POST['psn_source'] ) ? (string) wp_unslash( $_POST['psn_source'] ) : 'home_card',
		);

		$status = self::process_subscription( $input, self::ip_hash() );

		wp_send_json_success(
			array(
				'status'  => $status,
				'message' => self::status_message( $status ),
				'ok'      => in_array( $status, array( 'pending', 'confirmed', 'already_active' ), true ),
			)
		);
	}

	/**
	 * 状态对应的公开文案（AJAX 与模板共用）。
	 *
	 * @param string $status 状态键.
	 * @return string
	 */
	public static function status_message( $status ) {
		$map = array(
			'pending'        => 'status_pending',
			'confirmed'      => 'status_confirmed',
			'invalid_email'  => 'status_invalid_email',
			'already_active' => 'status_already_active',
			'rate_limited'   => 'status_rate_limited',
			'mail_failed'    => 'status_mail_failed',
			'error'          => 'status_error',
			'expired'        => 'status_expired',
			'unknown_token'  => 'status_unknown_token',
			'unsubscribed'   => 'status_unsubscribed',
			'unsub_invalid'  => 'status_unsub_invalid',
			'disabled'       => 'status_disabled',
		);

		if ( ! isset( $map[ $status ] ) ) {
			return '';
		}

		return psn_t( $map[ $status ] );
	}

	/**
	 * 状态对应的语气（供前端决定颜色）。
	 *
	 * @param string $status 状态键.
	 * @return string info|success|error
	 */
	public static function status_tone( $status ) {
		if ( in_array( $status, array( 'confirmed' ), true ) ) {
			return 'success';
		}

		if ( in_array( $status, array( 'pending', 'already_active', 'unsubscribed', 'disabled' ), true ) ) {
			return 'info';
		}

		return 'error';
	}

	/**
	 * 处理提交、确认、退订，处理完一律 302 回跳。
	 *
	 * @return void
	 */
	public static function handle_requests() {
		// AJAX 请求走 ajax_subscribe()，绝不能在这里 302：否则前端 fetch 会收到一段跳转，
		// 表现为「提示为空 / 状态错乱」。这也防住了表单里 psn_subscribe=1 与 action 同名的误判。
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		// 兜底：JS 没跑起来时的原生表单提交。
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- 用蜜罐 + 频率限制 + 二次确认替代。
		if ( isset( $_POST[ self::FORM_ACTION ] ) ) {
			self::handle_subscribe();

			return;
		}

		// 退订（POST，邮件客户端 List-Unsubscribe-Post 也打到这里）。
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['psn_unsub_do'] ) ) {
			self::handle_unsubscribe_post();

			return;
		}

		// 确认链接（GET）。
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['psn_confirm'] ) ) {
			self::handle_confirm();

			return;
		}

		// 退订链接（GET，先给二次确认）。
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['psn_unsub'] ) ) {
			self::redirect( 'unsub_confirm', array( 'psn_unsub' => sanitize_text_field( wp_unslash( $_GET['psn_unsub'] ) ), 'psn_uid' => (int) ( $_GET['psn_uid'] ?? 0 ) ) );
		}
	}

	/**
	 * 处理订阅表单（从 $_POST 读取后交给纯函数）。
	 *
	 * @return void
	 */
	private static function handle_subscribe() {
		$input = array(
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			'website' => isset( $_POST['psn_website'] ) ? (string) wp_unslash( $_POST['psn_website'] ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			'email'   => isset( $_POST['psn_email'] ) ? (string) wp_unslash( $_POST['psn_email'] ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			'name'    => isset( $_POST['psn_name'] ) ? (string) wp_unslash( $_POST['psn_name'] ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			'source'  => isset( $_POST['psn_source'] ) ? (string) wp_unslash( $_POST['psn_source'] ) : 'home_card',
		);

		self::redirect( self::process_subscription( $input, self::ip_hash() ) );
	}

	/**
	 * 订阅流程（纯逻辑，方便测试）。
	 *
	 * @param array  $input   表单字段 website/email/name/source.
	 * @param string $ip_hash 访客 IP 哈希.
	 * @return string 状态键
	 */
	public static function process_subscription( $input, $ip_hash = '' ) {
		$settings = PSN_Settings::get();

		if ( empty( $settings['mail_enabled'] ) ) {
			return 'disabled';
		}

		// 蜜罐命中：对机器人假装成功，不产生任何写入。
		if ( '' !== trim( (string) ( $input['website'] ?? '' ) ) ) {
			return 'pending';
		}

		$email = strtolower( trim( preg_replace( '#[\r\n\t]#', '', (string) ( $input['email'] ?? '' ) ) ) );

		if ( ! is_email( $email ) || strlen( $email ) > 254 ) {
			return 'invalid_email';
		}

		$name   = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		$source = sanitize_key( (string) ( $input['source'] ?? 'home_card' ) );
		$source = in_array( $source, array( 'home_card', 'shortcode' ), true ) ? $source : 'home_card';

		if ( self::is_rate_limited( $email ) ) {
			return 'rate_limited';
		}

		$result = PSN_Subscribers::create(
			array(
				'email'   => $email,
				'name'    => $name,
				'source'  => $source,
				'ip_hash' => $ip_hash,
			)
		);

		if ( empty( $result['ok'] ) ) {
			return 'error';
		}

		// 已确认的邮箱不计入频率（否则连续试探已订阅地址会被误伤）。
		if ( PSN_Subscribers::STATUS_ACTIVE !== $result['status'] ) {
			self::mark_attempt( $email );
		}

		if ( PSN_Subscribers::STATUS_ACTIVE === $result['status'] ) {
			return 'already_active';
		}

		$token = (string) $result['token'];

		if ( '' === $token ) {
			// 并发撞库时 create 返回空 token：再走一次即可轮换出新 token。
			$again = PSN_Subscribers::create(
				array(
					'email'   => $email,
					'name'    => $name,
					'source'  => $source,
					'ip_hash' => $ip_hash,
				)
			);
			$token = (string) ( $again['token'] ?? '' );
		}

		if ( '' === $token ) {
			return 'error';
		}

		// 发信走队列，不阻塞当前请求：SMTP 慢/失败时，用户也要立刻看到「去邮箱确认」。
		// 任务在主请求结束后（fastcgi_finish_request 之后）由 shutdown 钩子发起一次投递。
		PSN_Queue::enqueue_subscription_mail( (int) $result['id'] );

		return 'pending';
	}

	/**
	 * 处理 GET 确认链接。
	 *
	 * @return void
	 */
	private static function handle_confirm() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( $_GET['psn_confirm'] ) );

		self::redirect( self::process_confirm( $token ) );
	}

	/**
	 * 确认流程（纯逻辑）。
	 *
	 * @param string $token 明文 token.
	 * @return string 状态键
	 */
	public static function process_confirm( $token ) {
		$result = PSN_Subscribers::confirm( $token );

		if ( $result['ok'] ) {
			return 'confirmed';
		}

		return 'expired' === $result['reason'] ? 'expired' : 'unknown_token';
	}

	/**
	 * 处理 POST 退订。
	 *
	 * @return void
	 */
	private static function handle_unsubscribe_post() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$token = sanitize_text_field( wp_unslash( $_POST['psn_unsub'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$uid = (int) ( $_POST['psn_uid'] ?? 0 );

		self::redirect( self::process_unsubscribe( $uid, $token ) );
	}

	/**
	 * 退订流程（纯逻辑）。
	 *
	 * @param int    $uid   订阅者 ID.
	 * @param string $token 明文 token.
	 * @return string 状态键
	 */
	public static function process_unsubscribe( $uid, $token ) {
		return PSN_Subscribers::unsubscribe( (int) $uid, (string) $token ) ? 'unsubscribed' : 'unsub_invalid';
	}

	/**
	 * 302 回跳到首页并带上状态。
	 *
	 * @param string $status 状态键.
	 * @param array  $keep   需要保留的参数.
	 * @return void
	 */
	private static function redirect( $status, $keep = array() ) {
		$args = array_merge( $keep, array( 'psn_status' => $status ) );

		$url = add_query_arg( $args, home_url( '/' ) ) . '#psn-subscribe';

		wp_safe_redirect( $url, 302 );
		exit;
	}

	/**
	 * 简单的频率限制：同一 IP 10 分钟内最多 3 次，同一邮箱 1 分钟内不重复。
	 *
	 * @param string $email 邮箱.
	 * @return bool
	 */
	public static function is_rate_limited( $email ) {
		if ( (int) get_transient( 'psn_rate_ip_' . self::ip_hash() ) >= self::RATE_LIMIT ) {
			return true;
		}

		return (bool) get_transient( 'psn_rate_mail_' . md5( strtolower( trim( (string) $email ) ) ) );
	}

	/**
	 * 记录一次提交。
	 *
	 * IP 与邮箱分开计：IP 限制批量尝试，邮箱限制同一地址的连续重发。
	 *
	 * @param string $email 邮箱.
	 * @return void
	 */
	public static function mark_attempt( $email ) {
		$key   = 'psn_rate_ip_' . self::ip_hash();
		$count = (int) get_transient( $key );

		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
		set_transient( 'psn_rate_mail_' . md5( strtolower( trim( (string) $email ) ) ), 1, MINUTE_IN_SECONDS );
	}

	/**
	 * 清掉频率限制的 transient（测试用）。
	 *
	 * 邮箱按小写匹配，与 process_subscription 的规范化保持一致。
	 *
	 * @param string $email 邮箱.
	 * @return void
	 */
	public static function reset_rate_limits( $email = '' ) {
		delete_transient( 'psn_rate_ip_' . self::ip_hash() );

		$email = strtolower( trim( (string) $email ) );
		if ( '' !== $email ) {
			delete_transient( 'psn_rate_mail_' . md5( $email ) );
		}
	}

	/**
	 * 访客 IP 的哈希（不留明文 IP）。
	 *
	 * IP 取值走全站唯一入口 iro_get_client_ip()（mu-plugins/iro-real-ip.php），
	 * 它已处理 CDN 转发头与伪造校验，本插件不再自己解析。
	 *
	 * @return string
	 */
	private static function ip_hash() {
		$ip = '';

		if ( function_exists( 'iro_get_client_ip' ) ) {
			$ip = (string) iro_get_client_ip();
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = (string) wp_unslash( $_SERVER['REMOTE_ADDR'] );
		}

		/**
		 * 过滤访客 IP（在哈希之前）。
		 *
		 * @param string $ip IP.
		 */
		$ip = (string) apply_filters( 'psn_client_ip', $ip );

		return substr( hash( 'sha1', $ip . '|' . wp_salt( 'nonce' ) ), 0, 40 );
	}
}
