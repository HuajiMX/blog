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

add_action('admin_menu', 'katex_add_admin_menu');
add_action('admin_init', 'katex_settings_init');


function katex_add_admin_menu() {
    add_options_page('KaTeX', 'KaTeX', 'manage_options', 'katex', 'katex_options_page');
}


function katex_settings_init() {
    register_setting(
        'pluginPage',
        'katex_load_assets_conditionally',
        array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => KATEX__OPTION_DEFAULT_LOAD_ASSETS_CONDITIONALLY,
        )
    );

    register_setting(
        'pluginPage',
        'katex_enable_latex_shortcode',
        array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => KATEX__OPTION_DEFAULT_ENABLE_LATEX_SHORTCODE,
        )
    );

    add_settings_section(
        'katex_pluginPage_section',
        __('Options', 'katex'),
        'katex_settings_section_callback',
        'pluginPage'
    );

    add_settings_field(
        'katex_conditional_assets_setting',
        __('Load KaTeX assets conditionally', 'katex'),
        'katex_conditional_assets_setting_render',
        'pluginPage',
        'katex_pluginPage_section'
    );

    add_settings_field(
        'katex_latex_shortcode_setting',
        __('Enable the [latex] shortcode', 'katex'),
        'katex_latex_shortcode_setting_render',
        'pluginPage',
        'katex_pluginPage_section'
    );
}


function katex_conditional_assets_setting_render() {
    $option_katex_load_assets_conditionally = get_option('katex_load_assets_conditionally', KATEX__OPTION_DEFAULT_LOAD_ASSETS_CONDITIONALLY);
    ?>
    <input
        type='checkbox'
        name='katex_load_assets_conditionally'
        <?php checked($option_katex_load_assets_conditionally, 1); ?>
        value='1'>
    <?php
    echo esc_html__(
        'Only load the KaTeX JavaScript and CSS assets when KaTeX is used on the page. This might introduce asset enqueueing compatibility issues with themes or other plugins.',
        'katex'
    );
}


function katex_latex_shortcode_setting_render() {
    $option_katex_enable_latex_shortcode = get_option('katex_enable_latex_shortcode', KATEX__OPTION_DEFAULT_ENABLE_LATEX_SHORTCODE);
    ?>
    <input
        type='checkbox'
        name='katex_enable_latex_shortcode'
        <?php checked($option_katex_enable_latex_shortcode, 1); ?>
        value='1'>
    <?php
    echo esc_html__(
        'For compatibility with other plugins you can use [latex] shortcodes in addition to [katex].',
        'katex'
    );
}


function katex_settings_section_callback() {
     echo esc_html__('Configure how you\'d like to use KaTeX.', 'katex');
}


function katex_options_page() {
     ?>
    <div class="wrap">
        <h1>KaTeX</h1>
        <form action="options.php" method="post">
            <?php
            settings_fields( 'pluginPage' );
            do_settings_sections( 'pluginPage' );
            submit_button();
            ?>
        </form>
    </div>
    <?php
}
