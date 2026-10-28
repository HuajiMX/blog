<?php
/**
 * 邮件构建与发送：验证邮件、新文章推送、测试邮件。
 *
 * 所有正文使用行内样式与中性色，主题色只出现在标题条与按钮上，
 * 避免邮件客户端把它当成内容排版的一部分。
 *
 * @package Post_Subscribe_Notifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 邮件相关。
 */
class PSN_Mailer {

	/**
	 * 默认发件人。
	 *
	 * @return array{name:string,email:string}
	 */
	private static function from() {
		$settings = PSN_Settings::get();

		$name  = '' !== $settings['from_name'] ? $settings['from_name'] : get_bloginfo( 'name' );
		$email = '' !== $settings['from_email'] ? $settings['from_email'] : get_option( 'admin_email' );

		/**
		 * 过滤发件人。
		 *
		 * @param array{name:string,email:string} $from 发件人.
		 */
		return apply_filters(
			'psn_mail_from',
			array(
				'name'  => $name,
				'email' => $email,
			)
		);
	}

	/**
	 * 组装请求头。
	 *
	 * @param array<string,string> $extra 额外头.
	 * @return string[]
	 */
	private static function headers( $extra = array() ) {
		$from     = self::from();
		$settings = PSN_Settings::get();

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', self::encode_name( $from['name'] ), $from['email'] ),
		);

		$reply_to = '' !== $settings['reply_to'] ? $settings['reply_to'] : $from['email'];
		if ( $reply_to ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		foreach ( $extra as $header ) {
			$headers[] = $header;
		}

		return $headers;
	}

	/**
	 * 非 ASCII 显示名做 MIME 编码。
	 *
	 * @param string $name 显示名.
	 * @return string
	 */
	private static function encode_name( $name ) {
		$name = (string) $name;

		if ( '' === $name ) {
			return 'no-reply';
		}

		if ( preg_match( '#[^\x20-\x7E]#', $name ) ) {
			return '=?UTF-8?B?' . base64_encode( $name ) . '?=';
		}

		return str_replace( array( '"', "\r", "\n" ), '', $name );
	}

	/**
	 * 邮件外壳。
	 *
	 * @param string $title    标题.
	 * @param string $body_html 正文 HTML.
	 * @param string $footer   页脚文字.
	 * @return string
	 */
	private static function wrap( $title, $body_html, $footer = '' ) {
		$site_name = get_bloginfo( 'name' );
		$site_url  = home_url( '/' );
		$color     = self::accent();

		if ( '' === $footer ) {
			$footer = sprintf( '此邮件由系统自动发送，请勿直接回复。' );
		}

		return '<!DOCTYPE html>
<html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . esc_html( $title ) . '</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f7;color:#333;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f7;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden;">
<tr><td style="background:' . esc_attr( $color ) . ';padding:18px 24px;">
<div style="color:#ffffff;font-size:18px;font-weight:600;line-height:1.4;">' . esc_html( $title ) . '</div>
</td></tr>
<tr><td style="padding:24px;font-size:15px;line-height:1.7;color:#333;">' . $body_html . '</td></tr>
<tr><td style="background:#fafafa;border-top:1px solid #eeeeee;padding:16px 24px;color:#888888;font-size:12px;line-height:1.6;">
<p style="margin:0 0 6px;">' . esc_html( $footer ) . '</p>
<p style="margin:0;"><a href="' . esc_url( $site_url ) . '" style="color:' . esc_attr( $color ) . ';text-decoration:none;">' . esc_html( $site_name ) . '</a></p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>';
	}

	/**
	 * 邮件主色。取主题的动态主题色，并压到对白字可读的对比度。
	 *
	 * 主题色（如 #a4cdf6）很浅，直接用白字只有 1.7:1，
	 * 因此复用 PSN_Color 做对比度修正。
	 *
	 * @return string
	 */
	private static function accent() {
		$palette = PSN_Color::button_palette();

		/**
		 * 过滤邮件主色。
		 *
		 * @param string $color 颜色.
		 */
		return (string) apply_filters( 'psn_mail_accent', $palette['accent'] );
	}

	/**
	 * 按钮 HTML。
	 *
	 * @param string $url  链接.
	 * @param string $label 文案.
	 * @return string
	 */
	private static function button( $url, $label ) {
		return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0;"><tr><td style="border-radius:8px;background:' . esc_attr( self::accent() ) . ';">'
			. '<a href="' . esc_url( $url ) . '" style="display:inline-block;padding:12px 26px;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;">' . esc_html( $label ) . '</a>'
			. '</td></tr></table>';
	}

	/**
	 * 确认链接。
	 *
	 * @param string $token 明文 token.
	 * @return string
	 */
	public static function confirm_url( $token ) {
		return add_query_arg( 'psn_confirm', rawurlencode( $token ), home_url( '/' ) );
	}

	/**
	 * 退订链接。
	 *
	 * @param object $subscriber 订阅者行.
	 * @return string
	 */
	public static function unsubscribe_url( $subscriber ) {
		$id    = (int) ( $subscriber->id ?? 0 );
		$token = PSN_Subscribers::unsub_token( $subscriber );

		return add_query_arg(
			array(
				'psn_unsub' => rawurlencode( $token ),
				'psn_uid'   => $id,
			),
			home_url( '/' )
		);
	}

	/**
	 * 发送验证邮件。
	 *
	 * @param string $email 收件邮箱.
	 * @param string $token 明文 token.
	 * @param string $name  昵称.
	 * @return bool
	 */
	public static function send_confirmation( $email, $token, $name = '' ) {
		$site_name = get_bloginfo( 'name' );
		$url       = self::confirm_url( $token );
		$ttl       = (int) PSN_Settings::get()['confirm_ttl'];
		$hello     = '' !== $name ? sprintf( '%s，你好：', esc_html( $name ) ) : '你好：';

		$body = '<p style="margin:0 0 14px;">' . $hello . '</p>'
			. '<p style="margin:0 0 14px;">感谢订阅 <strong>' . esc_html( $site_name ) . '</strong> 的新文章推送。请点击下面的按钮确认你的邮箱地址，确认后每发布一篇新文章都会发到你的邮箱。</p>'
			. self::button( $url, '确认订阅' )
			. '<p style="margin:0 0 8px;color:#666;font-size:13px;">按钮无法点击时，请复制下面的链接到浏览器打开：</p>'
			. '<p style="margin:0 0 14px;font-size:13px;word-break:break-all;"><a href="' . esc_url( $url ) . '" style="color:' . esc_attr( self::accent() ) . ';">' . esc_html( $url ) . '</a></p>'
			. '<p style="margin:0;color:#999;font-size:13px;">链接 ' . (int) $ttl . ' 小时内有效。如果不是你本人操作，忽略这封邮件即可，不会收到任何推送。</p>';

		$subject = sprintf( '[%s] 请确认你的订阅', $site_name );

		return wp_mail( $email, $subject, self::wrap( '确认订阅', $body ), self::headers() );
	}

	/**
	 * 新文章推送邮件。
	 *
	 * @param object $subscriber 订阅者行.
	 * @param int    $post_id    文章 ID.
	 * @return bool
	 */
	public static function send_post_notification( $subscriber, $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return false;
		}

		$settings = PSN_Settings::get();
		$site_name = get_bloginfo( 'name' );
		$title     = get_the_title( $post );
		$permalink = get_permalink( $post );

		$excerpt = '';
		if ( ! empty( $settings['include_excerpt'] ) ) {
			$excerpt = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 80, '…' );
		}

		$hello  = ! empty( $subscriber->name ) ? sprintf( '%s，你好：', esc_html( $subscriber->name ) ) : '你好：';
		$intro  = '' !== $settings['intro'] ? $settings['intro'] : '我刚刚发布了一篇新文章，希望你会喜欢。';

		$body = '<p style="margin:0 0 14px;">' . $hello . '</p>'
			. '<p style="margin:0 0 18px;">' . esc_html( $intro ) . '</p>';

		$cover = '';
		if ( ! empty( $settings['include_cover'] ) ) {
			$cover_id = (int) get_post_thumbnail_id( $post );
			if ( $cover_id ) {
				$src = wp_get_attachment_image_url( $cover_id, 'large' );
				if ( $src ) {
					$cover = '<p style="margin:0 0 18px;"><a href="' . esc_url( $permalink ) . '" style="text-decoration:none;"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $title ) . '" style="display:block;width:100%;height:auto;border-radius:6px;"></a></p>';
				}
			}
		}

		$body .= $cover
			. '<h2 style="margin:0 0 12px;font-size:19px;line-height:1.5;color:#222;"><a href="' . esc_url( $permalink ) . '" style="color:#222;text-decoration:none;">' . esc_html( $title ) . '</a></h2>'
			. ( '' !== $excerpt ? '<p style="margin:0 0 6px;color:#555;">' . esc_html( $excerpt ) . '</p>' : '' )
			. self::button( $permalink, '阅读全文' );

		$unsub_url = self::unsubscribe_url( $subscriber );

		$body .= '<p style="margin:18px 0 0;color:#999;font-size:12px;">不想再收到新文章推送？<a href="' . esc_url( $unsub_url ) . '" style="color:' . esc_attr( self::accent() ) . ';">点此退订</a>。</p>';

		$subject = str_replace(
			array( '{site_name}', '{post_title}', '{post_excerpt}' ),
			array( $site_name, $title, $excerpt ),
			(string) $settings['subject_tpl']
		);
		$subject = str_replace( array( "\r", "\n" ), ' ', $subject );

		$headers = self::headers(
			array(
				'List-Unsubscribe: <' . $unsub_url . '>',
				'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
			)
		);

		$footer = sprintf( '你收到这封邮件是因为订阅了 %s 的新文章推送。', $site_name );

		return wp_mail( $subscriber->email, $subject, self::wrap( '新文章', $body, $footer ), $headers );
	}

	/**
	 * 测试邮件。
	 *
	 * @param string $to 收件人.
	 * @return bool
	 */
	public static function send_test( $to ) {
		$site_name = get_bloginfo( 'name' );

		$body = '<p style="margin:0 0 14px;">这是一封测试邮件，来自 <strong>' . esc_html( $site_name ) . '</strong> 的订阅推送插件。</p>'
			. '<p style="margin:0 0 14px;color:#666;">如果你能在邮箱里看到它，说明验证邮件与新文章推送也能正常发出。</p>'
			. '<p style="margin:0;color:#999;font-size:13px;">发送时间：' . esc_html( current_time( 'Y-m-d H:i:s' ) ) . '</p>';

		return wp_mail( $to, sprintf( '[%s] 订阅推送测试邮件', $site_name ), self::wrap( '测试邮件', $body ), self::headers() );
	}

	/**
	 * 渲染一段用于测试 WebHook 的假评论上下文（由 PSN_Webhook 使用）。
	 *
	 * @return array
	 */
	public static function placeholder_context() {
		return array(
			'author'          => '测试用户',
			'post_title'      => '测试文章',
			'post_url'        => home_url( '/' ),
			'comment_excerpt' => '这是一条测试评论。',
			'parent_author'   => '',
			'comment_url'     => home_url( '/' ),
			'site_name'       => get_bloginfo( 'name' ),
		);
	}
}
