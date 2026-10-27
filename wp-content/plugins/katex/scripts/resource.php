<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
/*
 * Copyright (C) 2018  Thomas Churchman
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

define('KATEX_JS_VERSION', '0.16.22');


/**
 * Cache-busting version for a plugin asset URL.
 *
 * `KATEX__PLUGIN_VERSION` alone is not enough: when a mutable file changes we
 * must give it a new query string, otherwise nginx/edge caches keep serving the
 * stale bytes for `?ver=<old version>` (cf. theme `sakura_scripts`, which uses
 * filemtime for the same reason). Vendor files (katex.min.js/css) are immutable
 * per KATEX_JS_VERSION, so they keep the static plugin version.
 *
 * @param string $relative_path Path under the plugin dir, e.g. 'assets/render.js'.
 * @return int|string filemtime when the file exists, else KATEX__PLUGIN_VERSION.
 */
function katex_asset_version( $relative_path ) {
    $file = KATEX__PLUGIN_DIR . ltrim( $relative_path, '/' );
    $mtime = is_readable( $file ) ? @filemtime( $file ) : false;
    return $mtime ? $mtime : KATEX__PLUGIN_VERSION;
}


add_action('init', 'katex_resources_init');
// wp_enqueue_scripts runs before wp_head, so the stylesheet still lands in
// <head>; 20 leaves room for the theme to register `app` first.
add_action('wp_enqueue_scripts', 'katex_resources_maybe_enqueue_early', 20);
add_action('wp_footer', 'katex_enable');


function katex_resources_init() {
    wp_register_script(
        'katex',
        KATEX__PLUGIN_URL . 'assets/katex-' . KATEX_JS_VERSION . '/katex.min.js',
        array(), // No dependencies.
        KATEX__PLUGIN_VERSION,
        true, // In footer.
    );
    wp_register_style(
        'katex',
        KATEX__PLUGIN_URL . 'assets/katex-' . KATEX_JS_VERSION . '/katex.min.css',
        array(), // No dependencies.
        KATEX__PLUGIN_VERSION,
    );

    wp_register_script(
        'katex-core',
        KATEX__PLUGIN_URL . 'assets/katex-core.js',
        array('katex'),
        katex_asset_version( 'assets/katex-core.js' ),
        true, // In footer.
    );

    wp_localize_script('katex-core', 'katexMathConfig', katex_math_runtime_config());

    wp_register_script(
        'katex-render',
        KATEX__PLUGIN_URL . 'assets/render.js',
        array('katex', 'katex-core'),
        katex_asset_version( 'assets/render.js' ),
        true, // In footer.
    );
}


/**
 * Configuration shared by the front end and the editor preview.
 *
 * The syntax table travels to the browser, so the preview does not have to
 * keep its own copy of the regular expressions.
 *
 * @return array
 */
function katex_math_runtime_config() {
    return array(
        'rules'  => katex_math_rules_for_js(),
        // Regions where `$` is literal. Shared so the editor preview protects
        // the same things (fences, code spans, link destinations, shortcodes)
        // and a post cannot look different in the editor and on the site.
        'literal' => array_map( 'katex_pattern_for_js', katex_markdown_literal_patterns() ),
        'katex'  => array(
            'throwOnError' => false,
            // `strict: ignore` stops KaTeX from warning about characters that
            // are fine in practice (e.g. `×` in Chinese-language notes).
            'strict'       => 'ignore',
            // `output: html` drops the MathML twin, which otherwise duplicates
            // formulas in the table of contents, in copy/paste and in text
            // extraction.
            'output'       => 'html',
        ),
        'render' => array(
            'texClass'      => 'katex-eq',
            'displayAttr'   => 'data-katex-display',
            'sourceAttr'    => 'data-katex-tex',
            'renderedClass' => 'katex-rendered',
            'errorClass'    => 'katex-error',
        ),
    );
}


/**
 * Enqueue KaTeX and the renderer.
 *
 * @return void
 */
function katex_resources_enqueue() {
    wp_enqueue_style('katex');
    wp_enqueue_script('katex');
    wp_enqueue_script('katex-core');
    wp_enqueue_script('katex-render');
}


/**
 * Decide before the header is printed, so the stylesheet lands in <head>.
 *
 * On a singular view we already know whether the post contains math. Archive
 * and home views fall back to the footer hook below, which catches anything
 * that only becomes known while rendering the loop.
 *
 * @return void
 */
function katex_resources_maybe_enqueue_early() {
    if (is_admin()) {
        return;
    }

    if (katex_current_request_has_math()) {
        katex_resources_enqueue();
    }
}


/**
 * Last chance: the renderer is cheap to skip but impossible to add after the
 * scripts have been printed, so check the flag set by the content filter.
 *
 * @return void
 */
function katex_enable() {
    global $katex_resources_required;

    if ($katex_resources_required && !katex_assets_are_enqueued()) {
        katex_resources_enqueue();
    }
}


/**
 * Are the assets already queued for this request?
 *
 * @return bool
 */
function katex_assets_are_enqueued() {
    return wp_script_is('katex-render', 'enqueued') || wp_script_is('katex-render', 'done');
}


/**
 * Pjax bridge.
 *
 * Sakurairo replaces the page body without executing the new page's footer
 * scripts, so navigating from a page without math to a post with math would
 * arrive with `.katex-eq` elements and no KaTeX. The loader rides on the
 * theme's always-present `app` handle and fetches the assets once, on demand.
 *
 * @return void
 */
function katex_pjax_bridge() {
    if (is_admin() || !wp_script_is('app', 'registered')) {
        return;
    }
    // Only Sakurairo needs this, and only with its Pjax mode on.
    if (function_exists('iro_opt') && !iro_opt('poi_pjax')) {
        return;
    }

    $assets = array(
        'css'    => add_query_arg('ver', KATEX__PLUGIN_VERSION, KATEX__PLUGIN_URL . 'assets/katex-' . KATEX_JS_VERSION . '/katex.min.css'),
        'js'     => add_query_arg('ver', KATEX__PLUGIN_VERSION, KATEX__PLUGIN_URL . 'assets/katex-' . KATEX_JS_VERSION . '/katex.min.js'),
        'core'   => add_query_arg('ver', katex_asset_version( 'assets/katex-core.js' ), KATEX__PLUGIN_URL . 'assets/katex-core.js'),
        'main'   => add_query_arg('ver', katex_asset_version( 'assets/render.js' ), KATEX__PLUGIN_URL . 'assets/render.js'),
        // katex-core.js reads this global; on a normal request wp_localize_script
        // provides it, but a Pjax navigation never prints it.
        'config' => katex_math_runtime_config(),
    );

    wp_add_inline_script(
        'app',
        'window.__iroKatexLoader||(window.__iroKatexLoader=1,function(){'
        . 'var a=' . wp_json_encode($assets) . ';'
        . 'function load(u,next){var s=document.createElement("script");s.src=u;'
        . 'if(next){s.onload=next;}document.body.appendChild(s);}'
        . 'function boot(){'
        . 'if(!document.querySelector(".katex-eq")){return;}'
        // The pipeline is complete only once render.js has run and defined
        // iroKatexRender. katex.min.js being present alone is NOT enough: a
        // stale/partial load can leave `katex` defined while katex-core and
        // render.js are missing, which made Pjax show raw TeX until reload.
        . 'if(window.iroKatexRender){window.iroKatexRender();return;}'
        . 'if(window.__iroKatexLoading){return;}'
        . 'window.__iroKatexLoading=1;'
        . 'if(!document.getElementById("iro-katex-css")){'
        . 'var c=document.createElement("link");c.id="iro-katex-css";c.rel="stylesheet";'
        . 'c.href=a.css;document.head.appendChild(c);}'
        . 'window.katexMathConfig=a.config;'
        . 'function afterCore(){load(a.main);}'
        . 'if(typeof katex!=="undefined"){load(a.core,afterCore);}'
        . 'else{load(a.js,function(){load(a.core,afterCore);});}}'
        . 'document.addEventListener("pjax:complete",boot);'
        . '}());',
        'before'
    );
}
add_action('wp_enqueue_scripts', 'katex_pjax_bridge', 20);

