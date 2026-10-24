<?php
/**
 * Settings, person metadata and authenticated editor tools.
 *
 * @package WPMyFamilyTree
 */

defined( 'ABSPATH' ) || exit;

final class WPMFT_Admin {

	public static function init() {
		// Override only this plugin's text, never the WordPress dashboard or theme locale.
		add_filter( 'gettext_my-family-tree', array( __CLASS__, 'translate_admin' ), 10, 3 );
		add_filter( 'use_block_editor_for_post', array( __CLASS__, 'block_editor' ), 100, 2 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'preserve_biography' ), 99, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'register_person_labels' ), 21 );
		add_filter( 'post_updated_messages', array( __CLASS__, 'person_updated_messages' ) );
		add_filter( 'bulk_post_updated_messages', array( __CLASS__, 'person_bulk_messages' ) );
		add_filter( 'admin_url', array( __CLASS__, 'person_admin_url' ), 10, 2 );
		add_filter( 'submenu_file', array( __CLASS__, 'person_submenu_file' ), 10, 2 );
		add_action( 'edit_form_after_title', array( __CLASS__, 'person_panel' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'add_meta_boxes_post', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_post', array( __CLASS__, 'save_person' ), 20, 3 );
		add_action( 'admin_post_wpmft_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_wpmft_create_page', array( __CLASS__, 'create_page' ) );
		add_action( 'admin_post_wpmft_revoke_sessions', array( __CLASS__, 'revoke_sessions' ) );
		add_action( 'admin_post_wpmft_protect_media', array( __CLASS__, 'protect_media' ) );
		add_action( 'admin_post_wpmft_clear_cache', array( __CLASS__, 'clear_cache' ) );
		add_action( 'wp_ajax_wpmft_admin_people', array( __CLASS__, 'people_search' ) );
		add_action( 'wp_ajax_wpmft_admin_protect_media', array( __CLASS__, 'protect_media_ajax' ) );
		add_action( 'wp_ajax_wpmft_admin_optimize_media', array( __CLASS__, 'optimize_media_ajax' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPMFT_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Explicit UI choice, then the Polylang person language, then the editor's profile. */
	public static function language() {
		foreach ( array( $_POST['wpmft_admin_lang'] ?? '', $_GET['wpmft_admin_lang'] ?? '', $_POST['post_lang_choice'] ?? '', $_GET['new_lang'] ?? '' ) as $candidate ) {
			if ( is_string( $candidate ) && isset( WPMFT_Languages::registry()[$candidate] ) ) { return $candidate; }
		}
		global $post;
		$id = $post instanceof WP_Post ? $post->ID : 0;
		foreach ( array( $_GET['post'] ?? 0, $_POST['post_ID'] ?? 0, $_POST['post_id'] ?? 0 ) as $candidate ) {
			if ( is_scalar( $candidate ) && absint( $candidate ) ) { $id = absint( $candidate ); break; }
		}
		if ( $id && function_exists( 'pll_get_post_language' ) && 'post' === get_post_type( $id ) ) {
			$lang = pll_get_post_language( $id, 'slug' );
			if ( isset( WPMFT_Languages::registry()[$lang] ) ) { return $lang; }
		}
		return WPMFT_Languages::from_locale( get_user_locale() );
	}

	public static function translate_admin( $translation, $text, $domain ) {
		if ( ! is_admin() || ! did_action( 'init' ) ) { return $translation; }
		// Front-end AJAX has its own request language; do not replace it with a user profile.
		if ( wp_doing_ajax() && ! in_array( $_REQUEST['action'] ?? '', array( 'wpmft_admin_people', 'wpmft_admin_protect_media', 'wpmft_admin_optimize_media' ), true ) ) { return $translation; }
		$labels = WPMFT_Languages::labels( self::language(), 'admin' );
		return $labels[$text] ?? $text;
	}

	private static function language_control() {
		?>
		<div class="wpmft-admin-language-control">
			<label for="wpmft-admin-language"><?php esc_html_e( 'Plugin interface language', 'my-family-tree' ); ?></label>
			<select id="wpmft-admin-language" class="wpmft-admin-language" name="wpmft_admin_lang"><?php foreach ( WPMFT_Languages::registry() as $slug => $entry ) : ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( self::language(), $slug ); ?>><?php echo esc_html( $entry['name'] ); ?></option><?php endforeach; ?></select>
			<p class="description"><?php esc_html_e( 'Person labels follow the Polylang language. This switch changes plugin labels only; names, biographies and the WordPress dashboard are unchanged.', 'my-family-tree' ); ?></p>
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
		return self::person_context( $post ) ? __( 'Person’s full name', 'my-family-tree' ) : $title;
	}

	/** Core registers posts early; translate after this plugin and optional Polylang initialize. */
	public static function register_person_labels() {
		add_filter( 'post_type_labels_post', array( __CLASS__, 'person_post_labels' ) );
		$type = get_post_type_object( 'post' );
		if ( $type ) {
			$type->labels = self::person_post_labels( $type->labels );
			$type->label = $type->labels->name;
		}
	}

	/** Only presentation changes: people remain ordinary posts with their existing data and URLs. */
	public static function person_post_labels( $labels ) {
		if ( ! did_action( 'init' ) || empty( wpmft_settings()['person_admin_labels'] ) || ( ! is_admin() && ! is_user_logged_in() ) ) { return $labels; }
		$person_labels = array(
			'name' => __( 'Persons', 'my-family-tree' ),
			'singular_name' => __( 'Person', 'my-family-tree' ),
			'menu_name' => __( 'Persons', 'my-family-tree' ),
			'name_admin_bar' => __( 'Person', 'my-family-tree' ),
			'add_new' => __( 'Add person', 'my-family-tree' ),
			'add_new_item' => __( 'Add person', 'my-family-tree' ),
			'edit_item' => __( 'Edit person', 'my-family-tree' ),
			'new_item' => __( 'New person', 'my-family-tree' ),
			'view_item' => __( 'View person', 'my-family-tree' ),
			'view_items' => __( 'View persons', 'my-family-tree' ),
			'search_items' => __( 'Search persons', 'my-family-tree' ),
			'not_found' => __( 'No persons found.', 'my-family-tree' ),
			'not_found_in_trash' => __( 'No persons found in Trash.', 'my-family-tree' ),
			'all_items' => __( 'All persons', 'my-family-tree' ),
			'archives' => __( 'Person archives', 'my-family-tree' ),
			'attributes' => __( 'Person attributes', 'my-family-tree' ),
			'insert_into_item' => __( 'Insert into person', 'my-family-tree' ),
			'uploaded_to_this_item' => __( 'Uploaded to this person', 'my-family-tree' ),
			'featured_image' => __( 'Portrait', 'my-family-tree' ),
			'set_featured_image' => __( 'Set portrait', 'my-family-tree' ),
			'remove_featured_image' => __( 'Remove portrait', 'my-family-tree' ),
			'use_featured_image' => __( 'Use as portrait', 'my-family-tree' ),
			'filter_items_list' => __( 'Filter persons list', 'my-family-tree' ),
			'items_list_navigation' => __( 'Persons list navigation', 'my-family-tree' ),
			'items_list' => __( 'Persons list', 'my-family-tree' ),
			'item_published' => __( 'Person published.', 'my-family-tree' ),
			'item_published_privately' => __( 'Person published privately.', 'my-family-tree' ),
			'item_reverted_to_draft' => __( 'Person reverted to draft.', 'my-family-tree' ),
			'item_trashed' => __( 'Person moved to Trash.', 'my-family-tree' ),
			'item_scheduled' => __( 'Person scheduled.', 'my-family-tree' ),
			'item_updated' => __( 'Person updated.', 'my-family-tree' ),
			'item_link' => __( 'Person link', 'my-family-tree' ),
			'item_link_description' => __( 'A link to a person.', 'my-family-tree' ),
			'template_name' => __( 'Single person', 'my-family-tree' ),
		);
		foreach ( $person_labels as $key => $value ) { $labels->$key = $value; }
		return $labels;
	}

	/** Core list, editor and toolbar Add links must open the person form too. */
	public static function person_admin_url( $url, $path ) {
		if ( empty( wpmft_settings()['person_admin_labels'] ) || ! is_string( $path ) ) { return $url; }
		$parts = wp_parse_url( $path );
		if ( ! is_array( $parts ) || 'post-new.php' !== ltrim( $parts['path'] ?? '', '/' ) ) { return $url; }
		parse_str( $parts['query'] ?? '', $query );
		if ( isset( $query['wpmft_person'] ) || ( isset( $query['post_type'] ) && 'post' !== $query['post_type'] ) ) { return $url; }
		return add_query_arg( 'wpmft_person', '1', $url );
	}

	public static function person_submenu_file( $file, $parent ) {
		return ! empty( wpmft_settings()['person_admin_labels'] ) && 'edit.php' === $parent && 'post-new.php' === $file ? 'post-new.php?wpmft_person=1' : $file;
	}

	/** Classic-editor notices are separate from WordPress post-type labels. */
	public static function person_updated_messages( $messages ) {
		$post = get_post();
		if ( empty( wpmft_settings()['person_admin_labels'] ) || ! $post instanceof WP_Post || 'post' !== $post->post_type ) { return $messages; }
		$view = ' <a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html__( 'View person', 'my-family-tree' ) . '</a>';
		$preview = ' <a target="_blank" rel="noopener" href="' . esc_url( get_preview_post_link( $post ) ) . '">' . esc_html__( 'Preview person', 'my-family-tree' ) . '</a>';
		$messages['post'][1] = esc_html__( 'Person updated.', 'my-family-tree' ) . $view;
		$messages['post'][4] = esc_html__( 'Person updated.', 'my-family-tree' );
		if ( isset( $_GET['revision'] ) && is_scalar( $_GET['revision'] ) ) {
			/* translators: %s: Date and time of the revision. */
			$messages['post'][5] = sprintf( esc_html__( 'Person restored to revision from %s.', 'my-family-tree' ), esc_html( wp_post_revision_title( absint( $_GET['revision'] ), false ) ) );
		}
		$messages['post'][6] = esc_html__( 'Person published.', 'my-family-tree' ) . $view;
		$messages['post'][7] = esc_html__( 'Person saved.', 'my-family-tree' );
		$messages['post'][8] = esc_html__( 'Person submitted.', 'my-family-tree' ) . $preview;
		/* translators: %s: Scheduled publication date and time. */
		$messages['post'][9] = sprintf( esc_html__( 'Person scheduled for: %s.', 'my-family-tree' ), esc_html( get_the_date( '', $post ) . ' ' . get_the_time( '', $post ) ) ) . $preview;
		$messages['post'][10] = esc_html__( 'Person draft updated.', 'my-family-tree' ) . $preview;
		return $messages;
	}

	public static function person_bulk_messages( $messages ) {
		if ( empty( wpmft_settings()['person_admin_labels'] ) ) { return $messages; }
		$messages['post'] = array(
			/* translators: %s: Number of persons. */
			'updated' => __( 'Persons updated: %s.', 'my-family-tree' ),
			/* translators: %s: Number of persons. */
			'locked' => __( 'Persons not updated because someone is editing them: %s.', 'my-family-tree' ),
			/* translators: %s: Number of persons. */
			'deleted' => __( 'Persons permanently deleted: %s.', 'my-family-tree' ),
			/* translators: %s: Number of persons. */
			'trashed' => __( 'Persons moved to Trash: %s.', 'my-family-tree' ),
			/* translators: %s: Number of persons. */
			'untrashed' => __( 'Persons restored from Trash: %s.', 'my-family-tree' ),
		);
		return $messages;
	}

	public static function person_panel( $post ) {
		if ( ! self::classic_person_screen( $post ) ) { return; }
		echo '<section class="wpmft-person-panel" aria-labelledby="wpmft-person-heading"><h2 id="wpmft-person-heading">' . esc_html__( 'Family tree — person details', 'my-family-tree' ) . '</h2>';
		self::person_box( $post );
		echo '</section><h2 class="wpmft-biography-heading">' . esc_html__( 'Biography', 'my-family-tree' ) . '</h2>';
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
		global $submenu;
		if ( ! empty( wpmft_settings()['person_admin_labels'] ) && isset( $submenu['edit.php'][10][2] ) && 'post-new.php' === $submenu['edit.php'][10][2] ) {
			$submenu['edit.php'][10][2] = 'post-new.php?wpmft_person=1';
		}
		add_menu_page( __( 'My Family Tree', 'my-family-tree' ), __( 'Family Tree', 'my-family-tree' ), 'manage_options', 'wpmft', array( __CLASS__, 'settings_page' ), 'dashicons-networking', 26 );
		add_submenu_page( 'wpmft', __( 'Settings', 'my-family-tree' ), __( 'Settings & help', 'my-family-tree' ), 'manage_options', 'wpmft', array( __CLASS__, 'settings_page' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wpmft' ) ) . '">' . esc_html__( 'Settings', 'my-family-tree' ) . '</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		$screen = get_current_screen();
		$is_post = $screen && 'post' === $screen->post_type && in_array( $hook, array( 'post.php', 'post-new.php' ), true );
		if ( 'toplevel_page_wpmft' !== $hook && ! $is_post ) {
			return;
		}
		wp_enqueue_style( 'wpmft-admin', wpmft_asset_url( 'admin.css' ), array(), wpmft_asset_version() );
		wp_enqueue_script( 'wpmft-admin', wpmft_asset_url( 'admin.js' ), array( 'jquery' ), wpmft_asset_version(), true );
		if ( $is_post ) {
			wp_enqueue_media();
		}
		wp_localize_script( 'wpmft-admin', 'WPMFT_ADMIN', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'language' => self::language(), 'catalogs' => WPMFT_Languages::dictionaries( 'admin' ),
			'languages' => array_keys( WPMFT_Languages::registry() ),
			'nonce' => wp_create_nonce( 'wpmft_admin_people' ),
			'mediaNonce' => wp_create_nonce( 'wpmft_protect_media' ),
			'optimizationNonce' => wp_create_nonce( 'wpmft_optimize_media' ),
			'strings' => array(
				'chooseImages' => __( 'Choose family photographs', 'my-family-tree' ),
				'useImages' => __( 'Use selected photographs', 'my-family-tree' ),
				'addImages' => __( 'Add photographs', 'my-family-tree' ),
				'reorderImages' => __( 'Reorder photographs', 'my-family-tree' ),
				'loadingImages' => __( 'Loading photographs…', 'my-family-tree' ),
				'galleryLoadError' => __( 'Could not load all photographs. Your gallery has not changed. Please try again.', 'my-family-tree' ),
				'photoStory' => __( 'Photo story / description', 'my-family-tree' ),
				'photoDate' => __( 'When / date', 'my-family-tree' ),
				'photoDatePlaceholder' => __( 'For example: Summer 1980', 'my-family-tree' ),
				'optimizingImages' => __( 'Preparing smaller family photographs…', 'my-family-tree' ),
				'imagesOptimizationChecking' => __( 'Checking photographs waiting for optimization…', 'my-family-tree' ),
				'imagesOptimizationPending' => __( 'Photographs waiting for optimization:', 'my-family-tree' ),
				'imagesOptimizationProcessed' => __( 'Photographs processed:', 'my-family-tree' ),
				'imagesOptimizationSkipped' => __( 'Photographs needing attention:', 'my-family-tree' ),
				'imagesOptimizationUnscanned' => __( 'Photographs still to scan:', 'my-family-tree' ),
				'imagesOptimizationPartial' => __( 'The scan finished, but some photographs need attention. Review the errors and try again after fixing them.', 'my-family-tree' ),
				'imagesOptimizationCheckError' => __( 'Could not calculate photograph counts. You can still start preparation or reload this page.', 'my-family-tree' ),
				'imagesOptimized' => __( 'Family photograph optimization is complete.', 'my-family-tree' ),
				'imagesOptimizationPaused' => __( 'Paused after the current batch. You can continue preparing photographs later.', 'my-family-tree' ),
				'imagesOptimizationError' => __( 'Some photographs could not be optimized. Existing images remain available. Please review the error and try again.', 'my-family-tree' ),
				'removeImage' => __( 'Remove photograph from gallery', 'my-family-tree' ),
				'remove' => __( 'Remove', 'my-family-tree' ),
				'noResults' => __( 'No family members found.', 'my-family-tree' ),
				'searchError' => __( 'Search could not load. Please try again.', 'my-family-tree' ),
				'searching' => __( 'Searching…', 'my-family-tree' ),
				'protecting' => __( 'Protecting family photographs…', 'my-family-tree' ),
				'protected' => __( 'Family photograph protection is complete.', 'my-family-tree' ),
				'remaining' => __( 'Photographs remaining:', 'my-family-tree' ),
				'protectionError' => __( 'Some photographs could not be protected. Keep the original backup and review the error before publishing.', 'my-family-tree' ),
				'relationshipLimit' => __( 'Add no more than 50 partner relationships for one person.', 'my-family-tree' ),
				'choosePortrait' => __( 'Choose a portrait', 'my-family-tree' ),
				'usePortrait' => __( 'Use this portrait', 'my-family-tree' ),
				'noPortrait' => __( 'No portrait selected', 'my-family-tree' ),
			),
		) );
	}

	private static function require_admin( $nonce_action ) {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'A POST request is required.', 'my-family-tree' ), '', array( 'response' => 405 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change family tree settings.', 'my-family-tree' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce_action );
	}

	private static function redirect( $message = 'saved', $section = '' ) {
		$anchor = in_array( $section, array( 'general', 'privacy', 'appearance', 'contact', 'performance', 'languages', 'demo', 'help' ), true ) ? '#wpmft-' . $section : '';
		wp_safe_redirect( add_query_arg( array( 'wpmft_notice' => $message, 'wpmft_admin_lang' => self::language() ), admin_url( 'admin.php?page=wpmft' ) ) . $anchor );
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
		if ( ! WPMFT_Demo::guard_required() && ! empty( $s['password_protection'] ) && empty( $s['password_hash'] ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Your family tree is locked for visitors. Set a family password in Privacy to allow access, or explicitly turn password protection off for a public tree.', 'my-family-tree' ) . '</p></div>';
		}
		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'wp-family-tree/family-tree.php' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Deactivate the original WP Family Tree plugin. Its older public routes can bypass the privacy settings of this replacement. Your posts and photographs are retained.', 'my-family-tree' ) . '</p></div>';
		}
	}

	public static function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = wpmft_settings();
		$notice = isset( $_GET['wpmft_notice'] ) && is_string( $_GET['wpmft_notice'] ) ? sanitize_key( wp_unslash( $_GET['wpmft_notice'] ) ) : '';
		$messages = array(
			'saved' => __( 'Settings saved.', 'my-family-tree' ),
			'created' => __( 'Your family tree page is ready.', 'my-family-tree' ),
			'revoked' => __( 'All remembered family sessions have been revoked.', 'my-family-tree' ),
			'password' => __( 'Settings were not saved. Use a family password with at least 12 characters.', 'my-family-tree' ),
			'hcaptcha' => __( 'Settings were not saved. Add both hCaptcha keys before enabling it.', 'my-family-tree' ),
			'error' => __( 'The page could not be created. Please try again.', 'my-family-tree' ),
			'email' => __( 'Settings were not saved. Enter a valid family administrator email address.', 'my-family-tree' ),
			'whatsapp' => __( 'Settings were not saved. Enter a WhatsApp number with its country code, or clear the number and turn off the administrator WhatsApp button.', 'my-family-tree' ),
			'media' => __( 'A batch of family photographs has been protected. Continue until no photographs remain.', 'my-family-tree' ),
			'cache' => __( 'Family Tree cache cleared. Static asset URLs have been refreshed.', 'my-family-tree' ),
		);
		?>
		<div class="wrap wpmft-settings">
			<div class="wpmft-admin-heading"><div><p class="wpmft-eyebrow">DigitalPulse</p><h1><?php esc_html_e( 'My Family Tree', 'my-family-tree' ); ?></h1><p><?php esc_html_e( 'Keep the stories. Connect the generations.', 'my-family-tree' ); ?></p></div><span class="wpmft-version"><?php echo esc_html( WPMFT_VERSION ); ?></span></div>
			<?php if ( isset( $messages[ $notice ] ) ) : ?><div class="notice <?php echo in_array( $notice, array( 'password', 'hcaptcha', 'error', 'email', 'whatsapp' ), true ) ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( $messages[ $notice ] ); ?></p></div><?php endif; ?>
			<div class="wpmft-quickstart">
				<strong><?php esc_html_e( 'Your tree, in any theme', 'my-family-tree' ); ?></strong><p><?php esc_html_e( 'Add the Family Tree block or this shortcode to a page:', 'my-family-tree' ); ?> <code>[wp_my_family_tree]</code></p>
				<div class="wpmft-admin-actions">
					<?php if ( ! empty( $s['page_id'] ) && get_post( $s['page_id'] ) ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( get_permalink( $s['page_id'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View tree', 'my-family-tree' ); ?></a>
						<a class="button" href="<?php echo esc_url( get_edit_post_link( $s['page_id'], 'raw' ) ); ?>"><?php esc_html_e( 'Edit tree page', 'my-family-tree' ); ?></a>
					<?php endif; ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?wpmft_person=1' ) ); ?>"><?php esc_html_e( 'Add a person', 'my-family-tree' ); ?></a>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'cat' => absint( $s['category_id'] ) ), admin_url( 'edit.php' ) ) ); ?>"><?php esc_html_e( 'Manage family posts', 'my-family-tree' ); ?></a>
				</div>
			</div>
			<nav class="wpmft-admin-tabs" aria-label="<?php esc_attr_e( 'Family tree settings', 'my-family-tree' ); ?>">
				<?php foreach ( array( 'general' => __( 'General', 'my-family-tree' ), 'privacy' => __( 'Privacy & access', 'my-family-tree' ), 'appearance' => __( 'Appearance', 'my-family-tree' ), 'contact' => __( 'Contact & hCaptcha', 'my-family-tree' ), 'performance' => __( 'Performance & cache', 'my-family-tree' ), 'languages' => __( 'Languages', 'my-family-tree' ), 'demo' => __( 'Demo', 'my-family-tree' ), 'help' => __( 'Getting started', 'my-family-tree' ) ) as $key => $label ) : ?>
				<?php if ( 'demo' === $key && ! WPMFT_Demo::tools_visible() ) { continue; } ?>
				<a href="#wpmft-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="wpmft-settings-form">
				<input type="hidden" name="action" value="wpmft_save_settings">
				<input type="hidden" name="wpmft[v110_present]" value="1">
				<input type="hidden" name="wpmft[v112_present]" value="1">
				<input type="hidden" name="wpmft[v120_present]" value="1">
				<input type="hidden" name="wpmft[v125_present]" value="1">
				<input type="hidden" name="wpmft[v127_demo_present]" value="1">
				<input type="hidden" name="wpmft[v127_contact_present]" value="1">
				<input type="hidden" name="wpmft[v127_media_present]" value="1">
				<input type="hidden" name="wpmft_section" value="general">
				<?php wp_nonce_field( 'wpmft_save_settings' ); ?>
				<?php self::language_control(); ?>
				<section id="wpmft-general" class="wpmft-settings-section"><h2><?php esc_html_e( 'General', 'my-family-tree' ); ?></h2>
					<?php self::checkbox( 'enabled', __( 'Enable the family tree', 'my-family-tree' ), $s ); ?>
					<?php self::checkbox( 'person_admin_labels', __( 'Use Persons labels for the WordPress Posts section', 'my-family-tree' ), $s ); ?>
					<p class="description"><?php esc_html_e( 'Turn this off if this site also uses posts for news or a blog. Existing content and URLs are unchanged.', 'my-family-tree' ); ?></p>
					<?php self::checkbox( 'person_block_editor', __( 'Enable the WordPress block editor for family people', 'my-family-tree' ), $s ); ?>
					<p class="description"><?php esc_html_e( 'By default, people open with their details form above the biography editor. Other posts and pages keep their usual editor. Existing biographies are preserved when only person details change.', 'my-family-tree' ); ?></p>
					<label class="wpmft-field"><span><?php esc_html_e( 'Family member category', 'my-family-tree' ); ?></span><?php wp_dropdown_categories( array( 'name' => 'wpmft[category_id]', 'selected' => absint( $s['category_id'] ), 'hide_empty' => false, 'show_option_none' => esc_html__( 'Select a category', 'my-family-tree' ), 'option_none_value' => 0, 'hierarchical' => true ) ); ?><small><?php esc_html_e( 'Existing WP Family Tree posts remain ordinary WordPress posts in this category.', 'my-family-tree' ); ?></small></label>
					<label class="wpmft-field"><span><?php esc_html_e( 'Tree page', 'my-family-tree' ); ?></span><?php wp_dropdown_pages( array( 'name' => 'wpmft[page_id]', 'selected' => absint( $s['page_id'] ), 'show_option_none' => esc_html__( 'Choose a page', 'my-family-tree' ), 'option_none_value' => 0 ) ); ?></label>
					<?php self::person_picker( 'wpmft-root-person', 'wpmft[root_id]', $s['root_id'], __( 'Starting person', 'my-family-tree' ) ); ?>
					<?php self::checkbox( 'standalone', __( 'Use the full-window layout without the theme header and footer', 'my-family-tree' ), $s ); ?>
					<p class="description"><?php esc_html_e( 'The plugin provides its own title, header and contact footer. This applies to pages containing the tree shortcode or block.', 'my-family-tree' ); ?></p>
					<label class="wpmft-field"><span><?php esc_html_e( 'Default interface language', 'my-family-tree' ); ?></span><select name="wpmft[language]"><?php foreach ( WPMFT_Languages::registry() as $slug => $entry ) : ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $s['language'], $slug ); ?>><?php echo esc_html( $entry['name'] ); ?></option><?php endforeach; ?></select></label>
					<?php self::checkbox( 'language_switch', __( 'Show the language switch', 'my-family-tree' ), $s ); ?>
					<p class="description"><?php esc_html_e( 'Open the Languages tab to enable preset languages, add languages or review optional Polylang for translated people and pages.', 'my-family-tree' ); ?></p>
					<h3><?php esc_html_e( 'Optional demo tools', 'my-family-tree' ); ?></h3>
					<?php self::checkbox( 'demo_tools_enabled', __( 'Show the Demo tab for sample content and demo sharing', 'my-family-tree' ), $s ); ?>
					<p class="description"><?php esc_html_e( 'After initial setup, demo tools stay hidden until you turn this on and save. This switch only shows the tools; importing content and enabling shared access each require a separate confirmation.', 'my-family-tree' ); ?></p>
					<?php WPMFT_Demo::render_general_status(); ?>
				</section>
				<section id="wpmft-privacy" class="wpmft-settings-section"><h2><?php esc_html_e( 'Privacy & access', 'my-family-tree' ); ?></h2>
					<?php self::checkbox( 'password_protection', __( 'Require a family password', 'my-family-tree' ), $s ); ?>
					<label class="wpmft-field"><span><?php esc_html_e( 'Set a new family password', 'my-family-tree' ); ?></span><input type="password" name="wpmft[new_password]" autocomplete="new-password" minlength="12" maxlength="256" value=""><small><?php esc_html_e( 'At least 12 characters. Leave blank to keep the current password. The password is stored as a one-way hash.', 'my-family-tree' ); ?></small></label>
					<?php self::field( 'remember_days', __( 'Maximum remembered access (days)', 'my-family-tree' ), $s, 'number', array( 'min' => 1, 'max' => 30 ) ); ?>
					<?php self::checkbox( 'hide_living_dates', __( 'Hide the birth dates of living people in the tree and person dialogs', 'my-family-tree' ), $s ); ?>
					<?php self::checkbox( 'private_media', __( 'Automatically encrypt family photograph files when people are saved', 'my-family-tree' ), $s ); ?>
					<p class="description"><?php esc_html_e( 'Encrypted photographs are served through the family access check. This switch controls protection of new files; disabling it does not make already encrypted photographs public. Use “Protect existing family photos” below to process older files.', 'my-family-tree' ); ?></p>
					<div class="wpmft-admin-note"><p><?php esc_html_e( 'Private pages and family API responses use no-store headers. Exclude the tree page, admin-ajax.php and protected media from host/CDN page caching. Purge older public caches after migration.', 'my-family-tree' ); ?></p><p><?php esc_html_e( 'Keep a full backup before protecting files. Protection replaces public family image files with an encrypted vault. The database encryption key and encrypted uploads must be backed up together. Older cached or externally copied photographs cannot be recalled.', 'my-family-tree' ); ?></p></div>
				</section>
				<section id="wpmft-appearance" class="wpmft-settings-section"><h2><?php esc_html_e( 'Appearance', 'my-family-tree' ); ?></h2>
					<?php self::field( 'title', __( 'Title (English)', 'my-family-tree' ), $s ); ?>
					<?php self::field( 'title_lv', __( 'Title (Latvian)', 'my-family-tree' ), $s ); ?>
					<?php self::field( 'subtitle', __( 'Introduction (English)', 'my-family-tree' ), $s ); ?>
					<?php self::field( 'subtitle_lv', __( 'Introduction (Latvian)', 'my-family-tree' ), $s ); ?>
					<div class="wpmft-field-row"><?php self::field( 'accent', __( 'Accent colour', 'my-family-tree' ), $s, 'color' ); ?><?php self::field( 'background', __( 'Background colour', 'my-family-tree' ), $s, 'color' ); ?></div>
					<?php self::field( 'tree_height', __( 'Tree canvas height on large screens (px)', 'my-family-tree' ), $s, 'number', array( 'min' => 480, 'max' => 1400 ) ); ?>
					<?php self::checkbox( 'allow_export', __( 'Allow signed-in family visitors to save a tree image and print', 'my-family-tree' ), $s ); ?>
					<?php self::checkbox( 'show_credit', __( 'Show an optional “Built by DigitalPulse” footer link', 'my-family-tree' ), $s ); ?>
					<?php self::checkbox( 'app_install', __( 'Show Android and Apple installation options in the footer', 'my-family-tree' ), $s ); ?>
					<?php self::checkbox( 'app_icons', __( 'Use the included oak leaf favicon and touch icons on the tree page', 'my-family-tree' ), $s ); ?>
				</section>
				<section id="wpmft-contact" class="wpmft-settings-section"><h2><?php esc_html_e( 'Contact & hCaptcha', 'my-family-tree' ); ?></h2>
					<?php self::checkbox( 'contact_enabled', __( 'Enable the Contact Us form', 'my-family-tree' ), $s ); ?>
					<?php self::field( 'contact_name', __( 'Family administrator name', 'my-family-tree' ), $s ); ?>
					<?php self::field( 'contact_email', __( 'Family administrator email', 'my-family-tree' ), $s, 'email' ); ?>
					<?php self::checkbox( 'show_email', __( 'Display the contact email button in the footer', 'my-family-tree' ), $s ); ?>
					<p class="description"><?php esc_html_e( 'Contact messages use WordPress mail delivery. The form does not save message contents to the family tree database. Your mail provider may retain delivered messages.', 'my-family-tree' ); ?></p>
					<h3><?php esc_html_e( 'WhatsApp chat', 'my-family-tree' ); ?></h3>
					<?php self::checkbox( 'whatsapp_enabled', __( 'Show a WhatsApp chat button for the family administrator', 'my-family-tree' ), $s ); ?>
					<?php self::field( 'whatsapp_phone', __( 'Administrator WhatsApp number', 'my-family-tree' ), $s, 'tel', array( 'maxlength' => 64, 'placeholder' => '+44…' ) ); ?>
					<p class="description"><?php esc_html_e( 'Use the full international number with country code. This optional button is visible on the tree and sign-in page. WhatsApp opens only when a visitor clicks; no chat script is loaded.', 'my-family-tree' ); ?></p>
					<h3><?php esc_html_e( 'Contact photo uploads', 'my-family-tree' ); ?></h3>
					<?php self::checkbox( 'contact_upload_enabled', __( 'Allow one photograph with a contact message', 'my-family-tree' ), $s ); ?>
					<?php self::field( 'contact_upload_max_mb', __( 'Maximum photograph size (MB)', 'my-family-tree' ), $s, 'number', array( 'min' => 1, 'max' => 100, 'step' => 1 ) ); ?>
					<p class="description"><?php esc_html_e( 'The default is 10 MB. Choose 1–100 MB; a lower server upload limit still applies. Photographs are emailed to the administrator and are not added to the public media library.', 'my-family-tree' ); ?></p>
					<fieldset class="wpmft-upload-formats"><legend><?php esc_html_e( 'Allowed photograph formats', 'my-family-tree' ); ?></legend>
					<?php foreach ( array( 'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif' ) as $format ) : ?><label class="wpmft-check"><input type="checkbox" name="wpmft[contact_upload_formats][]" value="<?php echo esc_attr( $format ); ?>" <?php checked( in_array( $format, (array) $s['contact_upload_formats'], true ) ); ?>><span><?php echo esc_html( strtoupper( $format ) ); ?></span></label><?php endforeach; ?>
					</fieldset>
					<p class="description"><?php esc_html_e( 'JPG, JPEG, PNG and WebP are selected by default. GIF and AVIF are optional when the server supports them. At least one format is required; selecting none restores the defaults.', 'my-family-tree' ); ?></p>
					<?php self::checkbox( 'hcaptcha_enabled', __( 'Enable hCaptcha (external anti-spam service)', 'my-family-tree' ), $s ); ?>
					<?php self::field( 'hcaptcha_site_key', __( 'hCaptcha site key', 'my-family-tree' ), $s ); ?>
					<label class="wpmft-field"><span><?php esc_html_e( 'hCaptcha secret key', 'my-family-tree' ); ?></span><input type="password" name="wpmft[hcaptcha_secret]" autocomplete="new-password" value=""><small><?php esc_html_e( 'Leave blank to keep the stored secret. It is never included in the public page.', 'my-family-tree' ); ?></small></label>
					<?php self::checkbox( 'hcaptcha_login', __( 'Use hCaptcha on the family password form', 'my-family-tree' ), $s ); ?>
					<?php self::checkbox( 'hcaptcha_contact', __( 'Use hCaptcha on the contact form', 'my-family-tree' ), $s ); ?>
					<p class="description"><?php esc_html_e( 'When enabled, hCaptcha receives technical browser information and verifies form tokens. Review its service terms and add it to your privacy notice.', 'my-family-tree' ); ?> <a href="https://www.hcaptcha.com/privacy" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Privacy policy', 'my-family-tree' ); ?></a> · <a href="https://www.hcaptcha.com/terms" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Terms', 'my-family-tree' ); ?></a></p>
				</section>
				<section id="wpmft-performance" class="wpmft-settings-section"><h2><?php esc_html_e( 'Performance & cache', 'my-family-tree' ); ?></h2><?php self::performance_panel( $s ); ?></section>
				<section id="wpmft-languages" class="wpmft-settings-section"><h2><?php esc_html_e( 'Languages', 'my-family-tree' ); ?></h2><?php WPMFT_Languages::render_panel(); ?></section>
				<?php if ( WPMFT_Demo::tools_visible() ) : ?><section id="wpmft-demo" class="wpmft-settings-section"><h2><?php esc_html_e( 'Demo', 'my-family-tree' ); ?></h2><?php WPMFT_Demo::render_panel(); ?></section><?php endif; ?>
				<section id="wpmft-help" class="wpmft-settings-section"><h2><?php esc_html_e( 'Getting started', 'my-family-tree' ); ?></h2><?php self::help(); ?></section>
				<div class="wpmft-save-bar"><?php submit_button( __( 'Save settings', 'my-family-tree' ), 'primary', 'submit', false ); ?><span><?php esc_html_e( 'Settings belong to the plugin and work independently of your theme.', 'my-family-tree' ); ?></span></div>
			</form>
			<?php WPMFT_Languages::render_form(); WPMFT_Demo::render_forms(); ?>
			<form id="wpmft-cache-tool" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="wpmft_clear_cache"><?php wp_nonce_field( 'wpmft_clear_cache' ); ?></form>
			<div class="wpmft-secondary-actions">
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="wpmft_create_page"><?php wp_nonce_field( 'wpmft_create_page' ); ?><button class="button" type="submit"><?php esc_html_e( 'Create a tree page', 'my-family-tree' ); ?></button></form>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="wpmft_revoke_sessions"><?php wp_nonce_field( 'wpmft_revoke_sessions' ); ?><button class="button" type="submit"><?php esc_html_e( 'Revoke all remembered access', 'my-family-tree' ); ?></button></form>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" id="wpmft-protect-media-form"><input type="hidden" name="action" value="wpmft_protect_media"><?php wp_nonce_field( 'wpmft_protect_media' ); ?><button class="button" type="submit"><?php esc_html_e( 'Protect existing family photos', 'my-family-tree' ); ?></button></form>
			</div>
			<p id="wpmft-media-progress" role="status" aria-live="polite"></p>
		</div>
		<?php
	}

	private static function checkbox( $key, $label, $settings ) {
		echo '<label class="wpmft-check"><input type="checkbox" name="wpmft[' . esc_attr( $key ) . ']" value="1" ' . checked( ! empty( $settings[ $key ] ), true, false ) . '><span>' . esc_html( $label ) . '</span></label>';
	}

	private static function performance_panel( $s ) {
		$status = WPMFT_Cache::status();
		$cleared = (int) get_option( 'wpmft_cache_cleared_at', 0 );
		self::checkbox( 'performance_cache', __( 'Cache the internal relationship index', 'my-family-tree' ), $s );
		self::field( 'cache_ttl', __( 'Cache lifetime (minutes)', 'my-family-tree' ), $s, 'number', array( 'min' => 1, 'max' => 60, 'step' => 1 ) );
		?>
		<label class="wpmft-field"><span><?php esc_html_e( 'Cache storage', 'my-family-tree' ); ?></span><select name="wpmft[cache_backend]">
			<?php foreach ( array( 'auto' => __( 'Automatic', 'my-family-tree' ), 'object' => __( 'Persistent object cache', 'my-family-tree' ), 'transient' => __( 'WordPress transients', 'my-family-tree' ) ) as $backend => $label ) : ?><option value="<?php echo esc_attr( $backend ); ?>" <?php selected( $s['cache_backend'], $backend ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
		</select><small><?php esc_html_e( 'Automatic uses the host’s persistent object cache when available. Redis or Memcached must be configured separately by your host; otherwise this plugin uses WordPress transients. No global cache flush is performed.', 'my-family-tree' ); ?></small></label>
		<?php if ( ! empty( $status['backend_fallback'] ) ) : ?><p class="wpmft-admin-note"><?php esc_html_e( 'A persistent object cache was requested but is not available. WordPress transients are being used safely until a host object cache is active.', 'my-family-tree' ); ?></p><?php endif; ?>
		<p class="description"><?php esc_html_e( 'The default is 15 minutes. Person and relationship edits refresh the index automatically. Only numeric relationships are stored on the server; personal profiles, photographs and access tokens are not cached by this feature.', 'my-family-tree' ); ?></p>
		<h3><?php esc_html_e( 'Image quality & modern formats', 'my-family-tree' ); ?></h3>
		<label class="wpmft-field"><span><?php esc_html_e( 'Family photograph optimization', 'my-family-tree' ); ?></span><select name="wpmft[image_optimization]">
			<option value="auto" <?php selected( $s['image_optimization'], 'auto' ); ?>><?php esc_html_e( 'Automatic AVIF / WebP when supported', 'my-family-tree' ); ?></option>
			<option value="off" <?php selected( $s['image_optimization'], 'off' ); ?>><?php esc_html_e( 'Use existing WordPress image sizes', 'my-family-tree' ); ?></option>
		</select></label>
		<?php self::field( 'image_quality', __( 'Optimized image quality', 'my-family-tree' ), $s, 'number', array( 'min' => 60, 'max' => 92, 'step' => 1 ) ); ?>
		<p class="description"><?php esc_html_e( 'The default quality is 82. Smaller display copies are prepared in the background after person edits; full-size originals are retained. Galleries load responsive previews first and the large image when opened. Animated images keep their original format.', 'my-family-tree' ); ?></p>
		<?php $image_support = WPMFT_Media::optimization_support(); ?>
		<p class="wpmft-admin-note"><strong><?php esc_html_e( 'Available server encoders:', 'my-family-tree' ); ?></strong> <?php echo esc_html( implode( ', ', array_keys( array_filter( array( 'AVIF' => $image_support['avif'], 'WebP' => $image_support['webp'] ) ) ) ) ?: __( 'No modern image encoder is available; existing image sizes are used.', 'my-family-tree' ) ); ?></p>
		<div class="wpmft-admin-actions">
			<button type="button" class="button" id="wpmft-optimize-images" <?php disabled( 'off' === $s['image_optimization'] || ! array_filter( $image_support ) ); ?>><?php esc_html_e( 'Prepare existing family photographs', 'my-family-tree' ); ?></button>
			<button type="button" class="button" id="wpmft-pause-image-optimization" hidden><?php esc_html_e( 'Pause', 'my-family-tree' ); ?></button>
		</div>
		<p id="wpmft-image-optimization-status" class="wpmft-gallery-status" role="status" aria-live="polite"></p>
		<p class="description"><?php esc_html_e( 'Save settings before preparing existing photographs. The tool processes small batches and can be paused. Optimized private photographs use the same protected delivery as the originals; no external image service is used.', 'my-family-tree' ); ?></p>
		<h3><?php esc_html_e( 'WordPress cleanup', 'my-family-tree' ); ?></h3>
		<?php self::checkbox( 'disable_comments', __( 'Disable comments and pingbacks on posts, pages and media', 'my-family-tree' ), $s ); ?>
		<p class="description"><?php esc_html_e( 'Existing comments are retained for administrators. Custom post type reviews and order notes are unaffected.', 'my-family-tree' ); ?></p>
		<?php self::checkbox( 'disable_emojis', __( 'Remove WordPress emoji scripts and styles', 'my-family-tree' ), $s ); ?>
		<p class="description"><?php esc_html_e( 'Native device emoji still work. WordPress fallback detection, styles and external emoji hints are removed.', 'my-family-tree' ); ?></p>
		<?php self::checkbox( 'clean_head', __( 'Remove unnecessary WordPress head tags', 'my-family-tree' ), $s ); ?>
		<p class="description"><?php esc_html_e( 'Removes generator, RSD, Windows Live Writer and shortlink tags. Full-window family pages also omit feed and embed discovery links. Canonical URLs, language links and privacy directives are kept.', 'my-family-tree' ); ?></p>
		<h3><?php esc_html_e( 'Page source and assets', 'my-family-tree' ); ?></h3>
		<?php self::checkbox( 'minify_assets', __( 'Use minified plugin JavaScript and CSS', 'my-family-tree' ), $s ); ?>
		<p class="description"><?php esc_html_e( 'Readable source files remain included. SCRIPT_DEBUG uses the readable files automatically.', 'my-family-tree' ); ?></p>
		<?php self::checkbox( 'minify_html', __( 'Compact the full-window page HTML', 'my-family-tree' ), $s ); ?>
		<p class="description"><?php esc_html_e( 'Reduces whitespace between tags while preserving inline spacing, scripts, styles and preformatted content. Admin pages and API responses are excluded.', 'my-family-tree' ); ?></p>
		<?php self::checkbox( 'lean_standalone', __( 'Use lean styles on full-window tree pages', 'my-family-tree' ), $s ); ?>
		<p class="description"><?php esc_html_e( 'Optional: omit theme styles, global presets and theme fonts. Keep this off when biographies or other page content use theme-styled blocks. Core block styles remain available; embedded pages retain their theme styling.', 'my-family-tree' ); ?></p>
		<div class="wpmft-cache-panel">
			<h3><?php esc_html_e( 'Cache tools', 'my-family-tree' ); ?></h3>
			<dl class="wpmft-cache-status">
				<dt><?php esc_html_e( 'Index cache', 'my-family-tree' ); ?></dt><dd><?php echo esc_html( ! $status['enabled'] ? __( 'Disabled', 'my-family-tree' ) : ( $status['warm'] ? __( 'Ready', 'my-family-tree' ) : __( 'Builds on the next tree request', 'my-family-tree' ) ) ); ?></dd>
				<dt><?php esc_html_e( 'Storage', 'my-family-tree' ); ?></dt><dd><?php echo esc_html( $status['persistent_object_cache'] ? __( 'Persistent WordPress object cache', 'my-family-tree' ) : __( 'WordPress database transients', 'my-family-tree' ) ); ?></dd>
				<dt><?php esc_html_e( 'Last manual clear', 'my-family-tree' ); ?></dt><dd><?php echo esc_html( $cleared ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $cleared ) : __( 'Not cleared yet', 'my-family-tree' ) ); ?></dd>
			</dl>
			<button class="button button-secondary" type="submit" form="wpmft-cache-tool"><?php esc_html_e( 'Clear Family Tree cache', 'my-family-tree' ); ?></button>
			<p><?php esc_html_e( 'Clears this plugin’s index and refreshes its static asset URLs. Passwords, remembered access, anti-spam limits and protected media are retained. Host, CDN and other plugins’ caches are managed separately.', 'my-family-tree' ); ?></p>
			<p class="description"><?php esc_html_e( 'Save changed settings before using the cache tool. Private family pages and API responses continue to use no-store headers.', 'my-family-tree' ); ?></p>
		</div>
		<?php
	}

	public static function clear_cache() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( esc_html__( 'A POST request is required.', 'my-family-tree' ), '', array( 'response' => 405 ) ); }
		self::require_admin( 'wpmft_clear_cache' );
		WPMFT_Cache::purge();
		self::redirect( 'cache', 'performance' );
	}

	public static function optimize_media_ajax() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_send_json_error( array( 'message' => __( 'A POST request is required.', 'my-family-tree' ) ), 405 ); }
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => __( 'Access denied.', 'my-family-tree' ) ), 403 ); }
		check_ajax_referer( 'wpmft_optimize_media', 'nonce' );
		if ( isset( $_POST['op'] ) && 'status' === $_POST['op'] ) { wp_send_json_success( WPMFT_Media::optimization_status() ); }
		$after = isset( $_POST['after'] ) && is_scalar( $_POST['after'] ) ? absint( $_POST['after'] ) : 0;
		wp_send_json_success( WPMFT_Media::optimize_batch( 1, $after ) );
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
		if ( isset( $input['v127_contact_present'] ) && '1' === $input['v127_contact_present'] ) {
			$s['whatsapp_enabled'] = isset( $input['whatsapp_enabled'] ) && '1' === $input['whatsapp_enabled'] ? 1 : 0;
			$raw_whatsapp = isset( $input['whatsapp_phone'] ) && is_string( $input['whatsapp_phone'] ) ? trim( $input['whatsapp_phone'] ) : '';
			$s['whatsapp_phone'] = wpmft_whatsapp_number( $raw_whatsapp );
			if ( ( '' !== $raw_whatsapp || $s['whatsapp_enabled'] ) && ! $s['whatsapp_phone'] ) { self::redirect( 'whatsapp', 'contact' ); }
		}
		if ( isset( $input['v127_media_present'] ) && '1' === $input['v127_media_present'] ) {
			$s['image_optimization'] = isset( $input['image_optimization'] ) && 'off' === $input['image_optimization'] ? 'off' : 'auto';
			$s['image_quality'] = max( 60, min( 92, isset( $input['image_quality'] ) && is_scalar( $input['image_quality'] ) ? absint( $input['image_quality'] ) : 82 ) );
		}
		foreach ( array( 'enabled', 'standalone', 'contact_enabled', 'show_email', 'password_protection', 'hide_living_dates', 'private_media', 'language_switch', 'allow_export', 'show_credit', 'hcaptcha_enabled', 'hcaptcha_login', 'hcaptcha_contact' ) as $key ) {
			$s[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}
		// Older forms left open during an upgrade must not reset settings they never displayed.
		if ( isset( $input['v127_demo_present'] ) && '1' === $input['v127_demo_present'] ) {
			$s['demo_tools_enabled'] = isset( $input['demo_tools_enabled'] ) && '1' === $input['demo_tools_enabled'] ? 1 : 0;
		}
		if ( isset( $input['v125_present'] ) && '1' === $input['v125_present'] ) {
			$s['person_admin_labels'] = isset( $input['person_admin_labels'] ) && '1' === $input['person_admin_labels'] ? 1 : 0;
		}
		if ( isset( $input['v112_present'] ) && '1' === $input['v112_present'] ) {
			foreach ( array( 'performance_cache', 'disable_comments', 'disable_emojis', 'clean_head', 'minify_assets', 'minify_html', 'lean_standalone' ) as $key ) { $s[$key] = isset( $input[$key] ) && '1' === $input[$key] ? 1 : 0; }
			$ttl = isset( $input['cache_ttl'] ) && is_scalar( $input['cache_ttl'] ) ? (int) $input['cache_ttl'] : 15;
			$s['cache_ttl'] = max( 1, min( 60, $ttl ) );
		}
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
		$s['language'] = isset( $input['language'] ) && is_string( $input['language'] ) && isset( WPMFT_Languages::registry()[$input['language']] ) ? $input['language'] : 'en';
		if ( isset( $input['v120_present'] ) && '1' === $input['v120_present'] ) { $s['cache_backend'] = isset( $input['cache_backend'] ) && in_array( $input['cache_backend'], array( 'auto', 'transient', 'object' ), true ) ? $input['cache_backend'] : 'auto'; }
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
		if ( isset( $input['v127_demo_present'] ) && '1' === $input['v127_demo_present'] ) { update_option( 'wpmft_demo_setup_complete', 1, false ); }
		do_action( 'wpmft_settings_saved', $s, $old );
		self::redirect( 'saved', isset( $_POST['wpmft_section'] ) && is_string( $_POST['wpmft_section'] ) ? sanitize_key( wp_unslash( $_POST['wpmft_section'] ) ) : '' );
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
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_send_json_error( array( 'message' => __( 'A POST request is required.', 'my-family-tree' ) ), 405 ); }
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => __( 'Access denied.', 'my-family-tree' ) ), 403 ); }
		check_ajax_referer( 'wpmft_protect_media', 'nonce' );
		if ( ! class_exists( 'WPMFT_Media' ) ) { wp_send_json_error( array( 'message' => __( 'The protected media service is not available.', 'my-family-tree' ) ), 500 ); }
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
		add_meta_box( 'wpmft-person', __( 'Family tree — person details', 'my-family-tree' ), array( __CLASS__, 'person_box' ), 'post', 'normal', 'high', array( '__block_editor_compatible_meta_box' => true ) );
	}

	private static function person_picker( $id, $name, $value, $label, $multiple = false ) {
		$values = self::relation_ids( $value );
		if ( ! $multiple ) { $values = array_slice( $values, 0, 1 ); }
		echo '<div class="wpmft-person-picker" data-multiple="' . ( $multiple ? '1' : '0' ) . '"><label for="' . esc_attr( $id ) . '-search">' . esc_html( $label ) . '</label><input type="hidden" name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" value="' . esc_attr( implode( ',', $values ) ) . '"><div class="wpmft-person-selection">';
		foreach ( $values as $person_id ) {
			echo '<span class="wpmft-person-chip" data-id="' . absint( $person_id ) . '"><span>' . esc_html( get_the_title( $person_id ) ) . ' <small>#' . absint( $person_id ) . '</small></span><button type="button" class="wpmft-remove-person" aria-label="' . esc_attr__( 'Remove selected person', 'my-family-tree' ) . '">×</button></span>';
		}
		echo '</div><input type="search" id="' . esc_attr( $id ) . '-search" class="wpmft-person-search" autocomplete="off" placeholder="' . esc_attr__( 'Search by name…', 'my-family-tree' ) . '" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="' . esc_attr( $id ) . '-results"><div class="wpmft-person-results" id="' . esc_attr( $id ) . '-results" role="listbox" hidden></div><span class="wpmft-search-status screen-reader-text" aria-live="polite"></span></div>';
	}

	private static function partner_labels() {
		return array(
			'' => __( 'Not specified', 'my-family-tree' ),
			'together' => __( 'Together / current partners', 'my-family-tree' ),
			'divorced' => __( 'Divorced', 'my-family-tree' ),
			'separated' => __( 'Separated', 'my-family-tree' ),
			'former' => __( 'Former partners', 'my-family-tree' ),
		);
	}

	private static function partner_row( $slot, $person = 0, $status = '', $note = '' ) {
		$prefix = 'wpmft_person[partners][' . $slot . ']';
		echo '<div class="wpmft-partner-row">';
		self::person_picker( 'wpmft-partner-' . $slot, $prefix . '[person]', $person, __( 'Partner / co-parent', 'my-family-tree' ) );
		echo '<label class="wpmft-field"><span>' . esc_html__( 'Relationship status', 'my-family-tree' ) . '</span><select name="' . esc_attr( $prefix . '[status]' ) . '">';
		foreach ( self::partner_labels() as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $status, $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></label><button type="button" class="button wpmft-remove-partner">' . esc_html__( 'Remove relationship row', 'my-family-tree' ) . '</button>';
		if ( $note ) { echo '<p class="description wpmft-partner-note">' . esc_html( $note ) . '</p>'; }
		if ( $person && current_user_can( 'edit_post', $person ) ) { echo '<a class="wpmft-partner-edit" href="' . esc_url( get_edit_post_link( $person, 'raw' ) ) . '">' . esc_html__( 'Edit the other person’s record', 'my-family-tree' ) . '</a>'; }
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
				$note = __( 'This status comes from the other person’s record. Removing this row does not change their record.', 'my-family-tree' );
			} elseif ( $status && isset( $effective[$person] ) && $status !== $effective[$person] ) {
				$note = __( 'The two records have different statuses. A former relationship takes priority; update both records to resolve the difference.', 'my-family-tree' );
			}
			$rows[$person] = array( 'status' => $status, 'note' => $note );
		}
		?>
		<section class="wpmft-partner-editor" data-next-index="<?php echo count( $rows ); ?>">
			<h3><?php esc_html_e( 'Partner relationships', 'my-family-tree' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Choose Together for a current partner and rings in the tree. Shared children alone never imply a current relationship.', 'my-family-tree' ); ?></p>
			<p class="description"><?php esc_html_e( 'Divorced, separated and former partners are hidden from each other’s own tree. Both recorded parents remain visible when their child is selected.', 'my-family-tree' ); ?></p>
			<input type="hidden" name="wpmft_person[partner_status_present]" value="1">
			<div class="wpmft-partner-rows">
				<?php $slot = 0; foreach ( $rows as $person => $row ) { self::partner_row( $slot++, $person, $row['status'], $row['note'] ); } ?>
			</div>
			<button type="button" class="button wpmft-add-partner"><?php esc_html_e( 'Add partner relationship', 'my-family-tree' ); ?></button>
			<p class="wpmft-partner-status screen-reader-text" role="status"></p>
			<p class="description"><?php esc_html_e( 'Only this person’s record is saved. To change a status stored on both records, update both people. Choosing Not specified removes this record’s status without removing a partner or parent selected above.', 'my-family-tree' ); ?></p>
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
			<label class="wpmft-check"><input type="checkbox" name="wpmft_person[enabled]" value="1" <?php checked( $is_person ); ?>><span><?php esc_html_e( 'Include this post as a person in the family tree', 'my-family-tree' ); ?></span></label>
			<p class="description"><?php esc_html_e( 'Use the post title for the person’s full name and the biography editor for their story. Parent, partner and date fields reuse your existing WP Family Tree data.', 'my-family-tree' ); ?></p>
			<?php if ( $is_classic ) : $portrait = get_post_thumbnail_id( $post->ID ); ?>
			<div class="wpmft-portrait-editor">
				<input type="hidden" id="wpmft-portrait-id" name="wpmft_person[_thumbnail_id]" value="<?php echo absint( $portrait ); ?>">
				<div id="wpmft-portrait-preview" class="wpmft-portrait-preview"><?php if ( $portrait ) { echo wp_get_attachment_image( $portrait, 'medium' ); } else { echo '<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>'; } ?></div>
				<div><h3><?php esc_html_e( 'Portrait', 'my-family-tree' ); ?></h3><p class="description"><?php esc_html_e( 'The portrait is the post’s featured image. Faces stay at the top of photo frames.', 'my-family-tree' ); ?></p>
					<div class="wpmft-portrait-actions">
						<button type="button" class="button" id="wpmft-choose-portrait"><?php esc_html_e( 'Choose a portrait', 'my-family-tree' ); ?></button>
						<button type="button" class="button wpmft-remove-portrait" id="wpmft-remove-portrait" <?php echo $portrait ? '' : 'hidden'; ?>><span class="dashicons dashicons-no-alt" aria-hidden="true"></span><?php esc_html_e( 'Remove portrait', 'my-family-tree' ); ?></button>
					</div>
					<p id="wpmft-portrait-status" class="screen-reader-text" role="status"></p>
				</div>
			</div>
			<?php endif; ?>
			<h3><?php esc_html_e( 'Personal details', 'my-family-tree' ); ?></h3>
			<div class="wpmft-person-grid">
				<label class="wpmft-field"><span><?php esc_html_e( 'Gender', 'my-family-tree' ); ?></span><select name="wpmft_person[gender]">
				<?php foreach ( array( '' => __( 'Not specified', 'my-family-tree' ), 'm' => __( 'Male', 'my-family-tree' ), 'f' => __( 'Female', 'my-family-tree' ), 'x' => __( 'Other', 'my-family-tree' ) ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( get_post_meta( $post->ID, 'gender', true ), $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
				</select></label>
				<?php foreach ( array( 'born' => __( 'Date of birth', 'my-family-tree' ), 'died' => __( 'Date of death (leave blank if living)', 'my-family-tree' ), '_wpmft_maiden_name' => __( 'Birth / maiden name', 'my-family-tree' ), '_wpmft_birth_place' => __( 'Birthplace', 'my-family-tree' ), '_wpmft_death_place' => __( 'Place of death', 'my-family-tree' ), '_wpmft_location' => __( 'Location', 'my-family-tree' ), '_wpmft_occupation' => __( 'Occupation', 'my-family-tree' ) ) as $key => $label ) : ?>
				<label class="wpmft-field"><span><?php echo esc_html( $label ); ?></span><input type="text" name="wpmft_person[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( get_post_meta( $post->ID, $key, true ) ); ?>" maxlength="250" <?php echo in_array( $key, array( 'born', 'died' ), true ) ? 'placeholder="YYYY-MM-DD"' : ''; ?>></label>
				<?php endforeach; ?>
			</div>
			<p class="description"><?php esc_html_e( 'Dates accept YYYY, YYYY-MM or YYYY-MM-DD. Older date text is retained unless you change it.', 'my-family-tree' ); ?></p>
			<div class="wpmft-person-grid">
			<?php foreach ( array( 'father' => __( 'Father / parent 1', 'my-family-tree' ), 'mother' => __( 'Mother / parent 2', 'my-family-tree' ), 'spouse' => __( 'Primary spouse / partner', 'my-family-tree' ) ) as $key => $label ) { self::person_picker( 'wpmft-' . $key, 'wpmft_person[' . $key . ']', get_post_meta( $post->ID, $key, true ), $label ); } ?>
			<?php self::person_picker( 'wpmft-spouses', 'wpmft_person[_wpmft_spouses]', get_post_meta( $post->ID, '_wpmft_spouses', true ), __( 'Other spouses / partners', 'my-family-tree' ), true ); ?>
			</div>
			<?php self::partner_editor( $post->ID ); ?>
			<section class="wpmft-contact-details"><h3><?php esc_html_e( 'Contact details', 'my-family-tree' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Only add details this person is comfortable sharing with everyone who can access the family tree.', 'my-family-tree' ); ?></p>
				<div class="wpmft-person-grid">
				<?php foreach ( array( '_wpmft_email' => array( __( 'Email address', 'my-family-tree' ), 'email', 254 ), '_wpmft_phone' => array( __( 'Telephone', 'my-family-tree' ), 'tel', 64 ), '_wpmft_mobile' => array( __( 'Mobile phone', 'my-family-tree' ), 'tel', 64 ), '_wpmft_website' => array( __( 'Website', 'my-family-tree' ), 'url', 2048 ) ) as $key => $field ) : ?>
					<label class="wpmft-field"><span><?php echo esc_html( $field[0] ); ?></span><input type="<?php echo esc_attr( $field[1] ); ?>" name="wpmft_person[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( get_post_meta( $post->ID, $key, true ) ); ?>" maxlength="<?php echo absint( $field[2] ); ?>" <?php echo 'url' === $field[1] ? 'placeholder="https://"' : ''; ?>></label>
				<?php endforeach; ?>
					<label class="wpmft-field wpmft-field-wide"><span><?php esc_html_e( 'Postal address', 'my-family-tree' ); ?></span><textarea name="wpmft_person[_wpmft_address]" rows="3" maxlength="500"><?php echo esc_textarea( get_post_meta( $post->ID, '_wpmft_address', true ) ); ?></textarea></label>
					<label class="wpmft-field"><span><?php esc_html_e( 'WhatsApp number', 'my-family-tree' ); ?></span><input type="tel" name="wpmft_person[_wpmft_whatsapp]" value="<?php echo esc_attr( WPMFT_Data::whatsapp_number( $post->ID ) ); ?>" maxlength="64" placeholder="+44…"><small><?php esc_html_e( 'Optional. Include the country code. Leave blank to hide this person’s WhatsApp button.', 'my-family-tree' ); ?></small></label>
				</div>
				<h3><?php esc_html_e( 'Social profiles', 'my-family-tree' ); ?></h3>
				<div class="wpmft-person-grid">
				<?php foreach ( array( 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'x' => 'X / Twitter', 'youtube' => 'YouTube', 'tiktok' => 'TikTok' ) as $network => $label ) : ?>
					<label class="wpmft-field"><span><?php echo esc_html( $label ); ?></span><input type="url" name="wpmft_person[_wpmft_social_<?php echo esc_attr( $network ); ?>]" value="<?php echo esc_attr( get_post_meta( $post->ID, '_wpmft_social_' . $network, true ) ); ?>" maxlength="2048" placeholder="https://"></label>
				<?php endforeach; ?>
				</div>
				<p class="description"><?php esc_html_e( 'Use a complete public profile URL beginning with https:// or http://.', 'my-family-tree' ); ?></p>
			</section>
			<h3><?php esc_html_e( 'Photo gallery', 'my-family-tree' ); ?></h3>
			<?php $gallery = WPMFT_Data::gallery_ids( $post->ID ); $gallery_details = WPMFT_Data::gallery_details( $post->ID ); ?>
			<input type="hidden" id="wpmft-gallery-ids" name="wpmft_person[_wpmft_gallery]" value="<?php echo esc_attr( implode( ',', $gallery ) ); ?>">
			<input type="hidden" name="wpmft_person[gallery_details_present]" value="1">
			<div id="wpmft-gallery-preview" class="wpmft-gallery-preview">
				<?php foreach ( $gallery as $image_id ) : $details = $gallery_details[$image_id]; ?>
				<figure data-id="<?php echo absint( $image_id ); ?>">
					<div class="wpmft-gallery-image"><?php echo wp_get_attachment_image( $image_id, 'medium', false, array( 'loading' => 'lazy', 'decoding' => 'async' ) ); ?></div>
					<button type="button" class="wpmft-remove-image" aria-label="<?php esc_attr_e( 'Remove photograph from gallery', 'my-family-tree' ); ?>">×</button>
					<figcaption class="wpmft-gallery-details">
						<label class="wpmft-field"><span><?php esc_html_e( 'When / date', 'my-family-tree' ); ?></span><input type="text" data-gallery-detail="date" name="wpmft_person[_wpmft_gallery_details][<?php echo absint( $image_id ); ?>][date]" value="<?php echo esc_attr( $details['date'] ); ?>" maxlength="100" placeholder="<?php esc_attr_e( 'For example: Summer 1980', 'my-family-tree' ); ?>"></label>
						<label class="wpmft-field"><span><?php esc_html_e( 'Photo story / description', 'my-family-tree' ); ?></span><textarea data-gallery-detail="description" name="wpmft_person[_wpmft_gallery_details][<?php echo absint( $image_id ); ?>][description]" rows="3" maxlength="2000"><?php echo esc_textarea( $details['description'] ); ?></textarea></label>
					</figcaption>
				</figure>
				<?php endforeach; ?>
			</div>
			<div class="wpmft-gallery-actions">
				<button type="button" class="button" id="wpmft-add-gallery"><?php esc_html_e( 'Add photographs', 'my-family-tree' ); ?></button>
				<button type="button" class="button" id="wpmft-edit-gallery" <?php disabled( empty( $gallery ) ); ?>><?php esc_html_e( 'Reorder photographs', 'my-family-tree' ); ?></button>
			</div>
			<p id="wpmft-gallery-status" class="wpmft-gallery-status" role="status" aria-live="polite"></p>
			<p class="description"><?php esc_html_e( 'Removing a photograph here only removes it from this gallery. It does not delete the original media file.', 'my-family-tree' ); ?></p>
			<p class="description"><?php esc_html_e( 'Add the people, place, occasion or an approximate date for each photograph. These stories belong to this person’s gallery and do not change captions on other pages.', 'my-family-tree' ); ?></p>
		</div>
		<?php
	}

	public static function people_search() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Access denied.', 'my-family-tree' ) ), 403 );
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
		if ( array_key_exists( '_wpmft_whatsapp', $input ) && is_string( $input['_wpmft_whatsapp'] ) ) {
			$raw = trim( $input['_wpmft_whatsapp'] );
			$number = wpmft_whatsapp_number( $raw );
			if ( '' === $raw || $number ) { update_post_meta( $post_id, '_wpmft_whatsapp', $number ); }
			else { self::editor_notice( __( 'The WhatsApp number was not saved. Include the country code; the previous number has been retained.', 'my-family-tree' ) ); }
		}
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
		if ( $invalid ) { self::editor_notice( __( 'A contact field was not saved. Use a valid email address and complete http:// or https:// links. The previous value has been retained.', 'my-family-tree' ) ); }
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
		// Keep already-visible legacy images eligible even when this save replaces their portrait reference.
		$existing_gallery = WPMFT_Data::gallery_ids( $post_id );
		$existing_details = WPMFT_Data::gallery_details( $post_id );
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
				self::editor_notice( __( 'A date was not saved. Use YYYY, YYYY-MM or a valid YYYY-MM-DD. The previous value has been retained.', 'my-family-tree' ) );
				continue;
			}
			update_post_meta( $post_id, $key, $value );
		}
		foreach ( array( 'father', 'mother', 'spouse' ) as $key ) {
			if ( ! array_key_exists( $key, $input ) || ! is_scalar( $input[$key] ) ) { continue; }
			$id = self::valid_relation( $input[$key], $post_id );
			if ( in_array( $key, array( 'father', 'mother' ), true ) && $id && self::parent_cycle( $post_id, $id ) ) {
				self::editor_notice( __( 'A parent relationship was not saved because it would make a person their own ancestor.', 'my-family-tree' ) );
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
		foreach ( isset( $input['_wpmft_gallery'] ) && is_string( $input['_wpmft_gallery'] ) ? explode( ',', $input['_wpmft_gallery'] ) : array() as $id ) {
			$id = absint( $id );
			// A new association may trigger encryption; only select files this editor owns or can edit.
			if ( $id && wp_attachment_is_image( $id ) && ( current_user_can( 'edit_post', $id ) || in_array( $id, $existing_gallery, true ) ) ) { $gallery[] = $id; }
		}
		if ( isset( $input['_wpmft_gallery'] ) && is_string( $input['_wpmft_gallery'] ) ) { update_post_meta( $post_id, '_wpmft_gallery', array_values( array_unique( array_slice( $gallery, 0, 100 ) ) ) ); }
		if ( isset( $input['gallery_details_present'] ) && '1' === $input['gallery_details_present'] ) {
			$details = array();
			$submitted = isset( $input['_wpmft_gallery_details'] ) && is_array( $input['_wpmft_gallery_details'] ) ? $input['_wpmft_gallery_details'] : array();
			foreach ( WPMFT_Data::gallery_ids( $post_id ) as $photo ) {
				$row = isset( $submitted[$photo] ) && is_array( $submitted[$photo] ) ? $submitted[$photo] : array();
				$previous = $existing_details[$photo] ?? array( 'description' => wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $photo, 'raw' ) ), 'date' => '' );
				$details[$photo] = array(
					'description' => isset( $row['description'] ) && is_string( $row['description'] ) ? self::limit_text( sanitize_textarea_field( $row['description'] ), 2000 ) : $previous['description'],
					'date' => isset( $row['date'] ) && is_string( $row['date'] ) ? self::limit_text( sanitize_text_field( $row['date'] ), 100 ) : $previous['date'],
				);
			}
			update_post_meta( $post_id, '_wpmft_gallery_details', $details );
		}
		do_action( 'wpmft_person_saved', $post_id );
	}

	private static function help() {
		?>
		<ol class="wpmft-help-steps">
			<li><strong><?php esc_html_e( 'Choose your family category.', 'my-family-tree' ); ?></strong> <?php esc_html_e( 'An existing WP Family Tree category is detected during setup. Keep it selected to reuse your people and relationships.', 'my-family-tree' ); ?></li>
			<li><strong><?php esc_html_e( 'Create or edit the tree page.', 'my-family-tree' ); ?></strong> <?php esc_html_e( 'Insert the Family Tree block, or a Shortcode block containing [wp_my_family_tree]. Choose a starting person in General.', 'my-family-tree' ); ?></li>
			<li><strong><?php esc_html_e( 'Set access and contact details.', 'my-family-tree' ); ?></strong> <?php esc_html_e( 'Private access starts locked until you set a password. Set the family administrator email and optionally enable hCaptcha.', 'my-family-tree' ); ?></li>
			<li><strong><?php esc_html_e( 'Add people as WordPress posts.', 'my-family-tree' ); ?></strong> <?php esc_html_e( 'Choose Add a person, enter the full name as the title, then add a portrait, dates, parents, partners, contact details and photographs in the form at the top. Write the biography below. Children and siblings come from parent links.', 'my-family-tree' ); ?></li>
			<li><strong><?php esc_html_e( 'Check privacy while signed out.', 'my-family-tree' ); ?></strong> <?php esc_html_e( 'Open a private window. Test the tree, a person’s old URL, a feed and an old direct photograph URL. Clear previous caches. The original plugin should be inactive.', 'my-family-tree' ); ?></li>
		</ol>
		<h3><?php esc_html_e( 'Shortcodes', 'my-family-tree' ); ?></h3>
		<p><code>[wp_my_family_tree]</code><br><code>[wp_my_family_tree root="123"]</code></p>
		<p><?php esc_html_e( 'The root is a WordPress post ID. Legacy [family-tree] and [family-members] shortcodes are supported for migration.', 'my-family-tree' ); ?></p>
		<h3><?php esc_html_e( 'Languages', 'my-family-tree' ); ?></h3>
		<p><?php esc_html_e( 'Fourteen front-end language presets are included. Add more languages and translation overrides in the Languages tab. Names and biographies are never automatically translated. With optional Polylang, configure your content languages and link translated pages and people.', 'my-family-tree' ); ?></p>
		<p><?php esc_html_e( 'In the default person editor, plugin labels follow the selected Polylang language without reloading or discarding unsaved edits. Other plugin screens start in your WordPress profile language when available and include an extensible interface language switch. With the optional block editor, Polylang may save and reload when its content language changes.', 'my-family-tree' ); ?></p>
		<h3><?php esc_html_e( 'Person editor', 'my-family-tree' ); ?></h3>
		<p><?php esc_html_e( 'Family people use a details-first classic editor by default. Enable the block editor in General if you prefer it. This setting does not change other posts, pages or saved biography content.', 'my-family-tree' ); ?></p>
		<h3><?php esc_html_e( 'Restore backups safely', 'my-family-tree' ); ?></h3>
		<p><?php esc_html_e( 'Restore a matching UpdraftPlus set on staging first. Preserve every filename exactly, including its date, site name, 12-character identifier and -db.gz / -plugins.zip / -themes.zip / -uploads.zip suffix. A standalone plugin ZIP is installed using Plugins → Add New → Upload Plugin, not the Updraft restore screen.', 'my-family-tree' ); ?></p>
		<h3><?php esc_html_e( 'Developer', 'my-family-tree' ); ?></h3>
		<p>Raivis Kalnins · <a href="https://digitalpulse.click/" target="_blank" rel="noopener noreferrer">DigitalPulse</a></p>
		<?php
	}
}
