<?php
/**
 * Settings, person metadata and authenticated editor tools.
 *
 * @package WPMyFamilyTree
 */

defined( 'ABSPATH' ) || exit;

final class WPMFT_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'add_meta_boxes_post', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_post', array( __CLASS__, 'save_person' ), 20, 3 );
		add_action( 'admin_post_wpmft_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_wpmft_create_page', array( __CLASS__, 'create_page' ) );
		add_action( 'admin_post_wpmft_revoke_sessions', array( __CLASS__, 'revoke_sessions' ) );
		add_action( 'admin_post_wpmft_protect_media', array( __CLASS__, 'protect_media' ) );
		add_action( 'wp_ajax_wpmft_admin_people', array( __CLASS__, 'people_search' ) );
		add_action( 'wp_ajax_wpmft_admin_protect_media', array( __CLASS__, 'protect_media_ajax' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPMFT_DIR . 'wp-my-family-tree.php' ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_menu_page( __( 'WP My Family Tree', 'wp-my-family-tree' ), __( 'Family Tree', 'wp-my-family-tree' ), 'manage_options', 'wpmft', array( __CLASS__, 'settings_page' ), 'dashicons-networking', 26 );
		add_submenu_page( 'wpmft', __( 'Settings', 'wp-my-family-tree' ), __( 'Settings & help', 'wp-my-family-tree' ), 'manage_options', 'wpmft', array( __CLASS__, 'settings_page' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wpmft' ) ) . '">' . esc_html__( 'Settings', 'wp-my-family-tree' ) . '</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		$screen = get_current_screen();
		$is_post = $screen && 'post' === $screen->post_type && in_array( $hook, array( 'post.php', 'post-new.php' ), true );
		if ( 'toplevel_page_wpmft' !== $hook && ! $is_post ) {
			return;
		}
		wp_enqueue_style( 'wpmft-admin', WPMFT_URL . 'assets/admin.css', array(), WPMFT_VERSION );
		wp_enqueue_script( 'wpmft-admin', WPMFT_URL . 'assets/admin.js', array(), WPMFT_VERSION, true );
		if ( $is_post ) {
			wp_enqueue_media();
		}
		wp_localize_script( 'wpmft-admin', 'WPMFT_ADMIN', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'wpmft_admin_people' ),
			'mediaNonce' => wp_create_nonce( 'wpmft_protect_media' ),
			'strings' => array(
				'chooseImages' => __( 'Choose family photographs', 'wp-my-family-tree' ),
				'useImages' => __( 'Use selected photographs', 'wp-my-family-tree' ),
				'remove' => __( 'Remove', 'wp-my-family-tree' ),
				'noResults' => __( 'No family members found.', 'wp-my-family-tree' ),
				'searchError' => __( 'Search could not load. Please try again.', 'wp-my-family-tree' ),
				'searching' => __( 'Searching…', 'wp-my-family-tree' ),
				'protecting' => __( 'Protecting family photographs…', 'wp-my-family-tree' ),
				'protected' => __( 'Family photograph protection is complete.', 'wp-my-family-tree' ),
				'remaining' => __( 'Photographs remaining:', 'wp-my-family-tree' ),
				'protectionError' => __( 'Some photographs could not be protected. Keep the original backup and review the error before publishing.', 'wp-my-family-tree' ),
			),
		) );
	}

	private static function require_admin( $nonce_action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change family tree settings.', 'wp-my-family-tree' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce_action );
	}

	private static function redirect( $message = 'saved' ) {
		wp_safe_redirect( add_query_arg( 'wpmft_notice', $message, admin_url( 'admin.php?page=wpmft' ) ) );
		exit;
	}

	private static function editor_notice( $message ) {
		set_transient( 'wpmft_notice_' . get_current_user_id(), $message, MINUTE_IN_SECONDS );
	}

	public static function notices() {
		$message = get_transient( 'wpmft_notice_' . get_current_user_id() );
		if ( $message ) {
			delete_transient( 'wpmft_notice_' . get_current_user_id() );
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_wpmft' !== $screen->id ) {
			return;
		}
		$s = wpmft_settings();
		if ( ! empty( $s['password_protection'] ) && empty( $s['password_hash'] ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Your family tree is locked for visitors. Set a family password in Privacy to allow access, or explicitly turn password protection off for a public tree.', 'wp-my-family-tree' ) . '</p></div>';
		}
		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'wp-family-tree/family-tree.php' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Deactivate the original WP Family Tree plugin. Its older public routes can bypass the privacy settings of this replacement. Your posts and photographs are retained.', 'wp-my-family-tree' ) . '</p></div>';
		}
	}

	public static function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = wpmft_settings();
		$notice = isset( $_GET['wpmft_notice'] ) && is_string( $_GET['wpmft_notice'] ) ? sanitize_key( wp_unslash( $_GET['wpmft_notice'] ) ) : '';
		$messages = array(
			'saved' => __( 'Settings saved.', 'wp-my-family-tree' ),
			'created' => __( 'Your family tree page is ready.', 'wp-my-family-tree' ),
			'revoked' => __( 'All remembered family sessions have been revoked.', 'wp-my-family-tree' ),
			'password' => __( 'Settings were not saved. Use a family password with at least 12 characters.', 'wp-my-family-tree' ),
			'hcaptcha' => __( 'Settings were not saved. Add both hCaptcha keys before enabling it.', 'wp-my-family-tree' ),
			'error' => __( 'The page could not be created. Please try again.', 'wp-my-family-tree' ),
			'email' => __( 'Settings were not saved. Enter a valid family administrator email address.', 'wp-my-family-tree' ),
			'media' => __( 'A batch of family photographs has been protected. Continue until no photographs remain.', 'wp-my-family-tree' ),
		);
		?>
		<div class="wrap wpmft-settings">
			<div class="wpmft-admin-heading"><div><p class="wpmft-eyebrow">DigitalPulse</p><h1><?php esc_html_e( 'WP My Family Tree', 'wp-my-family-tree' ); ?></h1><p><?php esc_html_e( 'Keep the stories. Connect the generations.', 'wp-my-family-tree' ); ?></p></div><span class="wpmft-version"><?php echo esc_html( WPMFT_VERSION ); ?></span></div>
			<?php if ( isset( $messages[ $notice ] ) ) : ?><div class="notice <?php echo in_array( $notice, array( 'password', 'hcaptcha', 'error', 'email' ), true ) ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( $messages[ $notice ] ); ?></p></div><?php endif; ?>
			<div class="wpmft-quickstart">
				<strong><?php esc_html_e( 'Your tree, in any theme', 'wp-my-family-tree' ); ?></strong><p><?php esc_html_e( 'Add the Family Tree block or this shortcode to a page:', 'wp-my-family-tree' ); ?> <code>[wp_my_family_tree]</code></p>
				<div class="wpmft-admin-actions">
					<?php if ( ! empty( $s['page_id'] ) && get_post( $s['page_id'] ) ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( get_permalink( $s['page_id'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View tree', 'wp-my-family-tree' ); ?></a>
						<a class="button" href="<?php echo esc_url( get_edit_post_link( $s['page_id'], 'raw' ) ); ?>"><?php esc_html_e( 'Edit tree page', 'wp-my-family-tree' ); ?></a>
					<?php endif; ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?wpmft_person=1' ) ); ?>"><?php esc_html_e( 'Add a person', 'wp-my-family-tree' ); ?></a>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'cat' => absint( $s['category_id'] ) ), admin_url( 'edit.php' ) ) ); ?>"><?php esc_html_e( 'Manage family posts', 'wp-my-family-tree' ); ?></a>
				</div>
			</div>
			<nav class="wpmft-admin-tabs" aria-label="<?php esc_attr_e( 'Family tree settings', 'wp-my-family-tree' ); ?>">
				<?php foreach ( array( 'general' => __( 'General', 'wp-my-family-tree' ), 'privacy' => __( 'Privacy & access', 'wp-my-family-tree' ), 'appearance' => __( 'Appearance', 'wp-my-family-tree' ), 'contact' => __( 'Contact & hCaptcha', 'wp-my-family-tree' ), 'help' => __( 'Getting started', 'wp-my-family-tree' ) ) as $key => $label ) : ?>
				<a href="#wpmft-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="wpmft-settings-form">
				<input type="hidden" name="action" value="wpmft_save_settings">
				<?php wp_nonce_field( 'wpmft_save_settings' ); ?>
				<section id="wpmft-general" class="wpmft-settings-section"><h2><?php esc_html_e( 'General', 'wp-my-family-tree' ); ?></h2>
					<?php self::checkbox( 'enabled', __( 'Enable the family tree', 'wp-my-family-tree' ), $s ); ?>
					<label class="wpmft-field"><span><?php esc_html_e( 'Family member category', 'wp-my-family-tree' ); ?></span><?php wp_dropdown_categories( array( 'name' => 'wpmft[category_id]', 'selected' => absint( $s['category_id'] ), 'hide_empty' => false, 'show_option_none' => esc_html__( 'Select a category', 'wp-my-family-tree' ), 'option_none_value' => 0, 'hierarchical' => true ) ); ?><small><?php esc_html_e( 'Existing WP Family Tree posts remain ordinary WordPress posts in this category.', 'wp-my-family-tree' ); ?></small></label>
					<label class="wpmft-field"><span><?php esc_html_e( 'Tree page', 'wp-my-family-tree' ); ?></span><?php wp_dropdown_pages( array( 'name' => 'wpmft[page_id]', 'selected' => absint( $s['page_id'] ), 'show_option_none' => esc_html__( 'Choose a page', 'wp-my-family-tree' ), 'option_none_value' => 0 ) ); ?></label>
					<?php self::person_picker( 'wpmft-root-person', 'wpmft[root_id]', $s['root_id'], __( 'Starting person', 'wp-my-family-tree' ) ); ?>
					<?php self::checkbox( 'standalone', __( 'Use the full-window layout without the theme header and footer', 'wp-my-family-tree' ), $s ); ?>
					<p class="description"><?php esc_html_e( 'The plugin provides its own title, header and contact footer. This applies to pages containing the tree shortcode or block.', 'wp-my-family-tree' ); ?></p>
					<label class="wpmft-field"><span><?php esc_html_e( 'Default interface language', 'wp-my-family-tree' ); ?></span><select name="wpmft[language]"><option value="en" <?php selected( $s['language'], 'en' ); ?>>English</option><option value="lv" <?php selected( $s['language'], 'lv' ); ?>>Latviešu</option></select></label>
					<?php self::checkbox( 'language_switch', __( 'Show the English / Latvian language switch', 'wp-my-family-tree' ), $s ); ?>
					<p class="description"><?php esc_html_e( 'Polylang is optional. When installed, it can manage translated pages and biographies; the plugin interface works in English and Latvian without it.', 'wp-my-family-tree' ); ?></p>
				</section>
				<section id="wpmft-privacy" class="wpmft-settings-section"><h2><?php esc_html_e( 'Privacy & access', 'wp-my-family-tree' ); ?></h2>
					<?php self::checkbox( 'password_protection', __( 'Require a family password', 'wp-my-family-tree' ), $s ); ?>
					<label class="wpmft-field"><span><?php esc_html_e( 'Set a new family password', 'wp-my-family-tree' ); ?></span><input type="password" name="wpmft[new_password]" autocomplete="new-password" minlength="12" maxlength="256" value=""><small><?php esc_html_e( 'At least 12 characters. Leave blank to keep the current password. The password is stored as a one-way hash.', 'wp-my-family-tree' ); ?></small></label>
					<?php self::field( 'remember_days', __( 'Maximum remembered access (days)', 'wp-my-family-tree' ), $s, 'number', array( 'min' => 1, 'max' => 30 ) ); ?>
					<?php self::checkbox( 'hide_living_dates', __( 'Hide the birth dates of living people in the tree and person dialogs', 'wp-my-family-tree' ), $s ); ?>
					<?php self::checkbox( 'private_media', __( 'Automatically encrypt family photograph files when people are saved', 'wp-my-family-tree' ), $s ); ?>
					<p class="description"><?php esc_html_e( 'Encrypted photographs are served through the family access check. This switch controls protection of new files; disabling it does not make already encrypted photographs public. Use “Protect existing family photos” below to process older files.', 'wp-my-family-tree' ); ?></p>
					<div class="wpmft-admin-note"><p><?php esc_html_e( 'Private pages and family API responses use no-store headers. Exclude the tree page, admin-ajax.php and protected media from host/CDN page caching. Purge older public caches after migration.', 'wp-my-family-tree' ); ?></p><p><?php esc_html_e( 'Keep a full backup before protecting files. Protection replaces public family image files with an encrypted vault. The database encryption key and encrypted uploads must be backed up together. Older cached or externally copied photographs cannot be recalled.', 'wp-my-family-tree' ); ?></p></div>
				</section>
				<section id="wpmft-appearance" class="wpmft-settings-section"><h2><?php esc_html_e( 'Appearance', 'wp-my-family-tree' ); ?></h2>
					<?php self::field( 'title', __( 'Title (English)', 'wp-my-family-tree' ), $s ); ?>
					<?php self::field( 'title_lv', __( 'Title (Latvian)', 'wp-my-family-tree' ), $s ); ?>
					<?php self::field( 'subtitle', __( 'Introduction (English)', 'wp-my-family-tree' ), $s ); ?>
					<?php self::field( 'subtitle_lv', __( 'Introduction (Latvian)', 'wp-my-family-tree' ), $s ); ?>
					<div class="wpmft-field-row"><?php self::field( 'accent', __( 'Accent colour', 'wp-my-family-tree' ), $s, 'color' ); ?><?php self::field( 'background', __( 'Background colour', 'wp-my-family-tree' ), $s, 'color' ); ?></div>
					<?php self::field( 'tree_height', __( 'Tree canvas height on large screens (px)', 'wp-my-family-tree' ), $s, 'number', array( 'min' => 480, 'max' => 1400 ) ); ?>
					<?php self::checkbox( 'allow_export', __( 'Allow signed-in family visitors to save a tree image and print', 'wp-my-family-tree' ), $s ); ?>
					<?php self::checkbox( 'show_credit', __( 'Show an optional “Built by DigitalPulse” footer link', 'wp-my-family-tree' ), $s ); ?>
				</section>
				<section id="wpmft-contact" class="wpmft-settings-section"><h2><?php esc_html_e( 'Contact & hCaptcha', 'wp-my-family-tree' ); ?></h2>
					<?php self::checkbox( 'contact_enabled', __( 'Enable the Contact Us form', 'wp-my-family-tree' ), $s ); ?>
					<?php self::field( 'contact_name', __( 'Family administrator name', 'wp-my-family-tree' ), $s ); ?>
					<?php self::field( 'contact_email', __( 'Family administrator email', 'wp-my-family-tree' ), $s, 'email' ); ?>
					<?php self::checkbox( 'show_email', __( 'Display the contact email in the footer and exported tree image', 'wp-my-family-tree' ), $s ); ?>
					<p class="description"><?php esc_html_e( 'Contact messages use WordPress mail delivery. The form does not save message contents to the family tree database. Your mail provider may retain delivered messages.', 'wp-my-family-tree' ); ?></p>
					<?php self::checkbox( 'hcaptcha_enabled', __( 'Enable hCaptcha (external anti-spam service)', 'wp-my-family-tree' ), $s ); ?>
					<?php self::field( 'hcaptcha_site_key', __( 'hCaptcha site key', 'wp-my-family-tree' ), $s ); ?>
					<label class="wpmft-field"><span><?php esc_html_e( 'hCaptcha secret key', 'wp-my-family-tree' ); ?></span><input type="password" name="wpmft[hcaptcha_secret]" autocomplete="new-password" value=""><small><?php esc_html_e( 'Leave blank to keep the stored secret. It is never included in the public page.', 'wp-my-family-tree' ); ?></small></label>
					<?php self::checkbox( 'hcaptcha_login', __( 'Use hCaptcha on the family password form', 'wp-my-family-tree' ), $s ); ?>
					<?php self::checkbox( 'hcaptcha_contact', __( 'Use hCaptcha on the contact form', 'wp-my-family-tree' ), $s ); ?>
					<p class="description"><?php esc_html_e( 'When enabled, hCaptcha receives technical browser information and verifies form tokens. Review its service terms and add it to your privacy notice.', 'wp-my-family-tree' ); ?> <a href="https://www.hcaptcha.com/privacy" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Privacy policy', 'wp-my-family-tree' ); ?></a> · <a href="https://www.hcaptcha.com/terms" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Terms', 'wp-my-family-tree' ); ?></a></p>
				</section>
				<section id="wpmft-help" class="wpmft-settings-section"><h2><?php esc_html_e( 'Getting started', 'wp-my-family-tree' ); ?></h2><?php self::help(); ?></section>
				<div class="wpmft-save-bar"><?php submit_button( __( 'Save settings', 'wp-my-family-tree' ), 'primary', 'submit', false ); ?><span><?php esc_html_e( 'Settings belong to the plugin and work independently of your theme.', 'wp-my-family-tree' ); ?></span></div>
			</form>
			<div class="wpmft-secondary-actions">
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="wpmft_create_page"><?php wp_nonce_field( 'wpmft_create_page' ); ?><button class="button" type="submit"><?php esc_html_e( 'Create a tree page', 'wp-my-family-tree' ); ?></button></form>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="wpmft_revoke_sessions"><?php wp_nonce_field( 'wpmft_revoke_sessions' ); ?><button class="button" type="submit"><?php esc_html_e( 'Revoke all remembered access', 'wp-my-family-tree' ); ?></button></form>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" id="wpmft-protect-media-form"><input type="hidden" name="action" value="wpmft_protect_media"><?php wp_nonce_field( 'wpmft_protect_media' ); ?><button class="button" type="submit"><?php esc_html_e( 'Protect existing family photos', 'wp-my-family-tree' ); ?></button></form>
			</div>
			<p id="wpmft-media-progress" role="status" aria-live="polite"></p>
		</div>
		<?php
	}

	private static function checkbox( $key, $label, $settings ) {
		echo '<label class="wpmft-check"><input type="checkbox" name="wpmft[' . esc_attr( $key ) . ']" value="1" ' . checked( ! empty( $settings[ $key ] ), true, false ) . '><span>' . esc_html( $label ) . '</span></label>';
	}

	private static function field( $key, $label, $settings, $type = 'text', $attrs = array() ) {
		echo '<label class="wpmft-field"><span>' . esc_html( $label ) . '</span><input type="' . esc_attr( $type ) . '" name="wpmft[' . esc_attr( $key ) . ']" value="' . esc_attr( isset( $settings[ $key ] ) ? $settings[ $key ] : '' ) . '"';
		foreach ( $attrs as $name => $value ) {
			echo ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
		}
		echo '></label>';
	}

	public static function save_settings() {
		self::require_admin( 'wpmft_save_settings' );
		$old = wpmft_settings();
		$s = $old;
		$input = isset( $_POST['wpmft'] ) && is_array( $_POST['wpmft'] ) ? wp_unslash( $_POST['wpmft'] ) : array();
		foreach ( array( 'enabled', 'standalone', 'contact_enabled', 'show_email', 'password_protection', 'hide_living_dates', 'private_media', 'language_switch', 'allow_export', 'show_credit', 'hcaptcha_enabled', 'hcaptcha_login', 'hcaptcha_contact' ) as $key ) {
			$s[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}
		foreach ( array( 'title', 'title_lv', 'subtitle', 'subtitle_lv', 'contact_name', 'hcaptcha_site_key' ) as $key ) {
			$s[ $key ] = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? substr( sanitize_text_field( $input[ $key ] ), 0, 500 ) : '';
		}
		$raw_email = isset( $input['contact_email'] ) && is_string( $input['contact_email'] ) ? trim( $input['contact_email'] ) : '';
		if ( ( $s['contact_enabled'] || '' !== $raw_email ) && ! is_email( $raw_email ) ) {
			self::redirect( 'email' );
		}
		$s['contact_email'] = sanitize_email( $raw_email );
		$s['remember_days'] = max( 1, min( 30, isset( $input['remember_days'] ) ? absint( $input['remember_days'] ) : 30 ) );
		$s['tree_height'] = max( 480, min( 1400, isset( $input['tree_height'] ) ? absint( $input['tree_height'] ) : 760 ) );
		$s['language'] = isset( $input['language'] ) && 'lv' === $input['language'] ? 'lv' : 'en';
		foreach ( array( 'accent', 'background' ) as $key ) {
			$value = isset( $input[ $key ] ) && is_string( $input[ $key ] ) ? sanitize_hex_color( $input[ $key ] ) : '';
			$s[ $key ] = $value ? $value : $old[ $key ];
		}
		$page_id = isset( $input['page_id'] ) ? absint( $input['page_id'] ) : 0;
		$s['page_id'] = $page_id && 'page' === get_post_type( $page_id ) ? $page_id : 0;
		$category_id = isset( $input['category_id'] ) ? absint( $input['category_id'] ) : 0;
		$s['category_id'] = $category_id && term_exists( $category_id, 'category' ) ? $category_id : absint( $old['category_id'] );
		$root_id = isset( $input['root_id'] ) ? absint( $input['root_id'] ) : 0;
		$s['root_id'] = $root_id && wpmft_is_person( $root_id ) ? $root_id : 0;
		$new_password = isset( $input['new_password'] ) && is_string( $input['new_password'] ) ? $input['new_password'] : '';
		if ( '' !== $new_password ) {
			if ( strlen( $new_password ) < 12 || strlen( $new_password ) > 256 ) {
				self::redirect( 'password' );
			}
			$s['password_hash'] = wp_hash_password( $new_password );
			$s['session_version'] = wp_generate_password( 32, false, false );
		}
		if ( isset( $input['hcaptcha_secret'] ) && is_string( $input['hcaptcha_secret'] ) && '' !== trim( $input['hcaptcha_secret'] ) ) {
			$s['hcaptcha_secret'] = substr( sanitize_text_field( $input['hcaptcha_secret'] ), 0, 500 );
		}
		if ( $s['hcaptcha_enabled'] && ( empty( $s['hcaptcha_site_key'] ) || empty( $s['hcaptcha_secret'] ) ) ) {
			self::redirect( 'hcaptcha' );
		}
		if ( $s['password_protection'] !== (int) $old['password_protection'] || $s['remember_days'] !== (int) $old['remember_days'] ) {
			$s['session_version'] = wp_generate_password( 32, false, false );
		}
		update_option( 'wpmft_settings', $s, false );
		do_action( 'wpmft_settings_saved', $s, $old );
		self::redirect();
	}

	public static function create_page() {
		self::require_admin( 'wpmft_create_page' );
		$s = wpmft_settings();
		if ( ! empty( $s['page_id'] ) && 'trash' !== get_post_status( $s['page_id'] ) && get_post( $s['page_id'] ) ) {
			self::redirect( 'created' );
		}
		$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $s['title'], 'post_content' => '<!-- wp:wp-my-family-tree/tree /-->', 'comment_status' => 'closed', 'ping_status' => 'closed' ), true );
		if ( is_wp_error( $id ) ) {
			self::redirect( 'error' );
		}
		$s['page_id'] = $id;
		update_option( 'wpmft_settings', $s, false );
		self::redirect( 'created' );
	}

	public static function revoke_sessions() {
		self::require_admin( 'wpmft_revoke_sessions' );
		$s = wpmft_settings();
		$s['session_version'] = wp_generate_password( 32, false, false );
		update_option( 'wpmft_settings', $s, false );
		self::redirect( 'revoked' );
	}

	public static function protect_media() {
		self::require_admin( 'wpmft_protect_media' );
		if ( class_exists( 'WPMFT_Media' ) ) {
			$result = WPMFT_Media::protect_batch( 10 );
			if ( ! empty( $result['errors'] ) ) { self::editor_notice( implode( ' ', array_map( 'sanitize_text_field', $result['errors'] ) ) ); }
		}
		self::redirect( 'media' );
	}

	public static function protect_media_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => __( 'Access denied.', 'wp-my-family-tree' ) ), 403 ); }
		check_ajax_referer( 'wpmft_protect_media', 'nonce' );
		if ( ! class_exists( 'WPMFT_Media' ) ) { wp_send_json_error( array( 'message' => __( 'The protected media service is not available.', 'wp-my-family-tree' ) ), 500 ); }
		$result = WPMFT_Media::protect_batch( 10 );
		wp_send_json_success( array(
			'processed' => isset( $result['processed'] ) ? absint( $result['processed'] ) : 0,
			'remaining' => isset( $result['remaining'] ) ? absint( $result['remaining'] ) : 0,
			'errors' => isset( $result['errors'] ) && is_array( $result['errors'] ) ? array_map( 'sanitize_text_field', $result['errors'] ) : array(),
		) );
	}

	public static function meta_boxes( $post ) {
		add_meta_box( 'wpmft-person', __( 'Family tree — person details', 'wp-my-family-tree' ), array( __CLASS__, 'person_box' ), 'post', 'normal', 'high', array( '__block_editor_compatible_meta_box' => true ) );
	}

	private static function person_picker( $id, $name, $value, $label, $multiple = false ) {
		$values = self::relation_ids( $value );
		if ( ! $multiple ) { $values = array_slice( $values, 0, 1 ); }
		echo '<div class="wpmft-person-picker" data-multiple="' . ( $multiple ? '1' : '0' ) . '"><label for="' . esc_attr( $id ) . '-search">' . esc_html( $label ) . '</label><input type="hidden" name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" value="' . esc_attr( implode( ',', $values ) ) . '"><div class="wpmft-person-selection">';
		foreach ( $values as $person_id ) {
			echo '<span class="wpmft-person-chip" data-id="' . absint( $person_id ) . '"><span>' . esc_html( get_the_title( $person_id ) ) . ' <small>#' . absint( $person_id ) . '</small></span><button type="button" class="wpmft-remove-person" aria-label="' . esc_attr__( 'Remove selected person', 'wp-my-family-tree' ) . '">×</button></span>';
		}
		echo '</div><input type="search" id="' . esc_attr( $id ) . '-search" class="wpmft-person-search" autocomplete="off" placeholder="' . esc_attr__( 'Search by name…', 'wp-my-family-tree' ) . '" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="' . esc_attr( $id ) . '-results"><div class="wpmft-person-results" id="' . esc_attr( $id ) . '-results" role="listbox" hidden></div><span class="wpmft-search-status screen-reader-text" aria-live="polite"></span></div>';
	}

	public static function person_box( $post ) {
		wp_nonce_field( 'wpmft_save_person_' . $post->ID, 'wpmft_person_nonce' );
		$settings = wpmft_settings();
		$is_person = wpmft_is_person( $post->ID ) || isset( $_GET['wpmft_person'] );
		?>
		<div class="wpmft-person-editor">
			<label class="wpmft-check"><input type="checkbox" name="wpmft_person[enabled]" value="1" <?php checked( $is_person ); ?>><span><?php esc_html_e( 'Include this post as a person in the family tree', 'wp-my-family-tree' ); ?></span></label>
			<p class="description"><?php esc_html_e( 'Use the post title for the person’s name, the block editor for their biography, and Featured image for their portrait. Parent, partner and date fields reuse your existing WP Family Tree data.', 'wp-my-family-tree' ); ?></p>
			<div class="wpmft-person-grid">
				<label class="wpmft-field"><span><?php esc_html_e( 'Gender', 'wp-my-family-tree' ); ?></span><select name="wpmft_person[gender]">
				<?php foreach ( array( '' => __( 'Not specified', 'wp-my-family-tree' ), 'm' => __( 'Male', 'wp-my-family-tree' ), 'f' => __( 'Female', 'wp-my-family-tree' ), 'x' => __( 'Other', 'wp-my-family-tree' ) ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( get_post_meta( $post->ID, 'gender', true ), $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
				</select></label>
				<?php foreach ( array( 'born' => __( 'Date of birth', 'wp-my-family-tree' ), 'died' => __( 'Date of death (leave blank if living)', 'wp-my-family-tree' ), '_wpmft_maiden_name' => __( 'Birth / maiden name', 'wp-my-family-tree' ), '_wpmft_birth_place' => __( 'Birthplace', 'wp-my-family-tree' ), '_wpmft_death_place' => __( 'Place of death', 'wp-my-family-tree' ), '_wpmft_location' => __( 'Location', 'wp-my-family-tree' ), '_wpmft_occupation' => __( 'Occupation', 'wp-my-family-tree' ) ) as $key => $label ) : ?>
				<label class="wpmft-field"><span><?php echo esc_html( $label ); ?></span><input type="text" name="wpmft_person[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( get_post_meta( $post->ID, $key, true ) ); ?>" maxlength="250" <?php echo in_array( $key, array( 'born', 'died' ), true ) ? 'placeholder="YYYY-MM-DD"' : ''; ?>></label>
				<?php endforeach; ?>
			</div>
			<p class="description"><?php esc_html_e( 'Dates accept YYYY, YYYY-MM or YYYY-MM-DD. Older date text is retained unless you change it.', 'wp-my-family-tree' ); ?></p>
			<div class="wpmft-person-grid">
			<?php foreach ( array( 'father' => __( 'Father / parent 1', 'wp-my-family-tree' ), 'mother' => __( 'Mother / parent 2', 'wp-my-family-tree' ), 'spouse' => __( 'Primary spouse / partner', 'wp-my-family-tree' ) ) as $key => $label ) { self::person_picker( 'wpmft-' . $key, 'wpmft_person[' . $key . ']', get_post_meta( $post->ID, $key, true ), $label ); } ?>
			<?php self::person_picker( 'wpmft-spouses', 'wpmft_person[_wpmft_spouses]', get_post_meta( $post->ID, '_wpmft_spouses', true ), __( 'Other spouses / partners', 'wp-my-family-tree' ), true ); ?>
			</div>
			<h3><?php esc_html_e( 'Photo gallery', 'wp-my-family-tree' ); ?></h3>
			<?php $gallery = array_filter( array_map( 'absint', (array) get_post_meta( $post->ID, '_wpmft_gallery', true ) ) ); ?>
			<input type="hidden" id="wpmft-gallery-ids" name="wpmft_person[_wpmft_gallery]" value="<?php echo esc_attr( implode( ',', $gallery ) ); ?>">
			<div id="wpmft-gallery-preview" class="wpmft-gallery-preview">
				<?php foreach ( $gallery as $image_id ) : ?><figure data-id="<?php echo absint( $image_id ); ?>"><?php echo wp_get_attachment_image( $image_id, 'thumbnail' ); ?><button type="button" class="wpmft-remove-image" aria-label="<?php esc_attr_e( 'Remove photograph from gallery', 'wp-my-family-tree' ); ?>">×</button></figure><?php endforeach; ?>
			</div>
			<button type="button" class="button" id="wpmft-edit-gallery"><?php esc_html_e( 'Choose / reorder photographs', 'wp-my-family-tree' ); ?></button>
			<p class="description"><?php esc_html_e( 'Removing a photograph here only removes it from this gallery. It does not delete the original media file.', 'wp-my-family-tree' ); ?></p>
		</div>
		<?php
	}

	public static function people_search() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Access denied.', 'wp-my-family-tree' ) ), 403 );
		}
		check_ajax_referer( 'wpmft_admin_people', 'nonce' );
		$q = isset( $_POST['q'] ) && is_string( $_POST['q'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['q'] ) ), 0, 100 ) : '';
		$categories = WPMFT_Data::categories();
		$args = array( 'post_type' => 'post', 'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future' ), 'posts_per_page' => 30, 's' => $q, 'orderby' => 'title', 'order' => 'ASC', 'suppress_filters' => false, 'lang' => '', 'wpmft_internal' => true );
		// Include translated and descendant categories, matching the tree's data scope.
		// Marked posts remain discoverable after a category migration.
		if ( $categories ) {
			$args['category__in'] = $categories;
		} else {
			$args['meta_key'] = '_wpmft_person';
			$args['meta_value'] = '1';
		}
		$posts = get_posts( $args );
		if ( $categories && count( $posts ) < 30 ) {
			unset( $args['category__in'] );
			$args['meta_key'] = '_wpmft_person';
			$args['meta_value'] = '1';
			$posts = array_merge( $posts, get_posts( $args ) );
		}
		$result = array();
		foreach ( $posts as $post ) {
			if ( current_user_can( 'read_post', $post->ID ) && ! isset( $result[ $post->ID ] ) ) {
				$result[ $post->ID ] = array( 'id' => $post->ID, 'title' => get_the_title( $post ) );
			}
			if ( count( $result ) >= 30 ) { break; }
		}
		nocache_headers();
		wp_send_json_success( array_values( $result ) );
	}

	private static function valid_relation( $id, $post_id ) {
		$id = absint( $id );
		return $id && $id !== (int) $post_id && 'post' === get_post_type( $id ) && wpmft_is_person( $id ) && current_user_can( 'read_post', $id ) ? $id : 0;
	}

	/** Read scalar or nested legacy relation metadata without casting arrays to ID 1. */
	private static function relation_ids( $raw ) {
		$ids = array();
		$values = is_array( $raw ) ? $raw : array( $raw );
		array_walk_recursive( $values, function ( $value ) use ( &$ids ) {
			if ( is_scalar( $value ) && preg_match( '/^\d+$/D', (string) $value ) && (int) $value > 0 ) { $ids[] = (int) $value; }
		} );
		return array_values( array_unique( $ids ) );
	}

	private static function parent_cycle( $post_id, $parent_id ) {
		$queue = array( $parent_id );
		$seen = array();
		while ( $queue && count( $seen ) < 10000 ) {
			$id = array_pop( $queue );
			if ( is_array( $id ) ) { $queue = array_merge( $queue, self::relation_ids( $id ) ); continue; }
			$id = absint( $id );
			if ( ! $id || isset( $seen[ $id ] ) ) { continue; }
			if ( $id === (int) $post_id ) { return true; }
			$seen[ $id ] = true;
			$queue[] = get_post_meta( $id, 'father', true );
			$queue[] = get_post_meta( $id, 'mother', true );
		}
		return ! empty( $queue );
	}

	private static function valid_date( $value ) {
		if ( '' === $value ) { return true; }
		if ( ! preg_match( '/^(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?$/', $value, $matches ) ) { return false; }
		$year = (int) $matches[1];
		$month = isset( $matches[2] ) ? (int) $matches[2] : 1;
		$day = isset( $matches[3] ) ? (int) $matches[3] : 1;
		return $year > 0 && checkdate( $month, $day, $year );
	}

	public static function save_person( $post_id, $post, $update ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || ! isset( $_POST['wpmft_person_nonce'] ) || ! is_string( $_POST['wpmft_person_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpmft_person_nonce'] ) ), 'wpmft_save_person_' . $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['wpmft_person'] ) || ! is_array( $_POST['wpmft_person'] ) ) { return; }
		$input = wp_unslash( $_POST['wpmft_person'] );
		$s = wpmft_settings();
		if ( ! empty( $input['enabled'] ) ) {
			update_post_meta( $post_id, '_wpmft_person', '1' );
			if ( ! empty( $s['category_id'] ) ) { wp_set_post_categories( $post_id, array( absint( $s['category_id'] ) ), true ); }
		} else {
			delete_post_meta( $post_id, '_wpmft_person' );
			$categories = WPMFT_Data::categories();
			if ( $categories ) { wp_remove_object_terms( $post_id, $categories, 'category' ); }
			return;
		}
		$gender = isset( $input['gender'] ) && is_string( $input['gender'] ) ? $input['gender'] : '';
		update_post_meta( $post_id, 'gender', in_array( $gender, array( '', 'm', 'f', 'x' ), true ) ? $gender : '' );
		foreach ( array( 'born', 'died', '_wpmft_maiden_name', '_wpmft_birth_place', '_wpmft_death_place', '_wpmft_location', '_wpmft_occupation' ) as $key ) {
			$value = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? substr( sanitize_text_field( $input[ $key ] ), 0, 250 ) : '';
			if ( in_array( $key, array( 'born', 'died' ), true ) && $value !== get_post_meta( $post_id, $key, true ) && ! self::valid_date( $value ) ) {
				self::editor_notice( __( 'A date was not saved. Use YYYY, YYYY-MM or a valid YYYY-MM-DD. The previous value has been retained.', 'wp-my-family-tree' ) );
				continue;
			}
			update_post_meta( $post_id, $key, $value );
		}
		foreach ( array( 'father', 'mother', 'spouse' ) as $key ) {
			$id = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? self::valid_relation( $input[ $key ], $post_id ) : 0;
			if ( in_array( $key, array( 'father', 'mother' ), true ) && $id && self::parent_cycle( $post_id, $id ) ) {
				self::editor_notice( __( 'A parent relationship was not saved because it would make a person their own ancestor.', 'wp-my-family-tree' ) );
				continue;
			}
			update_post_meta( $post_id, $key, $id ? $id : '' );
		}
		$spouses = array();
		foreach ( isset( $input['_wpmft_spouses'] ) && is_string( $input['_wpmft_spouses'] ) ? explode( ',', $input['_wpmft_spouses'] ) : array() as $id ) {
			$id = self::valid_relation( $id, $post_id );
			if ( $id ) { $spouses[] = $id; }
		}
		update_post_meta( $post_id, '_wpmft_spouses', array_values( array_unique( array_slice( $spouses, 0, 50 ) ) ) );
		$gallery = array();
		$existing_gallery = self::relation_ids( get_post_meta( $post_id, '_wpmft_gallery', true ) );
		foreach ( isset( $input['_wpmft_gallery'] ) && is_string( $input['_wpmft_gallery'] ) ? explode( ',', $input['_wpmft_gallery'] ) : array() as $id ) {
			$id = absint( $id );
			// A new association may trigger encryption; only select files this editor owns or can edit.
			if ( $id && wp_attachment_is_image( $id ) && ( current_user_can( 'edit_post', $id ) || in_array( $id, $existing_gallery, true ) ) ) { $gallery[] = $id; }
		}
		update_post_meta( $post_id, '_wpmft_gallery', array_values( array_unique( array_slice( $gallery, 0, 100 ) ) ) );
		do_action( 'wpmft_person_saved', $post_id );
	}

	private static function help() {
		?>
		<ol class="wpmft-help-steps">
			<li><strong><?php esc_html_e( 'Choose your family category.', 'wp-my-family-tree' ); ?></strong> <?php esc_html_e( 'An existing WP Family Tree category is detected during setup. Keep it selected to reuse your people and relationships.', 'wp-my-family-tree' ); ?></li>
			<li><strong><?php esc_html_e( 'Create or edit the tree page.', 'wp-my-family-tree' ); ?></strong> <?php esc_html_e( 'Insert the Family Tree block, or a Shortcode block containing [wp_my_family_tree]. Choose a starting person in General.', 'wp-my-family-tree' ); ?></li>
			<li><strong><?php esc_html_e( 'Set access and contact details.', 'wp-my-family-tree' ); ?></strong> <?php esc_html_e( 'Private access starts locked until you set a password. Set the family administrator email and optionally enable hCaptcha.', 'wp-my-family-tree' ); ?></li>
			<li><strong><?php esc_html_e( 'Add people as WordPress posts.', 'wp-my-family-tree' ); ?></strong> <?php esc_html_e( 'Tick “Include this post as a person”, add a portrait and biography, then choose parents, partners and photographs in the person details box. Children and siblings are derived from parent links.', 'wp-my-family-tree' ); ?></li>
			<li><strong><?php esc_html_e( 'Check privacy while signed out.', 'wp-my-family-tree' ); ?></strong> <?php esc_html_e( 'Open a private window. Test the tree, a person’s old URL, a feed and an old direct photograph URL. Clear previous caches. The original plugin should be inactive.', 'wp-my-family-tree' ); ?></li>
		</ol>
		<h3><?php esc_html_e( 'Shortcodes', 'wp-my-family-tree' ); ?></h3>
		<p><code>[wp_my_family_tree]</code><br><code>[wp_my_family_tree root="123"]</code></p>
		<p><?php esc_html_e( 'The root is a WordPress post ID. Legacy [family-tree] and [family-members] shortcodes are supported for migration.', 'wp-my-family-tree' ); ?></p>
		<h3><?php esc_html_e( 'Languages', 'wp-my-family-tree' ); ?></h3>
		<p><?php esc_html_e( 'English and Latvian interface text is included. Names and biographies are never automatically translated. With Polylang, add English and Latvian under Languages and create linked page/post translations as needed.', 'wp-my-family-tree' ); ?></p>
		<h3><?php esc_html_e( 'Restore backups safely', 'wp-my-family-tree' ); ?></h3>
		<p><?php esc_html_e( 'Restore a matching UpdraftPlus set on staging first. Preserve every filename exactly, including its date, site name, 12-character identifier and -db.gz / -plugins.zip / -themes.zip / -uploads.zip suffix. A standalone plugin ZIP is installed using Plugins → Add New → Upload Plugin, not the Updraft restore screen.', 'wp-my-family-tree' ); ?></p>
		<h3><?php esc_html_e( 'Developer', 'wp-my-family-tree' ); ?></h3>
		<p>Raivis Kalnins · <a href="https://digitalpulse.click/" target="_blank" rel="noopener noreferrer">DigitalPulse</a></p>
		<?php
	}
}
