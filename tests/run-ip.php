<?php
/**
 * Client IP resolution tests for wp-content/mu-plugins/iro-real-ip/resolver.php.
 *
 * Pure PHP: boots nothing, touches no database, needs no web request.
 *
 * Usage (from the WordPress root, anywhere PHP is available):
 *
 *   php tests/run-ip.php
 *
 * Exit code 0 means every case passed.
 */

require dirname( __DIR__ ) . '/wp-content/mu-plugins/iro-real-ip/resolver.php';

$suite = json_decode( file_get_contents( __DIR__ . '/ip-cases.json' ), true );
if ( ! is_array( $suite ) || empty( $suite['cases'] ) ) {
    fwrite( STDERR, "tests/ip-cases.json is missing or empty.\n" );
    exit( 1 );
}

$failures = 0;
$total    = 0;

foreach ( $suite['cases'] as $case ) {
    $total++;
    $server = $case['server'];
    $opts   = isset( $case['opts'] ) ? $case['opts'] : array();
    $got    = iro_resolve_client_ip( $server, $opts );
    $expect = $case['expect'];

    $problems = array();
    foreach ( $expect as $key => $value ) {
        if ( ! array_key_exists( $key, $got ) ) {
            $problems[] = sprintf( '%s: missing from result', $key );
            continue;
        }
        if ( $got[ $key ] !== $value ) {
            $problems[] = sprintf(
                '%s: expected %s, got %s',
                $key,
                json_encode( $value, JSON_UNESCAPED_SLASHES ),
                json_encode( $got[ $key ], JSON_UNESCAPED_SLASHES )
            );
        }
    }

    if ( $problems ) {
        $failures++;
        echo "FAIL  {$case['name']}\n";
        foreach ( $problems as $problem ) {
            echo "        - {$problem}\n";
        }
    } else {
        echo "ok    {$case['name']}\n";
    }
}

foreach ( (array) ( $suite['cidrCases'] ?? array() ) as $case ) {
    $total++;
    $got = iro_ip_in_cidrs( $case['ip'], $case['cidrs'] );
    if ( $got !== $case['expect'] ) {
        $failures++;
        echo "FAIL  cidr {$case['ip']} in " . implode( ',', $case['cidrs'] ) . "\n";
        echo '        - expected ' . json_encode( $case['expect'] ) . ', got ' . json_encode( $got ) . "\n";
    } else {
        echo "ok    cidr {$case['ip']} in " . implode( ',', $case['cidrs'] ) . "\n";
    }
}

echo "\n{$total} case(s), {$failures} failure(s)\n";
exit( $failures ? 1 : 0 );
