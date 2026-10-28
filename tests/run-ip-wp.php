<?php
/**
 * WordPress-side verification for the real-IP mu-plugin.
 *
 * Loads WordPress, then exercises the same paths the site uses:
 *   - $_SERVER['REMOTE_ADDR'] after bootstrap
 *   - get_the_user_ip() (theme helper)
 *   - the pre_comment_user_ip / wpb_get_ip filters
 *   - wp_filter_comment() with a REMOTE_ADDR-derived comment_author_IP
 *
 * Run with simulated headers (the CLI env carries its own $_SERVER, so the
 * caller sets them first):
 *
 *   REMOTE_ADDR=36.150.185.23 HTTP_X_VIA='shanks/0.11.13' \
 *   HTTP_X_REAL_IP=183.94.22.122 HTTP_CLIENT_IP=183.94.22.122 \
 *   php tests/run-ip-wp.php
 *
 * Exit code 0 means every assertion passed.
 */

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';

// php -S / CLI do not populate these; mirror what the caller exported.
foreach ( array( 'REMOTE_ADDR', 'HTTP_X_VIA', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR' ) as $key ) {
    if ( ! isset( $_SERVER[ $key ] ) ) {
        $value = getenv( $key );
        if ( $value !== false && $value !== '' ) {
            $_SERVER[ $key ] = $value;
        }
    }
}
if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}
if ( empty( $_SERVER['REQUEST_METHOD'] ) ) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

require dirname( __DIR__ ) . '/wp-load.php';

$checks = array();
$fail   = 0;

/**
 * Record one assertion.
 *
 * @param string $name     Human readable check.
 * @param bool   $ok       Result.
 * @param string $detail   Detail shown on failure.
 * @return void
 */
function ip_check( $name, $ok, $detail = '' ) {
    global $checks, $fail;
    $checks[] = array( $name, $ok, $detail );
    if ( ! $ok ) {
        $fail++;
    }
    echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $name . ( $ok || $detail === '' ? '' : "  ({$detail})" ) . "\n";
}

ip_check( 'mu-plugin loaded', function_exists( 'iro_get_client_ip' ) );
ip_check( 'edge address preserved', defined( 'IRO_REAL_IP_EDGE' ), 'IRO_REAL_IP_EDGE missing' );

$expected = getenv( 'IRO_EXPECT_IP' );
if ( $expected === false || $expected === '' ) {
    fwrite( STDERR, "Set IRO_EXPECT_IP to the address the scenario should resolve to.\n" );
    exit( 2 );
}

ip_check( 'iro_get_client_ip()', iro_get_client_ip() === $expected, 'got ' . iro_get_client_ip() . ', want ' . $expected );
ip_check( 'REMOTE_ADDR was rewritten', $_SERVER['REMOTE_ADDR'] === $expected, 'got ' . $_SERVER['REMOTE_ADDR'] );

if ( function_exists( 'get_the_user_ip' ) ) {
    ip_check( 'theme get_the_user_ip()', get_the_user_ip() === $expected, 'got ' . get_the_user_ip() );
} else {
    fwrite( STDERR, "theme get_the_user_ip() is not available; is the theme active?\n" );
    exit( 2 );
}

ip_check( 'pre_comment_user_ip filter', apply_filters( 'pre_comment_user_ip', '203.0.113.7' ) === $expected, 'got ' . apply_filters( 'pre_comment_user_ip', '203.0.113.7' ) );
ip_check( 'wpb_get_ip filter', apply_filters( 'wpb_get_ip', '203.0.113.7' ) === $expected, 'got ' . apply_filters( 'wpb_get_ip', '203.0.113.7' ) );

// wp_filter_comment() is what actually sanitizes a comment row; confirm the
// stored IP matches what a real submission would end up with.
$filtered = wp_filter_comment( array(
    'comment_post_ID'      => 1,
    'comment_author'       => 'ip test',
    'comment_author_email' => 'ip-test@example.com',
    'comment_author_url'   => '',
    'comment_content'      => 'ip test',
    'comment_author_IP'    => $_SERVER['REMOTE_ADDR'],
    'comment_agent'        => 'test',
) );
ip_check( 'wp_filter_comment() IP', $filtered['comment_author_IP'] === $expected, 'got ' . $filtered['comment_author_IP'] );

echo "\n" . count( $checks ) . " check(s), {$fail} failure(s)\n";
exit( $fail ? 1 : 0 );
