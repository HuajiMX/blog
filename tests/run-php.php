<?php
/**
 * Server-side math pipeline tests.
 *
 * Boots WordPress, then pushes every case from tests/math-cases.json through
 * the same two steps the site uses:
 *
 *   1. wp-githuber-md saves the post: Markdown -> HTML (math tokenized first)
 *   2. the KaTeX plugin filters the_content on the way out
 *
 * Usage (from the WordPress root, inside the machine that runs the site):
 *
 *   php tests/run-php.php
 *
 * Exit code 0 means every case passed.
 */

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';

$root = dirname( __DIR__ );
require $root . '/wp-load.php';

// The KaTeX plugin is loaded by WordPress only when it is active. Load it here
// so the tests also work on a checkout where the plugin is deactivated.
if ( ! function_exists( 'katex_markdown_protect' ) ) {
    define( 'KATEX__PLUGIN_VERSION', 'test' );
    define( 'KATEX__PLUGIN_DIR', $root . '/wp-content/plugins/katex/' );
    define( 'KATEX__PLUGIN_URL', 'http://localhost/wp-content/plugins/katex/' );
    define( 'KATEX__OPTION_DEFAULT_LOAD_ASSETS_CONDITIONALLY', false );
    define( 'KATEX__OPTION_DEFAULT_ENABLE_LATEX_SHORTCODE', true );

    require KATEX__PLUGIN_DIR . 'scripts/grammar.php';
    require KATEX__PLUGIN_DIR . 'scripts/shortcode.php';
    require KATEX__PLUGIN_DIR . 'scripts/dollar.php';
    require KATEX__PLUGIN_DIR . 'scripts/markdown.php';
}

if ( ! class_exists( 'Githuber\\Controller\\Markdown' ) ) {
    fwrite( STDERR, "wp-githuber-md is not active; the save step cannot be exercised.\n" );
    exit( 1 );
}

$suite = json_decode( file_get_contents( __DIR__ . '/math-cases.json' ), true );
if ( ! is_array( $suite ) || empty( $suite['cases'] ) ) {
    fwrite( STDERR, "tests/math-cases.json is missing or empty.\n" );
    exit( 1 );
}

$markdown = new Githuber\Controller\Markdown();
$failures = 0;
$total    = 0;

/**
 * Collect the properties the cases assert on.
 *
 * @param string $html Post content after both steps.
 * @return array
 */
function math_probe( $html ) {
    $elements = array();
    $tags     = array();
    $display  = array();
    $tex      = array();

    if ( preg_match_all( '#<(span|div)\b[^>]*class="[^"]*\bkatex-eq\b[^"]*"[^>]*>#i', $html, $matches, PREG_SET_ORDER ) ) {
        foreach ( $matches as $match ) {
            $tags[]  = strtolower( $match[1] );
            $display[] = (bool) preg_match( '#data-katex-display="true"#i', $match[0] );
            $tex[]     = preg_match( '#data-katex-tex="([^"]*)"#i', $match[0], $t )
                ? html_entity_decode( $t[1], ENT_QUOTES, 'UTF-8' )
                : null;
        }
    }

    return array(
        'elements' => count( $tags ),
        'tags'     => $tags,
        'display'  => $display,
        'tex'      => $tex,
        'html'     => $html,
    );
}

foreach ( $suite['cases'] as $case ) {
    $total++;
    $name = $case['name'];

    if ( isset( $case['html'] ) ) {
        // Saved markup from an older version: only the content filter runs.
        $front = apply_filters( 'the_content', $case['html'] );
    } else {
        $saved = $markdown->transform( $case['markdown'], array( 'unslash' => false ) );
        $front = apply_filters( 'the_content', $saved );
    }
    $probe = math_probe( $front );

    $problems = array();
    $expect   = isset( $case['expect'] ) ? $case['expect'] : array();

    if ( isset( $expect['elements'] ) && $probe['elements'] !== $expect['elements'] ) {
        $problems[] = sprintf( 'expected %d math element(s), found %d', $expect['elements'], $probe['elements'] );
    }
    if ( isset( $expect['display'] ) && $probe['display'] !== $expect['display'] ) {
        $problems[] = sprintf( 'display flags: expected [%s], found [%s]',
            implode( ',', array_map( 'intval', $expect['display'] ) ),
            implode( ',', array_map( 'intval', $probe['display'] ) ) );
    }
    if ( isset( $expect['tags'] ) && $probe['tags'] !== $expect['tags'] ) {
        $problems[] = sprintf( 'tags: expected [%s], found [%s]',
            implode( ',', $expect['tags'] ), implode( ',', $probe['tags'] ) );
    }
    if ( isset( $expect['tex'] ) && $probe['tex'] !== $expect['tex'] ) {
        $problems[] = sprintf( 'tex: expected %s, found %s',
            json_encode( $expect['tex'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
            json_encode( $probe['tex'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    }
    foreach ( (array) ( $expect['textContains'] ?? array() ) as $needle ) {
        if ( false === strpos( $front, $needle ) ) {
            $problems[] = sprintf( 'missing %s', json_encode( $needle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        }
    }
    foreach ( (array) ( $expect['absent'] ?? array() ) as $needle ) {
        if ( false !== strpos( $front, $needle ) ) {
            $problems[] = sprintf( 'unexpected %s', json_encode( $needle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        }
    }

    if ( $problems ) {
        $failures++;
        echo "FAIL  {$name}\n";
        foreach ( $problems as $problem ) {
            echo "        - {$problem}\n";
        }
        echo "        front: " . str_replace( "\n", '\\n', $front ) . "\n";
    } else {
        echo "ok    {$name}\n";
    }
}

echo "\n{$total} case(s), {$failures} failure(s)\n";
exit( $failures ? 1 : 0 );
