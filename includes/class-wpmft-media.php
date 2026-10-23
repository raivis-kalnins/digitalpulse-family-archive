<?php
/** Encrypted family photographs and authenticated delivery. @package WPMyFamilyTree */
defined( 'ABSPATH' ) || exit;

final class WPMFT_Media {
	private static $private_ids;
	public static function init() {
		add_action( 'wp_ajax_wpmft_media', array( __CLASS__, 'serve' ) );
		add_action( 'wp_ajax_nopriv_wpmft_media', array( __CLASS__, 'serve' ) );
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'attachment_url' ), 20, 2 );
		add_filter( 'image_downsize', array( __CLASS__, 'image_downsize' ), 20, 3 );
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'srcset' ), 20, 5 );
		add_action( 'wpmft_person_saved', array( __CLASS__, 'protect_person' ) );
		add_action( 'rest_after_insert_post', function ( $post ) { self::protect_person( $post->ID ); }, 100 );
		add_action( 'added_post_meta', array( __CLASS__, 'thumbnail_changed' ), 100, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'thumbnail_changed' ), 100, 4 );
		add_action( 'delete_attachment', array( __CLASS__, 'delete_files' ) );
		add_action( 'wp_ajax_image-editor', array( __CLASS__, 'guard_image_editor' ), 0 );
	}
	public static function private_ids() {
		if ( null !== self::$private_ids ) { return self::$private_ids; }
		global $wpdb;
		$ids = array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_wpmft_vault','_wpmft_private')" ) );
		$people = WPMFT_Data::ids( false );
		if ( $people ) {
			$placeholders = implode( ',', array_fill( 0, count( $people ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only fixed %d tokens are interpolated.
			$sql = $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_parent IN ({$placeholders})", $people );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; the resulting private index is cached for this request only.
			$children = $wpdb->get_col( $sql );
			$ids = array_merge( $ids, array_map( 'intval', $children ) );
		}
		// Attachment checks inside gallery_ids() should hit one primed cache, not a query per photograph.
		if ( $ids ) { _prime_post_caches( array_unique( $ids ), false, true ); }
		foreach ( $people as $person ) { $ids = array_merge( $ids, WPMFT_Data::gallery_ids( $person, false ) ); }
		if ( $ids ) { _prime_post_caches( $ids, false, true ); }
		self::$private_ids = array_values( array_unique( $ids ) );
		return self::$private_ids;
	}
	public static function is_private( $id ) {
		if ( 'attachment' !== get_post_type( $id ) ) { return false; }
		if ( get_post_meta( $id, '_wpmft_private', true ) || self::manifest( $id ) ) { return true; }
		return in_array( (int) $id, self::private_ids(), true );
	}
	public static function url( $id, $size = 'full' ) {
		return add_query_arg( array( 'action' => 'wpmft_media', 'id' => absint( $id ), 'size' => sanitize_key( $size ) ), admin_url( 'admin-ajax.php' ) );
	}
	public static function attachment_url( $url, $id ) {
		return self::is_private( $id ) ? self::url( $id ) : $url;
	}
	public static function image_downsize( $downsize, $id, $size ) {
		if ( ! self::is_private( $id ) ) { return $downsize; }
		$meta = wp_get_attachment_metadata( $id );
		$variant = is_string( $size ) ? sanitize_key( $size ) : 'medium';
		$w = (int) ( $meta['width'] ?? 0 ); $h = (int) ( $meta['height'] ?? 0 );
		if ( isset( $meta['sizes'][$variant] ) ) { $w = (int) $meta['sizes'][$variant]['width']; $h = (int) $meta['sizes'][$variant]['height']; }
		if ( is_array( $size ) ) { list( $w, $h ) = wp_constrain_dimensions( $w, $h, $size[0], $size[1] ); }
		return array( self::url( $id, $variant ), $w, $h, $variant !== 'full' );
	}
	public static function srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) { return self::is_private( $attachment_id ) ? false : $sources; }
	private static function key( $create = false ) {
		$stored = get_option( 'wpmft_media_key', '' ); $key = base64_decode( $stored, true );
		if ( ( ! is_string( $key ) || strlen( $key ) !== 32 ) && $create ) {
			$key = random_bytes( 32 ); add_option( 'wpmft_media_key', base64_encode( $key ), '', false );
			// Use the winning value if another request created the option concurrently.
			$key = base64_decode( get_option( 'wpmft_media_key', '' ), true );
		}
		return is_string( $key ) && strlen( $key ) === 32 ? $key : false;
	}
	public static function manifest( $id ) { $m = get_post_meta( $id, '_wpmft_vault', true ); return is_array( $m ) ? $m : array(); }
	private static function decrypt( $file ) {
		$uploads = wp_upload_dir(); $base = realpath( $uploads['basedir'] );
		$path = realpath( $uploads['basedir'] . '/' . $file );
		if ( ! $base || ! $path || strpos( $path, $base . DIRECTORY_SEPARATOR . 'wpmft-private' . DIRECTORY_SEPARATOR ) !== 0 || ! is_readable( $path ) ) { return false; }
		$key = self::key();
		if ( ! $key || ! function_exists( 'openssl_decrypt' ) ) { return false; }
		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local encrypted file.
		if ( strlen( $bytes ) < 35 || substr( $bytes, 0, 7 ) !== "WPMFT1\0" ) { return false; }
		return openssl_decrypt( substr( $bytes, 35 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $bytes, 7, 12 ), substr( $bytes, 19, 16 ) );
	}
	public static function serve() {
		WPMFT_Demo::set_request_scope( WPMFT_Demo::guard_required() && ! wpmft_staff() );
		WPMFT_Security::no_cache();
		$id = isset( $_GET['id'] ) && is_scalar( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( WPMFT_Demo::scoped_request() && ! WPMFT_Demo::allows_media( $id ) ) { status_header( 404 ); exit; }
		if ( ! WPMFT_Security::authorized() && ! current_user_can( 'edit_post', $id ) ) { status_header( 403 ); exit; }
		$size = isset( $_GET['size'] ) && is_string( $_GET['size'] ) ? sanitize_key( wp_unslash( $_GET['size'] ) ) : 'full';
		if ( ! $id || ! self::is_private( $id ) || ! self::can_view( $id ) ) { status_header( 404 ); exit; }
		$manifest = self::manifest( $id );
		$entry = $manifest[$size] ?? ( $manifest['full'] ?? null );
		if ( $entry ) { $bytes = self::decrypt( $entry['file'] ); }
		else {
			// Existing family imports remain readable while an administrator protects their files.
			$uploads = wp_upload_dir();
			$relative = get_post_meta( $id, '_wp_attached_file', true );
			$meta = wp_get_attachment_metadata( $id );
			if ( isset( $meta['sizes'][$size]['file'] ) ) { $relative = dirname( $relative ) . '/' . $meta['sizes'][$size]['file']; }
			$base = realpath( $uploads['basedir'] ); $file = realpath( $uploads['basedir'] . '/' . $relative );
			$bytes = $base && $file && strpos( $file, $base . DIRECTORY_SEPARATOR ) === 0 && is_readable( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		if ( false === $bytes ) { status_header( 404 ); exit; }
		$mime = get_post_mime_type( $id );
		$allowed = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/x-icon', 'image/vnd.microsoft.icon' );
		$inline = in_array( $mime, $allowed, true );
		header( 'Content-Type: ' . ( $inline ? $mime : 'application/octet-stream' ) ); header( 'Content-Length: ' . strlen( $bytes ) );
		// Other attachments protected in a batch remain downloadable; never render active content inline.
		$original = (string) get_post_meta( $id, '_wp_attached_file', true );
		$extension = strtolower( preg_replace( '/[^a-z0-9]/i', '', pathinfo( $original, PATHINFO_EXTENSION ) ) );
		$extension = substr( $extension, 0, 10 ) ?: 'bin';
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="family-file.' . $extension . '"' );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authenticated binary image, not HTML.
		exit;
	}
	private static function can_view( $id ) {
		if ( wpmft_staff() || current_user_can( 'edit_post', $id ) ) { return true; }
		if ( ! WPMFT_Security::authorized() ) { return false; }
		$parent = (int) get_post_field( 'post_parent', $id );
		if ( $parent && 'publish' === get_post_status( $parent ) && wpmft_is_person( $parent ) ) { return true; }
		$owners = get_post_meta( $id, '_wpmft_media_people', true );
		foreach ( is_array( $owners ) ? $owners : array() as $person ) {
			if ( 'publish' === get_post_status( $person ) && wpmft_is_person( $person ) && in_array( (int) $id, WPMFT_Data::gallery_ids( $person, false ), true ) ) { return true; }
		}
		// Legacy, not-yet-encrypted imports: check stored references, never a visitor-supplied owner ID.
		if ( ! self::manifest( $id ) ) {
			foreach ( WPMFT_Data::ids() as $person ) { if ( in_array( (int) $id, WPMFT_Data::gallery_ids( $person, false ), true ) ) { return true; } }
		}
		return false;
	}
	private static function record_owner( $photo, $person ) {
		$owners = get_post_meta( $photo, '_wpmft_media_people', true );
		$owners = is_array( $owners ) ? array_map( 'absint', $owners ) : array();
		if ( ! in_array( (int) $person, $owners, true ) ) { $owners[] = (int) $person; update_post_meta( $photo, '_wpmft_media_people', $owners ); }
	}
	public static function protect_attachment( $id ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) { return new WP_Error( 'openssl', __( 'OpenSSL is required to protect photographs.', 'wp-my-family-tree' ) ); }
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) { return new WP_Error( 'uploads', $uploads['error'] ); }
		$key = self::key( true );
		if ( ! $key ) { return new WP_Error( 'key', __( 'The private media key could not be created.', 'wp-my-family-tree' ) ); }
		update_post_meta( $id, '_wpmft_vault_complete', 0 );
		$relative = (string) get_post_meta( $id, '_wp_attached_file', true );
		if ( ! $relative ) { return new WP_Error( 'missing_file', __( 'An attachment has no source file.', 'wp-my-family-tree' ) ); }
		$meta = wp_get_attachment_metadata( $id ); $dir = dirname( $relative );
		$files = array( 'full' => $relative );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size => $info ) { if ( ! empty( $info['file'] ) ) { $files[$size] = $dir . '/' . $info['file']; } }
		if ( ! empty( $meta['original_image'] ) ) { $files['original'] = $dir . '/' . $meta['original_image']; }
		foreach ( (array) get_post_meta( $id, '_wp_attachment_backup_sizes', true ) as $size => $info ) { if ( is_array( $info ) && ! empty( $info['file'] ) ) { $files['backup_' . $size] = $dir . '/' . $info['file']; } }
		$vault = trailingslashit( $uploads['basedir'] ) . 'wpmft-private';
		if ( ! wp_mkdir_p( $vault ) ) { return new WP_Error( 'directory', __( 'The private media folder could not be created.', 'wp-my-family-tree' ) ); }
		// The ciphertext is safe even where these extra web-server rules are not supported.
		if ( ! file_exists( $vault . '/.htaccess' ) ) { file_put_contents( $vault . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); }
		if ( ! file_exists( $vault . '/index.php' ) ) { file_put_contents( $vault . '/index.php', '<?php http_response_code(404); exit;' ); }
		$manifest = self::manifest( $id ); $base = realpath( $uploads['basedir'] ); $remove = array();
		foreach ( $files as $size => $rel ) {
			$path = realpath( trailingslashit( $uploads['basedir'] ) . $rel );
			if ( ! $path || ! $base || strpos( $path, $base . DIRECTORY_SEPARATOR ) !== 0 ) {
				if ( $size === 'full' && ! isset( $manifest['full'] ) ) { return new WP_Error( 'missing_file', __( 'A source photograph is missing. Restore the matching uploads backup.', 'wp-my-family-tree' ) ); }
				continue;
			}
			$plain = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $plain ) { return new WP_Error( 'read', __( 'A photograph could not be read.', 'wp-my-family-tree' ) ); }
			if ( isset( $manifest[$size] ) && self::decrypt( $manifest[$size]['file'] ) === $plain ) { $remove[] = $path; continue; }
			$iv = random_bytes( 12 ); $tag = '';
			$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false === $cipher ) { return new WP_Error( 'encrypt', __( 'A photograph could not be encrypted.', 'wp-my-family-tree' ) ); }
			$file = 'wpmft-private/' . bin2hex( random_bytes( 24 ) ) . '.bin';
			$destination = trailingslashit( $uploads['basedir'] ) . $file;
			$container = "WPMFT1\0" . $iv . $tag . $cipher;
			if ( file_put_contents( $destination, $container, LOCK_EX ) !== strlen( $container ) || self::decrypt( $file ) !== $plain ) { wp_delete_file( $destination ); return new WP_Error( 'write', __( 'The encrypted photograph could not be verified.', 'wp-my-family-tree' ) ); }
			if ( ! class_exists( 'WP_Filesystem_Base' ) ) { require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php'; }
			if ( ! class_exists( 'WP_Filesystem_Direct' ) ) { require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php'; }
			$filesystem = new WP_Filesystem_Direct( null );
			$filesystem->chmod( $destination, 0600 );
			$manifest[$size] = array( 'file' => $file, 'original' => $rel, 'bytes' => strlen( $plain ) );
			$remove[] = $path;
		}
		if ( ! $manifest ) { return new WP_Error( 'empty', __( 'There are no photograph files to protect.', 'wp-my-family-tree' ) ); }
		update_post_meta( $id, '_wpmft_vault', $manifest ); update_post_meta( $id, '_wpmft_private', 1 );
		if ( self::manifest( $id ) !== $manifest ) { return new WP_Error( 'metadata', __( 'The private file index could not be saved. Originals were retained.', 'wp-my-family-tree' ) ); }
		foreach ( array_unique( $remove ) as $path ) { wp_delete_file( $path ); if ( file_exists( $path ) ) { return new WP_Error( 'delete_original', __( 'An original photograph could not be removed. Check file permissions before sharing the site.', 'wp-my-family-tree' ) ); } }
		update_post_meta( $id, '_wpmft_vault_complete', 1 );
		self::$private_ids = null;
		return true;
	}
	public static function needs_protection( $id ) {
		$manifest = self::manifest( $id );
		if ( ! $manifest || ! get_post_meta( $id, '_wpmft_vault_complete', true ) ) { return true; }
		$uploads = wp_upload_dir();
		foreach ( $manifest as $entry ) {
			if ( ! empty( $entry['original'] ) && file_exists( trailingslashit( $uploads['basedir'] ) . $entry['original'] ) ) { return true; }
		}
		$meta = wp_get_attachment_metadata( $id );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size => $data ) {
			if ( ! isset( $manifest[$size] ) && ! empty( $data['file'] ) && file_exists( trailingslashit( $uploads['basedir'] ) . dirname( get_post_meta( $id, '_wp_attached_file', true ) ) . '/' . $data['file'] ) ) { return true; }
		}
		return false;
	}
	public static function protect_person( $id ) {
		if ( ! wpmft_settings()['private_media'] || ! wpmft_is_person( $id ) ) { return; }
		$errors = array();
		foreach ( WPMFT_Data::gallery_ids( $id ) as $photo ) {
			if ( ! current_user_can( 'edit_post', $photo ) ) { $errors[] = __( 'A photograph could not be protected because you do not have permission to edit it. Ask an administrator to protect it or upload your own copy.', 'wp-my-family-tree' ); continue; }
			self::record_owner( $photo, $id );
			if ( ! self::needs_protection( $photo ) ) { continue; }
			$result = self::protect_attachment( $photo ); if ( is_wp_error( $result ) ) { $errors[] = $result->get_error_message(); }
		}
		if ( $errors && get_current_user_id() ) { set_transient( 'wpmft_notice_' . get_current_user_id(), implode( ' ', array_unique( $errors ) ), 60 ); }
	}
	public static function thumbnail_changed( $meta_id, $id, $key, $value ) { if ( in_array( $key, array( '_thumbnail_id', '_wpmft_gallery' ), true ) && wpmft_is_person( $id ) ) { self::protect_person( $id ); } }
	public static function protect_batch( $limit = 10 ) {
		foreach ( WPMFT_Data::ids( false ) as $person ) { foreach ( WPMFT_Data::gallery_ids( $person ) as $photo ) { self::record_owner( $photo, $person ); } }
		$pending = array_filter( self::private_ids(), function ( $id ) { return self::needs_protection( $id ); } );
		$processed = 0; $errors = array();
		foreach ( array_slice( $pending, 0, max( 1, min( 20, $limit ) ) ) as $id ) {
			$result = self::protect_attachment( $id );
			/* translators: 1: WordPress attachment ID, 2: the reason its protection failed. */
			if ( is_wp_error( $result ) ) { $errors[] = sprintf( __( 'Attachment %1$d: %2$s', 'wp-my-family-tree' ), $id, $result->get_error_message() ); } else { $processed++; }
		}
		return array( 'processed' => $processed, 'remaining' => max( 0, count( $pending ) - $processed ), 'errors' => $errors );
	}
	public static function rewrite_html( $html ) {
		$uploads = wp_upload_dir(); $replacements = array();
		foreach ( self::private_ids() as $id ) {
			$rel = get_post_meta( $id, '_wp_attached_file', true ); if ( ! $rel ) { continue; }
			$meta = wp_get_attachment_metadata( $id ); $paths = array( 'full' => $rel );
			foreach ( (array) ( $meta['sizes'] ?? array() ) as $size => $data ) { if ( isset( $data['file'] ) ) { $paths[$size] = dirname( $rel ) . '/' . $data['file']; } }
			foreach ( $paths as $size => $path ) {
				$url = trailingslashit( $uploads['baseurl'] ) . $path;
				$replacements[$url] = esc_url( self::url( $id, $size ) );
				$replacements[str_replace( 'https://', 'http://', $url )] = esc_url( self::url( $id, $size ) );
			}
		}
		$html = strtr( $html, $replacements );
		return preg_replace( '/\s+srcset\s*=\s*([\x22\x27]).*?\1/is', '', $html );
	}
	public static function delete_files( $id ) {
		$uploads = wp_upload_dir();
		foreach ( self::manifest( $id ) as $entry ) {
			if ( preg_match( '#^wpmft-private/[a-f0-9]+\.bin$#D', $entry['file'] ) ) { wp_delete_file( trailingslashit( $uploads['basedir'] ) . $entry['file'] ); }
		}
	}
	public static function guard_image_editor() {
		$id = isset( $_POST['postid'] ) ? absint( $_POST['postid'] ) : 0;
		if ( $id && self::manifest( $id ) && current_user_can( 'edit_post', $id ) ) { check_ajax_referer( 'image_editor-' . $id ); wp_send_json_error( array( 'message' => __( 'This photograph is encrypted. Download it from the family gallery, edit it on your device, and upload the revised photograph.', 'wp-my-family-tree' ) ), 409 ); }
	}
}
