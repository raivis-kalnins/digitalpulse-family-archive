<?php
/**
 * Settings, person metadata and authenticated editor tools.
 *
 * @package WPMyFamilyTree
 */

defined( 'ABSPATH' ) || exit;

final class WPMFT_Admin {
	private static $catalog;

	public static function init() {
		// Override only this plugin's text, never the WordPress dashboard or theme locale.
		add_filter( 'gettext_wp-my-family-tree', array( __CLASS__, 'translate_admin' ), 10, 3 );
		add_filter( 'use_block_editor_for_post', array( __CLASS__, 'block_editor' ), 100, 2 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'preserve_biography' ), 99, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		add_action( 'edit_form_after_title', array( __CLASS__, 'person_panel' ) );
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

	/** Explicit UI choice, then the Polylang person language, then the editor's profile. */
	public static function language() {
		foreach ( array( $_POST['wpmft_admin_lang'] ?? '', $_GET['wpmft_admin_lang'] ?? '', $_POST['post_lang_choice'] ?? '', $_GET['new_lang'] ?? '' ) as $candidate ) {
			if ( in_array( $candidate, array( 'en', 'lv' ), true ) ) { return $candidate; }
		}
		global $post;
		$id = $post instanceof WP_Post ? $post->ID : 0;
		foreach ( array( $_GET['post'] ?? 0, $_POST['post_ID'] ?? 0, $_POST['post_id'] ?? 0 ) as $candidate ) {
			if ( is_scalar( $candidate ) && absint( $candidate ) ) { $id = absint( $candidate ); break; }
		}
		if ( $id && function_exists( 'pll_get_post_language' ) && 'post' === get_post_type( $id ) ) {
			$lang = pll_get_post_language( $id, 'slug' );
			if ( in_array( $lang, array( 'en', 'lv' ), true ) ) { return $lang; }
		}
		return 0 === strpos( get_user_locale(), 'lv' ) ? 'lv' : 'en';
	}

	private static function catalog() {
		if ( null === self::$catalog ) {
			if ( ! class_exists( 'MO' ) ) { require_once ABSPATH . WPINC . '/pomo/mo.php'; }
			self::$catalog = new MO();
			self::$catalog->import_from_file( WPMFT_DIR . 'languages/wp-my-family-tree-lv.mo' );
		}
		return self::$catalog;
	}

	public static function translate_admin( $translation, $text, $domain ) {
		if ( ! is_admin() || ! did_action( 'init' ) ) { return $translation; }
		// Front-end AJAX has its own request language; do not replace it with a user profile.
		if ( wp_doing_ajax() && ! in_array( $_REQUEST['action'] ?? '', array( 'wpmft_admin_people', 'wpmft_admin_protect_media' ), true ) ) { return $translation; }
		return 'lv' === self::language() ? self::catalog()->translate( $text ) : $text;
	}

	private static function language_control() {
		?>
		<div class="wpmft-admin-language-control">
			<label for="wpmft-admin-language"><?php esc_html_e( 'Plugin interface language', 'wp-my-family-tree' ); ?></label>
			<select id="wpmft-admin-language" class="wpmft-admin-language" name="wpmft_admin_lang"><option value="en" <?php selected( self::language(), 'en' ); ?>>English</option><option value="lv" <?php selected( self::language(), 'lv' ); ?>>Latviešu</option></select>
			<p class="description"><?php esc_html_e( 'Person labels follow the Polylang language. This switch changes plugin labels only; names, biographies and the WordPress dashboard are unchanged.', 'wp-my-family-tree' ); ?></p>
		</div>
		<?php
	}

	/** Ordinary posts retain their usual editor. New translations inherit person context. */
	public static function person_context( $post ) {
		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) { return false; }
		if ( wpmft_is_person( $post->ID ) ) { return true; }
		if ( 'auto-draft' !== $post->post_status && 'post-new.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) { return false; }
		if ( isset( $_GET['wpmft_person'] ) && '1' === $_GET['wpmft_person'] ) { return true; }
		$from = isset( $_GET['from_post'] ) && is_scalar( $_GET['from_post'] ) ? absint( $_GET['from_post'] ) : 0;
		return $from && wpmft_is_person( $from );
	}

	public static function block_editor( $use_block_editor, $post ) {
		return self::person_context( $post ) && empty( wpmft_settings()['person_block_editor'] ) ? false : $use_block_editor;
	}

	private static function classic_person_screen( $post ) {
		if ( ! self::person_context( $post ) || isset( $_GET['meta-box-loader'] ) ) { return false; }
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && ! $screen->is_block_editor();
	}

	public static function title_placeholder( $title, $post ) {
		return self::person_context( $post ) ? __( 'Person’s full name', 'wp-my-family-tree' ) : $title;
	}

	public static function person_panel( $post ) {
		if ( ! self::classic_person_screen( $post ) ) { return; }
		echo '<section class="wpmft-person-panel" aria-labelledby="wpmft-person-heading"><h2 id="wpmft-person-heading">' . esc_html__( 'Family tree — person details', 'wp-my-family-tree' ) . '</h2>';
		self::person_box( $post );
		echo '</section><h2 class="wpmft-biography-heading">' . esc_html__( 'Biography', 'wp-my-family-tree' ) . '</h2>';
	}

	/** TinyMCE can normalise block markup on an untouched save. Retain the original bytes. */
	public static function preserve_biography( $data, $postarr ) {
		$id = isset( $postarr['ID'] ) ? absint( $postarr['ID'] ) : 0;
		if ( ! $id || 'post' !== ( $data['post_type'] ?? '' ) || wp_is_post_revision( $id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $id ) ) { return $data; }
		if ( ! isset( $_POST['wpmft_person']['keep_biography'], $_POST['wpmft_person_nonce'] ) || '1' !== $_POST['wpmft_person']['keep_biography'] || ! is_string( $_POST['wpmft_person_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpmft_person_nonce'] ) ), 'wpmft_save_person_' . $id ) ) { return $data; }
		$original = get_post( $id );
		if ( $original && self::person_context( $original ) ) { $data['post_content'] = wp_slash( $original->post_content ); }
		return $data;
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
		wp_enqueue_script( 'wpmft-admin', WPMFT_URL . 'assets/admin.js', array( 'jquery' ), WPMFT_VERSION, true );
		if ( $is_post ) {
			wp_enqueue_media();
		}
		$translations = array();
		foreach ( self::catalog()->entries as $entry ) {
			if ( ! $entry->context && ! $entry->is_plural && ! empty( $entry->translations[0] ) ) { $translations[ $entry->singular ] = $entry->translations[0]; }
		}
		wp_localize_script( 'wpmft-admin', 'WPMFT_ADMIN', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'language' => self::language(), 'translations' => $translations,
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
				'relationshipLimit' => __( 'Add no more than 50 partner relationships for one person.', 'wp-my-family-tree' ),
				'choosePortrait' => __( 'Choose a portrait', 'wp-my-family-tree' ),
				'usePortrait' => __( 'Use this portrait', 'wp-my-family-tree' ),
				'noPortrait' => __( 'No portrait selected', 'wp-my-family-tree' ),
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
		wp_safe_redirect( add_query_arg( array( 'wpmft_notice' => $message, 'wpmft_admin_lang' => self::language() ), admin_url( 'admin.php?page=wpmft' ) ) );
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
				<input type="hidden" name="wpmft[v110_present]" value="1">
				<?php wp_nonce_field( 'wpmft_save_settings' ); ?>
				<?php self::language_control(); ?>
				<section id="wpmft-general" class="wpmft-settings-section"><h2><?php esc_html_e( 'General', 'wp-my-family-tree' ); ?></h2>
					<?php self::checkbox( 'enabled', __( 'Enable the family tree', 'wp-my-family-tree' ), $s ); ?>
					<?php self::checkbox( 'person_block_editor', __( 'Enable the WordPress block editor for family people', 'wp-my-family-tree' ), $s ); ?>
					<p class="description"><?php esc_html_e( 'By default, people open with their details form above the biography editor. Other posts and pages keep their usual editor. Existing biographies are preserved when only person details change.', 'wp-my-family-tree' ); ?></p>
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
					<?php self::checkbox( 'app_install', __( 'Show Android and Apple installation options in the footer', 'wp-my-family-tree' ), $s ); ?>
					<?php self::checkbox( 'app_icons', __( 'Use the included oak leaf favicon and touch icons on the tree page', 'wp-my-family-tree' ), $s ); ?>
				</section>
				<section id="wpmft-contact" class="wpmft-settings-section"><h2><?php esc_html_e( 'Contact & hCaptcha', 'wp-my-family-tree' ); ?></h2>
					<?php self::checkbox( 'contact_enabled', __( 'Enable the Contact Us form', 'wp-my-family-tree' ), $s ); ?>
					<?php self::field( 'contact_name', __( 'Family administrator name', 'wp-my-family-tree' ), $s ); ?>
					<?php self::field( 'contact_email', __( 'Family administrator email', 'wp-my-family-tree' ), $s, 'email' ); ?>
					<?php self::checkbox( 'show_email', __( 'Display the contact email in the footer and exported tree image', 'wp-my-family-tree' ), $s ); ?>
					<p class="description"><?php esc_html_e( 'Contact messages use WordPress mail delivery. The form does not save message contents to the family tree database. Your mail provider may retain delivered messages.', 'wp-my-family-tree' ); ?></p>
					<h3><?php esc_html_e( 'Contact photo uploads', 'wp-my-family-tree' ); ?></h3>
					<?php self::checkbox( 'contact_upload_enabled', __( 'Allow one photograph with a contact message', 'wp-my-family-tree' ), $s ); ?>
					<?php self::field( 'contact_upload_max_mb', __( 'Maximum photograph size (MB)', 'wp-my-family-tree' ), $s, 'number', array( 'min' => 1, 'max' => 100, 'step' => 1 ) ); ?>
					<p class="description"><?php esc_html_e( 'The default is 10 MB. Choose 1–100 MB; a lower server upload limit still applies. Photographs are emailed to the administrator and are not added to the public media library.', 'wp-my-family-tree' ); ?></p>
					<fieldset class="wpmft-upload-formats"><legend><?php esc_html_e( 'Allowed photograph formats', 'wp-my-family-tree' ); ?></legend>
					<?php foreach ( array( 'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif' ) as $format ) : ?><label class="wpmft-check"><input type="checkbox" name="wpmft[contact_upload_formats][]" value="<?php echo esc_attr( $format ); ?>" <?php checked( in_array( $format, (array) $s['contact_upload_formats'], true ) ); ?>><span><?php echo esc_html( strtoupper( $format ) ); ?></span></label><?php endforeach; ?>
					</fieldset>
					<p class="description"><?php esc_html_e( 'JPG, JPEG, PNG and WebP are selected by default. GIF and AVIF are optional when the server supports them. At least one format is required; selecting none restores the defaults.', 'wp-my-family-tree' ); ?></p>
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
		// Older forms left open during an upgrade must not reset settings they never displayed.
		if ( isset( $input['v110_present'] ) && '1' === $input['v110_present'] ) {
			foreach ( array( 'person_block_editor', 'app_install', 'app_icons', 'contact_upload_enabled' ) as $key ) { $s[$key] = empty( $input[$key] ) ? 0 : 1; }
			$max_mb = isset( $input['contact_upload_max_mb'] ) && is_scalar( $input['contact_upload_max_mb'] ) ? absint( $input['contact_upload_max_mb'] ) : 10;
			$s['contact_upload_max_mb'] = max( 1, min( 100, $max_mb ) );
			$formats = isset( $input['contact_upload_formats'] ) && is_array( $input['contact_upload_formats'] ) ? array_filter( $input['contact_upload_formats'], 'is_string' ) : array();
			$s['contact_upload_formats'] = array_values( array_intersect( array( 'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif' ), $formats ) );
			if ( ! $s['contact_upload_formats'] ) { $s['contact_upload_formats'] = array( 'jpg', 'jpeg', 'png', 'webp' ); }
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
		if ( self::classic_person_screen( $post ) ) {
			remove_meta_box( 'postimagediv', 'post', 'side' );
			return;
		}
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

	private static function partner_labels() {
		return array(
			'' => __( 'Not specified', 'wp-my-family-tree' ),
			'together' => __( 'Together / current partners', 'wp-my-family-tree' ),
			'divorced' => __( 'Divorced', 'wp-my-family-tree' ),
			'separated' => __( 'Separated', 'wp-my-family-tree' ),
			'former' => __( 'Former partners', 'wp-my-family-tree' ),
		);
	}

	private static function partner_row( $slot, $person = 0, $status = '', $note = '' ) {
		$prefix = 'wpmft_person[partners][' . $slot . ']';
		echo '<div class="wpmft-partner-row">';
		self::person_picker( 'wpmft-partner-' . $slot, $prefix . '[person]', $person, __( 'Partner / co-parent', 'wp-my-family-tree' ) );
		echo '<label class="wpmft-field"><span>' . esc_html__( 'Relationship status', 'wp-my-family-tree' ) . '</span><select name="' . esc_attr( $prefix . '[status]' ) . '">';
		foreach ( self::partner_labels() as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $status, $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></label><button type="button" class="button wpmft-remove-partner">' . esc_html__( 'Remove relationship row', 'wp-my-family-tree' ) . '</button>';
		if ( $note ) { echo '<p class="description wpmft-partner-note">' . esc_html( $note ) . '</p>'; }
		if ( $person && current_user_can( 'edit_post', $person ) ) { echo '<a class="wpmft-partner-edit" href="' . esc_url( get_edit_post_link( $person, 'raw' ) ) . '">' . esc_html__( 'Edit the other person’s record', 'wp-my-family-tree' ) . '</a>'; }
		echo '</div>';
	}

	private static function partner_editor( $post_id ) {
		$effective = WPMFT_Data::partner_statuses( $post_id ); $own = array(); $rows = array();
		foreach ( WPMFT_Data::partner_declarations( $post_id ) as $person => $status ) {
			$person = WPMFT_Data::canonical( $person );
			$own[$person] = WPMFT_Data::resolve_partner_status( $own[$person] ?? '', $status );
		}
		$legacy = array_merge( self::relation_ids( get_post_meta( $post_id, 'spouse', true ) ), self::relation_ids( get_post_meta( $post_id, '_wpmft_spouses', true ) ) );
		foreach ( array_unique( array_merge( array_keys( $own ), array_keys( $effective ), $legacy ) ) as $person ) {
			$person = WPMFT_Data::canonical( $person );
			if ( $person === WPMFT_Data::canonical( $post_id ) || ! current_user_can( 'read_post', $person ) || 'post' !== get_post_type( $person ) ) { continue; }
			$status = $own[$person] ?? ( $effective[$person] ?? '' );
			if ( ! in_array( $status, array( 'together', 'divorced', 'separated', 'former' ), true ) ) { $status = ''; }
			$note = '';
			if ( $status && ! isset( $own[$person] ) ) {
				$note = __( 'This status comes from the other person’s record. Removing this row does not change their record.', 'wp-my-family-tree' );
			} elseif ( $status && isset( $effective[$person] ) && $status !== $effective[$person] ) {
				$note = __( 'The two records have different statuses. A former relationship takes priority; update both records to resolve the difference.', 'wp-my-family-tree' );
			}
			$rows[$person] = array( 'status' => $status, 'note' => $note );
		}
		?>
		<section class="wpmft-partner-editor" data-next-index="<?php echo count( $rows ); ?>">
			<h3><?php esc_html_e( 'Partner relationships', 'wp-my-family-tree' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Choose Together for a current partner and rings in the tree. Shared children alone never imply a current relationship.', 'wp-my-family-tree' ); ?></p>
			<p class="description"><?php esc_html_e( 'Divorced, separated and former partners are hidden from each other’s own tree. Both recorded parents remain visible when their child is selected.', 'wp-my-family-tree' ); ?></p>
			<input type="hidden" name="wpmft_person[partner_status_present]" value="1">
			<div class="wpmft-partner-rows">
				<?php $slot = 0; foreach ( $rows as $person => $row ) { self::partner_row( $slot++, $person, $row['status'], $row['note'] ); } ?>
			</div>
			<button type="button" class="button wpmft-add-partner"><?php esc_html_e( 'Add partner relationship', 'wp-my-family-tree' ); ?></button>
			<p class="wpmft-partner-status screen-reader-text" role="status"></p>
			<p class="description"><?php esc_html_e( 'Only this person’s record is saved. To change a status stored on both records, update both people. Choosing Not specified removes this record’s status without removing a partner or parent selected above.', 'wp-my-family-tree' ); ?></p>
			<template class="wpmft-partner-template"><?php self::partner_row( '__ROW__' ); ?></template>
		</section>
		<?php
	}

	public static function person_box( $post ) {
		wp_nonce_field( 'wpmft_save_person_' . $post->ID, 'wpmft_person_nonce' );
		$is_person = self::person_context( $post );
		$is_classic = self::classic_person_screen( $post );
		?>
		<div class="wpmft-person-editor" data-classic-person="<?php echo $is_classic ? '1' : '0'; ?>">
			<?php self::language_control(); ?>
			<label class="wpmft-check"><input type="checkbox" name="wpmft_person[enabled]" value="1" <?php checked( $is_person ); ?>><span><?php esc_html_e( 'Include this post as a person in the family tree', 'wp-my-family-tree' ); ?></span></label>
			<p class="description"><?php esc_html_e( 'Use the post title for the person’s full name and the biography editor for their story. Parent, partner and date fields reuse your existing WP Family Tree data.', 'wp-my-family-tree' ); ?></p>
			<?php if ( $is_classic ) : $portrait = get_post_thumbnail_id( $post->ID ); ?>
			<div class="wpmft-portrait-editor">
				<input type="hidden" id="wpmft-portrait-id" name="wpmft_person[_thumbnail_id]" value="<?php echo absint( $portrait ); ?>">
				<div id="wpmft-portrait-preview" class="wpmft-portrait-preview"><?php if ( $portrait ) { echo wp_get_attachment_image( $portrait, 'medium' ); } else { echo '<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>'; } ?></div>
				<div><h3><?php esc_html_e( 'Portrait', 'wp-my-family-tree' ); ?></h3><p class="description"><?php esc_html_e( 'The portrait is the post’s featured image. Faces stay at the top of photo frames.', 'wp-my-family-tree' ); ?></p><button type="button" class="button" id="wpmft-choose-portrait"><?php esc_html_e( 'Choose a portrait', 'wp-my-family-tree' ); ?></button> <button type="button" class="button-link-delete" id="wpmft-remove-portrait" <?php echo $portrait ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove portrait', 'wp-my-family-tree' ); ?></button><p id="wpmft-portrait-status" class="screen-reader-text" role="status"></p></div>
			</div>
			<?php endif; ?>
			<h3><?php esc_html_e( 'Personal details', 'wp-my-family-tree' ); ?></h3>
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
			<?php self::partner_editor( $post->ID ); ?>
			<section class="wpmft-contact-details"><h3><?php esc_html_e( 'Contact details', 'wp-my-family-tree' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Only add details this person is comfortable sharing with everyone who can access the family tree.', 'wp-my-family-tree' ); ?></p>
				<div class="wpmft-person-grid">
				<?php foreach ( array( '_wpmft_email' => array( __( 'Email address', 'wp-my-family-tree' ), 'email', 254 ), '_wpmft_phone' => array( __( 'Telephone', 'wp-my-family-tree' ), 'tel', 64 ), '_wpmft_mobile' => array( __( 'Mobile phone', 'wp-my-family-tree' ), 'tel', 64 ), '_wpmft_website' => array( __( 'Website', 'wp-my-family-tree' ), 'url', 2048 ) ) as $key => $field ) : ?>
					<label class="wpmft-field"><span><?php echo esc_html( $field[0] ); ?></span><input type="<?php echo esc_attr( $field[1] ); ?>" name="wpmft_person[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( get_post_meta( $post->ID, $key, true ) ); ?>" maxlength="<?php echo absint( $field[2] ); ?>" <?php echo 'url' === $field[1] ? 'placeholder="https://"' : ''; ?>></label>
				<?php endforeach; ?>
					<label class="wpmft-field wpmft-field-wide"><span><?php esc_html_e( 'Postal address', 'wp-my-family-tree' ); ?></span><textarea name="wpmft_person[_wpmft_address]" rows="3" maxlength="500"><?php echo esc_textarea( get_post_meta( $post->ID, '_wpmft_address', true ) ); ?></textarea></label>
				</div>
				<h3><?php esc_html_e( 'Social profiles', 'wp-my-family-tree' ); ?></h3>
				<div class="wpmft-person-grid">
				<?php foreach ( array( 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'x' => 'X / Twitter', 'youtube' => 'YouTube', 'tiktok' => 'TikTok' ) as $network => $label ) : ?>
					<label class="wpmft-field"><span><?php echo esc_html( $label ); ?></span><input type="url" name="wpmft_person[_wpmft_social_<?php echo esc_attr( $network ); ?>]" value="<?php echo esc_attr( get_post_meta( $post->ID, '_wpmft_social_' . $network, true ) ); ?>" maxlength="2048" placeholder="https://"></label>
				<?php endforeach; ?>
				</div>
				<p class="description"><?php esc_html_e( 'Use a complete public profile URL beginning with https:// or http://.', 'wp-my-family-tree' ); ?></p>
			</section>
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

	private static function limit_text( $value, $limit ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit, 'UTF-8' ) : wp_check_invalid_utf8( substr( $value, 0, $limit ), true );
	}

	private static function save_contact_details( $post_id, $input ) {
		$invalid = false;
		if ( array_key_exists( '_wpmft_email', $input ) && is_string( $input['_wpmft_email'] ) ) {
			$email = trim( $input['_wpmft_email'] );
			if ( '' === $email || ( strlen( $email ) <= 254 && is_email( $email ) ) ) { update_post_meta( $post_id, '_wpmft_email', sanitize_email( $email ) ); }
			else { $invalid = true; }
		}
		foreach ( array( '_wpmft_phone', '_wpmft_mobile', '_wpmft_address' ) as $key ) {
			if ( ! array_key_exists( $key, $input ) || ! is_string( $input[$key] ) ) { continue; }
			$value = '_wpmft_address' === $key ? sanitize_textarea_field( $input[$key] ) : sanitize_text_field( $input[$key] );
			update_post_meta( $post_id, $key, self::limit_text( $value, '_wpmft_address' === $key ? 500 : 64 ) );
		}
		foreach ( array( '_wpmft_website', '_wpmft_social_facebook', '_wpmft_social_instagram', '_wpmft_social_linkedin', '_wpmft_social_x', '_wpmft_social_youtube', '_wpmft_social_tiktok' ) as $key ) {
			if ( ! array_key_exists( $key, $input ) || ! is_string( $input[$key] ) ) { continue; }
			$value = trim( $input[$key] );
			if ( '' === $value ) { update_post_meta( $post_id, $key, '' ); continue; }
			$parts = wp_parse_url( $value );
			$url = esc_url_raw( $value, array( 'http', 'https' ) );
			if ( strlen( $value ) > 2048 || ! $url || ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) { $invalid = true; continue; }
			update_post_meta( $post_id, $key, $url );
		}
		if ( $invalid ) { self::editor_notice( __( 'A contact field was not saved. Use a valid email address and complete http:// or https:// links. The previous value has been retained.', 'wp-my-family-tree' ) ); }
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
		self::save_contact_details( $post_id, $input );
		if ( isset( $input['_thumbnail_id'] ) && is_scalar( $input['_thumbnail_id'] ) && preg_match( '/^\d+$/D', (string) $input['_thumbnail_id'] ) ) {
			$portrait = absint( $input['_thumbnail_id'] );
			if ( ! $portrait ) { delete_post_thumbnail( $post_id ); }
			elseif ( wp_attachment_is_image( $portrait ) && ( current_user_can( 'edit_post', $portrait ) || $portrait === (int) get_post_thumbnail_id( $post_id ) ) ) { set_post_thumbnail( $post_id, $portrait ); }
		}
		if ( isset( $input['gender'] ) && is_string( $input['gender'] ) && in_array( $input['gender'], array( '', 'm', 'f', 'x' ), true ) ) { update_post_meta( $post_id, 'gender', $input['gender'] ); }
		foreach ( array( 'born', 'died', '_wpmft_maiden_name', '_wpmft_birth_place', '_wpmft_death_place', '_wpmft_location', '_wpmft_occupation' ) as $key ) {
			if ( ! array_key_exists( $key, $input ) || ! is_scalar( $input[$key] ) ) { continue; }
			$value = self::limit_text( sanitize_text_field( $input[$key] ), 250 );
			if ( in_array( $key, array( 'born', 'died' ), true ) && $value !== get_post_meta( $post_id, $key, true ) && ! self::valid_date( $value ) ) {
				self::editor_notice( __( 'A date was not saved. Use YYYY, YYYY-MM or a valid YYYY-MM-DD. The previous value has been retained.', 'wp-my-family-tree' ) );
				continue;
			}
			update_post_meta( $post_id, $key, $value );
		}
		foreach ( array( 'father', 'mother', 'spouse' ) as $key ) {
			if ( ! array_key_exists( $key, $input ) || ! is_scalar( $input[$key] ) ) { continue; }
			$id = self::valid_relation( $input[$key], $post_id );
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
		if ( isset( $input['_wpmft_spouses'] ) && is_string( $input['_wpmft_spouses'] ) ) { update_post_meta( $post_id, '_wpmft_spouses', array_values( array_unique( array_slice( $spouses, 0, 50 ) ) ) ); }
		if ( isset( $input['partner_status_present'] ) && '1' === $input['partner_status_present'] ) {
			$partners = array();
			// Preserve declarations the current editor cannot inspect; never modify the other person's post.
			foreach ( WPMFT_Data::partner_declarations( $post_id ) as $person => $status ) {
				if ( ! current_user_can( 'read_post', $person ) ) { $partners[$person] = $status; }
			}
			$rows = isset( $input['partners'] ) && is_array( $input['partners'] ) ? array_slice( $input['partners'], 0, 50 ) : array();
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['person'], $row['status'] ) || ! is_scalar( $row['person'] ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $row['person'] ) || ! in_array( $row['status'], array( 'together', 'divorced', 'separated', 'former' ), true ) ) { continue; }
				$person = self::valid_relation( $row['person'], $post_id );
				if ( ! $person || WPMFT_Data::canonical( $person ) === WPMFT_Data::canonical( $post_id ) ) { continue; }
				$partners[$person] = WPMFT_Data::resolve_partner_status( $partners[$person] ?? '', $row['status'] );
			}
			update_post_meta( $post_id, '_wpmft_partner_status', $partners );
		}
		$gallery = array();
		$existing_gallery = self::relation_ids( get_post_meta( $post_id, '_wpmft_gallery', true ) );
		foreach ( isset( $input['_wpmft_gallery'] ) && is_string( $input['_wpmft_gallery'] ) ? explode( ',', $input['_wpmft_gallery'] ) : array() as $id ) {
			$id = absint( $id );
			// A new association may trigger encryption; only select files this editor owns or can edit.
			if ( $id && wp_attachment_is_image( $id ) && ( current_user_can( 'edit_post', $id ) || in_array( $id, $existing_gallery, true ) ) ) { $gallery[] = $id; }
		}
		if ( isset( $input['_wpmft_gallery'] ) && is_string( $input['_wpmft_gallery'] ) ) { update_post_meta( $post_id, '_wpmft_gallery', array_values( array_unique( array_slice( $gallery, 0, 100 ) ) ) ); }
		do_action( 'wpmft_person_saved', $post_id );
	}

	private static function help() {
		?>
		<ol class="wpmft-help-steps">
			<li><strong><?php esc_html_e( 'Choose your family category.', 'wp-my-family-tree' ); ?></strong> <?php esc_html_e( 'An existing WP Family Tree category is detected during setup. Keep it selected to reuse your people and relationships.', 'wp-my-family-tree' ); ?></li>
			<li><strong><?php esc_html_e( 'Create or edit the tree page.', 'wp-my-family-tree' ); ?></strong> <?php esc_html_e( 'Insert the Family Tree block, or a Shortcode block containing [wp_my_family_tree]. Choose a starting person in General.', 'wp-my-family-tree' ); ?></li>
			<li><strong><?php esc_html_e( 'Set access and contact details.', 'wp-my-family-tree' ); ?></strong> <?php esc_html_e( 'Private access starts locked until you set a password. Set the family administrator email and optionally enable hCaptcha.', 'wp-my-family-tree' ); ?></li>
			<li><strong><?php esc_html_e( 'Add people as WordPress posts.', 'wp-my-family-tree' ); ?></strong> <?php esc_html_e( 'Choose Add a person, enter the full name as the title, then add a portrait, dates, parents, partners, contact details and photographs in the form at the top. Write the biography below. Children and siblings come from parent links.', 'wp-my-family-tree' ); ?></li>
			<li><strong><?php esc_html_e( 'Check privacy while signed out.', 'wp-my-family-tree' ); ?></strong> <?php esc_html_e( 'Open a private window. Test the tree, a person’s old URL, a feed and an old direct photograph URL. Clear previous caches. The original plugin should be inactive.', 'wp-my-family-tree' ); ?></li>
		</ol>
		<h3><?php esc_html_e( 'Shortcodes', 'wp-my-family-tree' ); ?></h3>
		<p><code>[wp_my_family_tree]</code><br><code>[wp_my_family_tree root="123"]</code></p>
		<p><?php esc_html_e( 'The root is a WordPress post ID. Legacy [family-tree] and [family-members] shortcodes are supported for migration.', 'wp-my-family-tree' ); ?></p>
		<h3><?php esc_html_e( 'Languages', 'wp-my-family-tree' ); ?></h3>
		<p><?php esc_html_e( 'English and Latvian interface text is included. Names and biographies are never automatically translated. With Polylang, add English and Latvian under Languages and create linked page/post translations as needed.', 'wp-my-family-tree' ); ?></p>
		<p><?php esc_html_e( 'In the default person editor, plugin labels follow the selected Polylang language without reloading or discarding unsaved edits. Other plugin screens start in your WordPress profile language and include an English / Latvian label switch. With the optional block editor, Polylang may save and reload when its content language changes.', 'wp-my-family-tree' ); ?></p>
		<h3><?php esc_html_e( 'Person editor', 'wp-my-family-tree' ); ?></h3>
		<p><?php esc_html_e( 'Family people use a details-first classic editor by default. Enable the block editor in General if you prefer it. This setting does not change other posts, pages or saved biography content.', 'wp-my-family-tree' ); ?></p>
		<h3><?php esc_html_e( 'Restore backups safely', 'wp-my-family-tree' ); ?></h3>
		<p><?php esc_html_e( 'Restore a matching UpdraftPlus set on staging first. Preserve every filename exactly, including its date, site name, 12-character identifier and -db.gz / -plugins.zip / -themes.zip / -uploads.zip suffix. A standalone plugin ZIP is installed using Plugins → Add New → Upload Plugin, not the Updraft restore screen.', 'wp-my-family-tree' ); ?></p>
		<h3><?php esc_html_e( 'Developer', 'wp-my-family-tree' ); ?></h3>
		<p>Raivis Kalnins · <a href="https://digitalpulse.click/" target="_blank" rel="noopener noreferrer">DigitalPulse</a></p>
		<?php
	}
}
