<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/*
 * Adds support for writing math directly in post content using dollar
 * delimiters:
 *
 *   $x^2$              inline math
 *   $$
 *   \int_0^1 x^2\,dx
 *   $$                 display math
 *
 * The delimiters are converted to `.katex-eq` spans which are rendered on the
 * front end by `assets/render.js`, so no changes to the rendering code are
 * required.
 */

add_filter( 'the_content', 'katex_dollar_render', 9 );


function katex_dollar_render( $content ) {
    global $katex_resources_required;

    if ( empty( $content ) || false === strpos( $content, '$' ) ) {
        return $content;
    }

    // Protect existing KaTeX elements (shortcodes / blocks) and code blocks
    // from being touched by the dollar parsing below.
    $protected = array();

    $content = preg_replace_callback(
        '/<(span|div)\b[^>]*\bkatex-eq\b[^>]*>.*?<\/\1>|<pre\b[^>]*>.*?<\/pre>|<code\b[^>]*>.*?<\/code>/is',
        function ( $matches ) use ( &$protected ) {
            $key = "\x1A" . 'KATEXEQ' . count( $protected ) . "\x1A";
            $protected[ $key ] = $matches[0];
            return $key;
        },
        $content
    );

    // Display math: $$...$$ (may span multiple lines).
    $content = preg_replace_callback(
        '/\$\$([\s\S]+?)\$\$/',
        function ( $matches ) {
            global $katex_resources_required;
            $katex_resources_required = true;
            return '<span class="katex-eq" data-katex-display="true">'
                . htmlspecialchars( html_entity_decode( $matches[1] ), ENT_QUOTES )
                . '</span>';
        },
        $content
    );

    // Inline math: $...$ (single line only).
    $content = preg_replace_callback(
        '/(?<![\\\\$])\$(?!\$)([^\$\n]+?)(?<![\\\\])\$(?!\$)/',
        function ( $matches ) {
            global $katex_resources_required;
            $katex_resources_required = true;
            return '<span class="katex-eq" data-katex-display="false">'
                . htmlspecialchars( html_entity_decode( $matches[1] ), ENT_QUOTES )
                . '</span>';
        },
        $content
    );

    // Restore protected content.
    foreach ( $protected as $key => $value ) {
        $content = str_replace( $key, $value, $content );
    }

    return $content;
}
