<?php
/**
 * Optional, additive demonstration content and an isolated shared demo editor.
 *
 * Nothing is imported or made public on activation. The ordinary family password
 * and settings are never replaced by the demonstration credentials.
 *
 * @package WPMyFamilyTree
 */
defined( 'ABSPATH' ) || exit;

final class WPMFT_Demo {

	const MANIFEST_OPTION = 'wpmft_demo_manifest';
	const STATE_OPTION = 'wpmft_demo_state';
	const BASELINE_OPTION = 'wpmft_demo_baseline';
	const RESET_HOOK = 'wpmft_demo_daily_reset';
	const WRITE_LOCK = 'wpmft_demo_write_lock';
	const ROLE = 'wpmft_demo_editor';
	const CAP = 'wpmft_demo_edit';
	const MAX_VISITOR_ITEMS = 50;
	private static $quarantining = false;
	private static $request_scope = false;
	private static $resetting = false;
	private static $mutating_post = 0;
	private static $reset_failed = false;

	/** Demo writes use an explicit allowlist; identities, relationships and media ownership stay protected. */
	private static function editor_meta_keys() {
		return array( 'born', 'died', 'gender', '_wpmft_name_day', '_wpmft_maiden_name', '_wpmft_birth_place', '_wpmft_death_place', '_wpmft_location', '_wpmft_occupation', '_wpmft_email', '_wpmft_phone', '_wpmft_mobile', '_wpmft_whatsapp', '_wpmft_address', '_wpmft_website', '_wpmft_social_facebook', '_wpmft_social_instagram', '_wpmft_social_linkedin', '_wpmft_social_x', '_wpmft_social_youtube', '_wpmft_social_tiktok', '_wpmft_gallery_details' );
	}

	private static function valid_gallery_details( $value ) {
		if ( ! is_array( $value ) || count( $value ) > 100 ) { return false; }
		foreach ( $value as $id => $detail ) {
			if ( ! ctype_digit( (string) $id ) || (int) $id < 1 || ! self::allows_media( (int) $id ) || ! is_array( $detail ) || array_diff( array_keys( $detail ), array( 'description', 'date' ) ) || ! isset( $detail['description'], $detail['date'] ) || ! is_string( $detail['description'] ) || ! is_string( $detail['date'] ) || strlen( $detail['description'] ) > 8000 || strlen( $detail['date'] ) > 400 ) { return false; }
		}
		return true;
	}

	/** Once a response uses demo credentials it stays constrained, even if mode changes mid-request. */
	public static function set_request_scope( $enabled = true ) { if ( $enabled ) { self::$request_scope = true; } }
	public static function scoped_request() { return self::$request_scope; }

	public static function init() {
		add_action( 'admin_post_wpmft_demo_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_wpmft_demo_refresh', array( __CLASS__, 'handle_refresh' ) );
		add_action( 'admin_post_wpmft_demo_enable', array( __CLASS__, 'handle_enable' ) );
		add_action( 'admin_post_wpmft_demo_disable', array( __CLASS__, 'handle_disable' ) );
		add_action( 'admin_post_wpmft_demo_reset', array( __CLASS__, 'handle_reset' ) );
		add_action( 'admin_post_wpmft_demo_baseline', array( __CLASS__, 'handle_baseline' ) );
		add_action( 'admin_post_wpmft_demo_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_wpmft_demo_create', array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_wpmft_demo_remove', array( __CLASS__, 'handle_remove' ) );
		add_action( self::RESET_HOOK, array( __CLASS__, 'scheduled_reset' ) );
		add_action( 'init', array( __CLASS__, 'prepare_reset' ), 30 );
		add_action( 'init', array( __CLASS__, 'maybe_upgrade_demo_gallery' ), 35 );
		add_action( 'admin_init', array( __CLASS__, 'guard_admin' ), 0 );
		add_action( 'admin_menu', array( __CLASS__, 'editor_menu' ), 999 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_capability' ), 99, 4 );
		add_filter( 'get_edit_post_link', array( __CLASS__, 'edit_link' ), 99, 3 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 99, 3 );
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'authenticate' ), 99, 2 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'guard_rest' ), 1, 3 );
		add_filter( 'xmlrpc_enabled', array( __CLASS__, 'guard_xmlrpc' ), 99 );
		add_filter( 'posts_where', array( __CLASS__, 'restrict_queries' ), 99, 2 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'application_passwords' ), 99, 2 );
		add_filter( 'allow_password_reset', array( __CLASS__, 'allow_password_reset' ), 99, 2 );
		add_filter( 'wp_post_revisions_to_keep', array( __CLASS__, 'revision_count' ), PHP_INT_MAX, 2 );
		add_filter( 'wp_page_revisions_to_keep', array( __CLASS__, 'revision_count' ), PHP_INT_MAX, 2 );
	}

	/** Repair older demo galleries automatically after an update, without remote downloads. */
	public static function maybe_upgrade_demo_gallery() {
		$manifest = self::manifest();
		if ( empty( $manifest['token'] ) || empty( $manifest['items'] ) || (int) get_option( 'wpmft_demo_sample_version', 0 ) >= 5 ) { return; }
		$gallery_keys = array( 'george', 'lorraine', 'jennifer', 'amelie' );
		$gallery = array();
		foreach ( $gallery_keys as $key ) {
			$id = absint( $manifest['items']['portrait-' . $key] ?? 0 );
			if ( ! $id || ! self::is_demo_post( $id ) || 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) ) { return; }
			$gallery[] = $id;
		}
		$stories = array(
			array( 'date' => 'c. 1840', 'description' => __( 'An archival public-domain portrait used to demonstrate how a family memory can be captioned and dated.', 'my-family-tree' ) ),
			array( 'date' => 'c. 1845', 'description' => __( 'A second archival portrait used illustratively in this fictional family archive.', 'my-family-tree' ) ),
			array( 'date' => 'c. 1870', 'description' => __( 'A public-domain portrait used to demonstrate an individual memory inside a person gallery.', 'my-family-tree' ) ),
			array( 'date' => 'c. 1890', 'description' => __( 'A fourth archival portrait used to demonstrate a larger family-memory gallery.', 'my-family-tree' ) ),
		);
		$details = array();
		foreach ( $gallery as $index => $photo_id ) { $details[$photo_id] = $stories[$index]; }
		foreach ( self::people() as $key => $person ) {
			$id = absint( $manifest['items'][$key] ?? 0 );
			if ( ! $id || ! self::is_demo_post( $id ) ) { continue; }
			update_post_meta( $id, '_wpmft_gallery', $gallery );
			update_post_meta( $id, '_wpmft_gallery_details', $details );
			clean_post_cache( $id );
		}
		foreach ( array( 'album-1', 'album-2', 'album-3', 'album-4' ) as $legacy_key ) {
			$legacy_id = absint( $manifest['items'][$legacy_key] ?? 0 );
			if ( $legacy_id && 'attachment' === get_post_type( $legacy_id ) && self::is_demo_post( $legacy_id ) ) { wp_delete_attachment( $legacy_id, true ); }
			unset( $manifest['items'][$legacy_key] );
		}
		update_option( self::MANIFEST_OPTION, $manifest, false );
		$baseline = self::make_baseline( true );
		if ( ! is_wp_error( $baseline ) && self::valid_baseline( $baseline ) ) { update_option( self::BASELINE_OPTION, $baseline, false ); }
		update_option( 'wpmft_demo_sample_version', 5, false );
		WPMFT_Data::reset_cache();
		if ( class_exists( 'WPMFT_Cache' ) ) { WPMFT_Cache::purge(); }
	}

	public static function manifest() {
		$value = get_option( self::MANIFEST_OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	public static function state() {
		$value = get_option( self::STATE_OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/** Fresh lifecycle reads prevent a reset from reviving a concurrently disabled demo. */
	private static function fresh_option( $name, $fallback = array() ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A lifecycle/lock decision must bypass the request option cache.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s LIMIT 1", $name ) );
		if ( $wpdb->last_error ) { return false; }
		return null === $value ? $fallback : maybe_unserialize( $value );
	}

	/** Conditional state writes never overwrite an intervening disable or quarantine. */
	private static function replace_state( $before, $after ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap for this plugin's private state only.
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s", maybe_serialize( $after ), self::STATE_OPTION, maybe_serialize( $before ) ) );
		wp_cache_delete( self::STATE_OPTION, 'options' );
		return 1 === $changed;
	}

	/** Visitor reservations and deletion journals cannot overwrite a concurrent manifest change. */
	private static function replace_manifest( $before, $after ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap for the exclusively owned demo manifest.
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s", maybe_serialize( $after ), self::MANIFEST_OPTION, maybe_serialize( $before ) ) );
		wp_cache_delete( self::MANIFEST_OPTION, 'options' );
		return 1 === $changed;
	}

	/** A unique option serializes resets, baseline capture and public editor saves. */
	private static function acquire_write_lock() {
		$old = self::fresh_option( self::WRITE_LOCK, '' );
		if ( false === $old ) { return false; }
		if ( is_string( $old ) && $old && (int) substr( $old, strrpos( $old, ':' ) + 1 ) < time() ) { self::release_write_lock( $old ); }
		$lock = wp_generate_uuid4() . ':' . ( time() + 10 * MINUTE_IN_SECONDS );
		if ( ! add_option( self::WRITE_LOCK, $lock, '', false ) ) { return false; }
		return $lock;
	}

	private static function release_write_lock( $lock ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Release only the exact lock owned by this operation, never a successor's lock.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", self::WRITE_LOCK, $lock ) );
		wp_cache_delete( self::WRITE_LOCK, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	private static function owns_write_lock( $lock ) { return is_string( $lock ) && hash_equals( $lock, (string) self::fresh_option( self::WRITE_LOCK, '' ) ); }

	private static function reset_error() { return new WP_Error( 'demo_reset', __( 'The demo is resetting or its reset could not finish. Please try again shortly.', 'my-family-tree' ) ); }

	private static function revision_ids( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A fresh list is needed to remove edits from this demo cycle only.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='revision' AND post_parent=%d", $id ) );
		return $wpdb->last_error ? false : array_map( 'absint', $ids );
	}

	/** Only fields exposed by the constrained editor are stored or restored. */
	private static function make_baseline( $original = false ) {
		$manifest = self::fresh_option( self::MANIFEST_OPTION );
		if ( ! is_array( $manifest ) || empty( $manifest['token'] ) || empty( $manifest['items']['marty'] ) ) { return self::reset_error(); }
		$people = self::people();
		$pages = self::pages( $manifest );
		$baseline = array( 'version' => 3, 'token' => $manifest['token'] ?? '', 'created' => time(), 'source' => $original ? 'bundled' : 'administrator', 'items' => array() );
		global $wpdb;
		foreach ( array_merge( $people, $pages ) as $key => $seed ) {
			$id = absint( $manifest['items'][$key] ?? 0 );
			$type = isset( $people[$key] ) ? 'post' : 'page';
			if ( ! $id || ! self::validate_owned_ids( array( $id => $type ) ) ) { return self::reset_error(); }
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Capture the current authoritative demo fields, not a stale object.
			$post = $wpdb->get_row( $wpdb->prepare( "SELECT post_title,post_content,post_status FROM {$wpdb->posts} WHERE ID=%d AND post_type=%s", $id, $type ), ARRAY_A );
			$revisions = $original ? array() : self::revision_ids( $id );
			if ( ! is_array( $post ) || false === $revisions ) { return self::reset_error(); }
			$row = array( 'id' => $id, 'type' => $type, 'title' => $post['post_title'], 'story' => $post['post_content'], 'status' => $original ? 'publish' : $post['post_status'], 'meta' => array(), 'revisions' => $revisions );
			if ( $original ) { $row['title'] = 'post' === $type ? $seed['name'] : $seed['title']; $row['story'] = 'post' === $type ? self::person_content( $seed ) : $seed['content']; }
			if ( 'post' === $type ) {
				wp_cache_delete( $id, 'post_meta' );
				$defaults = array( 'born' => $seed['born'], 'gender' => $seed['gender'], '_wpmft_location' => $seed['location'], '_wpmft_occupation' => $seed['occupation'], '_wpmft_website' => 'https://example.com/', '_wpmft_social_instagram' => 'https://www.instagram.com/', '_wpmft_social_youtube' => 'https://www.youtube.com/' );
				foreach ( self::editor_meta_keys() as $meta ) {
					$value = metadata_exists( 'post', $id, $meta ) ? get_post_meta( $id, $meta, true ) : null;
					$row['meta'][$meta] = $original ? ( $defaults[$meta] ?? null ) : ( null === $value || '_wpmft_gallery_details' === $meta ? $value : ( is_scalar( $value ) ? (string) $value : null ) );
				}
			}
			$baseline['items'][$key] = $row;
		}
		return $baseline;
	}

	private static function valid_baseline( $baseline ) {
		$manifest = self::fresh_option( self::MANIFEST_OPTION );
		if ( ! is_array( $baseline ) || ! in_array( $baseline['version'] ?? 0, array( 1, 2, 3 ), true ) || empty( $baseline['token'] ) || empty( $manifest['token'] ) || ! hash_equals( (string) $manifest['token'], (string) $baseline['token'] ) ) { return false; }
		$types = array_fill_keys( array_keys( self::people() ), 'post' ) + array( 'tree-page' => 'page', 'about-page' => 'page' );
		if ( ! isset( $baseline['items'] ) || ! is_array( $baseline['items'] ) || array_diff_key( $types, $baseline['items'] ) || array_diff_key( $baseline['items'], $types ) ) { return false; }
		$expected = array();
		foreach ( $types as $key => $type ) {
			$row = $baseline['items'][$key];
			if ( ! is_array( $row ) || ! isset( $row['id'], $row['type'], $row['title'], $row['story'], $row['meta'], $row['revisions'] ) || absint( $row['id'] ) !== absint( $manifest['items'][$key] ?? 0 ) || $row['type'] !== $type || ! is_string( $row['title'] ) || ! is_string( $row['story'] ) || ! is_array( $row['meta'] ) || ! is_array( $row['revisions'] ) || isset( $expected[(int) $row['id']] ) ) { return false; }
			if ( $baseline['version'] >= 2 && ! in_array( $row['status'] ?? '', array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ), true ) ) { return false; }
			$allowed = 'post' === $type ? ( 3 === $baseline['version'] ? self::editor_meta_keys() : array( 'born', '_wpmft_location', '_wpmft_occupation' ) ) : array();
			if ( array_diff( array_keys( $row['meta'] ), $allowed ) || array_diff( $allowed, array_keys( $row['meta'] ) ) ) { return false; }
			foreach ( $row['meta'] as $meta => $value ) { if ( null !== $value && ( '_wpmft_gallery_details' === $meta ? ! self::valid_gallery_details( $value ) : ! is_string( $value ) ) ) { return false; } }
			$expected[(int) $row['id']] = $type;
		}
		return self::validate_owned_ids( $expected );
	}

	/** Upgrade old baselines before removal without recapturing visitor-edited stories or metadata. */
	private static function upgrade_baseline( $baseline, $lock, $state ) {
		if ( ! self::valid_baseline( $baseline ) ) { return self::reset_error(); }
		if ( 3 === $baseline['version'] ) { return $baseline; }
		$updated = $baseline;
		$updated['version'] = 3;
		global $wpdb;
		foreach ( $updated['items'] as &$row ) {
			if ( ! self::owns_write_lock( $lock ) || ! self::same_demo_session( $state, self::fresh_option( self::STATE_OPTION ) ) || ! self::validate_owned_ids( array( $row['id'] => $row['type'] ) ) ) { return self::reset_error(); }
			if ( 1 === $baseline['version'] ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Version 1 could not change publication status.
				$status = $wpdb->get_var( $wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID=%d AND post_type=%s", $row['id'], $row['type'] ) );
				if ( $wpdb->last_error || ! in_array( $status, array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ), true ) ) { return self::reset_error(); }
				$row['status'] = $status;
			}
			if ( 'post' === $row['type'] ) {
				wp_cache_delete( $row['id'], 'post_meta' );
				// Previous demo versions could not edit these fields. Never recapture the already-editable fields.
				foreach ( array_diff( self::editor_meta_keys(), array( 'born', '_wpmft_location', '_wpmft_occupation' ) ) as $meta ) {
					$value = metadata_exists( 'post', $row['id'], $meta ) ? get_post_meta( $row['id'], $meta, true ) : null;
					$row['meta'][$meta] = null === $value || '_wpmft_gallery_details' === $meta ? $value : ( is_scalar( $value ) ? (string) $value : null );
				}
			}
		}
		unset( $row );
		if ( ! self::valid_baseline( $updated ) || ! self::owns_write_lock( $lock ) || ! self::same_demo_session( $state, self::fresh_option( self::STATE_OPTION ) ) || self::fresh_option( self::BASELINE_OPTION ) !== $baseline ) { return self::reset_error(); }
		update_option( self::BASELINE_OPTION, $updated, false );
		return self::fresh_option( self::BASELINE_OPTION ) === $updated ? $updated : self::reset_error();
	}

	/** A bounded, server-created ledger is separate from the immutable imported baseline. */
	private static function valid_visitor_ledger( $manifest ) {
		if ( ! is_array( $manifest ) || empty( $manifest['token'] ) || ! is_string( $manifest['token'] ) || ! isset( $manifest['items'] ) || ! is_array( $manifest['items'] ) ) { return false; }
		$visitors = $manifest['visitors'] ?? array();
		if ( ! is_array( $visitors ) || count( $visitors ) > self::MAX_VISITOR_ITEMS ) { return false; }
		$counts = array_count_values( array_map( 'absint', array_values( $manifest['items'] ) ) );
		foreach ( $visitors as $key => $row ) {
			if ( ! is_string( $key ) || ! preg_match( '/^visitor-[a-f0-9-]{36}$/D', $key ) || ! is_array( $row ) || ! isset( $row['id'], $row['type'], $row['cycle'], $row['phase'] ) || ! is_int( $row['id'] ) || $row['id'] < 0 || ! in_array( $row['type'], array( 'post', 'page' ), true ) || ! is_string( $row['cycle'] ) || '' === $row['cycle'] || strlen( $row['cycle'] ) > 128 || ! in_array( $row['phase'], array( 'creating', 'active', 'deleting' ), true ) ) { return false; }
			if ( 'creating' === $row['phase'] ) {
				if ( 0 !== $row['id'] || isset( $manifest['items'][$key] ) ) { return false; }
			} elseif ( $row['id'] < 1 || absint( $manifest['items'][$key] ?? 0 ) !== $row['id'] || 1 !== ( $counts[$row['id']] ?? 0 ) ) { return false; }
		}
		foreach ( $manifest['items'] as $key => $id ) {
			if ( 0 === strpos( (string) $key, 'visitor-' ) && ! isset( $visitors[$key] ) ) { return false; }
		}
		return true;
	}

	/** Verify all three immutable creation markers without using a cached metadata value. */
	private static function visitor_markers_match( $manifest, $key, $entry, $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletion/recovery requires fresh, exact ownership markers.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key IN ('_wpmft_demo','_wpmft_demo_key','_wpmft_demo_created_cycle')", $id ), ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $rows ) || 3 !== count( $rows ) ) { return false; }
		$expected = array( '_wpmft_demo' => $manifest['token'], '_wpmft_demo_key' => $key, '_wpmft_demo_created_cycle' => $entry['cycle'] );
		foreach ( $rows as $row ) {
			if ( ! isset( $expected[$row['meta_key']] ) || ! hash_equals( (string) $expected[$row['meta_key']], (string) $row['meta_value'] ) ) { return false; }
			unset( $expected[$row['meta_key']] );
		}
		return ! $expected;
	}

	/** WordPress deletion must not reparent an unrelated page/media item or alter site assignments. */
	private static function visitor_deletion_safe( $id ) {
		if ( in_array( (int) $id, array( absint( get_option( 'page_on_front' ) ), absint( get_option( 'page_for_posts' ) ) ), true ) || in_array( (int) $id, array_map( 'absint', (array) get_option( 'sticky_posts', array() ) ), true ) ) { return false; }
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A visitor cannot attach children; unexpected children require administrator review.
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent=%d AND post_type<>'revision'", $id ) );
		return ! $wpdb->last_error && '0' === (string) $count;
	}

	/** Retry-safe cleanup never guesses an ID from a title or removes an unregistered record. */
	private static function cleanup_visitor_items( $state, $lock ) {
		$manifest = self::fresh_option( self::MANIFEST_OPTION );
		if ( ! self::valid_visitor_ledger( $manifest ) ) { return false; }
		global $wpdb;
		foreach ( array_keys( $manifest['visitors'] ?? array() ) as $key ) {
			if ( ! self::same_demo_session( $state, self::fresh_option( self::STATE_OPTION ) ) || ! self::owns_write_lock( $lock ) || self::real_family_count() ) { return false; }
			$manifest = self::fresh_option( self::MANIFEST_OPTION );
			if ( ! self::valid_visitor_ledger( $manifest ) || ! isset( $manifest['visitors'][$key] ) ) { return false; }
			$entry = $manifest['visitors'][$key];
			if ( 'creating' === $entry['phase'] ) {
				// Only a previously reserved, unguessable key can recover a crash before ID registration.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Resolve an interrupted auto-draft creation from its prior manifest reservation.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT p.ID,p.post_type,p.post_status FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} k ON k.post_id=p.ID WHERE k.meta_key='_wpmft_demo_key' AND k.meta_value=%s", $key ), ARRAY_A );
				if ( $wpdb->last_error || ! is_array( $rows ) || count( $rows ) > 1 ) { return false; }
				if ( ! $rows ) {
					$updated = $manifest; unset( $updated['visitors'][$key] );
					if ( ! self::owns_write_lock( $lock ) || ! self::same_demo_session( $state, self::fresh_option( self::STATE_OPTION ) ) || ! self::replace_manifest( $manifest, $updated ) ) { return false; }
					continue;
				}
				$id = absint( $rows[0]['ID'] );
				if ( $rows[0]['post_type'] !== $entry['type'] || 'auto-draft' !== $rows[0]['post_status'] || ! self::visitor_markers_match( $manifest, $key, $entry, $id ) || in_array( $id, array_map( 'absint', array_values( $manifest['items'] ) ), true ) ) { return false; }
				$updated = $manifest;
				$updated['items'][$key] = $id;
				$updated['visitors'][$key]['id'] = $id;
				$updated['visitors'][$key]['phase'] = 'active';
				if ( ! self::owns_write_lock( $lock ) || ! self::same_demo_session( $state, self::fresh_option( self::STATE_OPTION ) ) || ! self::replace_manifest( $manifest, $updated ) ) { return false; }
				$manifest = $updated; $entry = $updated['visitors'][$key];
			}
			$id = $entry['id'];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Distinguish a completed journaled deletion from a missing/repurposed record.
			$post = $wpdb->get_row( $wpdb->prepare( "SELECT ID,post_type FROM {$wpdb->posts} WHERE ID=%d", $id ), ARRAY_A );
			if ( $wpdb->last_error ) { return false; }
			if ( null === $post ) {
				if ( 'deleting' !== $entry['phase'] ) { return false; }
			} else {
				if ( $post['post_type'] !== $entry['type'] || ! self::validate_owned_ids( array( $id => $entry['type'] ) ) || ! self::visitor_markers_match( $manifest, $key, $entry, $id ) || ! self::visitor_deletion_safe( $id ) ) { return false; }
				if ( 'deleting' !== $entry['phase'] ) {
					$updated = $manifest; $updated['visitors'][$key]['phase'] = 'deleting';
					if ( ! self::owns_write_lock( $lock ) || ! self::same_demo_session( $state, self::fresh_option( self::STATE_OPTION ) ) || ! self::replace_manifest( $manifest, $updated ) ) { return false; }
					$manifest = $updated;
				}
				if ( ! self::owns_write_lock( $lock ) || ! self::same_demo_session( $state, self::fresh_option( self::STATE_OPTION ) ) || ! self::validate_owned_ids( array( $id => $entry['type'] ) ) || ! self::visitor_markers_match( $manifest, $key, $entry, $id ) || ! self::visitor_deletion_safe( $id ) ) { return false; }
				if ( ! wp_delete_post( $id, true ) ) { return false; }
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Do not prune ownership until deletion is confirmed.
				$remaining = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID=%d", $id ) );
				if ( $wpdb->last_error || null !== $remaining ) { return false; }
			}
			$updated = $manifest;
			unset( $updated['items'][$key], $updated['visitors'][$key] );
			if ( ! self::owns_write_lock( $lock ) || ! self::same_demo_session( $state, self::fresh_option( self::STATE_OPTION ) ) || ! self::replace_manifest( $manifest, $updated ) ) { return false; }
		}
		return true;
	}

	/** Replacing a known baseline is an explicit administrator action while sharing is off. */
	public static function capture_baseline() {
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'permission', __( 'Access denied.', 'my-family-tree' ) ); }
		$lock = self::acquire_write_lock();
		if ( ! $lock ) { return self::reset_error(); }
		try {
			$state = self::fresh_option( self::STATE_OPTION );
			if ( false === $state ) { return self::reset_error(); }
			if ( ! empty( $state['enabled'] ) ) { return new WP_Error( 'demo_enabled', __( 'Disable demo sharing before saving a new reset baseline.', 'my-family-tree' ) ); }
			$baseline = self::make_baseline();
			if ( is_wp_error( $baseline ) || ! self::valid_baseline( $baseline ) || ! self::owns_write_lock( $lock ) ) { return self::reset_error(); }
			update_option( self::BASELINE_OPTION, $baseline, false );
			return self::fresh_option( self::BASELINE_OPTION ) === $baseline ? true : self::reset_error();
		} finally { self::release_write_lock( $lock ); }
	}

	private static function same_demo_session( $before, $after ) {
		return is_array( $after ) && ! empty( $after['enabled'] ) && empty( $after['quarantined'] ) && ! empty( $before['generation'] ) && isset( $after['generation'] ) && hash_equals( (string) $before['generation'], (string) $after['generation'] );
	}

	private static function schedule_reset( $timestamp ) {
		if ( (int) wp_next_scheduled( self::RESET_HOOK ) === (int) $timestamp ) { return; }
		wp_clear_scheduled_hook( self::RESET_HOOK );
		wp_schedule_single_event( max( time() + 1, (int) $timestamp ), self::RESET_HOOK );
	}

	public static function prepare_reset() {
		$state = self::state();
		if ( ! empty( $state['enabled'] ) && empty( $state['quarantined'] ) ) { self::active(); }
	}

	public static function scheduled_reset() { self::reset_if_due(); }

	/** A late WP-Cron run cannot serve expired visitor edits: access also checks this deadline. */
	private static function reset_if_due( $force = false ) {
		if ( self::$reset_failed ) { return self::reset_error(); }
		$state = self::fresh_option( self::STATE_OPTION );
		if ( false === $state ) { return self::reset_error(); }
		if ( empty( $state['enabled'] ) || ! empty( $state['quarantined'] ) ) { wp_clear_scheduled_hook( self::RESET_HOOK ); return self::reset_error(); }
		$baseline = self::fresh_option( self::BASELINE_OPTION );
		if ( false === $baseline ) { return self::reset_error(); }
		$due = empty( $state['next_reset_at'] ) || empty( $state['reset_cycle'] ) || ! empty( $state['reset_pending'] ) || (int) $state['next_reset_at'] <= time();
		if ( ! $force && is_array( $baseline ) && 3 === ( $baseline['version'] ?? 0 ) && ! $due ) { self::schedule_reset( $state['next_reset_at'] ); return true; }
		$lock = self::acquire_write_lock();
		if ( ! $lock ) { return self::reset_error(); }
		self::$resetting = true;
		try {
			$current = self::fresh_option( self::STATE_OPTION );
			if ( ! self::same_demo_session( $state, $current ) || self::real_family_count() ) { if ( ! empty( $current['enabled'] ) && self::real_family_count() ) { self::quarantine(); } return self::reset_error(); }
			$state = $current;
			$baseline = self::fresh_option( self::BASELINE_OPTION );
			if ( false === $baseline ) { return self::reset_error(); }
			if ( ! $baseline ) {
				// Upgrading an already-public 1.2.0 demo must not treat visitor edits as its original state.
				self::purge_orphan_demo_media( $manifest );
			$baseline = self::make_baseline( true );
				if ( is_wp_error( $baseline ) ) { return self::reset_error(); }
				if ( ! self::owns_write_lock( $lock ) || ! self::same_demo_session( $state, self::fresh_option( self::STATE_OPTION ) ) ) { return self::reset_error(); }
				update_option( self::BASELINE_OPTION, $baseline, false );
				if ( self::fresh_option( self::BASELINE_OPTION ) !== $baseline ) { return self::reset_error(); }
			}
			$baseline = self::upgrade_baseline( $baseline, $lock, $state );
			if ( is_wp_error( $baseline ) || ! self::valid_baseline( $baseline ) ) { self::quarantine(); return self::reset_error(); }
			if ( empty( $state['next_reset_at'] ) || empty( $state['reset_cycle'] ) ) {
				$updated = $state;
				$updated['next_reset_at'] = max( 1, (int) ( $state['enabled_at'] ?? time() ) ) + DAY_IN_SECONDS;
				$updated['reset_cycle'] = wp_generate_uuid4();
				if ( ! self::replace_state( $state, $updated ) ) { return self::reset_error(); }
				$state = $updated;
			}
			if ( ! $force && empty( $state['reset_pending'] ) && (int) $state['next_reset_at'] > time() ) { self::schedule_reset( $state['next_reset_at'] ); return true; }
			$pending = $state;
			$pending['reset_pending'] = 1;
			$pending['next_reset_at'] = time();
			$pending['reset_cycle'] = wp_generate_uuid4();
			if ( ! self::replace_state( $state, $pending ) ) { return self::reset_error(); }
			$state = $pending;
			if ( ! self::cleanup_visitor_items( $state, $lock ) ) { self::$reset_failed = true; return self::reset_error(); }
			foreach ( $baseline['items'] as $row ) {
				$current = self::fresh_option( self::STATE_OPTION );
				if ( ! self::same_demo_session( $state, $current ) || ! self::owns_write_lock( $lock ) || self::real_family_count() || ! self::validate_owned_ids( array( $row['id'] => $row['type'] ) ) ) { self::$reset_failed = true; return self::reset_error(); }
				self::$mutating_post = (int) $row['id'];
				$result = wp_update_post( wp_slash( array( 'ID' => $row['id'], 'post_title' => $row['title'], 'post_content' => $row['story'], 'post_status' => $row['status'] ) ), true );
				self::$mutating_post = 0;
				if ( is_wp_error( $result ) || ! $result ) { self::$reset_failed = true; return self::reset_error(); }
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify authoritative stored fields before declaring a reset complete.
				$stored = $wpdb->get_row( $wpdb->prepare( "SELECT post_title,post_content,post_status FROM {$wpdb->posts} WHERE ID=%d", $row['id'] ), ARRAY_A );
				if ( ! is_array( $stored ) || $stored['post_title'] !== $row['title'] || $stored['post_content'] !== $row['story'] || $stored['post_status'] !== $row['status'] ) { self::$reset_failed = true; return self::reset_error(); }
				if ( ! self::same_demo_session( $state, self::fresh_option( self::STATE_OPTION ) ) || ! self::owns_write_lock( $lock ) || ! self::validate_owned_ids( array( $row['id'] => $row['type'] ) ) ) { self::$reset_failed = true; return self::reset_error(); }
				delete_post_meta( $row['id'], '_wpmft_demo_removed_cycle' );
				wp_cache_delete( $row['id'], 'post_meta' );
				if ( metadata_exists( 'post', $row['id'], '_wpmft_demo_removed_cycle' ) ) { self::$reset_failed = true; return self::reset_error(); }
				foreach ( $row['meta'] as $key => $value ) {
					if ( ! self::same_demo_session( $state, self::fresh_option( self::STATE_OPTION ) ) || ! self::owns_write_lock( $lock ) || ! self::validate_owned_ids( array( $row['id'] => $row['type'] ) ) ) { self::$reset_failed = true; return self::reset_error(); }
					if ( null === $value ) { delete_post_meta( $row['id'], $key ); } else { update_post_meta( $row['id'], $key, wp_slash( $value ) ); }
					wp_cache_delete( $row['id'], 'post_meta' );
					$stored_value = get_post_meta( $row['id'], $key, true );
					if ( null === $value ? metadata_exists( 'post', $row['id'], $key ) : ( is_array( $value ) ? $stored_value !== $value : (string) $stored_value !== $value ) ) { self::$reset_failed = true; return self::reset_error(); }
				}
				$revisions = self::revision_ids( $row['id'] );
				if ( false === $revisions ) { self::$reset_failed = true; return self::reset_error(); }
				foreach ( array_diff( $revisions, array_map( 'absint', $row['revisions'] ) ) as $revision ) {
					if ( ! self::same_demo_session( $state, self::fresh_option( self::STATE_OPTION ) ) || ! self::owns_write_lock( $lock ) || ! self::validate_owned_ids( array( $row['id'] => $row['type'] ) ) || ! wp_delete_post_revision( $revision ) ) { self::$reset_failed = true; return self::reset_error(); }
				}
			}
			$current = self::fresh_option( self::STATE_OPTION );
			if ( ! self::same_demo_session( $state, $current ) || ! self::owns_write_lock( $lock ) || self::real_family_count() || ! self::valid_baseline( $baseline ) ) { self::$reset_failed = true; return self::reset_error(); }
			$complete = $current;
			$complete['reset_pending'] = 0;
			$complete['last_reset_at'] = time();
			$complete['next_reset_at'] = time() + DAY_IN_SECONDS;
			if ( ! self::replace_state( $current, $complete ) ) { return self::reset_error(); }
			WPMFT_Data::reset_cache();
			self::schedule_reset( $complete['next_reset_at'] );
			return true;
		} finally {
			self::$mutating_post = 0;
			self::$resetting = false;
			self::release_write_lock( $lock );
		}
	}

	public static function reset_now() {
		return current_user_can( 'manage_options' ) ? self::reset_if_due( true ) : new WP_Error( 'permission', __( 'Access denied.', 'my-family-tree' ) );
	}

	/** Public saves/restores do not create a second retained copy of visitor edits. */
	public static function revision_count( $count, $post ) { return (int) $post->ID === self::$mutating_post ? 0 : $count; }

	/** A quarantined demonstration stays locked until an administrator disables it. */
	public static function guard_required() {
		$state = self::state();
		return ! empty( $state['enabled'] );
	}

	public static function is_demo_user( $id = 0 ) {
		$id = $id ? absint( $id ) : get_current_user_id();
		if ( ! $id ) { return false; }
		$user = get_userdata( $id );
		return $user && ( in_array( self::ROLE, (array) $user->roles, true ) || (string) get_user_meta( $id, '_wpmft_demo_account', true ) !== '' );
	}

	/** The manifest and marker must agree; a matching title or slug is never enough. */
	public static function is_demo_post( $id ) {
		$manifest = self::manifest();
		$ids = array_map( 'absint', array_values( $manifest['items'] ?? array() ) );
		return ! empty( $manifest['token'] ) && in_array( absint( $id ), $ids, true ) && hash_equals( (string) $manifest['token'], (string) get_post_meta( $id, '_wpmft_demo', true ) );
	}

	/** Removal is a private draft, independent of WordPress Trash retention settings. */
	public static function is_removed( $id ) {
		return self::is_demo_post( $id ) && '' !== (string) get_post_meta( $id, '_wpmft_demo_removed_cycle', true );
	}

	/** Keep the canonical demo route and its starting person available throughout each cycle. */
	public static function removal_protected( $id ) {
		$manifest = self::manifest();
		return in_array( absint( $id ), array( absint( $manifest['items']['marty'] ?? 0 ), absint( $manifest['items']['tree-page'] ?? 0 ) ), true );
	}

	/** These ownership checks intentionally ignore mode: callers capture their demo scope before work. */
	public static function allows_person( $id ) { return self::validate_owned_ids( array( absint( $id ) => 'post' ) ); }
	public static function allows_media( $id ) { return self::validate_owned_ids( array( absint( $id ) => 'attachment' ) ); }

	/** Validate the finished response, closing a concurrent real-record/media insertion window. */
	public static function validate_payload( $payload, $kind ) {
		if ( ! is_array( $payload ) ) { return false; }
		$expected = array();
		$collect = function ( $id, $type ) use ( &$expected ) {
			if ( ! ( is_int( $id ) || is_string( $id ) && ctype_digit( $id ) ) || (int) $id < 1 ) { return false; }
			$id = (int) $id;
			if ( isset( $expected[$id] ) && $expected[$id] !== $type ) { return false; }
			$expected[$id] = $type;
			return true;
		};
		$image = function ( $url ) use ( $collect ) {
			if ( '' === $url || null === $url ) { return true; }
			if ( ! is_string( $url ) ) { return false; }
			$query = wp_parse_url( $url, PHP_URL_QUERY );
			if ( ! is_string( $query ) ) { return false; }
			parse_str( $query, $parts );
			return isset( $parts['action'], $parts['id'] ) && 'wpmft_media' === $parts['action'] && $collect( $parts['id'], 'attachment' );
		};
		$srcset = function ( $value ) use ( $image ) {
			if ( '' === $value ) { return true; }
			if ( ! is_string( $value ) || strlen( $value ) > 16000 ) { return false; }
			$candidates = preg_split( '/\s*,\s*/', trim( $value ) );
			if ( ! $candidates || count( $candidates ) > 12 ) { return false; }
			foreach ( $candidates as $candidate ) {
				if ( ! preg_match( '/^(\S+)\s+[1-9][0-9]*w$/D', $candidate, $match ) || ! $image( $match[1] ) ) { return false; }
			}
			return true;
		};
		$person = function ( $row ) use ( $collect, $image ) {
			return is_array( $row ) && isset( $row['id'] ) && $collect( $row['id'], 'post' ) && $image( $row['photo'] ?? '' );
		};
		if ( 'tree' === $kind ) {
			if ( ! isset( $payload['nodes'], $payload['edges'] ) || ! is_array( $payload['nodes'] ) || ! is_array( $payload['edges'] ) ) { return false; }
			if ( ! empty( $payload['root'] ) && ! $collect( $payload['root'], 'post' ) ) { return false; }
			foreach ( $payload['nodes'] ?? array() as $row ) { if ( ! $person( $row ) ) { return false; } }
			foreach ( $payload['edges'] ?? array() as $edge ) { if ( ! is_array( $edge ) || ! $collect( $edge['from'] ?? 0, 'post' ) || ! $collect( $edge['to'] ?? 0, 'post' ) ) { return false; } }
		} elseif ( 'search' === $kind ) {
			if ( ! isset( $payload['people'] ) || ! is_array( $payload['people'] ) ) { return false; }
			foreach ( $payload['people'] ?? array() as $row ) { if ( ! $person( $row ) ) { return false; } }
		} elseif ( 'calendar' === $kind ) {
			foreach ( array( 'birthdays', 'namedays', 'todayBirthdays', 'todayNamedays' ) as $field ) {
				if ( isset( $payload[$field] ) && ! is_array( $payload[$field] ) ) { return false; }
				foreach ( $payload[$field] ?? array() as $row ) { if ( ! is_array( $row ) || ! $collect( $row['id'] ?? 0, 'post' ) ) { return false; } }
			}
		} elseif ( 'person' === $kind ) {
			if ( ! $person( $payload ) ) { return false; }
			if ( isset( $payload['relations'] ) && ! is_array( $payload['relations'] ) || isset( $payload['gallery'] ) && ! is_array( $payload['gallery'] ) ) { return false; }
			foreach ( $payload['relations'] ?? array() as $rows ) { if ( ! is_array( $rows ) ) { return false; } foreach ( $rows as $row ) { if ( ! $person( $row ) ) { return false; } } }
			foreach ( $payload['gallery'] ?? array() as $row ) {
				if ( ! is_array( $row ) || ! $collect( $row['id'] ?? 0, 'attachment' ) || ! $image( $row['url'] ?? '' ) || ! $image( $row['thumb'] ?? '' ) || ! $image( $row['display'] ?? '' ) || ! $srcset( $row['srcset'] ?? '' ) || isset( $row['sources'] ) && ! is_array( $row['sources'] ) ) { return false; }
				foreach ( $row['sources'] ?? array() as $source ) {
					if ( ! is_array( $source ) || ! in_array( $source['type'] ?? '', array( 'image/avif', 'image/webp', 'image/jpeg', 'image/png', 'image/gif' ), true ) || ! $srcset( $source['srcset'] ?? '' ) ) { return false; }
				}
			}
		} else { return false; }
		return self::validate_owned_ids( $expected );
	}

	/** Batch ownership verification bypasses post-meta caches and fails closed on database errors. */
	private static function validate_owned_ids( $expected ) {
		if ( ! $expected ) { return true; }
		$manifest = self::fresh_option( self::MANIFEST_OPTION );
		if ( ! is_array( $manifest ) ) { return false; }
		$owned = array_map( 'absint', array_values( $manifest['items'] ?? array() ) );
		if ( empty( $manifest['token'] ) || array_diff( array_keys( $expected ), $owned ) || isset( $expected[0] ) ) { return false; }
		foreach ( $expected as $id => $post_type ) {
			$id = absint( $id );
			$post = $id ? get_post( $id ) : null;
			if ( ! $post instanceof WP_Post || $post_type !== $post->post_type ) { return false; }
			$marker = (string) get_post_meta( $id, '_wpmft_demo', true );
			if ( '' === $marker || ! hash_equals( (string) $manifest['token'], $marker ) ) { return false; }
		}
		return true;
	}

	/** Fresh SQL includes unpublished and trashed family records, not just the cached graph. */
	public static function real_family_count() {
		global $wpdb;
		$manifest = self::fresh_option( self::MANIFEST_OPTION );
		if ( ! is_array( $manifest ) ) { return PHP_INT_MAX; }
		$ids = array_map( 'absint', array_values( $manifest['items'] ?? array() ) );
		$categories = WPMFT_Data::categories() ?: array( 0 );
		$slots = implode( ',', array_fill( 0, count( $categories ), '%d' ) );
		$owned = $ids ? ' AND p.ID IN (' . implode( ',', $ids ) . ')' : ' AND 1=0';
		// The interpolated fragments contain only fixed %d placeholders and validated integer IDs.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type='post' AND p.post_status<>'auto-draft' AND (EXISTS (SELECT 1 FROM {$wpdb->postmeta} f WHERE f.post_id=p.ID AND f.meta_key='_wpmft_person' AND f.meta_value='1') OR EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=p.ID AND tt.taxonomy='category' AND tt.term_id IN ({$slots}))) AND NOT (EXISTS (SELECT 1 FROM {$wpdb->postmeta} d WHERE d.post_id=p.ID AND d.meta_key='_wpmft_demo' AND d.meta_value=%s){$owned})", array_merge( $categories, array( (string) ( $manifest['token'] ?? '' ) ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- An access decision must not use the performance cache.
		$count = $wpdb->get_var( $sql );
		// Null or malformed results mean the isolation check failed, never an empty real tree.
		return is_scalar( $count ) && preg_match( '/^[0-9]+$/D', (string) $count ) ? (int) $count : PHP_INT_MAX;
	}

	public static function active() {
		$state = self::state();
		if ( empty( $state['enabled'] ) || ! empty( $state['quarantined'] ) ) { return false; }
		// Other requests stay closed while a journaled reset removes visitor metadata and posts.
		if ( ! empty( $state['reset_pending'] ) && ! self::$resetting ) { return ! is_wp_error( self::reset_if_due() ); }
		$manifest = self::manifest();
		$root = absint( $manifest['items']['marty'] ?? 0 );
		if ( ! $root || ! self::is_demo_post( $root ) || 'publish' !== get_post_status( $root ) || self::real_family_count() ) {
			self::quarantine();
			return false;
		}
		return self::$resetting || ! is_wp_error( self::reset_if_due() );
	}

	private static function quarantine() {
		if ( self::$quarantining ) { return; }
		self::$quarantining = true;
		try {
			$state = self::fresh_option( self::STATE_OPTION );
			if ( empty( $state['enabled'] ) || ! empty( $state['quarantined'] ) ) { return; }
			$updated = $state;
			$updated['quarantined'] = 1;
			$updated['generation'] = wp_generate_password( 32, false, false );
			if ( ! self::replace_state( $state, $updated ) ) { return; }
			wp_clear_scheduled_hook( self::RESET_HOOK );
			self::revoke_account( $updated, false );
		} finally { self::$quarantining = false; }
	}

	/** Separate cookie, generation and namespace prevent demo sessions becoming family sessions. */
	public static function front_authorized() {
		if ( ! self::active() ) { return false; }
		$state = self::state();
		if ( self::is_demo_user() && get_current_user_id() === absint( $state['user_id'] ?? 0 ) && current_user_can( self::CAP ) ) { return true; }
		$token = isset( $_COOKIE['wpmft_demo_session'] ) && is_string( $_COOKIE['wpmft_demo_session'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['wpmft_demo_session'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) { return false; }
		$session = get_transient( 'wpmft_demo_session_' . hash( 'sha256', $token ) );
		return is_array( $session ) && isset( $session['generation'], $session['expires'] ) && $session['expires'] > time() && hash_equals( (string) $state['generation'], (string) $session['generation'] );
	}

	public static function unlock( $password, $remember = false ) {
		if ( ! self::active() ) { return new WP_Error( 'demo_locked', __( 'Demo sharing is unavailable. Please contact the site administrator.', 'my-family-tree' ) ); }
		if ( ! WPMFT_Security::rate_limit( 'demo_unlock', 10, 15 * MINUTE_IN_SECONDS ) ) { return new WP_Error( 'rate_limit', __( 'Too many attempts. Please try again in 15 minutes.', 'my-family-tree' ) ); }
		if ( ! is_string( $password ) || ! hash_equals( 'demo', $password ) ) { return new WP_Error( 'password', __( 'The demo password is incorrect.', 'my-family-tree' ) ); }
		self::logout();
		$token = bin2hex( random_bytes( 32 ) );
		$ttl = $remember ? DAY_IN_SECONDS : HOUR_IN_SECONDS;
		$state = self::state();
		set_transient( 'wpmft_demo_session_' . hash( 'sha256', $token ), array( 'generation' => $state['generation'], 'expires' => time() + $ttl ), $ttl );
		WPMFT_Security::cookie( 'wpmft_demo_session', $token, $remember ? time() + $ttl : 0 );
		return true;
	}

	public static function logout() {
		$token = isset( $_COOKIE['wpmft_demo_session'] ) && is_string( $_COOKIE['wpmft_demo_session'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['wpmft_demo_session'] ) ) : '';
		if ( preg_match( '/^[a-f0-9]{64}$/D', $token ) ) { delete_transient( 'wpmft_demo_session_' . hash( 'sha256', $token ) ); }
		if ( $token ) { WPMFT_Security::cookie( 'wpmft_demo_session', '', time() - HOUR_IN_SECONDS ); }
	}

	public static function public_config() {
		$active = self::active();
		$manifest = self::manifest();
		return array(
			'active' => $active, 'guarded' => self::guard_required(),
			'label' => $active ? __( 'Family archive demo', 'my-family-tree' ) : '',
			'notice' => $active ? __( 'Fictional family archive with invented people and relationships. Visitor edits are public and reset every 24 hours.', 'my-family-tree' ) : '',
			'password' => $active ? 'demo' : '',
			'rootId' => $active ? absint( $manifest['items']['marty'] ?? 0 ) : 0,
			'treeUrl' => $active && ! empty( $manifest['items']['tree-page'] ) ? get_permalink( $manifest['items']['tree-page'] ) : '',
		);
	}

	/** Administrator-only additive import. Existing records, pages and settings are left intact. */
	public static function import() {
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'permission', __( 'Only an administrator can import demo content.', 'my-family-tree' ) ); }
		$lock = get_option( 'wpmft_demo_import_lock', 0 );
		if ( $lock && (int) $lock < time() - 10 * MINUTE_IN_SECONDS ) { delete_option( 'wpmft_demo_import_lock' ); }
		if ( ! add_option( 'wpmft_demo_import_lock', time(), '', false ) ) { return new WP_Error( 'busy', __( 'Another demo import is running. Please try again shortly.', 'my-family-tree' ) ); }
		$write_lock = self::acquire_write_lock();
		if ( ! $write_lock ) { delete_option( 'wpmft_demo_import_lock' ); return self::reset_error(); }
		try {
			$manifest = self::manifest();
			if ( empty( $manifest['token'] ) ) { $manifest = array( 'token' => wp_generate_uuid4(), 'items' => array(), 'created' => time() ); }
			update_option( self::MANIFEST_OPTION, $manifest, false );
			$people = self::people();
			$created = array();
			foreach ( $people as $key => $person ) {
				$existing = self::existing_item( $manifest, $key );
				if ( $existing ) {
					if ( ! self::is_demo_post( $existing ) ) { return new WP_Error( 'demo_changed', __( 'A previous demo record was repurposed. The importer stopped without changing it; restore its demo marker or use a separate demo site.', 'my-family-tree' ) ); }
					if ( ! get_post_meta( $existing, '_wpmft_demo_seeded', true ) ) { $created[$key] = $existing; }
					continue;
				}
				$content = self::person_content( $person );
				$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => $person['name'], 'post_content' => $content, 'post_excerpt' => $person['bio'], 'comment_status' => 'closed', 'ping_status' => 'closed', 'meta_input' => array( '_wpmft_person' => '1', '_wpmft_demo' => $manifest['token'], '_wpmft_demo_key' => $key ) ), true );
				if ( is_wp_error( $id ) ) { return $id; }
				$manifest['items'][$key] = (int) $id;
				$created[$key] = (int) $id;
				update_option( self::MANIFEST_OPTION, $manifest, false );
				foreach ( array( 'gender' => $person['gender'], 'born' => $person['born'], '_wpmft_location' => $person['location'], '_wpmft_occupation' => $person['occupation'], '_wpmft_website' => 'https://example.com/', '_wpmft_social_instagram' => 'https://www.instagram.com/', '_wpmft_social_youtube' => 'https://www.youtube.com/' ) as $meta => $value ) { update_post_meta( $id, $meta, $value ); }
				if ( function_exists( 'pll_set_post_language' ) && in_array( 'en', (array) pll_languages_list(), true ) ) { pll_set_post_language( $id, 'en' ); }
			}
			// Only newly inserted people receive relationships. A later import preserves demo edits too.
			foreach ( $created as $key => $id ) {
				$person = $people[$key];
				foreach ( array( 'father', 'mother', 'spouse' ) as $relation ) { if ( ! empty( $person[$relation] ) ) { update_post_meta( $id, $relation, $manifest['items'][$person[$relation]] ); } }
				if ( ! empty( $person['spouse'] ) ) { update_post_meta( $id, '_wpmft_partner_status', array( $manifest['items'][$person['spouse']] => 'together' ) ); }
				$portrait = self::import_demo_photo( $manifest, 'portrait-' . $key, $key, $id, $person['name'] . ' — archival public-domain demo portrait' );
				if ( is_wp_error( $portrait ) ) { return $portrait; }
				set_post_thumbnail( $id, $portrait );
			}
			/* Reuse four validated archival portrait attachments for the demo gallery. This avoids a second remote-media path while still demonstrating captions, dates and the lightbox. */
			$gallery_keys = array( 'george', 'lorraine', 'jennifer', 'amelie' );
			$gallery = array();
			foreach ( $gallery_keys as $gallery_key ) {
				$photo_id = absint( $manifest['items']['portrait-' . $gallery_key] ?? 0 );
				if ( ! $photo_id || ! wp_attachment_is_image( $photo_id ) ) { return new WP_Error( 'demo_gallery_missing', __( 'A validated archival demo portrait is missing, so the sample gallery could not be created.', 'my-family-tree' ) ); }
				$gallery[] = $photo_id;
			}
			$gallery_details = array();
			$gallery_copy = array(
				array( 'date' => 'c. 1840', 'description' => __( 'An archival public-domain portrait used to demonstrate how a family memory can be captioned and dated.', 'my-family-tree' ) ),
				array( 'date' => 'c. 1845', 'description' => __( 'A second archival portrait used illustratively in this fictional family archive.', 'my-family-tree' ) ),
				array( 'date' => 'c. 1870', 'description' => __( 'A public-domain portrait used to demonstrate an individual memory inside a person gallery.', 'my-family-tree' ) ),
				array( 'date' => 'c. 1890', 'description' => __( 'A fourth archival portrait used to demonstrate a larger family-memory gallery.', 'my-family-tree' ) ),
			);
			foreach ( $gallery as $index => $photo_id ) { $gallery_details[$photo_id] = $gallery_copy[$index] ?? array( 'date' => '', 'description' => '' ); }
			foreach ( $created as $id ) { update_post_meta( $id, '_wpmft_gallery', $gallery ); update_post_meta( $id, '_wpmft_gallery_details', $gallery_details ); do_action( 'wpmft_person_saved', $id ); update_post_meta( $id, '_wpmft_demo_seeded', 1 ); }
			$pages = self::pages( $manifest );
			foreach ( $pages as $key => $page ) {
				if ( self::existing_item( $manifest, $key ) ) { continue; }
				$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $page['title'], 'post_content' => $page['content'], 'comment_status' => 'closed', 'ping_status' => 'closed', 'meta_input' => array( '_wpmft_demo' => $manifest['token'], '_wpmft_demo_key' => $key ) ), true );
				if ( is_wp_error( $id ) ) { return $id; }
				$manifest['items'][$key] = (int) $id;
				update_option( self::MANIFEST_OPTION, $manifest, false );
				if ( function_exists( 'pll_set_post_language' ) && in_array( 'en', (array) pll_languages_list(), true ) ) { pll_set_post_language( $id, 'en' ); }
			}
			WPMFT_Data::reset_cache();
			return array( 'created_people' => count( $created ), 'people' => count( $people ), 'page_id' => $manifest['items']['tree-page'] );
		} finally { self::release_write_lock( $write_lock ); delete_option( 'wpmft_demo_import_lock' ); }
	}

	private static function existing_item( &$manifest, $key ) {
		$id = absint( $manifest['items'][$key] ?? 0 );
		if ( $id && get_post( $id ) ) {
			// A removed marker means the administrator repurposed the record: do not modify it.
			return $id;
		}
		return 0;
	}


	/** Public-domain media used only when an administrator imports or refreshes demo content. */
	private static function media_sources() {
		return array(
			'george' => array( 'file' => 'A fisherman NLW3365030.jpg', 'credit' => 'John Thomas / National Library of Wales', 'license' => 'Public Domain Mark 1.0' ),
			'lorraine' => array( 'file' => 'A woman holding a fan NLW3364728.jpg', 'credit' => 'John Thomas / National Library of Wales', 'license' => 'Public Domain Mark 1.0' ),
			'marty' => array( 'file' => 'A bearded man NLW3364994.jpg', 'credit' => 'John Thomas / National Library of Wales', 'license' => 'Public Domain Mark 1.0' ),
			'jennifer' => array( 'file' => 'A woman (Tanner) NLW3362610.jpg', 'credit' => 'John Thomas / National Library of Wales', 'license' => 'Public Domain Mark 1.0' ),
			'amelie' => array( 'file' => 'A girl standing NLW3364957.jpg', 'credit' => 'John Thomas / National Library of Wales', 'license' => 'Public Domain Mark 1.0' ),
			'nino' => array( 'file' => 'A boy standing NLW3364958.jpg', 'credit' => 'John Thomas / National Library of Wales', 'license' => 'Public Domain Mark 1.0' ),
			'paddington' => array( 'file' => 'A child sitting NLW3364776.jpg', 'credit' => 'John Thomas / National Library of Wales', 'license' => 'Public Domain Mark 1.0' ),
			'matilda' => array( 'file' => 'A child NLW3364996.jpg', 'credit' => 'John Thomas / National Library of Wales', 'license' => 'Public Domain Mark 1.0' ),
			'album-1' => array( 'file' => 'Family photograph 1965.jpg', 'credit' => 'Jeanne boleyn / Wikimedia Commons', 'license' => 'Public domain dedication' ),
			'album-2' => array( 'file' => 'OldFamily.JPG', 'credit' => 'Polylerus / Wikimedia Commons', 'license' => 'Public domain dedication' ),
			'album-3' => array( 'file' => 'A group including a man, woman and two children NLW3364678.jpg', 'credit' => 'John Thomas / National Library of Wales', 'license' => 'Public Domain Mark 1.0' ),
			'album-4' => array( 'file' => 'A girl, four boys and a baby NLW3364931.jpg', 'credit' => 'John Thomas / National Library of Wales', 'license' => 'Public Domain Mark 1.0' ),
		);
	}

	private static function commons_file_url( $filename ) {
		return 'https://commons.wikimedia.org/wiki/Special:Redirect/file/' . rawurlencode( str_replace( ' ', '_', trim( (string) $filename ) ) );
	}

	private static function import_demo_photo( &$manifest, $key, $source_key, $parent, $alt ) {
		$existing = self::existing_item( $manifest, $key );
		if ( $existing ) { return $existing; }
		$sources = self::media_sources();
		$source = $sources[$source_key] ?? null;
		if ( ! $source ) { return new WP_Error( 'demo_image', __( 'A demo photograph source is missing.', 'my-family-tree' ) ); }
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$url = self::commons_file_url( $source['file'] );
		$tmp = download_url( $url, 25 );
		if ( is_wp_error( $tmp ) ) {
			/* translators: 1: archival photograph filename, 2: download error message. */
			return new WP_Error( 'demo_image_download', sprintf( __( 'Could not download the archival demo photograph “%1$s”: %2$s', 'my-family-tree' ), $source['file'], $tmp->get_error_message() ) );
		}
		$size = is_file( $tmp ) ? filesize( $tmp ) : 0;
		$mime = is_file( $tmp ) && function_exists( 'wp_get_image_mime' ) ? wp_get_image_mime( $tmp ) : false;
		if ( ! $size || $size > 5 * MB_IN_BYTES || 'image/jpeg' !== $mime ) {
			if ( is_file( $tmp ) ) { wp_delete_file( $tmp ); }
			/* translators: %s: archival photograph filename. */
			return new WP_Error( 'demo_image_invalid', sprintf( __( 'The archival demo photograph “%s” was unavailable or was not a valid JPEG image.', 'my-family-tree' ), $source['file'] ) );
		}
		$file = array( 'name' => sanitize_file_name( $source['file'] ), 'tmp_name' => $tmp );
		$upload = wp_handle_sideload( $file, array( 'test_form' => false, 'mimes' => array( 'jpg|jpeg' => 'image/jpeg' ) ) );
		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			if ( is_file( $tmp ) ) { wp_delete_file( $tmp ); }
			/* translators: 1: archival photograph filename, 2: WordPress upload error message. */
			return new WP_Error( 'demo_image_upload', sprintf( __( 'WordPress could not save the archival demo photograph “%1$s”: %2$s', 'my-family-tree' ), $source['file'], (string) ( $upload['error'] ?? __( 'Unknown upload error.', 'my-family-tree' ) ) ) );
		}
		$id = wp_insert_attachment( array(
			'post_title' => $alt,
			/* translators: 1: photograph source/creator, 2: licence name. */
			'post_excerpt' => sprintf( __( 'Archival public-domain photograph used illustratively in the fictional demo. Source: %1$s. Licence: %2$s.', 'my-family-tree' ), $source['credit'], $source['license'] ),
			'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'post_parent' => absint( $parent ),
			'meta_input' => array( '_wpmft_demo' => $manifest['token'], '_wpmft_demo_key' => $key, '_wp_attachment_image_alt' => $alt, '_wpmft_demo_source_url' => $url, '_wpmft_demo_credit' => $source['credit'], '_wpmft_demo_license' => $source['license'], '_wpmft_demo_media_kind' => 'archival-public-domain' ),
		), $upload['file'], $parent, true );
		if ( is_wp_error( $id ) ) { wp_delete_file( $upload['file'] ); return $id; }
		$metadata = wp_generate_attachment_metadata( $id, $upload['file'] );
		if ( is_array( $metadata ) ) { wp_update_attachment_metadata( $id, $metadata ); }
		$manifest['items'][$key] = (int) $id;
		update_option( self::MANIFEST_OPTION, $manifest, false );
		return (int) $id;
	}

	private static function purge_orphan_demo_media( $manifest ) {
		$token = isset( $manifest['token'] ) ? (string) $manifest['token'] : '';
		if ( '' === $token ) { return; }
		$keep = array_map( 'absint', array_values( (array) ( $manifest['items'] ?? array() ) ) );
		$ids = get_posts( array(
			'post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'posts_per_page' => -1,
			'meta_key' => '_wpmft_demo', 'meta_value' => $token,
		) );
		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( $id && ! in_array( $id, $keep, true ) ) { wp_delete_attachment( $id, true ); }
		}
	}

	private static function delete_demo_media_key( &$manifest, $key ) {
		$id = absint( $manifest['items'][$key] ?? 0 );
		if ( ! $id ) { return true; }
		if ( 'attachment' !== get_post_type( $id ) || ! self::is_demo_post( $id ) ) { return false; }
		if ( ! wp_delete_attachment( $id, true ) ) { return false; }
		unset( $manifest['items'][$key] );
		update_option( self::MANIFEST_OPTION, $manifest, false );
		return true;
	}

	/** Administrator-only refresh of the realistic fictional family samples and archival demo media. */
	public static function refresh_samples() {
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'permission', __( 'Only an administrator can refresh demo content.', 'my-family-tree' ) ); }
		if ( self::guard_required() ) { return new WP_Error( 'demo_enabled', __( 'Disable shared demo mode before refreshing its sample content.', 'my-family-tree' ) ); }
		$lock = self::acquire_write_lock();
		if ( ! $lock ) { return self::reset_error(); }
		try {
			$manifest = self::manifest();
			$people = self::people();
			foreach ( $people as $key => $person ) {
				$id = absint( $manifest['items'][$key] ?? 0 );
				if ( ! $id || ! self::is_demo_post( $id ) || 'post' !== get_post_type( $id ) ) { return new WP_Error( 'demo_missing', __( 'Import the demo content before refreshing its sample data and photographs.', 'my-family-tree' ) ); }
			}

			/* Download every replacement first. A failed remote source leaves the current demo untouched. */
			$new_media = array();
			$temp_keys = array();
			foreach ( $people as $key => $person ) {
				$temp_key = 'refresh-portrait-' . $key;
				if ( isset( $manifest['items'][$temp_key] ) ) { self::delete_demo_media_key( $manifest, $temp_key ); }
				$photo = self::import_demo_photo( $manifest, $temp_key, $key, absint( $manifest['items'][$key] ), $person['name'] . ' — archival public-domain demo portrait' );
				if ( is_wp_error( $photo ) ) {
					foreach ( $temp_keys as $cleanup_key ) { self::delete_demo_media_key( $manifest, $cleanup_key ); }
					return $photo;
				}
				$temp_keys[] = $temp_key;
				$new_media['portrait-' . $key] = absint( $photo );
			}
			$old_media = array();
			foreach ( array_merge( array_map( static function ( $key ) { return 'portrait-' . $key; }, array_keys( $people ) ), array( 'album-1', 'album-2', 'album-3', 'album-4' ) ) as $media_key ) {
				$old_media[$media_key] = absint( $manifest['items'][$media_key] ?? 0 );
			}

			foreach ( $people as $key => $person ) {
				$id = absint( $manifest['items'][$key] );
				wp_update_post( array( 'ID' => $id, 'post_title' => $person['name'], 'post_content' => self::person_content( $person ), 'post_excerpt' => $person['bio'], 'post_status' => 'publish' ) );
				foreach ( array( 'gender' => $person['gender'], 'born' => $person['born'], '_wpmft_location' => $person['location'], '_wpmft_occupation' => $person['occupation'], '_wpmft_website' => 'https://example.com/' ) as $meta => $value ) { update_post_meta( $id, $meta, $value ); }
				foreach ( array( 'father', 'mother', 'spouse' ) as $relation ) {
					if ( ! empty( $person[$relation] ) ) { update_post_meta( $id, $relation, absint( $manifest['items'][$person[$relation]] ?? 0 ) ); } else { delete_post_meta( $id, $relation ); }
				}
				$status = array();
				if ( ! empty( $person['spouse'] ) ) { $status[absint( $manifest['items'][$person['spouse']] ?? 0 )] = 'together'; }
				if ( $status ) { update_post_meta( $id, '_wpmft_partner_status', $status ); } else { delete_post_meta( $id, '_wpmft_partner_status' ); }
				set_post_thumbnail( $id, $new_media['portrait-' . $key] );
			}

			$gallery_keys = array( 'george', 'lorraine', 'jennifer', 'amelie' );
			$gallery = array();
			foreach ( $gallery_keys as $gallery_key ) {
				$photo_id = absint( $new_media['portrait-' . $gallery_key] ?? 0 );
				if ( ! $photo_id || ! wp_attachment_is_image( $photo_id ) ) { return new WP_Error( 'demo_gallery_missing', __( 'A validated archival demo portrait is missing, so the sample gallery could not be created.', 'my-family-tree' ) ); }
				$gallery[] = $photo_id;
			}
			$details = array();
			$stories = array(
				array( 'date' => 'c. 1840', 'description' => __( 'An archival public-domain portrait used to demonstrate how a family memory can be captioned and dated.', 'my-family-tree' ) ),
				array( 'date' => 'c. 1845', 'description' => __( 'A second archival portrait used illustratively in this fictional family archive.', 'my-family-tree' ) ),
				array( 'date' => 'c. 1870', 'description' => __( 'A public-domain portrait used to demonstrate an individual memory inside a person gallery.', 'my-family-tree' ) ),
				array( 'date' => 'c. 1890', 'description' => __( 'A fourth archival portrait used to demonstrate a larger family-memory gallery.', 'my-family-tree' ) ),
			);
			foreach ( $gallery as $index => $photo_id ) { $details[$photo_id] = $stories[$index]; }
			foreach ( $people as $key => $person ) {
				$id = absint( $manifest['items'][$key] );
				update_post_meta( $id, '_wpmft_gallery', $gallery );
				update_post_meta( $id, '_wpmft_gallery_details', $details );
				update_post_meta( $id, '_wpmft_demo_seeded', 1 );
				do_action( 'wpmft_person_saved', $id );
				clean_post_cache( $id );
			}

			/* Promote the temporary attachments to their stable manifest keys. */
			foreach ( $new_media as $media_key => $attachment_id ) {
				$manifest['items'][$media_key] = absint( $attachment_id );
				update_post_meta( $attachment_id, '_wpmft_demo_key', $media_key );
			}
			foreach ( $temp_keys as $temp_key ) { unset( $manifest['items'][$temp_key] ); }
			update_option( self::MANIFEST_OPTION, $manifest, false );

			/* Delete superseded placeholders only after every real archival photograph is ready. */
			foreach ( $old_media as $old_id ) {
				if ( $old_id && ! in_array( $old_id, $new_media, true ) && 'attachment' === get_post_type( $old_id ) && self::is_demo_post( $old_id ) ) { wp_delete_attachment( $old_id, true ); }
			}
			/* Album-only attachments from older releases are no longer part of the manifest. The gallery now reuses validated portrait media. */
			foreach ( array( 'album-1', 'album-2', 'album-3', 'album-4' ) as $legacy_album_key ) { unset( $manifest['items'][$legacy_album_key] ); }
			update_option( self::MANIFEST_OPTION, $manifest, false );
			self::purge_orphan_demo_media( $manifest );

			$pages = self::pages( $manifest );
			foreach ( $pages as $key => $page ) {
				$id = absint( $manifest['items'][$key] ?? 0 );
				if ( $id && self::is_demo_post( $id ) ) { wp_update_post( array( 'ID' => $id, 'post_title' => $page['title'], 'post_content' => $page['content'], 'post_status' => 'publish' ) ); }
			}
			$baseline = self::make_baseline( true );
			if ( is_wp_error( $baseline ) || ! self::valid_baseline( $baseline ) ) { return self::reset_error(); }
			update_option( self::BASELINE_OPTION, $baseline, false );
			update_option( 'wpmft_demo_sample_version', 4, false );
			WPMFT_Data::reset_cache();
			if ( class_exists( 'WPMFT_Cache' ) ) { WPMFT_Cache::purge(); }
			return array( 'portraits' => count( $people ), 'gallery' => 4, 'sample_version' => 4 );
		} finally { self::release_write_lock( $lock ); }
	}

	public static function enable() {
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'permission', __( 'Only an administrator can enable demo sharing.', 'my-family-tree' ) ); }
		$lock = self::acquire_write_lock();
		if ( ! $lock ) { return self::reset_error(); }
		try {
			$before_state = self::fresh_option( self::STATE_OPTION );
			if ( false === $before_state ) { return self::reset_error(); }
			if ( is_multisite() ) { return new WP_Error( 'multisite', __( 'Shared demo accounts are available on single-site installations only. Demo content can still be imported on this network.', 'my-family-tree' ) ); }
			$manifest = self::manifest();
			if ( empty( $manifest['items']['marty'] ) || ! self::is_demo_post( $manifest['items']['marty'] ) || 'publish' !== get_post_status( $manifest['items']['marty'] ) ) { return new WP_Error( 'demo_missing', __( 'Import the demo first and keep its starting person published.', 'my-family-tree' ) ); }
			if ( self::real_family_count() ) { return new WP_Error( 'real_content', __( 'Demo sharing is blocked because real family records exist, including unpublished or trashed records. Use a separate demo site.', 'my-family-tree' ) ); }
			if ( self::guard_required() ) { return new WP_Error( 'already_enabled', __( 'Disable the existing demo session before enabling it again.', 'my-family-tree' ) ); }
			if ( username_exists( 'demo' ) ) { return new WP_Error( 'username_exists', __( 'The username demo already exists. No existing account has been changed. Use a separate demo site or rename that account yourself.', 'my-family-tree' ) ); }
			$baseline = self::fresh_option( self::BASELINE_OPTION );
			if ( false === $baseline ) { return self::reset_error(); }
			if ( ! $baseline ) {
				$baseline = self::make_baseline();
				if ( is_wp_error( $baseline ) ) { return $baseline; }
				if ( ! self::owns_write_lock( $lock ) ) { return self::reset_error(); }
				update_option( self::BASELINE_OPTION, $baseline, false );
				if ( self::fresh_option( self::BASELINE_OPTION ) !== $baseline ) { return self::reset_error(); }
			}
			if ( ! self::valid_baseline( $baseline ) ) { return new WP_Error( 'demo_baseline', __( 'The saved demo baseline no longer matches the marked samples. Review the demo content and save a new baseline before sharing.', 'my-family-tree' ) ); }
			if ( ! self::owns_write_lock( $lock ) || self::fresh_option( self::STATE_OPTION ) !== $before_state ) { return self::reset_error(); }
			// No standard Editor capabilities: this public account can use only our constrained editor.
			remove_role( self::ROLE );
			add_role( self::ROLE, __( 'Family Tree Demo Editor', 'my-family-tree' ), array( 'read' => true, self::CAP => true ) );
			$id = wp_insert_user( array( 'user_login' => 'demo', 'user_pass' => 'Demo@demo123Editor', 'user_email' => '', 'display_name' => 'Family Archive Demo Editor', 'role' => self::ROLE, 'show_admin_bar_front' => 'false' ) );
			if ( is_wp_error( $id ) ) { return $id; }
			$account_token = wp_generate_uuid4();
			update_user_meta( $id, '_wpmft_demo_account', $account_token );
			$new_state = array( 'enabled' => 1, 'quarantined' => 0, 'user_id' => (int) $id, 'account_token' => $account_token, 'generation' => wp_generate_password( 32, false, false ), 'owner_id' => get_current_user_id(), 'enabled_at' => time(), 'reset_cycle' => wp_generate_uuid4(), 'next_reset_at' => time() + DAY_IN_SECONDS, 'reset_pending' => 0, 'reset_version' => 1 );
			if ( ! self::owns_write_lock( $lock ) || self::fresh_option( self::STATE_OPTION ) !== $before_state || ! ( $before_state ? self::replace_state( $before_state, $new_state ) : add_option( self::STATE_OPTION, $new_state, '', false ) ) ) { self::revoke_account( $new_state, true ); return self::reset_error(); }
			self::$reset_failed = false;
			self::schedule_reset( time() + DAY_IN_SECONDS );
			return true;
		} finally { self::release_write_lock( $lock ); }
	}

	private static function revoke_account( $state, $delete ) {
		$id = absint( $state['user_id'] ?? 0 );
		$marker = $id ? (string) get_user_meta( $id, '_wpmft_demo_account', true ) : '';
		if ( ! $id || ! $marker || empty( $state['account_token'] ) || ! hash_equals( (string) $state['account_token'], $marker ) ) { return; }
		WP_Session_Tokens::get_instance( $id )->destroy_all();
		wp_set_password( wp_generate_password( 48, true, true ), $id );
		$user = get_userdata( $id );
		if ( $user ) { $user->set_role( '' ); }
		if ( $delete && ! is_multisite() ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			$owner = absint( $state['owner_id'] ?? 0 );
			if ( $owner && $owner !== $id && get_userdata( $owner ) ) { wp_delete_user( $id, $owner ); }
		}
	}

	public static function disable() {
		$state = self::state();
		update_option( self::STATE_OPTION, array( 'enabled' => 0, 'generation' => wp_generate_password( 32, false, false ) ), false );
		wp_clear_scheduled_hook( self::RESET_HOOK );
		self::revoke_account( $state, true );
		remove_role( self::ROLE );
		self::logout();
		return true;
	}

	/** Deactivation revokes the public account; it never deletes demo posts or family data. */
	public static function deactivate() { self::disable(); }

	private static function require_admin( $action ) {
		if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) { wp_die( esc_html__( 'A POST request is required.', 'my-family-tree' ), '', array( 'response' => 405 ) ); }
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Access denied.', 'my-family-tree' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( $action );
		if ( 'wpmft_demo_disable' !== $action && ! self::tools_visible() ) { wp_die( esc_html__( 'Enable the optional demo tools in General settings before using this action.', 'my-family-tree' ), '', array( 'response' => 403 ) ); }
		$confirmed = isset( $_POST['wpmft_demo_confirm'] ) && is_string( $_POST['wpmft_demo_confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['wpmft_demo_confirm'] ) ) : '';
		if ( '1' !== $confirmed ) { wp_die( esc_html__( 'Read and confirm the demo notice before continuing.', 'my-family-tree' ), '', array( 'response' => 400 ) ); }
	}

	private static function finish( $result, $message ) {
		set_transient( 'wpmft_demo_notice_' . get_current_user_id(), array( 'error' => is_wp_error( $result ), 'message' => is_wp_error( $result ) ? $result->get_error_message() : $message ), MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=wpmft#' . ( self::tools_visible() ? 'wpmft-demo' : 'wpmft-general' ) ) );
		exit;
	}

	public static function handle_import() { self::require_admin( 'wpmft_demo_import' ); self::finish( self::import(), __( 'Demo content is ready. Existing content and settings were preserved. Sharing remains off until you explicitly enable it.', 'my-family-tree' ) ); }
	public static function handle_enable() { self::require_admin( 'wpmft_demo_enable' ); self::finish( self::enable(), __( 'Demo sharing is active. The shared editor can edit demo content only.', 'my-family-tree' ) ); }
	public static function handle_disable() { self::require_admin( 'wpmft_demo_disable' ); self::finish( self::disable(), __( 'Demo sharing is off. Its sessions and managed account have been revoked. Demo content is retained.', 'my-family-tree' ) ); }
	public static function handle_reset() { self::require_admin( 'wpmft_demo_reset' ); self::finish( self::reset_now(), __( 'Demo visitor edits have been reset. The next reset is due in 24 hours.', 'my-family-tree' ) ); }
	public static function handle_refresh() { self::require_admin( 'wpmft_demo_refresh' ); self::finish( self::refresh_samples(), __( 'The demo now uses realistic fictional family data and public-domain archival photographs.', 'my-family-tree' ) ); }

	public static function handle_baseline() { self::require_admin( 'wpmft_demo_baseline' ); self::finish( self::capture_baseline(), __( 'The current demo titles, stories, personal details, contacts and photo stories are saved as the reset baseline.', 'my-family-tree' ) ); }

	/** First-time empty installs offer setup once; established sites opt in from General. */
	public static function tools_visible() {
		$s = wpmft_settings();
		if ( ! empty( $s['demo_tools_enabled'] ) ) { return true; }
		return ! get_option( 'wpmft_demo_setup_complete', 0 ) && empty( $s['page_id'] ) && empty( self::manifest()['items'] ) && ! WPMFT_Data::ids( false );
	}

	/** Hiding tools never hides an active shared account or silently changes its access. */
	public static function render_general_status() {
		if ( ! self::guard_required() ) {
			if ( self::tools_visible() && empty( wpmft_settings()['demo_tools_enabled'] ) ) { echo '<p class="description">' . esc_html__( 'The Demo tab is available for this initial setup. Save General settings to finish setup.', 'my-family-tree' ) . '</p>'; }
			return;
		}
		echo '<div class="wpmft-admin-note"><p><strong>' . esc_html__( 'Shared demo access is active or locked.', 'my-family-tree' ) . '</strong> ' . esc_html__( 'Hiding the Demo tab does not stop shared access or its daily reset.', 'my-family-tree' ) . '</p>';
		if ( self::tools_visible() ) {
			echo '<p><a class="button" href="#wpmft-demo">' . esc_html__( 'Manage or disable demo sharing', 'my-family-tree' ) . '</a></p>';
		} else {
			echo '<label class="wpmft-check"><input type="checkbox" form="wpmft-demo-disable" name="wpmft_demo_confirm" value="1" required><span>' . esc_html__( 'Revoke the shared account and demo sessions, keep demo content, and restore the normal Privacy settings.', 'my-family-tree' ) . '</span></label><p><button type="submit" form="wpmft-demo-disable" class="button">' . esc_html__( 'Disable demo mode', 'my-family-tree' ) . '</button></p>';
		}
		echo '</div>';
	}

	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$notice = get_transient( 'wpmft_demo_notice_' . get_current_user_id() );
		if ( is_array( $notice ) && isset( $notice['message'] ) ) {
			delete_transient( 'wpmft_demo_notice_' . get_current_user_id() );
			echo '<div class="notice ' . ( ! empty( $notice['error'] ) ? 'notice-error' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}
		if ( ! empty( self::state()['quarantined'] ) ) { echo '<div class="notice notice-error"><p>' . esc_html__( 'Family Tree demo sharing was locked because its content is no longer isolated. Shared access has been revoked. Disable demo mode in Family Tree settings before configuring normal family access.', 'my-family-tree' ) . '</p></div>'; }
	}

	private static function demo_media_health( $manifest ) {
		$keys = array();
		foreach ( array_keys( self::people() ) as $key ) { $keys[] = 'portrait-' . $key; }
		$ready = 0;
		$missing = array();
		foreach ( $keys as $key ) {
			$id = absint( $manifest['items'][$key] ?? 0 );
			if ( $id && 'attachment' === get_post_type( $id ) && 'archival-public-domain' === get_post_meta( $id, '_wpmft_demo_media_kind', true ) && wp_attachment_is_image( $id ) ) { $ready++; } else { $missing[] = $key; }
		}
		return array( 'ready' => $ready, 'total' => count( $keys ), 'missing' => $missing );
	}

	/** Buttons can live in the settings form; each targets its own external action form. */
	public static function render_panel() {
		$manifest = self::manifest();
		$active = self::active();
		$guarded = self::guard_required();
		?>
		<h2><?php esc_html_e( 'Demo content & sharing', 'my-family-tree' ); ?></h2>
		<p><?php esc_html_e( 'Add eight fictional family members, eight realistic archival demo photographs and two English pages. The importer adds marked sample content and preserves existing records, pages and settings. The public-domain photographs are downloaded from Wikimedia Commons only when an administrator imports or refreshes the demo.', 'my-family-tree' ); ?></p>
		<p><?php esc_html_e( 'All names, dates, biographies and family links are fictional. Archival photographs are public-domain demonstration media and do not depict the named fictional people.', 'my-family-tree' ); ?></p>
		<?php $media_health = self::demo_media_health( $manifest ); ?>
		<p><strong><?php esc_html_e( 'Archival demo photos:', 'my-family-tree' ); ?></strong> <?php echo esc_html( $media_health['ready'] . '/' . $media_health['total'] ); ?></p>
		<?php if ( $media_health['ready'] < $media_health['total'] && ! empty( $manifest['items']['marty'] ) ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'Some demo records still use older placeholder media. Disable shared demo mode and run “Refresh demo sample data & photos”. The refresh succeeds only after all eight archival photographs have been downloaded and validated.', 'my-family-tree' ); ?></p></div>
		<?php endif; ?>
		<label class="wpmft-check"><input type="checkbox" form="wpmft-demo-import" name="wpmft_demo_confirm" value="1" required> <span><?php esc_html_e( 'I understand that demo people will be added alongside existing content. This does not enable public demo access.', 'my-family-tree' ); ?></span></label>
		<p><button type="submit" form="wpmft-demo-import" class="button button-secondary"><?php esc_html_e( 'Import demo content', 'my-family-tree' ); ?></button></p>
		<?php if ( ! empty( $manifest['items']['marty'] ) && ! $guarded ) : ?>
		<label class="wpmft-check"><input type="checkbox" form="wpmft-demo-refresh" name="wpmft_demo_confirm" value="1" required> <span><?php esc_html_e( 'Replace only the marked demo samples with the realistic family dataset and public-domain archival photographs. Real family content is never changed.', 'my-family-tree' ); ?></span></label>
		<p><button type="submit" form="wpmft-demo-refresh" class="button"><?php esc_html_e( 'Refresh demo sample data & photos', 'my-family-tree' ); ?></button></p>
		<?php endif; ?>
		<?php if ( ! empty( $manifest['items']['tree-page'] ) && get_post( $manifest['items']['tree-page'] ) ) : ?>
		<p><a class="button" href="<?php echo esc_url( get_permalink( $manifest['items']['tree-page'] ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open demo tree page', 'my-family-tree' ); ?></a></p>
		<?php endif; ?>
		<hr>
		<h3><?php esc_html_e( 'Optional shared demo mode', 'my-family-tree' ); ?></h3>
		<p><?php esc_html_e( 'Use this on a separate demonstration site containing only demo family records. The restricted Family Tree Demo Editor can add, edit and remove demo persons and pages, and view a read-only settings overview. Contact sending is disabled during demo mode. Visitor edits are public and reset every 24 hours.', 'my-family-tree' ); ?></p>
		<?php if ( $active ) : ?>
		<p><strong><?php esc_html_e( 'Demo mode is active.', 'my-family-tree' ); ?></strong></p>
		<dl><dt><?php esc_html_e( 'WordPress demo login', 'my-family-tree' ); ?></dt><dd><code>demo</code></dd><dt><?php esc_html_e( 'WordPress demo password', 'my-family-tree' ); ?></dt><dd><code>Demo@demo123Editor</code></dd><dt><?php esc_html_e( 'Front-end demo password', 'my-family-tree' ); ?></dt><dd><code>demo</code></dd></dl>
		<?php elseif ( $guarded ) : ?>
		<p><strong><?php esc_html_e( 'Demo sharing is locked. Its isolation check failed; shared sessions have been revoked.', 'my-family-tree' ); ?></strong></p>
		<?php else : ?>
		<label class="wpmft-check"><input type="checkbox" form="wpmft-demo-enable" name="wpmft_demo_confirm" value="1" required> <span><?php esc_html_e( 'I understand that this creates a shared public demo account with a published password, on an isolated demo tree only.', 'my-family-tree' ); ?></span></label>
		<p><button type="submit" form="wpmft-demo-enable" class="button"><?php esc_html_e( 'Enable shared demo mode', 'my-family-tree' ); ?></button></p>
		<?php endif; ?>
		<?php if ( $guarded ) : ?>
		<label class="wpmft-check"><input type="checkbox" form="wpmft-demo-disable" name="wpmft_demo_confirm" value="1" required> <span><?php esc_html_e( 'Revoke the shared account and demo sessions, keep demo content, and restore the normal Privacy settings.', 'my-family-tree' ); ?></span></label>
		<p><button type="submit" form="wpmft-demo-disable" class="button"><?php esc_html_e( 'Disable demo mode', 'my-family-tree' ); ?></button></p>
		<?php endif; ?>
		<hr>
		<h3><?php esc_html_e( 'Automatic demo reset', 'my-family-tree' ); ?></h3>
		<p><?php esc_html_e( 'Every 24 hours the plugin restores original demo titles, stories, publication status, personal details, contacts and photo stories. Removed original samples return, and visitor-created records are deleted. Settings, relationships, media and unrelated content are kept. No backup plugin is required.', 'my-family-tree' ); ?></p>
		<p class="description"><?php esc_html_e( 'WordPress runs scheduled tasks when the site receives a request. An overdue reset also runs before the next demo visit or save. An editor opened before a reset must be reloaded before saving.', 'my-family-tree' ); ?></p>
		<?php if ( $active ) : $reset_state = self::state(); ?>
		<p><strong><?php esc_html_e( 'Next reset:', 'my-family-tree' ); ?></strong> <?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $reset_state['next_reset_at'] ) ); ?></p>
		<label class="wpmft-check"><input type="checkbox" form="wpmft-demo-reset" name="wpmft_demo_confirm" value="1" required> <span><?php esc_html_e( 'Restore the saved demo starting state now and discard current visitor edits.', 'my-family-tree' ); ?></span></label>
		<p><button type="submit" form="wpmft-demo-reset" class="button"><?php esc_html_e( 'Reset demo now', 'my-family-tree' ); ?></button></p>
		<?php elseif ( ! $guarded && ! empty( $manifest['items']['marty'] ) ) : ?>
		<p><?php esc_html_e( 'The first activation saves the imported demo samples as their starting state. That baseline is retained when sharing is disabled and enabled again. To change it, edit those original samples as an administrator while sharing is off, then save a new baseline below.', 'my-family-tree' ); ?></p>
		<label class="wpmft-check"><input type="checkbox" form="wpmft-demo-baseline" name="wpmft_demo_confirm" value="1" required> <span><?php esc_html_e( 'Use the current original demo samples, including their publication status, for future resets. Visitor-created records are still removed at reset.', 'my-family-tree' ); ?></span></label>
		<p><button type="submit" form="wpmft-demo-baseline" class="button"><?php esc_html_e( 'Save current demo as reset baseline', 'my-family-tree' ); ?></button></p>
		<?php endif; ?>
		<?php
	}

	public static function render_forms() {
		foreach ( array( 'import', 'refresh', 'enable', 'disable', 'reset', 'baseline' ) as $action ) {
			echo '<form id="wpmft-demo-' . esc_attr( $action ) . '" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post"><input type="hidden" name="action" value="wpmft_demo_' . esc_attr( $action ) . '">';
			wp_nonce_field( 'wpmft_demo_' . $action );
			echo '</form>';
		}
	}

	public static function admin_assets( $hook ) {
		if ( ! in_array( $hook, array( 'toplevel_page_wpmft', 'toplevel_page_wpmft-demo-editor' ), true ) ) { return; }
		if ( 'toplevel_page_wpmft-demo-editor' === $hook && self::is_demo_user() ) {
			// The core command palette loads REST-backed preferences, unavailable to this restricted account.
			wp_dequeue_script( 'wp-core-commands' );
			wp_dequeue_script( 'wp-commands' );
			wp_dequeue_style( 'wp-commands' );
			wp_enqueue_style( 'wpmft-admin', wpmft_asset_url( 'admin.css' ), array(), wpmft_asset_version() );
		}
		wp_enqueue_script( 'wpmft-demo-admin', wpmft_asset_url( 'demo-admin.js' ), array(), wpmft_asset_version(), true );
		wp_enqueue_style( 'wpmft-demo-admin', wpmft_asset_url( 'demo-admin.css' ), array(), wpmft_asset_version() );
		wp_localize_script( 'wpmft-demo-admin', 'WPMFT_DEMO_ADMIN', array(
			'import' => __( 'Add fictional demo people, public-domain archival photographs and English pages? Existing content will be kept. Importing does not enable shared demo access.', 'my-family-tree' ),
			'refresh' => __( 'Replace only the marked demo samples with the realistic fictional family dataset and public-domain archival photographs? Shared demo mode must be disabled.', 'my-family-tree' ),
			'enable' => __( 'Enable a public shared demo login? Anyone given its published credentials can add, edit and remove demo persons and pages. Changes reset every 24 hours. This requires an isolated demo tree.', 'my-family-tree' ),
			'disable' => __( 'Revoke the shared account and all demo sessions? Demo content will be kept and the normal Privacy settings will apply again.', 'my-family-tree' ),
			'reset' => __( 'Discard current visitor edits and restore the saved demo starting state? Other site content and settings will be kept.', 'my-family-tree' ),
			'baseline' => __( 'Replace the saved starting state with the current original demo samples? Daily resets will restore these values and remove visitor-created records.', 'my-family-tree' ),
		) );
	}

	/** No primitive WordPress editing capability is granted to the shared account. */
	public static function map_capability( $caps, $cap, $user_id, $args ) {
		if ( ! self::is_demo_user( $user_id ) ) { return $caps; }
		if ( in_array( $cap, array( 'edit_post', 'read_post', 'delete_post', 'edit_page', 'read_page', 'delete_page' ), true ) ) {
			$id = absint( $args[0] ?? 0 );
			$visible = 'attachment' === get_post_type( $id ) || ( 'publish' === get_post_status( $id ) && ! self::is_removed( $id ) );
			return self::active() && self::is_demo_post( $id ) && $visible && in_array( $cap, array( 'edit_post', 'read_post', 'edit_page', 'read_page' ), true ) && ( 'read_post' === $cap || 'read_page' === $cap || in_array( get_post_type( $id ), array( 'post', 'page' ), true ) ) ? array( self::CAP ) : array( 'do_not_allow' );
		}
		if ( in_array( $cap, array( 'edit_post_meta', 'add_post_meta', 'delete_post_meta', 'edit_user', 'delete_user', 'promote_user', 'remove_user', 'create_users', 'edit_users' ), true ) ) { return array( 'do_not_allow' ); }
		return $caps;
	}

	public static function edit_link( $link, $id, $context ) {
		return self::is_demo_user() && self::active() && self::is_demo_post( $id ) && ! self::is_removed( $id ) && 'publish' === get_post_status( $id ) ? add_query_arg( array( 'page' => 'wpmft-demo-editor', 'person' => absint( $id ) ), admin_url( 'admin.php' ) ) : $link;
	}

	public static function login_redirect( $redirect, $requested, $user ) {
		return $user instanceof WP_User && self::is_demo_user( $user->ID ) ? admin_url( 'admin.php?page=wpmft-demo-editor' ) : $redirect;
	}

	public static function authenticate( $user, $password ) {
		if ( $user instanceof WP_User && self::is_demo_user( $user->ID ) && ! self::active() ) { return new WP_Error( 'demo_disabled', __( 'Demo sharing is not active.', 'my-family-tree' ) ); }
		return $user;
	}

	public static function application_passwords( $available, $user ) { return $user instanceof WP_User && self::is_demo_user( $user->ID ) ? false : $available; }
	public static function allow_password_reset( $allow, $user_id ) { return self::is_demo_user( $user_id ) ? false : $allow; }
	public static function guard_xmlrpc( $enabled ) { return self::guard_required() ? false : $enabled; }
	public static function guard_rest( $result, $server, $request ) { return self::is_demo_user() ? new WP_Error( 'demo_rest', __( 'Use the restricted demo editor for sample content.', 'my-family-tree' ), array( 'status' => 403 ) ) : $result; }

	public static function restrict_queries( $where, $query ) {
		if ( ! self::is_demo_user() ) { return $where; }
		global $wpdb;
		$manifest = self::manifest();
		$ids = array_map( 'absint', array_values( $manifest['items'] ?? array() ) );
		return $where . ( self::active() && $ids ? " AND {$wpdb->posts}.ID IN (" . implode( ',', $ids ) . ") AND ({$wpdb->posts}.post_type='attachment' OR {$wpdb->posts}.post_status='publish')" : ' AND 1=0' );
	}

	public static function guard_admin() {
		if ( ! self::is_demo_user() ) { return; }
		WPMFT_Security::no_cache();
		if ( ! self::active() || ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Demo sharing is not active.', 'my-family-tree' ), '', array( 'response' => 403 ) ); }
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( wp_doing_ajax() ) {
			if ( in_array( $action, array( 'wpmft_api', 'wpmft_media' ), true ) ) { return; }
			wp_send_json_error( array( 'message' => __( 'This action is not available to the demo editor.', 'my-family-tree' ) ), 403 );
		}
		if ( 'admin-post.php' === ( $GLOBALS['pagenow'] ?? '' ) && in_array( $action, array( 'wpmft_demo_save', 'wpmft_demo_create', 'wpmft_demo_remove' ), true ) ) { return; }
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'admin.php' === ( $GLOBALS['pagenow'] ?? '' ) && 'wpmft-demo-editor' === $page ) { return; }
		wp_safe_redirect( admin_url( 'admin.php?page=wpmft-demo-editor' ) );
		exit;
	}

	public static function editor_menu() {
		if ( ! self::is_demo_user() ) { return; }
		global $menu;
		$menu = array();
		add_menu_page( __( 'Family Tree Demo', 'my-family-tree' ), __( 'Persons', 'my-family-tree' ), self::CAP, 'wpmft-demo-editor', array( __CLASS__, 'editor_page' ), 'dashicons-groups', 2 );
		add_submenu_page( 'wpmft-demo-editor', __( 'Persons', 'my-family-tree' ), __( 'All persons', 'my-family-tree' ), self::CAP, 'wpmft-demo-editor', array( __CLASS__, 'editor_page' ) );
		add_submenu_page( 'wpmft-demo-editor', __( 'Add person', 'my-family-tree' ), __( 'Add person', 'my-family-tree' ), self::CAP, 'wpmft-demo-editor&create=post', array( __CLASS__, 'editor_page' ) );
		add_submenu_page( 'wpmft-demo-editor', __( 'Pages', 'my-family-tree' ), __( 'Pages', 'my-family-tree' ), self::CAP, 'wpmft-demo-editor&view=pages', array( __CLASS__, 'editor_page' ) );
		add_submenu_page( 'wpmft-demo-editor', __( 'Family tree settings', 'my-family-tree' ), __( 'Family tree settings', 'my-family-tree' ), self::CAP, 'wpmft-demo-editor&view=settings', array( __CLASS__, 'editor_page' ) );
	}

	public static function editor_page() {
		if ( ! self::is_demo_user() || ! self::active() || ! current_user_can( self::CAP ) ) { return; }
		$manifest = self::manifest();
		$id = isset( $_GET['person'] ) && is_scalar( $_GET['person'] ) ? absint( wp_unslash( $_GET['person'] ) ) : 0;
		$remove_id = isset( $_GET['remove'] ) && is_scalar( $_GET['remove'] ) ? absint( wp_unslash( $_GET['remove'] ) ) : 0;
		if ( $remove_id ) { $id = $remove_id; }
		$post = $id && self::is_demo_post( $id ) ? get_post( $id ) : null;
		if ( $post && ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) || 'publish' !== $post->post_status || self::is_removed( $id ) ) ) { $post = null; }
		$create_value = isset( $_GET['create'] ) && is_string( $_GET['create'] ) ? sanitize_key( wp_unslash( $_GET['create'] ) ) : '';
		$create_type = ! $post && in_array( $create_value, array( 'post', 'page' ), true ) ? $create_value : '';
		$removing = $post && $remove_id && ! self::removal_protected( $id );
		$view = isset( $_GET['view'] ) && is_string( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		$show_pages = $post ? 'page' === $post->post_type : ( $create_type ? 'page' === $create_type : 'pages' === $view );
		$show_settings = ! $post && ! $create_type && 'settings' === $view;
		$list_url = admin_url( 'admin.php?page=wpmft-demo-editor' );
		$pages_url = add_query_arg( 'view', 'pages', $list_url );
		$settings_url = add_query_arg( 'view', 'settings', $list_url );
		$tree_id = absint( $manifest['items']['tree-page'] ?? 0 );
		$tree_url = $tree_id && self::is_demo_post( $tree_id ) ? get_permalink( $tree_id ) : '';
		$next_reset = (int) ( self::state()['next_reset_at'] ?? 0 );
		$heading = $post ? ( $show_pages ? __( 'Edit demo page', 'my-family-tree' ) : __( 'Edit person', 'my-family-tree' ) ) : ( $show_settings ? __( 'Settings overview', 'my-family-tree' ) : ( $show_pages ? __( 'Demo pages', 'my-family-tree' ) : __( 'Persons', 'my-family-tree' ) ) );
		if ( $create_type ) { $heading = $show_pages ? __( 'Add page', 'my-family-tree' ) : __( 'Add person', 'my-family-tree' ); }
		if ( $removing ) { $heading = $show_pages ? __( 'Remove page', 'my-family-tree' ) : __( 'Remove person', 'my-family-tree' ); }
		?>
		<div class="wrap wpmft-settings wpmft-demo-editor">
			<header class="wpmft-demo-header">
				<div class="wpmft-admin-heading"><div><p class="wpmft-eyebrow">DigitalPulse · <?php esc_html_e( 'Family Tree Demo', 'my-family-tree' ); ?></p><h1><?php echo esc_html( $heading ); ?></h1></div><span class="wpmft-version"><?php echo esc_html( WPMFT_VERSION ); ?></span></div>
				<?php if ( $post ) : ?><p class="wpmft-demo-selected-name"><?php echo esc_html( $post->post_title ); ?></p><?php endif; ?>
				<nav class="wpmft-demo-nav" aria-label="<?php esc_attr_e( 'Demo navigation', 'my-family-tree' ); ?>">
					<a class="button" href="<?php echo esc_url( $list_url ); ?>" <?php echo ! $post && ! $create_type && ! $show_pages && ! $show_settings ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'All persons', 'my-family-tree' ); ?></a>
					<a class="button" href="<?php echo esc_url( $pages_url ); ?>" <?php echo ! $post && ! $create_type && $show_pages ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'Demo pages', 'my-family-tree' ); ?></a>
					<a class="button" href="<?php echo esc_url( $settings_url ); ?>" <?php echo $show_settings ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'Settings overview', 'my-family-tree' ); ?></a>
					<?php if ( $tree_url ) : ?><a class="button" href="<?php echo esc_url( $tree_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View tree', 'my-family-tree' ); ?></a><?php endif; ?>
					<a class="button wpmft-demo-logout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Log out', 'my-family-tree' ); ?></a>
				</nav>
			</header>
			<div class="wpmft-demo-reset-note">
				<p><?php esc_html_e( 'You can add, edit and remove demo persons and pages. Changes are public. Every 24 hours the original demo returns and visitor-created items are removed. Do not enter personal or confidential information.', 'my-family-tree' ); ?></p>
				<?php if ( $next_reset ) : ?><p class="wpmft-demo-reset-time"><?php
					/* translators: %s: The next automatic reset date and time in the site timezone. */
					echo esc_html( sprintf( __( 'Next reset: %s.', 'my-family-tree' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) . ' T', $next_reset ) ) );
				?></p><?php endif; ?>
			</div>
			<?php
		if ( $post && isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Demo changes saved.', 'my-family-tree' ) . '</p></div>'; }
		if ( $post && isset( $_GET['created'] ) ) { echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Demo item added. It will be removed at the next daily reset.', 'my-family-tree' ) . '</p></div>'; }
		if ( isset( $_GET['removed'] ) ) { echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Demo item removed. The original demo will return at the next daily reset.', 'my-family-tree' ) . '</p></div>'; }
		if ( $post && $remove_id && ! $removing ) { echo '<div class="notice notice-info inline"><p>' . esc_html__( 'The starting person and tree page are kept so visitors can always open the demo.', 'my-family-tree' ) . '</p></div>'; }
		if ( $removing ) {
			echo '<form class="wpmft-demo-remove-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post"><input type="hidden" name="action" value="wpmft_demo_remove"><input type="hidden" name="person" value="' . absint( $id ) . '">';
			wp_nonce_field( 'wpmft_demo_remove' );
			echo '<input type="hidden" name="wpmft_demo_reset_cycle" value="' . esc_attr( self::state()['reset_cycle'] ?? '' ) . '">';
			echo '<p>' . esc_html__( 'This item will disappear from the public demo. The original demo is restored every 24 hours.', 'my-family-tree' ) . '</p><label class="wpmft-demo-setting-check"><input type="checkbox" name="wpmft_demo_confirm" value="1" required><span>' . esc_html__( 'I confirm removal of this demo item.', 'my-family-tree' ) . '</span></label><div class="wpmft-demo-form-actions"><button type="submit" class="button wpmft-demo-remove-button">' . esc_html( $heading ) . '</button><a class="button" href="' . esc_url( $show_pages ? $pages_url : $list_url ) . '">' . esc_html__( 'Cancel', 'my-family-tree' ) . '</a></div></form>';
		} elseif ( $post || $create_type ) {
			echo '<form class="wpmft-demo-person-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post"><input type="hidden" name="action" value="' . ( $create_type ? 'wpmft_demo_create' : 'wpmft_demo_save' ) . '">';
			if ( $create_type ) { echo '<input type="hidden" name="demo_type" value="' . esc_attr( $create_type ) . '">'; }
			else { echo '<input type="hidden" name="person" value="' . absint( $id ) . '">'; }
			wp_nonce_field( $create_type ? 'wpmft_demo_create' : 'wpmft_demo_save' );
			echo '<input type="hidden" name="wpmft_demo_reset_cycle" value="' . esc_attr( self::state()['reset_cycle'] ?? '' ) . '">';
			echo '<div class="wpmft-person-panel"><label class="wpmft-field wpmft-demo-title-field" for="wpmft-demo-title"><span>' . esc_html( $show_pages ? __( 'Name / title', 'my-family-tree' ) : __( 'Person’s full name', 'my-family-tree' ) ) . '</span><input class="large-text" id="wpmft-demo-title" name="demo_title" value="' . esc_attr( $post ? $post->post_title : '' ) . '" maxlength="160" required></label>';
			if ( ! $show_pages ) { self::editor_person_fields( $post ); }
			echo '</div><section class="wpmft-person-panel"><h2 class="wpmft-biography-heading">' . esc_html__( 'Biography / page story', 'my-family-tree' ) . '</h2>';
			if ( $id === $tree_id ) {
				echo '<textarea class="large-text" rows="3" id="wpmft-demo-story" name="demo_story" readonly>' . esc_textarea( $post->post_content ) . '</textarea><p class="description">' . esc_html__( 'This page displays the demo family tree. Its shortcode is preserved; you can edit the page title.', 'my-family-tree' ) . '</p>';
			} else {
				wp_editor( $post ? $post->post_content : '', 'wpmft-demo-story', array( 'textarea_name' => 'demo_story', 'media_buttons' => false, 'textarea_rows' => 14, 'teeny' => true, 'tinymce' => array( 'toolbar1' => 'formatselect,bold,italic,bullist,numlist,blockquote,undo,redo,removeformat', 'toolbar2' => '' ), 'quicktags' => array( 'buttons' => 'strong,em,block,del,ins,ul,ol,li,close' ) ) );
				echo '<p class="description">' . esc_html__( 'Write a story with simple formatting. Shared demo pages do not run shortcodes or embedded scripts.', 'my-family-tree' ) . '</p>';
			}
			echo '</section>';
			echo '<div class="wpmft-demo-form-actions">';
			submit_button( $create_type ? $heading : ( $show_pages ? __( 'Save demo page', 'my-family-tree' ) : __( 'Save person', 'my-family-tree' ) ), 'primary', 'submit', false );
			echo '<a class="button" href="' . esc_url( $show_pages ? $pages_url : $list_url ) . '">' . esc_html__( 'Cancel', 'my-family-tree' ) . '</a></div></form>';
			if ( $post && ! self::removal_protected( $id ) ) { echo '<p><a class="wpmft-demo-remove-link" href="' . esc_url( add_query_arg( 'remove', $id, $list_url ) ) . '">' . esc_html( $show_pages ? __( 'Remove page', 'my-family-tree' ) : __( 'Remove person', 'my-family-tree' ) ) . '</a></p>'; }
		} elseif ( $show_settings ) {
			self::editor_settings_overview( $manifest, $tree_url, $list_url );
		} else {
			echo '<p class="wpmft-demo-content-actions"><a class="button button-primary" href="' . esc_url( add_query_arg( 'create', $show_pages ? 'page' : 'post', $list_url ) ) . '">' . esc_html( $show_pages ? __( 'Add page', 'my-family-tree' ) : __( 'Add person', 'my-family-tree' ) ) . '</a></p>';
			if ( ! $show_pages ) {
				echo '<p class="wpmft-demo-intro">' . esc_html__( 'Choose Edit person to try personal details, contact fields, WhatsApp and photo stories in the same layout as the family editor. Use View person to see the public profile.', 'my-family-tree' ) . '</p>';
				echo '<div class="wpmft-demo-search"><label for="wpmft-demo-search">' . esc_html__( 'Search persons', 'my-family-tree' ) . '</label><input type="search" id="wpmft-demo-search" aria-controls="wpmft-demo-person-list" autocomplete="off"></div>';
			}
			echo '<ul class="wpmft-demo-cards" id="wpmft-demo-person-list">';
			foreach ( $manifest['items'] ?? array() as $item ) {
				$sample = get_post( $item );
				if ( ! $sample || ! self::is_demo_post( $item ) || self::is_removed( $item ) || 'publish' !== $sample->post_status || ( $show_pages ? 'page' : 'post' ) !== $sample->post_type ) { continue; }
				$edit_url = add_query_arg( 'person', absint( $item ), $list_url );
				$view_url = $show_pages ? get_permalink( $item ) : ( $tree_url ? add_query_arg( 'person', absint( $item ), $tree_url ) : '' );
				$portrait = $show_pages ? 0 : get_post_thumbnail_id( $item );
				echo '<li class="wpmft-demo-card" data-wpmft-demo-name="' . esc_attr( $sample->post_title ) . '"><div class="wpmft-demo-card-summary">';
				if ( $portrait && self::is_demo_post( $portrait ) ) { echo wp_get_attachment_image( $portrait, 'thumbnail', false, array( 'class' => 'wpmft-demo-card-portrait', 'alt' => '', 'loading' => 'lazy' ) ); }
				echo '<div><h2>' . esc_html( $sample->post_title ) . '</h2>';
				if ( ! $show_pages ) { echo '<p>' . esc_html( get_post_meta( $item, '_wpmft_occupation', true ) ) . '</p>'; }
				echo '</div></div><div class="wpmft-demo-card-actions"><a class="button button-primary" href="' . esc_url( $edit_url ) . '">' . esc_html( $show_pages ? __( 'Edit demo page', 'my-family-tree' ) : __( 'Edit person', 'my-family-tree' ) ) . '<span class="screen-reader-text">: ' . esc_html( $sample->post_title ) . '</span></a>';
				if ( $view_url ) { echo '<a class="button" href="' . esc_url( $view_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $show_pages ? __( 'View page', 'my-family-tree' ) : __( 'View person', 'my-family-tree' ) ) . '<span class="screen-reader-text">: ' . esc_html( $sample->post_title ) . '</span></a>'; }
				echo '</div>';
				if ( ! self::removal_protected( $item ) ) { echo '<a class="wpmft-demo-remove-link" href="' . esc_url( add_query_arg( 'remove', absint( $item ), $list_url ) ) . '">' . esc_html( $show_pages ? __( 'Remove page', 'my-family-tree' ) : __( 'Remove person', 'my-family-tree' ) ) . '<span class="screen-reader-text">: ' . esc_html( $sample->post_title ) . '</span></a>'; }
				else { echo '<small class="wpmft-demo-protected-note">' . esc_html__( 'Required for the demo tree.', 'my-family-tree' ) . '</small>'; }
				echo '</li>';
			}
			echo '</ul><p id="wpmft-demo-empty" role="status" hidden>' . esc_html__( 'No persons found.', 'my-family-tree' ) . '</p>';
		}
		echo '</div>';
	}

	/** The shared editor uses the regular person panel styles without exposing WordPress media or user APIs. */
	private static function editor_person_fields( $post ) {
		$id = $post ? (int) $post->ID : 0;
		$portrait = $id ? get_post_thumbnail_id( $id ) : 0;
		?>
		<div class="wpmft-person-editor">
			<label class="wpmft-check"><input type="checkbox" checked disabled><span><?php esc_html_e( 'Include this post as a person in the family tree', 'my-family-tree' ); ?></span></label>
			<div class="wpmft-portrait-editor">
				<div class="wpmft-portrait-preview"><?php if ( $portrait && self::allows_media( $portrait ) ) { echo wp_get_attachment_image( $portrait, 'medium' ); } else { echo '<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>'; } ?></div>
				<div><h3><?php esc_html_e( 'Portrait', 'my-family-tree' ); ?></h3><p class="description"><?php esc_html_e( 'The portrait is the post’s featured image. Faces stay at the top of photo frames.', 'my-family-tree' ); ?></p><p class="description"><?php esc_html_e( 'Sample portraits, gallery selection and family links are protected in the shared demo. Personal details, contacts and photo stories below are editable.', 'my-family-tree' ); ?></p></div>
			</div>
			<h3><?php esc_html_e( 'Personal details', 'my-family-tree' ); ?></h3>
			<div class="wpmft-person-grid">
				<label class="wpmft-field"><span><?php esc_html_e( 'Gender', 'my-family-tree' ); ?></span><select name="demo_meta[gender]">
				<?php foreach ( array( '' => __( 'Not specified', 'my-family-tree' ), 'm' => __( 'Male', 'my-family-tree' ), 'f' => __( 'Female', 'my-family-tree' ), 'x' => __( 'Other', 'my-family-tree' ) ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $id ? get_post_meta( $id, 'gender', true ) : '', $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
				</select></label>
				<?php foreach ( array( 'born' => __( 'Date of birth', 'my-family-tree' ), 'died' => __( 'Date of death (leave blank if living)', 'my-family-tree' ), '_wpmft_name_day' => __( 'Name day override (MM-DD, optional)', 'my-family-tree' ), '_wpmft_maiden_name' => __( 'Birth / maiden name', 'my-family-tree' ), '_wpmft_birth_place' => __( 'Birthplace', 'my-family-tree' ), '_wpmft_death_place' => __( 'Place of death', 'my-family-tree' ), '_wpmft_location' => __( 'Location', 'my-family-tree' ), '_wpmft_occupation' => __( 'Occupation', 'my-family-tree' ) ) as $key => $label ) { self::editor_field( $id, $key, $label ); } ?>
			</div>
			<p class="description"><?php esc_html_e( 'Dates accept YYYY, YYYY-MM or YYYY-MM-DD. Older date text is retained unless you change it.', 'my-family-tree' ); ?></p>
			<h3><?php esc_html_e( 'Family connections', 'my-family-tree' ); ?></h3>
			<div class="wpmft-person-grid">
			<?php foreach ( array( 'father' => __( 'Father / parent 1', 'my-family-tree' ), 'mother' => __( 'Mother / parent 2', 'my-family-tree' ), 'spouse' => __( 'Primary spouse / partner', 'my-family-tree' ) ) as $key => $label ) : $related = $id ? absint( get_post_meta( $id, $key, true ) ) : 0; ?>
				<label class="wpmft-field"><span><?php echo esc_html( $label ); ?></span><input type="text" readonly value="<?php echo esc_attr( $related && self::allows_person( $related ) ? get_the_title( $related ) : '' ); ?>"></label>
			<?php endforeach; ?>
			</div>
			<section class="wpmft-contact-details"><h3><?php esc_html_e( 'Contact details', 'my-family-tree' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Use fictional contact information in this public demo. Changes are visible to other visitors until the daily reset.', 'my-family-tree' ); ?></p>
				<div class="wpmft-person-grid">
				<?php foreach ( array( '_wpmft_email' => array( __( 'Email address', 'my-family-tree' ), 'email' ), '_wpmft_phone' => array( __( 'Telephone', 'my-family-tree' ), 'tel' ), '_wpmft_mobile' => array( __( 'Mobile phone', 'my-family-tree' ), 'tel' ), '_wpmft_whatsapp' => array( __( 'WhatsApp number', 'my-family-tree' ), 'tel' ), '_wpmft_website' => array( __( 'Website', 'my-family-tree' ), 'url' ) ) as $key => $field ) { self::editor_field( $id, $key, $field[0], $field[1] ); } ?>
				<label class="wpmft-field wpmft-field-wide"><span><?php esc_html_e( 'Postal address', 'my-family-tree' ); ?></span><textarea name="demo_meta[_wpmft_address]" rows="3" maxlength="500"><?php echo esc_textarea( $id ? get_post_meta( $id, '_wpmft_address', true ) : '' ); ?></textarea></label>
				</div>
				<p class="description"><?php esc_html_e( 'For WhatsApp, include the country code, for example +371 or +44. Leave it blank to hide the chat icon.', 'my-family-tree' ); ?></p>
				<h3><?php esc_html_e( 'Social profiles', 'my-family-tree' ); ?></h3><div class="wpmft-person-grid">
				<?php foreach ( array( 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'x' => 'X / Twitter', 'youtube' => 'YouTube', 'tiktok' => 'TikTok' ) as $key => $label ) { self::editor_field( $id, '_wpmft_social_' . $key, $label, 'url' ); } ?>
				</div>
			</section>
			<h3><?php esc_html_e( 'Photo gallery', 'my-family-tree' ); ?></h3>
			<?php $gallery = $id ? array_values( array_filter( WPMFT_Data::gallery_ids( $id ), array( __CLASS__, 'allows_media' ) ) ) : array(); $details = $id ? WPMFT_Data::gallery_details( $id ) : array(); ?>
			<?php if ( $gallery ) : ?><div class="wpmft-demo-gallery">
			<?php foreach ( $gallery as $photo ) : $detail = $details[$photo] ?? array( 'description' => '', 'date' => '' ); ?>
				<article class="wpmft-demo-gallery-card">
					<?php echo wp_get_attachment_image( $photo, 'medium', false, array( 'loading' => 'lazy', 'decoding' => 'async' ) ); ?>
					<div><label class="wpmft-field"><span><?php esc_html_e( 'When was it taken?', 'my-family-tree' ); ?></span><input type="text" name="demo_meta[_wpmft_gallery_details][<?php echo absint( $photo ); ?>][date]" value="<?php echo esc_attr( $detail['date'] ); ?>" maxlength="100"></label>
					<label class="wpmft-field"><span><?php esc_html_e( 'Photo story / description', 'my-family-tree' ); ?></span><textarea name="demo_meta[_wpmft_gallery_details][<?php echo absint( $photo ); ?>][description]" rows="3" maxlength="2000"><?php echo esc_textarea( $detail['description'] ); ?></textarea></label></div>
				</article>
			<?php endforeach; ?>
			</div><?php else : ?><p class="description"><?php esc_html_e( 'The administrator can assign sample photographs to this person. Existing sample galleries let you try photo stories and dates.', 'my-family-tree' ); ?></p><?php endif; ?>
		</div>
		<?php
	}

	private static function editor_field( $person, $key, $label, $type = 'text' ) {
		$value = $person ? get_post_meta( $person, $key, true ) : '';
		?>
		<label class="wpmft-field"><span><?php echo esc_html( $label ); ?></span><input type="<?php echo esc_attr( $type ); ?>" name="demo_meta[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( is_scalar( $value ) ? $value : '' ); ?>" maxlength="<?php echo 'url' === $type ? 2048 : 250; ?>" <?php echo in_array( $key, array( 'born', 'died' ), true ) ? 'placeholder="YYYY-MM-DD"' : ''; ?>></label>
		<?php
	}

	/** A read-only allowlist. Private credentials and contact recipients never enter this markup. */
	private static function editor_settings_overview( $manifest, $tree_url, $list_url ) {
		$s = wpmft_settings();
		$requested_cache = in_array( $s['cache_backend'], array( 'auto', 'object', 'transient' ), true ) ? $s['cache_backend'] : 'auto';
		// Display configuration only; inspecting the graph cache can create its generation option.
		$cache = array(
			'enabled' => ! empty( $s['performance_cache'] ),
			'ttl' => max( 1, min( 60, absint( $s['cache_ttl'] ) ) ),
			'backend' => 'transient' !== $requested_cache && wp_using_ext_object_cache() ? 'object' : 'transient',
		);
		$languages = wpmft_language_config();
		$registry = WPMFT_Languages::registry();
		$language_names = array_column( $languages['languages'], 'name', 'slug' );
		$default_language = is_string( $s['language'] ) ? $s['language'] : 'en';
		if ( 'polylang' === $languages['provider'] && function_exists( 'pll_default_language' ) ) {
			$polylang_default = pll_default_language( 'slug' );
			if ( is_string( $polylang_default ) && isset( $language_names[$polylang_default] ) ) { $default_language = $polylang_default; }
		}
		$default_language_name = $language_names[$default_language] ?? $registry[$default_language]['name'] ?? 'English';
		$root_id = absint( $manifest['items']['marty'] ?? 0 );
		$root = $root_id && self::allows_person( $root_id ) ? get_post( $root_id ) : null;
		$tree_id = absint( $manifest['items']['tree-page'] ?? 0 );
		$accent = sanitize_hex_color( $s['accent'] ) ?: '#315a42';
		$background = sanitize_hex_color( $s['background'] ) ?: '#f5f4ed';
		?>
		<p class="wpmft-demo-intro" id="wpmft-demo-settings-help"><?php esc_html_e( 'These settings explain how My Family Tree works. Only the site administrator can change them.', 'my-family-tree' ); ?></p>
		<nav class="wpmft-admin-tabs wpmft-demo-settings-tabs" aria-label="<?php esc_attr_e( 'Family tree settings', 'my-family-tree' ); ?>">
			<?php foreach ( array( 'general' => __( 'General', 'my-family-tree' ), 'privacy' => __( 'Privacy & access', 'my-family-tree' ), 'appearance' => __( 'Appearance', 'my-family-tree' ), 'contact' => __( 'Contact & hCaptcha', 'my-family-tree' ), 'performance' => __( 'Performance & cache', 'my-family-tree' ), 'languages' => __( 'Languages', 'my-family-tree' ) ) as $key => $label ) : ?><a href="#wpmft-demo-settings-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?>
		</nav>
		<div class="wpmft-settings-form wpmft-demo-settings-panels" aria-describedby="wpmft-demo-settings-help">
			<section id="wpmft-demo-settings-general" class="wpmft-settings-section wpmft-demo-settings-group">
				<h2><?php esc_html_e( 'General', 'my-family-tree' ); ?></h2>
				<dl>
					<dt><?php esc_html_e( 'Tree page', 'my-family-tree' ); ?></dt><dd><?php if ( $tree_url ) : ?><a href="<?php echo esc_url( $tree_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( get_the_title( $tree_id ) ); ?></a><?php endif; ?></dd>
					<dt><?php esc_html_e( 'Starting person', 'my-family-tree' ); ?></dt><dd><?php if ( $root ) : ?><a href="<?php echo esc_url( add_query_arg( 'person', $root_id, $list_url ) ); ?>"><?php echo esc_html( $root->post_title ); ?></a><?php endif; ?></dd>
				</dl>
				<label class="wpmft-demo-setting"><span><?php esc_html_e( 'Shortcodes', 'my-family-tree' ); ?></span><input type="text" readonly value="[wp_my_family_tree]"></label>
				<label class="wpmft-demo-setting-check"><input type="checkbox" disabled <?php checked( ! empty( $s['demo_tools_enabled'] ) ); ?>><span><?php esc_html_e( 'Show the Demo tab for sample content and demo sharing', 'my-family-tree' ); ?></span></label>
			</section>
			<section id="wpmft-demo-settings-appearance" class="wpmft-settings-section wpmft-demo-settings-group">
				<h2><?php esc_html_e( 'Appearance', 'my-family-tree' ); ?></h2>
				<label class="wpmft-demo-setting-check"><input type="checkbox" disabled <?php checked( ! empty( $s['standalone'] ) ); ?>><span><?php esc_html_e( 'Use the full-window layout without the theme header and footer', 'my-family-tree' ); ?></span></label>
				<label class="wpmft-demo-setting"><span><?php esc_html_e( 'Accent colour', 'my-family-tree' ); ?></span><input type="text" readonly value="<?php echo esc_attr( $accent ); ?>"></label>
				<label class="wpmft-demo-setting"><span><?php esc_html_e( 'Background colour', 'my-family-tree' ); ?></span><input type="text" readonly value="<?php echo esc_attr( $background ); ?>"></label>
				<label class="wpmft-demo-setting"><span><?php esc_html_e( 'Tree canvas height on large screens (px)', 'my-family-tree' ); ?></span><input type="number" readonly value="<?php echo absint( $s['tree_height'] ); ?>"></label>
			</section>
			<section id="wpmft-demo-settings-languages" class="wpmft-settings-section wpmft-demo-settings-group">
				<h2><?php esc_html_e( 'Languages', 'my-family-tree' ); ?></h2>
				<label class="wpmft-demo-setting"><span><?php esc_html_e( 'Default interface language', 'my-family-tree' ); ?></span><input type="text" readonly value="<?php echo esc_attr( $default_language_name ); ?>"></label>
				<label class="wpmft-demo-setting-check"><input type="checkbox" disabled <?php checked( ! empty( $s['language_switch'] ) ); ?>><span><?php esc_html_e( 'Show the language switch', 'my-family-tree' ); ?></span></label>
				<ul class="wpmft-demo-language-list"><?php foreach ( $languages['languages'] as $language ) : ?><li><?php echo esc_html( $language['name'] ); ?></li><?php endforeach; ?></ul>
				<p><?php echo esc_html( 'polylang' === $languages['provider'] ? __( 'Polylang is active. Its configured languages and translated page links control the public language menu. These presets supply interface labels.', 'my-family-tree' ) : __( 'Polylang is optional and free to install. The tree and its interface languages work without it. Installing it does not automatically translate your content.', 'my-family-tree' ) ); ?></p>
			</section>
			<section id="wpmft-demo-settings-privacy" class="wpmft-settings-section wpmft-demo-settings-group">
				<h2><?php esc_html_e( 'Privacy & access', 'my-family-tree' ); ?></h2>
				<label class="wpmft-demo-setting-check"><input type="checkbox" disabled checked><span><?php esc_html_e( 'Optional shared demo mode', 'my-family-tree' ); ?></span></label>
				<p><?php esc_html_e( 'Demo access is separate from the private-family password. The shared account can manage demo persons and pages only. Original content returns and new items are removed every 24 hours.', 'my-family-tree' ); ?></p>
			</section>
			<section id="wpmft-demo-settings-contact" class="wpmft-settings-section wpmft-demo-settings-group">
				<h2><?php esc_html_e( 'Contact & hCaptcha', 'my-family-tree' ); ?></h2>
				<label class="wpmft-demo-setting-check"><input type="checkbox" disabled><span><?php esc_html_e( 'Contact messages', 'my-family-tree' ); ?></span></label>
				<p><?php esc_html_e( 'Family visitors can send a message, a relative’s description and an optional photograph to the administrator. Sending is disabled during shared demo mode.', 'my-family-tree' ); ?></p>
				<label class="wpmft-demo-setting-check"><input type="checkbox" disabled <?php checked( ! empty( $s['hcaptcha_enabled'] ) ); ?>><span><?php esc_html_e( 'Enable hCaptcha (external anti-spam service)', 'my-family-tree' ); ?></span></label>
				<p><?php esc_html_e( 'Optional WhatsApp buttons can connect visitors with the administrator or individual persons. Administrator contact details and service keys are not shown in this shared settings view.', 'my-family-tree' ); ?></p>
			</section>
			<section id="wpmft-demo-settings-performance" class="wpmft-settings-section wpmft-demo-settings-group">
				<h2><?php esc_html_e( 'Performance & cache', 'my-family-tree' ); ?></h2>
				<label class="wpmft-demo-setting-check"><input type="checkbox" disabled <?php checked( $cache['enabled'] ); ?>><span><?php esc_html_e( 'Cache the internal relationship index', 'my-family-tree' ); ?></span></label>
				<label class="wpmft-demo-setting"><span><?php esc_html_e( 'Cache storage', 'my-family-tree' ); ?></span><input type="text" readonly value="<?php echo esc_attr( 'object' === $cache['backend'] ? __( 'Persistent WordPress object cache', 'my-family-tree' ) : __( 'WordPress database transients', 'my-family-tree' ) ); ?>"></label>
				<label class="wpmft-demo-setting"><span><?php esc_html_e( 'Cache lifetime (minutes)', 'my-family-tree' ); ?></span><input type="number" readonly value="<?php echo absint( $cache['ttl'] ); ?>"></label>
				<label class="wpmft-demo-setting-check"><input type="checkbox" disabled <?php checked( ! empty( $s['minify_assets'] ) ); ?>><span><?php esc_html_e( 'Use minified plugin JavaScript and CSS', 'my-family-tree' ); ?></span></label>
				<p><?php esc_html_e( 'A persistent object cache requires support from the host. Private family responses remain uncached.', 'my-family-tree' ); ?></p>
			</section>
		</div>
		<?php
	}

	/** Every public mutation rechecks its deadline and cycle while holding the same write lock. */
	private static function mutation_state( $cycle, $lock ) {
		$state = self::fresh_option( self::STATE_OPTION );
		if ( ! is_array( $state ) || empty( $state['enabled'] ) || ! empty( $state['quarantined'] ) || ! empty( $state['reset_pending'] ) || (int) ( $state['next_reset_at'] ?? 0 ) <= time() || get_current_user_id() !== absint( $state['user_id'] ?? 0 ) || ! self::owns_write_lock( $lock ) || self::real_family_count() ) { return self::reset_error(); }
		if ( ! is_string( $cycle ) || empty( $state['reset_cycle'] ) || ! hash_equals( (string) $state['reset_cycle'], $cycle ) ) { return new WP_Error( 'demo_stale', __( 'The demo has reset since this page was opened. Reload it before making changes.', 'my-family-tree' ) ); }
		$baseline = self::fresh_option( self::BASELINE_OPTION );
		if ( ! is_array( $baseline ) || 3 !== ( $baseline['version'] ?? 0 ) || ! self::valid_baseline( $baseline ) || ! self::valid_visitor_ledger( self::fresh_option( self::MANIFEST_OPTION ) ) ) { return self::reset_error(); }
		return $state;
	}

	private static function editable_demo_post( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removed/nonpublic records must not be writable through an old editor form.
		$post = $wpdb->get_row( $wpdb->prepare( "SELECT ID,post_type,post_status FROM {$wpdb->posts} WHERE ID=%d AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} r WHERE r.post_id=%d AND r.meta_key='_wpmft_demo_removed_cycle')", $id, $id ), ARRAY_A );
		return ! $wpdb->last_error && is_array( $post ) && in_array( $post['post_type'], array( 'post', 'page' ), true ) && 'publish' === $post['post_status'] && self::validate_owned_ids( array( $id => $post['post_type'] ) ) ? $post : false;
	}

	private static function limit_editor_text( $value, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : wp_check_invalid_utf8( substr( $value, 0, $length ), true );
	}

	/** Values are sanitized again under the mutation lock; only current sample gallery images can receive stories. */
	private static function sanitize_editor_meta( $submitted, $person = 0 ) {
		$meta = array();
		if ( ! is_array( $submitted ) ) { return $meta; }
		foreach ( self::editor_meta_keys() as $key ) {
			if ( ! array_key_exists( $key, $submitted ) ) { continue; }
			if ( '_wpmft_gallery_details' === $key ) {
				if ( ! $person || ! is_array( $submitted[$key] ) || count( $submitted[$key] ) > 100 ) { continue; }
				$details = array();
				foreach ( WPMFT_Data::gallery_ids( $person ) as $photo ) {
					if ( ! self::allows_media( $photo ) || ! isset( $submitted[$key][$photo] ) || ! is_array( $submitted[$key][$photo] ) ) { continue; }
					$row = $submitted[$key][$photo];
					if ( ! isset( $row['description'], $row['date'] ) || ! is_string( $row['description'] ) || ! is_string( $row['date'] ) || strlen( $row['description'] ) > 8000 || strlen( $row['date'] ) > 400 ) { continue; }
					$details[$photo] = array( 'description' => self::limit_editor_text( sanitize_textarea_field( $row['description'] ), 2000 ), 'date' => self::limit_editor_text( sanitize_text_field( $row['date'] ), 100 ) );
				}
				$meta[$key] = $details;
				continue;
			}
			if ( ! is_string( $submitted[$key] ) || strlen( $submitted[$key] ) > 8192 ) { continue; }
			$value = '_wpmft_address' === $key ? sanitize_textarea_field( $submitted[$key] ) : sanitize_text_field( $submitted[$key] );
			if ( in_array( $key, array( 'born', 'died' ), true ) && ! self::valid_date( $value ) ) { continue; }
			if ( 'gender' === $key && ! in_array( $value, array( '', 'm', 'f', 'x' ), true ) ) { continue; }
			if ( '_wpmft_email' === $key ) {
				if ( '' !== $value && ! is_email( $value ) ) { continue; }
				$value = sanitize_email( $value );
			}
			if ( '_wpmft_whatsapp' === $key ) { $value = wpmft_whatsapp_number( $value ); }
			$url_field = '_wpmft_website' === $key || 0 === strpos( $key, '_wpmft_social_' );
			if ( $url_field && '' !== $value ) {
				$parts = wp_parse_url( $value );
				if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) { continue; }
				$value = esc_url_raw( $value, array( 'http', 'https' ) );
			}
			$meta[$key] = self::limit_editor_text( $value, $url_field ? 2048 : ( '_wpmft_address' === $key ? 500 : 250 ) );
		}
		return $meta;
	}

	/** Shared create/save inputs expose only static stories and allowlisted person fields. */
	private static function editor_values( $request ) {
		$request = is_array( $request ) ? $request : array();
		$title = isset( $request['demo_title'] ) && is_string( $request['demo_title'] ) ? sanitize_text_field( $request['demo_title'] ) : '';
		$story = isset( $request['demo_story'] ) && is_string( $request['demo_story'] ) ? $request['demo_story'] : '';
		if ( '' === $title || strlen( $title ) > 640 || strlen( $story ) > 48000 ) { return new WP_Error( 'demo_fields', __( 'Enter a title and a story within the displayed length limits.', 'my-family-tree' ) ); }
		$story = preg_replace( '/<!--.*?-->/s', '', $story );
		$story = preg_replace( '/\[[^\]]*\]/', '', $story );
		$story = wp_kses_post( $story );
		$submitted = isset( $request['demo_meta'] ) && is_array( $request['demo_meta'] ) ? $request['demo_meta'] : array();
		$person = isset( $request['person'] ) && is_scalar( $request['person'] ) ? absint( $request['person'] ) : 0;
		$meta = self::sanitize_editor_meta( $submitted, $person );
		$cycle = isset( $request['wpmft_demo_reset_cycle'] ) && is_string( $request['wpmft_demo_reset_cycle'] ) ? sanitize_text_field( $request['wpmft_demo_reset_cycle'] ) : '';
		return array( 'title' => $title, 'story' => $story, 'meta' => $meta, 'cycle' => $cycle );
	}

	public static function handle_create() {
		if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) || ! self::is_demo_user() || ! self::active() || ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Access denied.', 'my-family-tree' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'wpmft_demo_create' );
		$request = wp_unslash( $_POST );
		$type = isset( $request['demo_type'] ) && is_string( $request['demo_type'] ) ? sanitize_key( $request['demo_type'] ) : '';
		if ( ! in_array( $type, array( 'post', 'page' ), true ) ) { wp_die( esc_html__( 'Choose a person or a demo page.', 'my-family-tree' ), '', array( 'response' => 400 ) ); }
		$values = self::editor_values( $request );
		if ( is_wp_error( $values ) ) { wp_die( esc_html( $values->get_error_message() ), '', array( 'response' => 400 ) ); }
		$result = self::create_item( $type, $values['title'], $values['story'], $values['meta'], $values['cycle'] );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 409 ) ); }
		wp_safe_redirect( add_query_arg( array( 'page' => 'wpmft-demo-editor', 'person' => $result, 'created' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function create_item( $type, $title, $story, $meta, $cycle ) {
		if ( ! self::is_demo_user() || ! self::active() || ! current_user_can( self::CAP ) || ! in_array( $type, array( 'post', 'page' ), true ) ) { return self::reset_error(); }
		$lock = self::acquire_write_lock();
		if ( ! $lock ) { return self::reset_error(); }
		try {
			$state = self::mutation_state( $cycle, $lock );
			if ( is_wp_error( $state ) ) { return $state; }
			$manifest = self::fresh_option( self::MANIFEST_OPTION );
			if ( count( $manifest['visitors'] ?? array() ) >= self::MAX_VISITOR_ITEMS ) {
				/* translators: %d: Maximum number of visitor-created persons and pages before the next demo reset. */
				return new WP_Error( 'demo_limit', sprintf( __( 'The shared demo allows up to %d new persons and pages before the next reset.', 'my-family-tree' ), self::MAX_VISITOR_ITEMS ) );
			}
			$owner = absint( $state['owner_id'] ?? 0 );
			if ( ! $owner || ! get_userdata( $owner ) ) { return self::reset_error(); }
			$key = 'visitor-' . wp_generate_uuid4();
			$entry = array( 'id' => 0, 'type' => $type, 'cycle' => $cycle, 'phase' => 'creating' );
			$reserved = $manifest; $reserved['visitors'][$key] = $entry;
			if ( ! self::valid_visitor_ledger( $reserved ) || ! self::owns_write_lock( $lock ) || ! self::same_demo_session( $state, self::fresh_option( self::STATE_OPTION ) ) || ! self::replace_manifest( $manifest, $reserved ) ) { return self::reset_error(); }
			// Auto-drafts are excluded from real-family detection until exact ownership is registered.
			$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'auto-draft', 'post_author' => $owner, 'post_title' => $title, 'post_content' => '', 'post_parent' => 0, 'comment_status' => 'closed', 'ping_status' => 'closed', 'meta_input' => array( '_wpmft_demo' => $manifest['token'], '_wpmft_demo_key' => $key, '_wpmft_demo_created_cycle' => $cycle ) ), true );
			if ( is_wp_error( $id ) || ! $id ) { return self::reset_error(); }
			$registered = $reserved;
			$registered['items'][$key] = (int) $id;
			$registered['visitors'][$key]['id'] = (int) $id;
			$registered['visitors'][$key]['phase'] = 'active';
			if ( ! self::visitor_markers_match( $reserved, $key, $entry, $id ) || ! self::valid_visitor_ledger( $registered ) || ! self::owns_write_lock( $lock ) || ! self::same_demo_session( $state, self::fresh_option( self::STATE_OPTION ) ) || ! self::replace_manifest( $reserved, $registered ) ) { return self::reset_error(); }
			if ( is_wp_error( self::mutation_state( $cycle, $lock ) ) || ! self::validate_owned_ids( array( $id => $type ) ) ) { return self::reset_error(); }
			if ( 'post' === $type ) {
				update_post_meta( $id, '_wpmft_person', '1' );
				foreach ( self::sanitize_editor_meta( $meta ) as $field => $value ) { update_post_meta( $id, $field, wp_slash( $value ) ); }
			}
			if ( function_exists( 'pll_set_post_language' ) && function_exists( 'pll_languages_list' ) ) {
				$source = absint( $manifest['items']['post' === $type ? 'marty' : 'tree-page'] ?? 0 );
				$language = $source && self::validate_owned_ids( array( $source => $type ) ) && function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $source, 'slug' ) : '';
				$available = (array) pll_languages_list( array( 'fields' => 'slug' ) );
				if ( ! is_string( $language ) || ! in_array( $language, $available, true ) ) { $language = function_exists( 'pll_default_language' ) ? pll_default_language( 'slug' ) : ''; }
				if ( is_string( $language ) && WPMFT_Languages::valid_slug( $language ) && in_array( $language, $available, true ) ) { pll_set_post_language( $id, $language ); }
			}
			if ( is_wp_error( self::mutation_state( $cycle, $lock ) ) || ! self::validate_owned_ids( array( $id => $type ) ) || ! self::visitor_markers_match( $registered, $key, $entry, $id ) ) { return self::reset_error(); }
			self::$mutating_post = (int) $id;
			$result = wp_update_post( wp_slash( array( 'ID' => $id, 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $story, 'post_name' => 'demo-' . substr( $key, 8 ), 'comment_status' => 'closed', 'ping_status' => 'closed' ) ), true );
			self::$mutating_post = 0;
			if ( is_wp_error( $result ) || ! $result || ! self::same_demo_session( $state, self::fresh_option( self::STATE_OPTION ) ) || ! self::owns_write_lock( $lock ) || ! self::editable_demo_post( $id ) || ! self::visitor_markers_match( $registered, $key, $entry, $id ) ) { return self::reset_error(); }
			WPMFT_Data::reset_cache();
			return (int) $id;
		} finally { self::$mutating_post = 0; self::release_write_lock( $lock ); }
	}

	public static function handle_remove() {
		if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) || ! self::is_demo_user() || ! self::active() || ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Access denied.', 'my-family-tree' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'wpmft_demo_remove' );
		$request = wp_unslash( $_POST );
		$id = isset( $request['person'] ) && is_scalar( $request['person'] ) ? absint( $request['person'] ) : 0;
		$confirmed = isset( $request['wpmft_demo_confirm'] ) && is_string( $request['wpmft_demo_confirm'] ) ? sanitize_text_field( $request['wpmft_demo_confirm'] ) : '';
		if ( '1' !== $confirmed ) { wp_die( esc_html__( 'Confirm removal before continuing.', 'my-family-tree' ), '', array( 'response' => 400 ) ); }
		$cycle = isset( $request['wpmft_demo_reset_cycle'] ) && is_string( $request['wpmft_demo_reset_cycle'] ) ? sanitize_text_field( $request['wpmft_demo_reset_cycle'] ) : '';
		$result = self::remove_item( $id, $cycle );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 409 ) ); }
		$query = array( 'page' => 'wpmft-demo-editor', 'removed' => 1 );
		if ( 'page' === $result ) { $query['view'] = 'pages'; }
		wp_safe_redirect( add_query_arg( $query, admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function remove_item( $id, $cycle ) {
		if ( ! self::is_demo_user() || ! self::active() || ! current_user_can( self::CAP ) ) { return self::reset_error(); }
		$lock = self::acquire_write_lock();
		if ( ! $lock ) { return self::reset_error(); }
		try {
			$state = self::mutation_state( $cycle, $lock );
			if ( is_wp_error( $state ) ) { return $state; }
			$manifest = self::fresh_option( self::MANIFEST_OPTION );
			if ( in_array( (int) $id, array( absint( $manifest['items']['marty'] ?? 0 ), absint( $manifest['items']['tree-page'] ?? 0 ) ), true ) ) { return new WP_Error( 'demo_protected', __( 'The demo starting person and tree page stay available and cannot be removed.', 'my-family-tree' ) ); }
			$post = self::editable_demo_post( $id );
			if ( ! $post ) { return new WP_Error( 'demo_content', __( 'This is not editable demo content.', 'my-family-tree' ) ); }
			// Never call wp_trash_post(): EMPTY_TRASH_DAYS=0 would permanently delete an original ID.
			self::$mutating_post = (int) $id;
			$result = wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ), true );
			self::$mutating_post = 0;
			if ( is_wp_error( $result ) || ! $result || ! self::owns_write_lock( $lock ) || ! self::same_demo_session( $state, self::fresh_option( self::STATE_OPTION ) ) || ! self::validate_owned_ids( array( $id => $post['post_type'] ) ) ) { return self::reset_error(); }
			update_post_meta( $id, '_wpmft_demo_removed_cycle', $cycle );
			wp_cache_delete( $id, 'post_meta' );
			clean_post_cache( $id );
			if ( 'draft' !== get_post_status( $id ) || (string) get_post_meta( $id, '_wpmft_demo_removed_cycle', true ) !== $cycle ) { return self::reset_error(); }
			WPMFT_Data::reset_cache();
			return $post['post_type'];
		} finally { self::$mutating_post = 0; self::release_write_lock( $lock ); }
	}

	public static function handle_save() {
		if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) || ! self::is_demo_user() || ! self::active() || ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Access denied.', 'my-family-tree' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'wpmft_demo_save' );
		$request = wp_unslash( $_POST );
		$id = isset( $request['person'] ) && is_scalar( $request['person'] ) ? absint( $request['person'] ) : 0;
		if ( ! self::is_demo_post( $id ) || ! in_array( get_post_type( $id ), array( 'post', 'page' ), true ) ) { wp_die( esc_html__( 'This is not editable demo content.', 'my-family-tree' ), '', array( 'response' => 403 ) ); }
		$values = self::editor_values( $request );
		if ( is_wp_error( $values ) ) { wp_die( esc_html( $values->get_error_message() ), '', array( 'response' => 400 ) ); }
		$manifest = self::manifest();
		if ( $id === absint( $manifest['items']['tree-page'] ?? 0 ) ) { $values['story'] = '[wp_my_family_tree root="' . absint( $manifest['items']['marty'] ?? 0 ) . '"]'; }
		$result = self::save_changes( $id, $values['title'], $values['story'], $values['meta'], $values['cycle'] );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 409 ) ); }
		wp_safe_redirect( add_query_arg( array( 'page' => 'wpmft-demo-editor', 'person' => $id, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** The nonce and input sanitization are checked by handle_save before this locked write. */
	private static function save_changes( $id, $title, $story, $meta, $cycle ) {
		if ( ! self::is_demo_user() || ! self::active() || ! current_user_can( self::CAP ) ) { return self::reset_error(); }
		$lock = self::acquire_write_lock();
		if ( ! $lock ) { return self::reset_error(); }
		try {
			$state = self::mutation_state( $cycle, $lock );
			if ( is_wp_error( $state ) ) { return $state; }
			$post = self::editable_demo_post( $id );
			if ( ! $post ) { return new WP_Error( 'demo_content', __( 'This is not editable demo content.', 'my-family-tree' ) ); }
			$type = $post['post_type'];
			self::$mutating_post = (int) $id;
			$result = wp_update_post( wp_slash( array( 'ID' => $id, 'post_title' => $title, 'post_content' => $story ) ), true );
			self::$mutating_post = 0;
			if ( is_wp_error( $result ) || ! $result ) { return self::reset_error(); }
			if ( 'post' === $type ) {
				foreach ( self::sanitize_editor_meta( $meta, $id ) as $key => $value ) {
					if ( ! self::same_demo_session( $state, self::fresh_option( self::STATE_OPTION ) ) || ! self::owns_write_lock( $lock ) || ! self::validate_owned_ids( array( $id => $type ) ) ) { return self::reset_error(); }
					update_post_meta( $id, $key, wp_slash( $value ) );
				}
			}
			WPMFT_Data::reset_cache();
			return true;
		} finally { self::$mutating_post = 0; self::release_write_lock( $lock ); }
	}

	private static function valid_date( $value ) {
		if ( '' === $value ) { return true; }
		if ( ! preg_match( '/^([0-9]{4})(?:-([0-9]{2})(?:-([0-9]{2}))?)?$/D', $value, $parts ) ) { return false; }
		return (int) $parts[1] > 0 && checkdate( isset( $parts[2] ) ? (int) $parts[2] : 1, isset( $parts[3] ) ? (int) $parts[3] : 1, (int) $parts[1] );
	}

	private static function person_content( $person ) {
		return '<p><strong>Fictional heritage-family demo.</strong> Names, dates, biographies and relationships are invented. Public-domain archival photographs are used illustratively and do not depict the named fictional people.</p><p>' . esc_html( $person['bio'] ) . '</p><h2>A little more about me</h2><p>' . esc_html( $person['story'] ) . '</p><p>Explore relatives, dates, contact fields and the archival family-memory gallery to see how a real family archive can be organised.</p>';
	}

	private static function pages( $manifest ) {
		return array(
			'tree-page' => array( 'title' => 'Morgan Family Archive — Interactive Demo', 'content' => '[wp_my_family_tree root="' . absint( $manifest['items']['marty'] ?? 0 ) . '"]' ),
			'about-page' => array( 'title' => 'About the Morgan Family Demo', 'content' => '<!-- wp:heading --><h2 class="wp-block-heading">A realistic fictional family archive</h2><!-- /wp:heading --><!-- wp:paragraph --><p>This sample shows how a private family archive can combine people, relationships, biographies, dates, contact details and photographs.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p><strong>All names, dates, biographies and family links are fictional.</strong> The archival photographs are public-domain demonstration media from Wikimedia Commons and the National Library of Wales; they do not depict the named sample people.</p><!-- /wp:paragraph --><!-- wp:heading --><h2 class="wp-block-heading">Try the experience</h2><!-- /wp:heading --><!-- wp:list --><ul class="wp-block-list"><li>Open a person and follow parents, partners, siblings and children.</li><li>Read photo captions and approximate dates in the family-memory gallery.</li><li>Try fullscreen, calendar, search and export.</li></ul><!-- /wp:list --><!-- wp:paragraph --><p>The sample is designed to demonstrate the plugin without publishing private family information.</p><!-- /wp:paragraph -->' ),
		);
	}

	private static function people() {
		return array(
			'george' => array( 'name' => 'Arthur Morgan', 'gender' => 'm', 'born' => '1842-03-18', 'location' => 'Cardiff, Wales', 'occupation' => 'Railway clerk', 'spouse' => 'lorraine', 'bio' => 'A careful record-keeper who saved letters, receipts and family photographs in labelled boxes.', 'story' => 'Arthur spent most of his working life on the railway. In retirement he began writing down family stories so younger generations would know where the family had lived and worked.' ),
			'lorraine' => array( 'name' => 'Eleanor Hughes Morgan', 'gender' => 'f', 'born' => '1845-09-02', 'location' => 'Cardiff, Wales', 'occupation' => 'Dressmaker', 'spouse' => 'george', 'bio' => 'A skilled dressmaker remembered for keeping the extended family connected through letters and regular Sunday visits.', 'story' => 'Eleanor kept a small address book containing birthdays, new addresses and notes about family events. Many of the demo archive traditions are inspired by that kind of practical family record.' ),
			'marty' => array( 'name' => 'William Morgan', 'gender' => 'm', 'born' => '1868-01-14', 'location' => 'Swansea, Wales', 'occupation' => 'Schoolmaster', 'father' => 'george', 'mother' => 'lorraine', 'spouse' => 'jennifer', 'bio' => 'A schoolmaster who collected local history and encouraged his children to label the names and places in old photographs.', 'story' => 'William moved from Cardiff to Swansea as a young teacher. His notebooks mix everyday family details with local history, making him the natural centre of this demonstration tree.' ),
			'jennifer' => array( 'name' => 'Clara Evans Morgan', 'gender' => 'f', 'born' => '1870-06-23', 'location' => 'Swansea, Wales', 'occupation' => 'Library assistant', 'spouse' => 'marty', 'bio' => 'A library assistant with a love of handwritten catalogues, family recipes and carefully dated photographs.', 'story' => 'Clara organised household papers and family albums by year. Her practical notes are represented here through photo captions, dates and person records.' ),
			'amelie' => array( 'name' => 'Alice Morgan', 'gender' => 'f', 'born' => '1892-05-07', 'location' => 'Bristol, England', 'occupation' => 'Primary-school teacher', 'father' => 'marty', 'mother' => 'jennifer', 'spouse' => 'nino', 'bio' => 'A teacher who moved to Bristol and continued the family habit of writing names and dates on the backs of photographs.', 'story' => 'Alice exchanged letters with relatives in Wales and collected family photographs from both sides of the family. Her branch demonstrates how a person can connect two households.' ),
			'nino' => array( 'name' => 'Owen Price', 'gender' => 'm', 'born' => '1890-11-19', 'location' => 'Bristol, England', 'occupation' => 'Printer', 'spouse' => 'amelie', 'bio' => 'A printer whose work made him unusually attentive to names, dates and the small details that make an archive useful.', 'story' => 'Owen printed invitations and local notices. At home he helped Alice organise family correspondence and albums into folders that could be passed down.' ),
			'paddington' => array( 'name' => 'Edith Morgan', 'gender' => 'f', 'born' => '1895-02-02', 'location' => 'Swansea, Wales', 'occupation' => 'Nurse', 'father' => 'marty', 'mother' => 'jennifer', 'bio' => 'A nurse who remained in Swansea and became the relative everyone contacted when a date, address or family story needed checking.', 'story' => 'Edith kept birthday cards, postcards and short notes from relatives. Her profile demonstrates siblings who share the same parents while following a different life path.' ),
			'matilda' => array( 'name' => 'Megan Price', 'gender' => 'f', 'born' => '1916-08-21', 'location' => 'Bristol, England', 'occupation' => 'Student', 'father' => 'nino', 'mother' => 'amelie', 'bio' => 'The youngest person in the sample archive, linking the older family records to a new generation.', 'story' => 'Megan represents the next generation of the fictional Morgan-Price family. Her branch demonstrates how children stay visually grouped beneath their parents.' ),
		);
	}

}
