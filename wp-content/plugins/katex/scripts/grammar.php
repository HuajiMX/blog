<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/*
 * Single source of truth for math syntax.
 *
 * Everything that has to understand `$...$` uses this file:
 *
 *   1. scripts/dollar.php   - front end: HTML -> .katex-eq elements
 *   2. scripts/markdown.php - save time: tokenize math so Markdown cannot mangle it
 *   3. assets/katex-core.js - browser: same markup, driven by the localized table
 *
 * Adding a syntax means adding one entry to katex_math_rules(): nothing else
 * needs to know about it. Rules are plain PCRE strings that are also valid
 * JavaScript regular expressions, so one table drives both PHP and the browser.
 * Stay inside that common subset: no possessive quantifiers, no atomic groups,
 * no `\K`, no PCRE-only verbs. The TeX source must be capture group 1.
 */

/**
 * The math syntax table.
 *
 * Each rule:
 *   id      string  Stable identifier, also sent to the browser.
 *   display bool    Render in display (block) mode.
 *   block   bool    May become a real <div> when it spans several lines.
 *   pattern string  PCRE, TeX source in capture group 1, delimiters included.
 *
 * To add `\(x\)` / `\[x\]`, append:
 *
 *   array( 'id' => 'display_bracket', 'display' => true, 'block' => true,
 *          'pattern' => '/(?<!\\\\)\\\\\[((?:(?!\\\\\]).)+?)(?<!\\\\)\\\\\]/s' ),
 *   array( 'id' => 'inline_paren', 'display' => false, 'block' => false,
 *          'pattern' => '/(?<!\\\\)\\\\\(((?:(?!\\\\\)).)+?)(?<!\\\\)\\\\\)/s' ),
 *
 * @return array[]
 */
function katex_math_rules() {
    static $rules = null;

    if ( null === $rules ) {
        $rules = apply_filters(
            'katex_math_rules',
            array(
                array(
                    'id'      => 'display_dollar',
                    'display' => true,
                    'block'   => true,
                    'pattern' => '/\$\$(?!\s*\$)([\s\S]+?)\$\$/',
                ),
                array(
                    'id'      => 'inline_dollar',
                    'display' => false,
                    'block'   => false,
                    // `$x$` only: no newline, no leading/trailing space and no
                    // currency amount (`$5 … $10`), which is the classic `$`
                    // ambiguity. `\$` never starts a match.
                    'pattern' => '/(?<![\\\\$])\$(?![\s$])([^\n$]+?)(?<![\s\\\\])\$(?!\d)/',
                ),
            )
        );
    }

    return $rules;
}

/**
 * Regex body of a rule, without PHP delimiters and modifiers.
 *
 * @param array $rule Rule.
 * @return string
 */
function katex_rule_body( $rule ) {
    $pattern   = $rule['pattern'];
    $delimiter = substr( $pattern, 0, 1 );
    $end       = strrpos( $pattern, $delimiter );

    return substr( $pattern, 1, $end - 1 );
}

/**
 * Modifiers of a pattern, translated to JavaScript flags.
 *
 * @param string $pattern PCRE with delimiters.
 * @return string
 */
function katex_rule_flags( $pattern ) {
    $delimiter = substr( $pattern, 0, 1 );
    $modifiers = substr( $pattern, strrpos( $pattern, $delimiter ) + 1 );

    $map = array(
        'i' => 'i',
        'm' => 'm',
        's' => 's',
        'u' => 'u',
    );

    $flags = '';
    foreach ( str_split( $modifiers ) as $modifier ) {
        if ( isset( $map[ $modifier ] ) && false === strpos( $flags, $map[ $modifier ] ) ) {
            $flags .= $map[ $modifier ];
        }
    }

    return $flags;
}

/**
 * Same rule, shaped for JavaScript.
 *
 * @param array $rule Rule.
 * @return array
 */
function katex_rule_for_js( $rule ) {
    return array(
        'id'      => $rule['id'],
        'display' => (bool) $rule['display'],
        'block'   => (bool) $rule['block'],
        // JS builds its own RegExp, so PHP delimiters go and modifiers become
        // JavaScript flags.
        'pattern' => katex_rule_body( $rule ),
        'flags'   => katex_rule_flags( $rule['pattern'] ),
    );
}

/**
 * The same table, shaped for JavaScript.
 *
 * @return array[]
 */
function katex_math_rules_for_js() {
    return array_map( 'katex_rule_for_js', katex_math_rules() );
}

/**
 * Build one math element.
 *
 * TeX is carried twice on purpose:
 *   - in `data-katex-tex`, so wptexturize/smilies cannot corrupt it (they skip
 *     attribute values) and wpautop cannot insert <br /> into it;
 *   - in the text content (newlines as `&#10;`), so feeds, meta descriptions
 *     and no-JS readers still get something readable.
 *
 * @param string $tex     TeX source.
 * @param bool   $display Display mode.
 * @param bool   $block   Emit a <div> instead of a <span>.
 * @return string
 */
function katex_math_element( $tex, $display, $block ) {
    $tex = str_replace( array( "\r\n", "\r" ), "\n", (string) $tex );
    $tex = htmlspecialchars( html_entity_decode( $tex, ENT_QUOTES, 'UTF-8' ), ENT_QUOTES, 'UTF-8' );

    if ( $block ) {
        $text = str_replace( "\n", '&#10;', $tex );
        $tag  = 'div';
    } else {
        $text = trim( preg_replace( '/\s+/', ' ', str_replace( "\n", ' ', $tex ) ) );
        $tag  = 'span';
    }

    return sprintf(
        '<%1$s class="katex-eq" data-katex-display="%2$s" data-katex-tex="%3$s">%4$s</%1$s>',
        $tag,
        $display ? 'true' : 'false',
        str_replace( "\n", '&#10;', $tex ),
        $text
    );
}

/* -------------------------------------------------------------------------
 * Shared: opaque placeholders
 * ---------------------------------------------------------------------- */

/**
 * Hide a region behind a placeholder.
 *
 * @param array  $store Placeholder map, appended to.
 * @param string $html  Region to hide.
 * @return string
 */
function katex_store_region( &$store, $html ) {
    $key           = "\x1A" . 'IROMATH' . count( $store ) . "\x1A";
    $store[ $key ] = $html;
    return $key;
}

/**
 * @param string $content Content with placeholders.
 * @param array  $store   Placeholder map.
 * @return string
 */
function katex_restore_regions( $content, $store ) {
    if ( empty( $store ) ) {
        return $content;
    }

    // Placeholders can nest (a math element inside a shortcode that was
    // protected later), so restore until nothing changes instead of assuming an
    // order.
    $pending  = $store;
    $attempts = 0;

    while ( ! empty( $pending ) && $attempts < 5 ) {
        $attempts++;
        $changed = false;

        foreach ( $pending as $key => $value ) {
            if ( false !== strpos( $content, $key ) ) {
                $content = str_replace( $key, $value, $content );
                unset( $pending[ $key ] );
                $changed = true;
            }
        }

        if ( ! $changed ) {
            // Nothing matched: the placeholder was consumed by another region.
            break;
        }
    }

    return $content;
}

/**
 * Hide everything the front end must not rewrite: code, already rendered math
 * and shortcodes whose body another handler owns.
 *
 * @param string $content HTML.
 * @param array  $store   Placeholder map, appended to.
 * @return string
 */
function katex_protect_html_regions( $content, &$store ) {
    $patterns = array(
        // Raw code and non-text elements.
        '#<(pre|code|script|style|textarea|svg|math)\b[^>]*>.*?</\1>#is',
        // Math produced by a shortcode, a Gutenberg block or a previous run.
        '#<(span|div)\b[^>]*\bclass="[^"]*\bkatex-eq\b[^"]*"[^>]*>.*?</\1>#is',
        // `[latex]$x$[/latex]`: do_shortcode() renders it later, so converting
        // the body here would double escape it.
        '#\[(?:katex|latex)\b[^\]]*\].*?\[/(?:katex|latex)\]#is',
    );

    foreach ( $patterns as $pattern ) {
        $content = preg_replace_callback(
            $pattern,
            function ( $matches ) use ( &$store ) {
                return katex_store_region( $store, $matches[0] );
            },
            $content
        );
    }

    return $content;
}

/* -------------------------------------------------------------------------
 * Front end: HTML -> .katex-eq
 * ---------------------------------------------------------------------- */

/**
 * Normalise a math element written by an older version of this plugin.
 *
 * Before the markup contract settled, display math was a `<span>` inside its
 * own paragraph and its TeX was plain text content, so `wpautop` had inserted
 * `<br />` at every newline. Those elements are upgraded here: the <br />s
 * become newlines again and the TeX is copied into `data-katex-tex`, so the
 * renderer sees the same markup for old and new posts.
 *
 * @param string $element Math element markup.
 * @param bool   $block   Emit a <div> instead of a <span>.
 * @return string
 */
function katex_upgrade_legacy_element( $element, $block ) {
    if ( false !== strpos( $element, 'data-katex-tex=' ) ) {
        // Already in the current format; only the tag may need changing.
        if ( $block ) {
            $element = preg_replace( '#^<span\b#', '<div', $element );
            $element = preg_replace( '#</span>\s*$#', '</div>', $element );
        }
        return $element;
    }

    $tex = preg_replace( '#<br\s*/?>#i', "\n", $element );
    $tex = preg_replace( '#<[^>]+>#', '', $tex );
    $tex = html_entity_decode( $tex, ENT_QUOTES, 'UTF-8' );
    $tex = trim( $tex );

    // The first version of this plugin kept the delimiters inside the element;
    // KaTeX must not see them.
    $tex = preg_replace( '/^\$\$(.*)\$\$$/s', '$1', $tex );
    $tex = preg_replace( '/^\$(.*)\$$/s', '$1', $tex );
    $tex = trim( $tex );

    $display = (bool) preg_match( '#data-katex-display="true"#i', $element );

    return katex_math_element( $tex, $display, $block );
}

/**
 * Promote a display formula that owns its paragraph into a block element.
 *
 * Covers both freshly converted math and posts saved before a display rule was
 * marked as block-level.
 *
 * @param string $content HTML.
 * @param array  $store   Placeholder map, appended to.
 * @return string
 */
function katex_promote_display_blocks( $content, &$store ) {
    $content = preg_replace_callback(
        // The body may not contain another span/div: with `.*?</span>` the
        // engine backtracks past the first closing tag and a paragraph holding
        // two formulas would be rebuilt with mismatched tags.
        '#<p\b[^>]*>\s*(<span class="katex-eq" data-katex-display="true"[^>]*>(?:(?!</?span\b|</?div\b|</p\b).)*?</span>)\s*</p>#is',
        function ( $matches ) use ( &$store ) {
            return katex_store_region( $store, katex_upgrade_legacy_element( $matches[1], true ) );
        },
        $content
    );

    return $content;
}

/**
 * Turn display math that owns a whole paragraph into a block element *before*
 * wpautop runs, so wpautop can neither split the TeX at a blank line nor turn
 * its newlines into <br />.
 *
 * @param string $content HTML.
 * @param array  $store   Placeholder map, appended to.
 * @return string
 */
function katex_apply_block_rules( $content, &$store ) {
    foreach ( katex_math_rules() as $rule ) {
        if ( empty( $rule['block'] ) ) {
            continue;
        }

        $content = preg_replace_callback(
            '#<p\b[^>]*>\s*(' . katex_rule_body( $rule ) . ')\s*</p>#',
            function ( $matches ) use ( $rule, &$store ) {
                // Group 1 is the whole rule match, group 2 the TeX source.
                return katex_store_region( $store, katex_math_element( $matches[2], $rule['display'], true ) );
            },
            $content
        );
    }

    return $content;
}

/**
 * Apply every rule to text nodes only.
 *
 * Tags are skipped, so a `$` inside an href/src or a shortcode attribute can
 * never be rewritten into markup.
 *
 * @param string $content HTML.
 * @param array  $store   Placeholder map, appended to.
 * @return string
 */
function katex_apply_inline_rules( $content, &$store ) {
    $rules = katex_math_rules();

    if ( empty( $rules ) || ( false === strpos( $content, '$' ) && false === strpos( $content, '\\' ) ) ) {
        return $content;
    }

    // wp_html_split() alternates text / tag; only even indexes are text.
    $parts = wp_html_split( $content );

    foreach ( $parts as $index => $part ) {
        if ( 0 !== $index % 2 || '' === $part ) {
            continue;
        }
        if ( false === strpos( $part, '$' ) && false === strpos( $part, '\\' ) ) {
            continue;
        }

        foreach ( $rules as $rule ) {
            $part = preg_replace_callback(
                $rule['pattern'],
                function ( $matches ) use ( $rule, &$store ) {
                    return katex_store_region( $store, katex_math_element( $matches[1], $rule['display'], false ) );
                },
                $part
            );
        }

        $parts[ $index ] = $part;
    }

    return implode( '', $parts );
}

/**
 * Front-end entry point.
 *
 * @param string $content Post content (HTML, already produced by Markdown).
 * @return string
 */
function katex_render_math_html( $content ) {
    global $katex_resources_required;

    if ( ! is_string( $content ) || '' === $content ) {
        return $content;
    }
    // `katex-eq` matters: a post saved by an older version needs its markup
    // upgraded even though it contains no `$` or `\` any more.
    if ( false === strpos( $content, '$' ) && false === strpos( $content, '\\' ) && false === strpos( $content, 'katex-eq' ) ) {
        return $content;
    }

    // Excerpts are plain prose: `k \ll n` is noise there, and a display formula
    // reduced to its source is worse than no formula. Removing the math before
    // wp_trim_excerpt() strips the tags is the only point where the TeX source
    // is still identifiable as math rather than as text.
    if ( doing_filter( 'get_the_excerpt' ) || doing_filter( 'the_excerpt' ) ) {
        $drop   = array();
        $plain  = katex_apply_block_rules( $content, $drop );
        $plain  = katex_restore_regions( $plain, $drop );

        return katex_strip_math_from_excerpt( $plain );
    }

    $store = array();

    // Posts saved before display math was written as a block still carry a
    // `<p><span class="katex-eq" data-katex-display="true">…</span></p>`.
    // Promote those first: the protection pass below hides them behind a
    // placeholder, after which they are no longer reachable.
    $content = katex_promote_display_blocks( $content, $store );

    $content = katex_protect_html_regions( $content, $store );

    // Block passes store their own output, so later passes never look inside it.
    $content = katex_apply_block_rules( $content, $store );
    $content = katex_apply_inline_rules( $content, $store );

    $content = katex_restore_regions( $content, $store );

    if ( false !== strpos( $content, 'katex-eq' ) ) {
        $katex_resources_required = true;
    }

    return $content;
}

/* -------------------------------------------------------------------------
 * Save time: Markdown source -> tokens -> .katex-eq
 * ---------------------------------------------------------------------- */

/**
 * Markdown regions where `$` is literal.
 *
 * Fenced blocks matter most: a lone `$$` inside a fence used to pair with the
 * next `$$` in the document and the rest of the post was swallowed by one code
 * block. Link destinations, shortcode bodies and inline code matter because a
 * `$…$` there is a URL or an example, not math.
 *
 * Shared with the browser (see katex_math_config_js()) so the editor preview
 * makes the same decisions as the server.
 *
 * @return array[]
 */
function katex_markdown_literal_patterns() {
    $patterns = array(
        // Fenced blocks, ``` or ~~~, kept together with their closing fence.
        '/^[ \t]*(`{3,}|~{3,})[^\n]*\n[\s\S]*?\n[ \t]*\1[ \t]*(?=\n|$)/m',
        // Indented code blocks (four spaces or one tab at line start).
        '/(?:^(?: {4}|\t)[^\n]*(?:\n|$))+/m',
        // Code spans, longest fence first so ``a ` b`` stays intact.
        '/(`{3,})(?!`)([\s\S]+?)(?<!`)\1(?!`)/',
        '/(`{2})(?!`)([\s\S]+?)(?<!`)\1(?!`)/',
        '/(`)([^\n`]+?)\1/',
        // Inline link / image destinations.
        '/\]\([^)\n]*\)/',
        // Paired KaTeX shortcodes first: `[latex]$x$[/latex]` is rendered by
        // do_shortcode() later, so its body must reach it untouched. The other
        // single-tag patterns below run after this one, or they would eat the
        // opening tag and leave the body exposed.
        '/\[(katex|latex)\b[^\]]*\][\s\S]*?\[\/\1\]/i',
    );

    // Only protect tags WordPress actually has a handler for. Matching every
    // `[word …]` would swallow `\[x\]`-style math and plain bracketed prose.
    $registered = katex_registered_shortcodes();
    if ( $registered ) {
        $alternation = implode( '|', array_map( 'preg_quote', $registered, array_fill( 0, count( $registered ), '/' ) ) );

        $patterns[] = '/\[\/?(?:' . $alternation . ')\b[^\]\n]*\]/i';
    }

    return apply_filters( 'katex_markdown_literal_patterns', $patterns );
}

/**
 * Shortcode tags registered on this request.
 *
 * Cached, because shortcode_tags does not change after `init` in practice.
 *
 * @return string[]
 */
function katex_registered_shortcodes() {
    static $tags = null;

    if ( null === $tags ) {
        $tags = array();

        if ( ! empty( $GLOBALS['shortcode_tags'] ) && is_array( $GLOBALS['shortcode_tags'] ) ) {
            $tags = array_keys( $GLOBALS['shortcode_tags'] );
            $tags = array_values( array_filter( $tags, 'is_string' ) );
        }

        // The KaTeX shortcodes are registered by this plugin, but the list is
        // also built during save when the front-end hooks may not be loaded.
        foreach ( array( 'katex', 'latex' ) as $own ) {
            if ( ! in_array( $own, $tags, true ) ) {
                $tags[] = $own;
            }
        }
    }

    return $tags;
}

/**
 * @param array $pattern One entry of katex_markdown_literal_patterns().
 * @return array
 */
function katex_pattern_for_js( $pattern ) {
    return array(
        'pattern' => katex_rule_body( array( 'pattern' => $pattern ) ),
        'flags'   => katex_rule_flags( $pattern ),
    );
}

/**
 * Hide Markdown regions where `$` is literal.
 *
 * @param string $text   Markdown.
 * @param array  $store  Placeholder map, appended to.
 * @return string
 */
function katex_protect_markdown_literal( $text, &$store ) {
    foreach ( katex_markdown_literal_patterns() as $pattern ) {
        $text = preg_replace_callback(
            $pattern,
            function ( $matches ) use ( &$store ) {
                return katex_store_region( $store, $matches[0] );
            },
            $text
        );
    }

    return $text;
}

/**
 * Tokenize math in a Markdown source so Parsedown never sees it.
 *
 * Parsedown would otherwise eat backslash escapes (`$\{x\}$` loses the
 * backslash) and turn `*` / `_` inside formulas into emphasis.
 *
 * @param string $text   Markdown.
 * @param array  $tokens Token map: key => array( 'html', 'tex', 'display', 'block' ).
 * @return string Markdown with placeholder tokens.
 */
function katex_markdown_protect( $text, &$tokens ) {
    if ( ! is_string( $text ) || '' === $text ) {
        return $text;
    }
    if ( false === strpos( $text, '$' ) && false === strpos( $text, '\\' ) ) {
        return $text;
    }

    $literal = array();
    $text    = katex_protect_markdown_literal( $text, $literal );

    foreach ( katex_math_rules() as $rule ) {
        $text = preg_replace_callback(
            $rule['pattern'],
            function ( $matches ) use ( $rule, &$tokens ) {
                // A display formula written across several lines owns its
                // paragraph; a one-liner may sit inside a sentence.
                $block = (bool) $rule['block'] && false !== strpos( $matches[0], "\n" );
                $key   = "\x1AMMATH" . count( $tokens ) . "\x1A";

                $tokens[ $key ] = array(
                    'html'    => katex_math_element( $matches[1], $rule['display'], $block ),
                    // Fallback for a "block" formula that ends up sharing a
                    // paragraph with other content.
                    'inline'  => katex_math_element( $matches[1], $rule['display'], false ),
                    'tex'     => htmlspecialchars( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ), ENT_QUOTES, 'UTF-8' ),
                    'display' => (bool) $rule['display'],
                    'block'   => $block,
                );

                return $key;
            },
            $text
        );
    }

    return katex_restore_regions( $text, $literal );
}

/**
 * Put the math back into Parsedown's output.
 *
 * A token that ended up inside an HTML tag (a link destination, a shortcode
 * attribute, raw HTML) must degrade to plain TeX instead of an element, or the
 * markup would be destroyed.
 *
 * @param string $text   Parsed HTML.
 * @param array  $tokens Token map.
 * @return string
 */
function katex_markdown_restore( $text, $tokens ) {
    if ( empty( $tokens ) || ! is_string( $text ) ) {
        return $text;
    }

    // Block tokens were wrapped in <p>…</p> by Parsedown; drop that wrapper,
    // because a <div> may not live inside a <p>. When the token shares its
    // paragraph with other text the block form is impossible, so fall back to
    // the inline element.
    //
    // A callback is used instead of a replacement string: a replacement would
    // interpret `\\` and `$1` inside the TeX, which silently ate the `\\`
    // row separator in `\begin{aligned}`.
    foreach ( $tokens as $key => $token ) {
        if ( empty( $token['block'] ) ) {
            continue;
        }

        $text = preg_replace_callback(
            '#<p>\s*' . preg_quote( $key, '#' ) . '\s*</p>#',
            function () use ( $token ) {
                return $token['html'];
            },
            $text
        );

        if ( false !== strpos( $text, $key ) ) {
            $text = str_replace( $key, $token['inline'], $text );
        }
    }

    // Degrade tokens that sit inside a tag to plain text.
    $parts = wp_html_split( $text );
    foreach ( $parts as $index => $part ) {
        if ( 0 === $index % 2 || '' === $part ) {
            continue;
        }
        foreach ( $tokens as $key => $token ) {
            if ( false !== strpos( $part, $key ) ) {
                $parts[ $index ] = str_replace( $key, $token['tex'], $parts[ $index ] );
            }
        }
    }
    $text = implode( '', $parts );

    foreach ( $tokens as $key => $token ) {
        $text = str_replace( $key, $token['html'], $text );
    }

    return $text;
}

/* -------------------------------------------------------------------------
 * Queries
 * ---------------------------------------------------------------------- */

/**
 * Does this content contain math we would render?
 *
 * Used to load KaTeX assets only on pages that need them.
 *
 * @param string $content Raw post content.
 * @return bool
 */
function katex_content_has_math( $content ) {
    if ( ! is_string( $content ) || '' === $content ) {
        return false;
    }
    if ( false !== strpos( $content, 'katex-eq' ) ) {
        return true;
    }
    if ( preg_match( '/\[(?:katex|latex)\b/i', $content ) ) {
        return true;
    }

    foreach ( katex_math_rules() as $rule ) {
        if ( preg_match( $rule['pattern'], $content ) ) {
            return true;
        }
    }

    return false;
}

/**
 * Does the current request display something that may contain math?
 *
 * @return bool
 */
function katex_current_request_has_math() {
    if ( is_admin() && ! wp_doing_ajax() ) {
        return true; // The editor preview needs the assets.
    }

    if ( ! is_singular() ) {
        return false;
    }

    $post = get_post();
    if ( ! $post ) {
        return false;
    }

    return katex_content_has_math( $post->post_content );
}
