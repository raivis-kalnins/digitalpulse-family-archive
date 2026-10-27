<?php
/**
 * Plugin Name: DigitalPulse Family Archive
 * Plugin URI: https://digitalpulse.click/
 * Description: Free, open-source family trees with biographies, photo galleries, multilingual labels, private access and demo tools.
 * Version: 1.4.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: Raivis Kalnins — DigitalPulse
 * Author URI: https://digitalpulse.click/
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: digitalpulse-family-archive
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
			wp_die(
				esc_html__( 'Deactivate the older WP DigitalPulse Family Archive plugin or any duplicate copy before activating DigitalPulse Family Archive. Your family posts, photographs and saved settings will be retained.', 'digitalpulse-family-archive' ),
				esc_html__( 'Another family tree plugin is active', 'digitalpulse-family-archive' ),
				array( 'back_link' => true )
			);
		} );

		$notice = static function () use ( $plugin_file ) {
			if ( ! current_user_can( 'activate_plugins' ) && ! current_user_can( 'manage_network_plugins' ) ) { return; }
			echo '<div class="notice notice-error"><p>' . esc_html__( 'DigitalPulse Family Archive is not running because another copy is active. Deactivate the older WP DigitalPulse Family Archive plugin or duplicate copy, then activate DigitalPulse Family Archive.', 'digitalpulse-family-archive' ) . '</p></div>';
		};
		add_action( 'admin_notices', $notice );
		add_action( 'network_admin_notices', $notice );
		return;
	}

	define( 'WPMFT_VERSION', '1.4.0' );
	define( 'WPMFT_FILE', $plugin_file );
	define( 'WPMFT_DIR', plugin_dir_path( $plugin_file ) );
	define( 'WPMFT_URL', plugin_dir_url( $plugin_file ) );
	require_once WPMFT_DIR . 'includes/bootstrap.php';
} )( __FILE__ );
