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

/**
 * Editor preview assets.
 *
 * The preview must use the same syntax table and the same KaTeX build as the
 * front end, so it is enqueued from this plugin and only *called* by
 * wp-githuber-md. Githuber's own bundled KaTeX (0.12.0) is no longer used.
 */

add_action( 'admin_enqueue_scripts', 'katex_enqueue_preview' );


function katex_enqueue_preview() {
    wp_enqueue_style( 'katex' );
    wp_enqueue_script( 'katex' );
    wp_enqueue_script( 'katex-core' );

    wp_enqueue_script(
        'katex-preview',
        KATEX__PLUGIN_URL . 'assets/preview.js',
        array( 'katex-core', 'jquery' ),
        KATEX__PLUGIN_VERSION,
        true
    );
}
