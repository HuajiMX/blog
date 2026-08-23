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


add_action('init', 'katex_resources_init');
add_action('wp_footer', 'katex_enable');


function katex_resources_init() {
    $option_load_assets_conditionally = get_option('katex_load_assets_conditionally', KATEX__OPTION_DEFAULT_LOAD_ASSETS_CONDITIONALLY);

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
        'katex-render',
        KATEX__PLUGIN_URL . 'assets/render.js',
        array('katex'),
        KATEX__PLUGIN_VERSION,
        true, // In footer.
    );

    if ($option_load_assets_conditionally) {
        add_action('loop_end', 'katex_resources_enqueue_conditionally');
        add_action('admin_footer', 'katex_resources_enqueue_conditionally');
    } else {
        add_action('wp_enqueue_scripts', 'katex_resources_enqueue');
        add_action('admin_enqueue_scripts', 'katex_resources_enqueue');
    }
}


function katex_resources_enqueue() {
    wp_enqueue_script('katex');
    wp_enqueue_style('katex');
}


function katex_resources_enqueue_conditionally() {
    global $katex_resources_required;

    if ($katex_resources_required) {
        katex_resources_enqueue();
    }
}


function katex_enable() {
    global $katex_resources_required;

    $option_load_assets_conditionally = get_option('katex_load_assets_conditionally', KATEX__OPTION_DEFAULT_LOAD_ASSETS_CONDITIONALLY);

    if ($katex_resources_required || !$option_load_assets_conditionally) {
      wp_enqueue_script('katex-render');
    }
}
