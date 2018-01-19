<?php
/*
 * Plugin Name: Contentful WP
 * Author: Tanios
 * Author URI: http://www.tanios.ca/
 * Version: 0.0.2
 */

//including autoloader
include plugin_dir_path( __FILE__ ) . '/inc/autoloader.inc.php';

//including bootstrap
include plugin_dir_path( __FILE__ ) . '/inc/bootstrap.inc.php';

//activation hooks for rewriting
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
register_activation_hook( __FILE__, array( $plugin, 'rewrite' ) );