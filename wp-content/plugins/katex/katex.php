<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
/*
 * Plugin Name: KaTeX
 * Plugin URI: https://wordpress.org/plugins/katex
 * Description: Use the fastest math typesetting library on your website.
 * Version: 2.2.5
 * Author: Tom Churchman
 * Author URI: https://churchman.nl
 * License: GPLv2 or later
 */
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

define('KATEX__PLUGIN_VERSION', '2.2.5');

define('KATEX__PLUGIN_DIR', plugin_dir_path(__FILE__));
define('KATEX__PLUGIN_URL', plugin_dir_url(__FILE__));

define('KATEX__OPTION_DEFAULT_LOAD_ASSETS_CONDITIONALLY', false);
define('KATEX__OPTION_DEFAULT_ENABLE_LATEX_SHORTCODE', true);


$katex_resources_required = false;


if (is_admin() && !wp_doing_ajax()) {
    $katex_resources_required = true;
    require_once(KATEX__PLUGIN_DIR . 'scripts/admin.php');
}

require_once(KATEX__PLUGIN_DIR . 'scripts/shortcode.php');
if (!wp_doing_ajax()) {
    require_once(KATEX__PLUGIN_DIR . 'scripts/block.php');
    require_once(KATEX__PLUGIN_DIR . 'scripts/resource.php');
}

register_uninstall_hook(__FILE__, 'katex_uninstall');

function katex_uninstall() {
    delete_option('katex_load_assets_conditionally');
    delete_option('katex_enable_latex_shortcode');
}

function katex_update() {
    $installed_version = get_option('katex_version', '0.0.0');

    $updated = false;

    // Migrations (from oldest to newest).
    if (version_compare($installed_version, '2.2.4', '<')) {
        delete_option('katex_use_jsdelivr');
    }

    if ($updated) {
        update_option('katex_version', KATEX__PLUGIN_VERSION);
    }
}
register_activation_hook( __FILE__, 'katex_update' );
