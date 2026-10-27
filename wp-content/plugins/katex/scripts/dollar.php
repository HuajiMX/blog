<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/*
 * Front-end math support.
 *
 * Markdown keeps `$x$` / `$$…$$` verbatim in post_content; this filter turns
 * them into `.katex-eq` elements that assets/render.js hands to KaTeX.
 *
 * The syntax itself is defined once in scripts/grammar.php, so adding a new
 * delimiter does not require touching this file.
 *
 * Runs at priority 9, right after do_blocks() and before wpautop() (10), so a
 * display formula can still own its paragraph and avoid wpautop's <br />.
 */

add_filter( 'the_content', 'katex_render_math_html', 9 );

// Home and archive cards show `the_excerpt()`. wp_trim_excerpt() runs
// `the_content` and then strips tags, which would leave `k \ll n` in the
// teaser, so math is removed while the excerpt is being built (see
// katex_render_math_html()). These filters catch a manually written excerpt
// that already contains rendered math.
add_filter( 'get_the_excerpt', 'katex_strip_math_from_excerpt', 20 );
add_filter( 'the_excerpt', 'katex_strip_math_from_excerpt', 20 );


/**
 * Backwards-compatible alias for the pre-refactor filter callback.
 *
 * @param string $content Post content.
 * @return string
 */
function katex_dollar_render( $content ) {
    return katex_render_math_html( $content );
}

/**
 * Remove math from a teaser.
 *
 * A card excerpt is prose: `k \ll n` is noise there, and a display formula
 * reduced to its source is worse than no formula. The full article still shows
 * the math, and the KaTeX assets are not loaded on archive pages at all.
 *
 * @param string $excerpt Excerpt HTML or text.
 * @return string
 */
function katex_strip_math_from_excerpt( $excerpt ) {
    if ( ! is_string( $excerpt ) || '' === $excerpt ) {
        return $excerpt;
    }
    if ( false === strpos( $excerpt, 'katex-eq' ) && false === strpos( $excerpt, '$' ) ) {
        return $excerpt;
    }

    // Rendered math elements, including their TeX body and attribute.
    $excerpt = preg_replace(
        '#<(span|div)\b[^>]*\bclass="[^"]*\bkatex-eq\b[^"]*"[^>]*>.*?</\1>#is',
        ' ',
        $excerpt
    );

    // Math that was never converted (excerpt taken before the content filter,
    // or a saved post that predates it).
    foreach ( katex_math_rules() as $rule ) {
        $excerpt = preg_replace( $rule['pattern'], ' ', $excerpt );
    }

    // The removals leave double spaces and space before punctuation.
    $excerpt = preg_replace( '/\s+/u', ' ', $excerpt );
    $excerpt = preg_replace( '/\s+([，。、；：！？,.;:!?])/u', '$1', $excerpt );

    return trim( $excerpt );
}
