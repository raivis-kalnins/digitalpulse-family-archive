<?php
/**
 * Clear, non-destructive guidance when the legacy plugin is still active.
 *
 * @package WPMyFamilyTree
 */

defined( 'ABSPATH' ) || exit;

final class WPMFT_Compatibility {
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'legacy_notice' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'legacy_shortcodes' ), 99 );
	}

	public static function legacy_active() {
		$legacy = 'wp-family-tree/family-tree.php';
		$active = (array) get_option( 'active_plugins', array() );
		$network = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();
		return in_array( $legacy, $active, true ) || isset( $network[$legacy] );
	}

	/** Both plugins reuse the same posts; keep old shortcodes on the guarded renderer. */
	public static function legacy_shortcodes() {
		if ( empty( wpmft_settings()['enabled'] ) || ! self::legacy_active() ) { return; }
		foreach ( array( 'family-tree', 'family-members' ) as $tag ) {
			add_shortcode( $tag, 'wpmft_shortcode' );
		}
	}

	public static function legacy_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! self::legacy_active() ) { return; }
		?>
		<div class="notice notice-warning">
			<p><strong><?php esc_html_e( 'Two family tree plugins are active', 'my-family-tree' ); ?></strong></p>
			<p><?php esc_html_e( 'The older WP Family Tree plugin can still fail on missing parent records or invalid birth dates. Check your tree with My Family Tree, then deactivate the older WP Family Tree plugin. Your existing family posts and relationships are retained.', 'my-family-tree' ); ?></p>
			<?php if ( current_user_can( 'activate_plugins' ) ) : ?>
				<p><a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'Review active plugins', 'my-family-tree' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
