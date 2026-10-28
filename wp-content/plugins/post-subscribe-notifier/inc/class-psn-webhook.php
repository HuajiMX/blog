<?php
/**
 * 评论 WebHook：载荷构建、签名、发送与重试判定。
 *
 * 载荷在「发送时」才构建，因为段评插件的 paragraph_index、主题的 new_field_qq
 * 都是在 wp_insert_comment 之后才写入 meta 的。
 *
 * @package Post_Subscribe_Notifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WebHook 相关。
 */
class PSN_Webhook {

	/**
	 * 私密评论的 meta 键（Sakurairo 主题）。
	 */
	const PRIVATE_META = '_private';

	/**
	 * 分发时构建并 POST 一条评论载荷。
	 *
	 * @param object $job 任务行.
	 * @return array{ok:bool,code:int,error:string,retry:bool,delay:int}
	 */
	public static function deliver( $job ) {
		$settings = PSN_Settings::get();
		$url      = (string) $settings['webhook_url'];

		if ( '' === $url ) {
			return array(
				'ok'    => false,
				'code'  => 0,
				'error' => 'WebHook 地址为空',
				'retry' => false,
				'delay' => 0,
			);
		}

		$comment = get_comment( (int) $job->ref );

		if ( ! $comment ) {
			return array(
				'ok'    => false,
				'code'  => 0,
				'error' => '评论已不存在',
				'retry' => false,
				'delay' => 0,
			);
		}

		$payload = self::build_payload( $comment, (int) $job->id );
		$body    = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		$headers = array_merge(
			array(
				'Content-Type'   => 'application/json; charset=utf-8',
				'User-Agent'     => 'PostSubscribeNotifier/' . PSN_VERSION . '; ' . home_url( '/' ),
				'X-PSN-Event'    => (string) $payload['event'],
				'X-PSN-Delivery' => (string) $job->id,
			),
			PSN_Settings::extra_headers()
		);

		if ( '' !== (string) $settings['webhook_secret'] ) {
			$headers['X-PSN-Signature'] = 'sha256=' . hash_hmac( 'sha256', (string) $body, (string) $settings['webhook_secret'] );
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => (int) $settings['webhook_timeout'],
				'redirection' => 2,
				'blocking'    => true,
				'headers'     => $headers,
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'code'  => 0,
				'error' => $response->get_error_message(),
				'retry' => true,
				'delay' => 0,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$ok   = $code >= 200 && $code < 300;

		if ( $ok ) {
			return array(
				'ok'    => true,
				'code'  => $code,
				'error' => '',
				'retry' => false,
				'delay' => 0,
			);
		}

		$retry = ( $code >= 500 ) || in_array( $code, array( 408, 425, 429 ), true );
		$delay = 0;

		if ( $retry ) {
			$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
			if ( is_numeric( $retry_after ) ) {
				$delay = min( 1800, max( 0, (int) $retry_after ) );
			}
		}

		$snippet = mb_substr( wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) ), 0, 300 );

		return array(
			'ok'    => false,
			'code'  => $code,
			'error' => sprintf( 'HTTP %d %s', $code, $snippet ),
			'retry' => $retry,
			'delay' => $delay,
		);
	}

	/**
	 * 是否应当推送这条评论。
	 *
	 * @param WP_Comment $comment 评论.
	 * @param string     $event   触发事件.
	 * @return bool
	 */
	public static function should_notify( $comment, $event = 'comment.new' ) {
		$settings = PSN_Settings::get();

		if ( empty( $settings['webhook_enabled'] ) || '' === (string) $settings['webhook_url'] ) {
			return false;
		}

		$post = get_post( (int) $comment->comment_post_ID );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return false;
		}

		if ( post_password_required( $post ) ) {
			return false;
		}

		// 段评。
		if ( 'wp_paragraph_comment' === $comment->comment_type && empty( $settings['include_paragraph'] ) ) {
			return false;
		}

		// 说说。
		if ( 'shuoshuo' === $post->post_type && empty( $settings['include_shuoshuo'] ) ) {
			return false;
		}

		// 评论人是文章作者本人。
		if ( empty( $settings['notify_self'] ) ) {
			$author_email = strtolower( (string) get_the_author_meta( 'user_email', (int) $post->post_author ) );
			if ( $author_email && strtolower( (string) $comment->comment_author_email ) === $author_email ) {
				return false;
			}
		}

		/**
		 * 过滤是否推送这条评论。
		 *
		 * @param bool       $notify  是否推送.
		 * @param WP_Comment $comment 评论.
		 * @param string     $event   事件名.
		 */
		return (bool) apply_filters( 'psn_should_notify_comment', true, $comment, $event );
	}

	/**
	 * 构建载荷。
	 *
	 * @param WP_Comment $comment    评论.
	 * @param int        $delivery_id 任务 ID（0 表示测试/未入队）.
	 * @return array
	 */
	public static function build_payload( $comment, $delivery_id = 0 ) {
		$comment = self::normalize_comment( $comment );
		$post    = get_post( (int) $comment->comment_post_ID );

		$approved = (int) $comment->comment_approved === 1;
		$event    = $approved ? 'comment.approved' : 'comment.new';

		$parent      = $comment->comment_parent ? get_comment( (int) $comment->comment_parent ) : null;
		$is_private  = 'true' === (string) get_comment_meta( (int) $comment->comment_ID, self::PRIVATE_META, true );
		$is_para     = 'wp_paragraph_comment' === $comment->comment_type;
		$para_index  = $is_para ? (int) get_comment_meta( (int) $comment->comment_ID, 'paragraph_index', true ) : null;
		$content_raw = (string) $comment->comment_content;

		$payload = array(
			'event'       => $event,
			'delivery_id' => (int) $delivery_id,
			'sent_at'     => self::iso_now(),
			'site'        => array(
				'name'    => get_bloginfo( 'name' ),
				'url'     => home_url( '/' ),
				'version' => PSN_VERSION,
			),
			'comment'     => array(
				'id'               => (int) $comment->comment_ID,
				'type'             => (string) $comment->comment_type,
				'url'              => get_comment_link( $comment ),
				'content'          => mb_substr( $content_raw, 0, 2000 ),
				'content_text'     => self::plain_text( $content_raw ),
				'content_excerpt'  => self::excerpt( $content_raw ),
				'approved'         => (string) $comment->comment_approved,
				'is_private'       => $is_private,
				'is_paragraph'     => $is_para,
				'paragraph_index'  => $para_index,
				'created_at'       => (string) $comment->comment_date,
				'created_at_iso'   => self::iso( (string) $comment->comment_date ),
				'author'           => self::author_block( (int) $comment->comment_ID, $comment->comment_author, $comment->comment_author_email, $comment->comment_author_url ),
				'parent'           => $parent ? array(
					'id'              => (int) $parent->comment_ID,
					'author'          => self::author_block( (int) $parent->comment_ID, $parent->comment_author, $parent->comment_author_email, $parent->comment_author_url ),
					'content_excerpt' => self::excerpt( (string) $parent->comment_content ),
				) : null,
				'post'             => $post ? array(
					'id'     => (int) $post->ID,
					'type'   => (string) $post->post_type,
					'title'  => get_the_title( $post ),
					'url'    => get_permalink( $post ),
					'author' => array(
						'id'    => (int) $post->post_author,
						'name'  => get_the_author_meta( 'display_name', (int) $post->post_author ),
						'email' => get_the_author_meta( 'user_email', (int) $post->post_author ),
						'qq'    => (string) get_user_meta( (int) $post->post_author, 'qq', true ),
					),
				) : null,
			),
			'targets'     => self::targets( $comment, $parent, $post ),
			'summary'     => '',
		);

		$payload['summary'] = self::summary( $payload );

		/**
		 * 过滤最终的 WebHook 载荷。
		 *
		 * @param array      $payload 载荷.
		 * @param WP_Comment $comment 评论.
		 */
		return apply_filters( 'psn_webhook_payload', $payload, $comment );
	}

	/**
	 * 把评论对象补齐成 WP_Comment（有些钩子传的是 stdClass 克隆）。
	 *
	 * @param mixed $comment 评论.
	 * @return WP_Comment
	 */
	private static function normalize_comment( $comment ) {
		return $comment instanceof WP_Comment ? $comment : get_comment( (int) $comment->comment_ID );
	}

	/**
	 * 评论人信息块。
	 *
	 * @param int    $comment_id 评论 ID（用于读 meta）.
	 * @param string $name       昵称.
	 * @param string $email      邮箱.
	 * @param string $url        网站.
	 * @return array
	 */
	private static function author_block( $comment_id, $name, $email, $url ) {
		$email = (string) $email;
		$qq    = (string) get_comment_meta( $comment_id, 'new_field_qq', true );

		return array(
			'id'          => 0,
			'name'        => (string) $name,
			'email'       => $email,
			'url'         => (string) $url,
			'qq'          => $qq,
			'avatar'      => $email ? (string) get_avatar_url( $email, array( 'size' => 96 ) ) : '',
			'ip_location' => (string) get_comment_meta( $comment_id, 'iro_ip_location', true ),
		);
	}

	/**
	 * 应被提醒的角色。
	 *
	 * @param WP_Comment      $comment 评论.
	 * @param WP_Comment|null $parent  父评论.
	 * @param WP_Post|null    $post    文章.
	 * @return string[]
	 */
	private static function targets( $comment, $parent, $post ) {
		$targets = array();

		if ( $post ) {
			$targets[] = 'post_author';
		}

		if ( $parent ) {
			$targets[] = 'parent_author';
		}

		/**
		 * 过滤提醒目标。
		 *
		 * @param string[]        $targets 目标.
		 * @param WP_Comment      $comment 评论.
		 * @param WP_Comment|null $parent  父评论.
		 */
		return apply_filters( 'psn_webhook_targets', $targets, $comment, $parent );
	}

	/**
	 * 单行摘要。
	 *
	 * @param array $payload 载荷.
	 * @return string
	 */
	private static function summary( $payload ) {
		$settings  = PSN_Settings::get();
		$comment   = $payload['comment'];
		$post      = $comment['post'];
		$parent    = $comment['parent'];

		$replace = array(
			'{site_name}'       => $payload['site']['name'],
			'{author}'          => $comment['author']['name'],
			'{post_title}'      => $post ? $post['title'] : '',
			'{post_url}'        => $post ? $post['url'] : '',
			'{comment_excerpt}' => $comment['content_excerpt'],
			'{parent_author}'   => $parent ? $parent['author']['name'] : '',
			'{comment_url}'     => $comment['url'],
		);

		$line = strtr( (string) $settings['summary_tpl'], $replace );
		$line = preg_replace( '#[\r\n]+#', ' ', (string) $line );

		/**
		 * 过滤单行摘要。
		 *
		 * @param string $line    摘要.
		 * @param array  $payload 载荷.
		 */
		return (string) apply_filters( 'psn_webhook_summary', $line, $payload );
	}

	/**
	 * 评论纯文本：去标签、去表情语法、压缩空白。
	 *
	 * @param string $raw 原始内容.
	 * @return string
	 */
	public static function plain_text( $raw ) {
		$text = (string) $raw;

		// 主题的表情语法：:name:、::tieba::、{{bili}}、[img]url[/img]。
		$text = preg_replace( '#\[img\](.*?)\[/img\]#is', '[图片]', $text );
		$text = preg_replace( '#::?[a-z0-9_+\-]+:?:?#i', '', $text );
		$text = preg_replace( '#\{\{(.*?)\}\}#s', '', $text );
		$text = strip_shortcodes( $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '#\s+#u', ' ', $text );

		return trim( (string) $text );
	}

	/**
	 * 摘要：纯文本前 N 个字符。
	 *
	 * @param string $raw 原始内容.
	 * @param int    $len 长度.
	 * @return string
	 */
	public static function excerpt( $raw, $len = 120 ) {
		$text = self::plain_text( $raw );

		if ( '' === $text ) {
			return '';
		}

		return mb_strlen( $text ) > $len ? mb_substr( $text, 0, $len ) . '…' : $text;
	}

	/**
	 * 本地时间转 ISO 8601。
	 *
	 * @param string $mysql 本地 mysql 时间.
	 * @return string
	 */
	private static function iso( $mysql ) {
		$timestamp = strtotime( (string) $mysql );

		if ( ! $timestamp ) {
			return '';
		}

		return (string) wp_date( 'c', $timestamp, wp_timezone() );
	}

	/**
	 * 当前时间的 ISO 表示。
	 *
	 * @return string
	 */
	private static function iso_now() {
		return (string) wp_date( 'c', current_time( 'timestamp' ), wp_timezone() );
	}

	/**
	 * 后台测试按钮用：发送一条假评论载荷。
	 *
	 * @return array{ok:bool,code:int,error:string,retry:bool,delay:int}
	 */
	public static function send_test() {
		$settings = PSN_Settings::get();
		$url      = (string) $settings['webhook_url'];

		if ( '' === $url ) {
			return array(
				'ok'    => false,
				'code'  => 0,
				'error' => psn_t( 'test_webhook_disabled' ),
				'retry' => false,
				'delay' => 0,
			);
		}

		$context = PSN_Mailer::placeholder_context();

		$payload = array(
			'event'       => 'webhook.test',
			'delivery_id' => 0,
			'sent_at'     => self::iso_now(),
			'site'        => array(
				'name'    => get_bloginfo( 'name' ),
				'url'     => home_url( '/' ),
				'version' => PSN_VERSION,
			),
			'comment'     => array(
				'id'              => 0,
				'type'            => 'comment',
				'url'             => home_url( '/' ),
				'content'         => $context['comment_excerpt'],
				'content_text'    => $context['comment_excerpt'],
				'content_excerpt' => $context['comment_excerpt'],
				'approved'        => '1',
				'is_private'      => false,
				'is_paragraph'    => false,
				'paragraph_index' => null,
				'created_at'      => current_time( 'mysql' ),
				'created_at_iso'  => self::iso_now(),
				'author'          => array(
					'id'          => 0,
					'name'        => $context['author'],
					'email'       => '',
					'url'         => '',
					'qq'          => '',
					'avatar'      => '',
					'ip_location' => '',
				),
				'parent'          => null,
				'post'            => array(
					'id'     => 0,
					'type'   => 'post',
					'title'  => $context['post_title'],
					'url'    => $context['post_url'],
					'author' => array(
						'id'    => get_current_user_id(),
						'name'  => wp_get_current_user()->display_name,
						'email' => wp_get_current_user()->user_email,
						'qq'    => (string) get_user_meta( get_current_user_id(), 'qq', true ),
					),
				),
			),
			'targets'     => array( 'post_author' ),
			'summary'     => strtr(
				(string) $settings['summary_tpl'],
				array(
					'{site_name}'       => get_bloginfo( 'name' ),
					'{author}'          => $context['author'],
					'{post_title}'      => $context['post_title'],
					'{post_url}'        => $context['post_url'],
					'{comment_excerpt}' => $context['comment_excerpt'],
					'{parent_author}'   => '',
					'{comment_url}'     => $context['comment_url'],
				)
			),
		);

		$body = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		$headers = array_merge(
			array(
				'Content-Type'   => 'application/json; charset=utf-8',
				'User-Agent'     => 'PostSubscribeNotifier/' . PSN_VERSION . '; ' . home_url( '/' ),
				'X-PSN-Event'    => 'webhook.test',
				'X-PSN-Delivery' => 'test',
			),
			PSN_Settings::extra_headers()
		);

		if ( '' !== (string) $settings['webhook_secret'] ) {
			$headers['X-PSN-Signature'] = 'sha256=' . hash_hmac( 'sha256', (string) $body, (string) $settings['webhook_secret'] );
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => (int) $settings['webhook_timeout'],
				'redirection' => 2,
				'blocking'    => true,
				'headers'     => $headers,
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'code'  => 0,
				'error' => $response->get_error_message(),
				'retry' => true,
				'delay' => 0,
			);
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$snippet = mb_substr( wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) ), 0, 300 );

		return array(
			'ok'    => $code >= 200 && $code < 300,
			'code'  => $code,
			'error' => $code >= 200 && $code < 300 ? $snippet : sprintf( 'HTTP %d %s', $code, $snippet ),
			'retry' => false,
			'delay' => 0,
		);
	}
}
