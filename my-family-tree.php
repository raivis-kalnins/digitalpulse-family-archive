<?php
/**
 * Plugin Name: My Family Tree
 * Plugin URI: https://github.com/raivis-kalnins/wp-my-family-tree
 * Description: Free, open-source family trees with biographies, photo galleries, multilingual labels, private access and demo tools.
 * Version: 1.2.6
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: Raivis Kalnins — DigitalPulse
 * Author URI: https://digitalpulse.click/
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: my-family-tree
 * Domain Path: /languages
 *
 * @package MyFamilyTree
 */

defined( 'ABSPATH' ) || exit;

// Keep this entry point free of global declarations: an older copy may be loaded.
( static function ( $plugin_file ) {
	$previous_plugin = 'wp-my-family-tree/wp-my-family-tree.php';
	$active_plugins = (array) get_option( 'active_plugins', array() );
	$network_plugins = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();
	$conflict = defined( 'WPMFT_VERSION' ) || function_exists( 'wpmft_activate' ) || class_exists( 'WPMFT_Data', false ) || in_array( $previous_plugin, $active_plugins, true ) || isset( $network_plugins[$previous_plugin] );

	if ( $conflict ) {
		// WordPress includes the entry point before calling its activation hook.
		register_activation_hook( $plugin_file, static function () use ( $plugin_file ) {
			load_plugin_textdomain( 'my-family-tree', false, dirname( plugin_basename( $plugin_file ) ) . '/languages' );
			wp_die(
				esc_html__( 'Deactivate the older WP My Family Tree plugin or any duplicate copy before activating My Family Tree. Your family posts, photographs and saved settings will be retained.', 'my-family-tree' ),
				esc_html__( 'Another family tree plugin is active', 'my-family-tree' ),
				array( 'back_link' => true )
			);
		} );

		$notice = static function () use ( $plugin_file ) {
			if ( ! current_user_can( 'activate_plugins' ) && ! current_user_can( 'manage_network_plugins' ) ) { return; }
			load_plugin_textdomain( 'my-family-tree', false, dirname( plugin_basename( $plugin_file ) ) . '/languages' );
			echo '<div class="notice notice-error"><p>' . esc_html__( 'My Family Tree is not running because another copy is active. Deactivate the older WP My Family Tree plugin or duplicate copy, then activate My Family Tree.', 'my-family-tree' ) . '</p></div>';
		};
		add_action( 'admin_notices', $notice );
		add_action( 'network_admin_notices', $notice );
		return;
	}

	define( 'WPMFT_VERSION', '1.2.6' );
	define( 'WPMFT_FILE', $plugin_file );
	define( 'WPMFT_DIR', plugin_dir_path( $plugin_file ) );
	define( 'WPMFT_URL', plugin_dir_url( $plugin_file ) );
	require_once WPMFT_DIR . 'includes/bootstrap.php';
} )( __FILE__ );
