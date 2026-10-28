<?php
/**
 * Geo lookup behaviour tests (no network).
 *
 * Exercises the pure decision logic that governs how IpLocation reacts to a
 * provider outcome: it must never leak warnings to the page, and a
 * "this IP has no record" result must NOT open the global outage window
 * (which would disable lookups for every other IP for 10 minutes).
 *
 * Usage: php tests/run-geo.php
 *
 * Exit code 0 means every case passed.
 */

// Minimal WordPress shims: IpLocation only needs these few functions.
$GLOBALS['__transients'] = array();
$GLOBALS['__comments_meta'] = array();
$GLOBALS['__errors'] = array();

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

function get_locale() { return 'zh_CN'; }
function apply_filters( $tag, $value ) { return $value; }
function get_transient( $key ) { return isset( $GLOBALS['__transients'][ $key ] ) ? $GLOBALS['__transients'][ $key ] : false; }
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['__transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['__transients'][ $key ] ); return true; }
function add_comment_meta( $id, $key, $value ) { $GLOBALS['__comments_meta'][ $id ][ $key ] = $value; return true; }
function get_comment_meta( $id, $key ) { return isset( $GLOBALS['__comments_meta'][ $id ][ $key ] ) ? $GLOBALS['__comments_meta'][ $id ][ $key ] : ''; }
function get_comment_author_IP( $id ) { return isset( $GLOBALS['__comment_ips'][ $id ] ) ? $GLOBALS['__comment_ips'][ $id ] : ''; }
function iro_opt( $key ) { return true; }
function __( $s ) { return $s; }

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

class WP_Error {
    private $message;
    public function __construct( $message = '' ) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}

class FakeResponse {
    public $code;
    public $body;
    public $headers;
    public function __construct( $code, $body, $headers = array() ) {
        $this->code = $code;
        $this->body = $body;
        $this->headers = $headers;
    }
}

function wp_remote_get( $url, $args = array() ) {
    $GLOBALS['__requests'][] = $url;
    $handler = $GLOBALS['__http_handler'];
    return $handler( $url, $args );
}
function wp_remote_retrieve_response_code( $r ) { return $r->code; }
function wp_remote_retrieve_body( $r ) { return $r->body; }
function wp_remote_retrieve_headers( $r ) { return $r->headers; }

require dirname( __DIR__ ) . '/wp-content/themes/Sakurairo/inc/classes/IpLocation.php';

use Sakura\API\IpLocation;

$failures = 0;
$total    = 0;

function geo_check( $name, $ok, $detail = '' ) {
    global $failures, $total;
    $total++;
    if ( ! $ok ) {
        $failures++;
    }
    echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $name . ( $ok || $detail === '' ? '' : "  ({$detail})" ) . "\n";
}

function reset_state() {
    $GLOBALS['__transients']  = array();
    $GLOBALS['__requests']    = array();
    $GLOBALS['__errors']      = array();
    $GLOBALS['__http_handler'] = null;
}

// ---- 1. Provider "no record for this IP" must not open the outage window. ----
reset_state();
$GLOBALS['__http_handler'] = function ( $url ) {
    return new FakeResponse( 200, '{"ip":"8.8.8.8","success":false,"message":"Reserved range"}' );
};

$loc = new IpLocation( '8.8.8.8' );
$result = $loc->getLocation();
geo_check( 'no-record returns false', false === $result );
geo_check( 'no-record does NOT open outage window', false === get_transient( 'ip_location_outage' ) );

// ---- 2. A later, healthy IP still gets looked up after a no-record result. ----
$GLOBALS['__http_handler'] = function ( $url ) {
    if ( strpos( $url, 'ipwho.is' ) !== false ) {
        return new FakeResponse( 200, '{"success":true,"country":"中国","region":"湖北省","city":"武汉"}' );
    }
    return new FakeResponse( 500, '' );
};
$loc2 = new IpLocation( '58.19.27.122' );
$result2 = $loc2->getLocation();
geo_check( 'healthy IP resolves after a no-record result',
    is_array( $result2 ) && $result2['country'] === '中国' && $result2['city'] === '武汉' );

// ---- 3. Primary unreachable -> falls back to secondary. ----
reset_state();
$GLOBALS['__http_handler'] = function ( $url ) {
    if ( strpos( $url, 'ipwho.is' ) !== false ) {
        return new WP_Error( 'cURL error 28' );
    }
    return new FakeResponse( 200, '{"status":"success","country":"美国","regionName":"加州","city":"圣何塞"}' );
};
$loc3 = new IpLocation( '8.8.8.8' );
$result3 = $loc3->getLocation();
geo_check( 'primary down -> secondary answers',
    is_array( $result3 ) && $result3['city'] === '圣何塞' );
geo_check( 'fallback success does NOT open outage window', false === get_transient( 'ip_location_outage' ) );

// ---- 4. Both providers unreachable -> outage window opens, next call is skipped. ----
reset_state();
$GLOBALS['__http_handler'] = function () {
    return new WP_Error( 'cURL error 28: timed out' );
};
$loc4 = new IpLocation( '8.8.8.8' );
geo_check( 'both down returns false', false === $loc4->getLocation() );
geo_check( 'both down opens outage window', false !== get_transient( 'ip_location_outage' ) );

$requestsBefore = count( $GLOBALS['__requests'] );
$loc5 = new IpLocation( '1.1.1.1' );
geo_check( 'outage window short-circuits', false === $loc5->getLocation() );
geo_check( 'outage window issues no request', count( $GLOBALS['__requests'] ) === $requestsBefore );

// ---- 5. Partial data (country only) still counts as a hit. ----
reset_state();
$GLOBALS['__http_handler'] = function () {
    return new FakeResponse( 200, '{"success":true,"country":"中国","region":"","city":""}' );
};
$loc6 = new IpLocation( '36.150.185.23' );
$result6 = $loc6->getLocation();
geo_check( 'country-only is accepted', is_array( $result6 ) && $result6['country'] === '中国' );
geo_check( 'country-only concision has no double spaces',
    ( new \Sakura\API\IpLocationParse( array( 'country' => '中国', 'region' => '', 'city' => '' ) ) )->getLocationConcision() === '中国' );

// ---- 6. Invalid / private addresses never hit the network. ----
reset_state();
$GLOBALS['__http_handler'] = function () {
    return new FakeResponse( 200, '{}' );
};
geo_check( 'private IP rejected before lookup', false === ( new IpLocation( '192.168.1.1' ) )->getLocation() );
geo_check( 'private IP issues no request', count( $GLOBALS['__requests'] ) === 0 );

// ---- 7. Never emit PHP warnings (the production display_errors=On leak). ----
reset_state();
$GLOBALS['__http_handler'] = function () {
    return new WP_Error( 'cURL error 28' );
};
$captured = array();
set_error_handler( function ( $errno, $errstr ) use ( &$captured ) {
    $captured[] = $errstr;
    return true;
} );
error_reporting( E_ALL );
( new IpLocation( '8.8.8.8' ) )->getLocation();
restore_error_handler();
geo_check( 'lookup emits no PHP warnings/notices', $captured === array(), implode( '; ', $captured ) );

echo "\n{$total} case(s), {$failures} failure(s)\n";
exit( $failures ? 1 : 0 );
