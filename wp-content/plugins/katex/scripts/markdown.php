<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/*
 * Save-time adapter.
 *
 * wp-githuber-md turns Markdown into HTML with Parsedown before the post is
 * stored. Math has to survive that step untouched, otherwise Parsedown eats
 * backslash escapes (`$\{x\}$` -> `${x}$`) and turns `*`/`_` inside formulas
 * into emphasis.
 *
 * The original patch tokenized `$…$` in the Markdown source, but it only
 * protected HTML `<pre>/<code>` — not Markdown fences. A single `$$` inside a
 * fenced code block then paired with the next one in the document and the rest
 * of the post ended up inside one <pre>. That is fixed by
 * katex_protect_markdown_code() in grammar.php.
 *
 * The syntax table lives in grammar.php; this file only wires it into Githuber.
 */

/**
 * Replace math with placeholders before Parsedown runs.
 *
 * @param string $text   Markdown source.
 * @param array  $tokens Placeholder map, filled by reference.
 * @return string
 */
function katex_markdown_preserve( $text, &$tokens ) {
    return katex_markdown_protect( $text, $tokens );
}

/**
 * Put the math elements back into Parsedown's output.
 *
 * @param string $text   HTML.
 * @param array  $tokens Placeholder map.
 * @return string
 */
function katex_markdown_unpreserve( $text, $tokens ) {
    return katex_markdown_restore( $text, $tokens );
}

/**
 * Is the grammar layer available?
 *
 * wp-githuber-md is edited in place here, but a missing KaTeX plugin must
 * degrade to "no protection" instead of a fatal error.
 *
 * @return bool
 */
function katex_markdown_available() {
    return function_exists( 'katex_markdown_protect' ) && function_exists( 'katex_markdown_restore' );
}
