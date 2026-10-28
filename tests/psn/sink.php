<?php
/**
 * 本地 WebHook 接收器（手工端到端验证用）。
 *
 * 起服务：
 *   php -S 127.0.0.1:8899 tests/psn/sink.php
 *
 * 收到的每条请求都会追加到 tests/psn/_received.jsonl，并按签名校验结果返回 JSON。
 * 生产环境不需要这个文件，它只是给调试用的。
 *
 * @package Post_Subscribe_Notifier
 */

$raw    = file_get_contents( 'php://input' );
$secret = getenv( 'PSN_SINK_SECRET' );
$sig    = isset( $_SERVER['HTTP_X_PSN_SIGNATURE'] ) ? (string) $_SERVER['HTTP_X_PSN_SIGNATURE'] : '';
$event  = isset( $_SERVER['HTTP_X_PSN_EVENT'] ) ? (string) $_SERVER['HTTP_X_PSN_EVENT'] : '';

$signature_ok = null;
if ( false !== $secret && '' !== $secret ) {
	$signature_ok = hash_equals( 'sha256=' . hash_hmac( 'sha256', $raw, $secret ), $sig );
}

$record = array(
	'time'         => date( 'c' ),
	'event'        => $event,
	'signature'    => $sig,
	'signature_ok' => $signature_ok,
	'payload'      => json_decode( $raw, true ),
);

$dir = __DIR__;
file_put_contents( $dir . '/_received.jsonl', json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n", FILE_APPEND );

header( 'Content-Type: application/json; charset=utf-8' );
echo json_encode(
	array(
		'ok'           => true,
		'signature_ok' => $signature_ok,
		'event'        => $event,
	),
	JSON_UNESCAPED_UNICODE
);
