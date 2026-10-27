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
		add_action( 'wpmft_person_saved', array( __CLASS__, 'queue_person' ), 120 );
		add_action( 'wpmft_optimize_image', array( __CLASS__, 'optimize_attachment' ) );
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'queue_upload' ), 30, 3 );
	}
	public static function private_ids() {
		if ( null !== self::$private_ids ) { return self::$private_ids; }
		$ids = get_posts( array(
			'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
			'meta_query' => array( 'relation' => 'OR', array( 'key' => '_wpmft_vault', 'compare' => 'EXISTS' ), array( 'key' => '_wpmft_private', 'compare' => 'EXISTS' ), array( 'key' => '_wpmft_optimized_images', 'compare' => 'EXISTS' ) ),
			'wpmft_internal' => true, 'suppress_filters' => false,
		) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Small private-media index cached for the request.
		$people = WPMFT_Data::ids( false );
		if ( $people ) {
			$children = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids', 'post_parent__in' => $people, 'wpmft_internal' => true, 'suppress_filters' => false ) );
			$ids = array_merge( $ids, $children );
		}
		if ( $ids ) { _prime_post_caches( array_unique( array_map( 'absint', $ids ) ), false, true ); }
		foreach ( $people as $person ) { $ids = array_merge( $ids, WPMFT_Data::referenced_image_ids( $person, false ) ); }
		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
		self::$private_ids = array_values( array_filter( $ids, static function ( $id ) { return ! self::is_public_demo_media( $id ); } ) );
		return self::$private_ids;
	}

	private static function is_public_demo_media( $id ) {
		return 'archival-public-domain' === (string) get_post_meta( absint( $id ), '_wpmft_demo_media_kind', true ) && class_exists( 'WPMFT_Demo' ) && WPMFT_Demo::is_demo_post( absint( $id ) ) && ! self::manifest( absint( $id ) );
	}

	public static function is_private( $id ) {
		if ( 'attachment' !== get_post_type( $id ) || self::is_public_demo_media( $id ) ) { return false; }
		if ( get_post_meta( $id, '_wpmft_private', true ) || self::manifest( $id ) || get_post_meta( $id, '_wpmft_optimized_images', true ) ) { return true; }
		return in_array( (int) $id, self::private_ids(), true );
	}
	public static function url( $id, $size = 'full' ) {
		$id = absint( $id );
		if ( self::is_public_demo_media( $id ) ) {
			$url = wp_get_attachment_image_url( $id, sanitize_key( $size ) );
			if ( ! $url ) { $url = wp_get_attachment_url( $id ); }
			return $url ? $url : '';
		}
		return add_query_arg( array( 'action' => 'wpmft_media', 'id' => $id, 'size' => sanitize_key( $size ) ), admin_url( 'admin-ajax.php' ) );
	}
	/** Responsive photographs always use the authenticated media endpoint. */
	public static function image_data( $id ) {
		if ( self::is_public_demo_media( $id ) ) {
			$meta = wp_get_attachment_metadata( $id );
			$full = wp_get_attachment_image_url( $id, 'full' ) ?: '';
			$medium = wp_get_attachment_image_url( $id, 'medium_large' ) ?: ( wp_get_attachment_image_url( $id, 'medium' ) ?: $full );
			$srcset = wp_get_attachment_image_srcset( $id, 'large' );
			return array( 'url' => esc_url_raw( $full ), 'thumb' => esc_url_raw( $medium ), 'display' => esc_url_raw( wp_get_attachment_image_url( $id, 'large' ) ?: $full ), 'width' => absint( $meta['width'] ?? 0 ), 'height' => absint( $meta['height'] ?? 0 ), 'srcset' => is_string( $srcset ) ? $srcset : '', 'sources' => array() );
		}
		$meta = wp_get_attachment_metadata( $id ); $vault = self::manifest( $id );
		$width = absint( $meta['width'] ?? 0 ); $height = absint( $meta['height'] ?? 0 );
		$candidates = array();
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size => $row ) {
			$w = absint( $row['width'] ?? 0 ); $h = absint( $row['height'] ?? 0 );
			// A cropped thumbnail cannot share a width-descriptor source set with the complete photograph.
			if ( ! $w || ! $h || ! $width || ! $height || abs( $w / $h - $width / $height ) > 0.02 || ( $vault && ! isset( $vault[$size] ) ) ) { continue; }
			$candidates[$w] = self::url( $id, $size );
		}
		if ( $width ) { $candidates[$width] = self::url( $id ); }
		ksort( $candidates, SORT_NUMERIC );
		$thumb = self::url( $id, 'medium' ); $display = self::url( $id ); $srcset = array();
		foreach ( $candidates as $w => $url ) {
			if ( $w <= 1600 ) { $srcset[] = $url . ' ' . $w . 'w'; }
			if ( $w <= 1600 || $display === self::url( $id ) ) { $display = $url; }
		}
		if ( ! $srcset && $candidates ) { reset( $candidates ); $srcset[] = current( $candidates ) . ' ' . key( $candidates ) . 'w'; }
		foreach ( $candidates as $w => $url ) { $thumb = $url; if ( $w >= 320 ) { break; } }
		$sources = array();
		$optimized = get_post_meta( $id, '_wpmft_optimized_images', true );
		if ( 'off' !== ( wpmft_settings()['image_optimization'] ?? 'auto' ) && is_array( $optimized ) ) {
			foreach ( (array) ( $optimized['variants'] ?? array() ) as $size => $row ) {
				if ( ! in_array( $row['mime'] ?? '', array( 'image/avif', 'image/webp' ), true ) || empty( $row['width'] ) || ! preg_match( '/^wpmft-opt-[a-f0-9]{12}-[0-9]+$/D', $size ) || ! self::variant_available( $row ) ) { continue; }
				$sources[$row['mime']][] = self::url( $id, $size ) . ' ' . absint( $row['width'] ) . 'w';
			}
		}
		$result = array( 'url' => self::url( $id ), 'thumb' => $thumb, 'display' => $display, 'width' => $width, 'height' => $height, 'srcset' => implode( ', ', $srcset ), 'sources' => array() );
		foreach ( $sources as $mime => $set ) { $result['sources'][] = array( 'type' => $mime, 'srcset' => implode( ', ', $set ) ); }
		return $result;
	}

	public static function optimization_support() {
		static $support;
		if ( null === $support ) {
			$support = array( 'avif' => wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ), 'webp' => wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) );
		}
		return $support;
	}

	private static function optimization_signature( $id ) {
		$meta = wp_get_attachment_metadata( $id ); $vault = self::manifest( $id ); $s = wpmft_settings();
		$file = get_attached_file( $id, true );
		return hash( 'sha256', wp_json_encode( array( 1, get_post_mime_type( $id ), $meta, $vault['full'] ?? array(), $file && is_file( $file ) ? array( filemtime( $file ), filesize( $file ) ) : array(), max( 60, min( 92, (int) ( $s['image_quality'] ?? 82 ) ) ), self::optimization_support() ) ) );
	}

	private static function needs_optimization( $id ) {
		if ( 'off' === ( wpmft_settings()['image_optimization'] ?? 'auto' ) || ! in_array( get_post_mime_type( $id ), array( 'image/jpeg', 'image/png' ), true ) ) { return false; }
		$stored = get_post_meta( $id, '_wpmft_optimized_images', true );
		if ( ! is_array( $stored ) || ( $stored['signature'] ?? '' ) !== self::optimization_signature( $id ) || ! is_array( $stored['variants'] ?? null ) ) { return true; }
		// A matching index is not complete when its generated files were removed or only partly restored.
		foreach ( $stored['variants'] as $row ) { if ( ! self::variant_available( $row ) ) { return true; } }
		return false;
	}

	public static function queue_person( $id ) {
		if ( ! wpmft_is_person( $id ) || 'off' === ( wpmft_settings()['image_optimization'] ?? 'auto' ) ) { return; }
		$support = self::optimization_support(); if ( ! $support['avif'] && ! $support['webp'] ) { return; }
		foreach ( WPMFT_Data::referenced_image_ids( $id ) as $photo ) {
			if ( self::needs_optimization( $photo ) && ! wp_next_scheduled( 'wpmft_optimize_image', array( $photo ) ) ) { wp_schedule_single_event( time() + 15, 'wpmft_optimize_image', array( $photo ) ); }
		}
	}

	public static function queue_upload( $meta, $id, $context = 'create' ) {
		$parent = (int) get_post_field( 'post_parent', $id );
		if ( ! in_array( get_post_mime_type( $id ), array( 'image/jpeg', 'image/png' ), true ) ) { return $meta; }
		$support = self::optimization_support(); if ( ! $support['avif'] && ! $support['webp'] ) { return $meta; }
		if ( $parent && wpmft_is_person( $parent ) && 'off' !== ( wpmft_settings()['image_optimization'] ?? 'auto' ) && ! wp_next_scheduled( 'wpmft_optimize_image', array( (int) $id ) ) ) { wp_schedule_single_event( time() + 15, 'wpmft_optimize_image', array( (int) $id ) ); }
		return $meta;
	}

	/** One private temporary directory; plaintext is never written below the website or uploads folder. */
	private static function temporary_directory() {
		$base = realpath( get_temp_dir() ); $website = realpath( ABSPATH ); $uploads = wp_upload_dir();
		$media = realpath( $uploads['basedir'] );
		// Also cover installations in a subdirectory of the public document root.
		$document_root = ! empty( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) : false;
		if ( ! $base ) { return false; }
		foreach ( array_filter( array( $website, $media, $document_root ) ) as $public_path ) {
			if ( $base === $public_path || strpos( $base, rtrim( $public_path, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) === 0 ) { return false; }
		}
		$path = trailingslashit( $base ) . 'wpmft-image-' . bin2hex( random_bytes( 16 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Private temporary directory outside the public document root.
		return mkdir( $path, 0700 ) ? $path : false;
	}

	private static function delete_variants( $variants ) {
		$uploads = wp_upload_dir();
		foreach ( (array) $variants as $row ) {
			if ( is_array( $row ) && isset( $row['file'] ) && preg_match( '#^wpmft-private/[a-f0-9]+\.bin$#D', $row['file'] ) ) { wp_delete_file( trailingslashit( $uploads['basedir'] ) . $row['file'] ); }
		}
	}

	/** Check ciphertext availability without decrypting or encoding on a visitor request. */
	private static function variant_available( $row ) {
		if ( ! is_array( $row ) || ! is_string( $row['file'] ?? null ) || ! preg_match( '#^wpmft-private/[a-f0-9]+\.bin$#D', $row['file'] ) || empty( $row['bytes'] ) ) { return false; }
		$uploads = wp_upload_dir(); $base = realpath( $uploads['basedir'] ); $path = realpath( trailingslashit( $uploads['basedir'] ) . $row['file'] );
		if ( ! $base || ! $path || strpos( $path, $base . DIRECTORY_SEPARATOR . 'wpmft-private' . DIRECTORY_SEPARATOR ) !== 0 ) { return false; }
		clearstatcache( true, $path );
		return is_file( $path ) && is_readable( $path ) && filesize( $path ) === (int) $row['bytes'] + 35;
	}

	/** Validate real encoded bytes: some host encoders report success while writing an empty file. */
	private static function encode_previews( $source, $directory, $mime, $quality, $original_bytes ) {
		$editor = wp_get_image_editor( $source, array( 'output_mime_type' => $mime ) );
		if ( is_wp_error( $editor ) ) { return $editor; }
		if ( ! $editor->supports_mime_type( $mime ) ) { return new WP_Error( 'image_format', __( 'The image editor returned an unexpected image format.', 'digitalpulse-family-archive' ) ); }
		$editor->set_quality( $quality ); $previews = array(); $seen = array();
		foreach ( array( 1600, 960, 384 ) as $target ) {
			$size = $editor->get_size();
			if ( max( $size['width'], $size['height'] ) > $target ) {
				$resized = $editor->resize( $target, $target, false ); if ( is_wp_error( $resized ) ) { return $resized; }
			}
			$size = $editor->get_size(); $dimensions_key = $size['width'] . 'x' . $size['height'];
			if ( isset( $seen[$dimensions_key] ) ) { continue; }
			$seen[$dimensions_key] = true;
			// Native GD may warn before returning failure. Convert only that expected encoder warning
			// into the normal fallback path, keeping unrelated errors and the site's handler intact.
			$encoder_warning = false; $previous_handler = null;
			$previous_handler = set_error_handler( static function ( $number, $message, $file, $line ) use ( &$encoder_warning, &$previous_handler, $mime ) {
				if ( E_WARNING === $number && 0 === strpos( $message, 'image/avif' === $mime ? 'imageavif():' : 'imagewebp():' ) ) { $encoder_warning = true; return true; }
				return $previous_handler ? call_user_func( $previous_handler, $number, $message, $file, $line ) : false;
			} );
			try { $saved = $editor->save( $directory . '/preview-' . $target . ( 'image/avif' === $mime ? '.avif' : '.webp' ), $mime ); }
			finally { restore_error_handler(); }
			if ( $encoder_warning ) { return new WP_Error( 'image_format', __( 'The image editor returned an unexpected image format.', 'digitalpulse-family-archive' ) ); }
			if ( is_wp_error( $saved ) ) { return $saved; }
			if ( ( $saved['mime-type'] ?? '' ) !== $mime || realpath( dirname( $saved['path'] ) ) !== realpath( $directory ) ) { return new WP_Error( 'image_format', __( 'The image editor returned an unexpected image format.', 'digitalpulse-family-archive' ) ); }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Encoded plaintext remains private until encrypted.
			if ( ! chmod( $saved['path'], 0600 ) ) { return new WP_Error( 'image_temp', __( 'The temporary photograph could not be prepared.', 'digitalpulse-family-archive' ) ); }
			clearstatcache( true, $saved['path'] );
			$length = filesize( $saved['path'] );
			$dimensions = $length && wp_get_image_mime( $saved['path'] ) === $mime ? wp_getimagesize( $saved['path'] ) : false;
			if ( ! $dimensions || (int) $dimensions[0] !== (int) $saved['width'] || (int) $dimensions[1] !== (int) $saved['height'] ) { return new WP_Error( 'image_format', __( 'The image editor returned an unexpected image format.', 'digitalpulse-family-archive' ) ); }
			if ( $length + 35 >= $original_bytes ) { continue; }
			$previews[absint( $saved['width'] )] = array( 'path' => $saved['path'], 'width' => absint( $saved['width'] ), 'height' => absint( $saved['height'] ), 'bytes' => $length, 'mime' => $mime );
		}
		return $previews;
	}

	/** Generate at most three sizes away from visitor requests, preserving the source and its existing vault. */
	public static function optimize_attachment( $id ) {
		$id = absint( $id );
		if ( ! $id || self::is_public_demo_media( $id ) || ! self::is_private( $id ) || ! self::needs_optimization( $id ) ) { return true; }
		$support = self::optimization_support(); $mime = $support['avif'] ? 'image/avif' : ( $support['webp'] ? 'image/webp' : '' );
		if ( ! $mime ) { return new WP_Error( 'image_encoder', __( 'This server does not support AVIF or WebP encoding. Existing photographs remain available.', 'digitalpulse-family-archive' ) ); }
		if ( ! function_exists( 'openssl_encrypt' ) ) { return new WP_Error( 'openssl', __( 'OpenSSL is required to protect photographs.', 'digitalpulse-family-archive' ) ); }
		$lock_name = 'wpmft_image_lock_' . $id; $token = time() . ':' . wp_generate_uuid4(); $previous = (string) get_option( $lock_name, '' );
		global $wpdb;
		if ( $previous && (int) $previous < time() - 600 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-delete an expired per-image lock only.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $lock_name, $previous ) );
			wp_cache_delete( $lock_name, 'options' );
		}
		if ( ! add_option( $lock_name, $token, '', false ) ) { return new WP_Error( 'image_busy', __( 'This photograph is already being optimized. Please try again shortly.', 'digitalpulse-family-archive' ) ); }
		$directory = false; $created = array(); $committed = false; $quality_filter = null;
		try {
			$signature = self::optimization_signature( $id ); $vault = self::manifest( $id );
			$source = get_attached_file( $id, true ); $uploads = wp_upload_dir(); $base = realpath( $uploads['basedir'] );
			$path = $source ? realpath( $source ) : false;
			$bytes = isset( $vault['full']['file'] ) ? self::decrypt( $vault['full']['file'] ) : ( $base && $path && strpos( $path, $base . DIRECTORY_SEPARATOR ) === 0 && is_readable( $path ) ? file_get_contents( $path ) : false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local image, never a remote URL.
			if ( false === $bytes ) { return new WP_Error( 'image_source', __( 'The source photograph is unavailable. Existing previews are retained.', 'digitalpulse-family-archive' ) ); }
			$meta = wp_get_attachment_metadata( $id );
			if ( strlen( $bytes ) > 32 * MB_IN_BYTES || (int) ( $meta['width'] ?? 0 ) * (int) ( $meta['height'] ?? 0 ) > 40000000 ) { return new WP_Error( 'image_size', __( 'This photograph exceeds the safe optimization size. Its existing images remain available.', 'digitalpulse-family-archive' ) ); }
			$old = get_post_meta( $id, '_wpmft_optimized_images', true );
			// Animated PNG remains untouched, as do GIF/WebP/AVIF files excluded above.
			if ( 'image/png' === get_post_mime_type( $id ) && false !== strpos( $bytes, 'acTL' ) ) {
				if ( self::optimization_signature( $id ) !== $signature ) { return new WP_Error( 'image_changed', __( 'This photograph changed during optimization. Please try again.', 'digitalpulse-family-archive' ) ); }
				$record = array( 'signature' => $signature, 'variants' => array() );
				update_post_meta( $id, '_wpmft_optimized_images', $record );
				if ( get_post_meta( $id, '_wpmft_optimized_images', true ) !== $record ) { return new WP_Error( 'image_metadata', __( 'The optimized photograph index could not be saved.', 'digitalpulse-family-archive' ) ); }
				self::delete_variants( $old['variants'] ?? array() );
				return true;
			}
			$directory = self::temporary_directory();
			if ( ! $directory ) { return new WP_Error( 'image_temp', __( 'Image optimization needs a private temporary directory outside the website folder.', 'digitalpulse-family-archive' ) ); }
			$source_file = $directory . '/source.' . ( 'image/png' === get_post_mime_type( $id ) ? 'png' : 'jpg' );
			if ( file_put_contents( $source_file, $bytes, LOCK_EX ) !== strlen( $bytes ) ) { return new WP_Error( 'image_temp', __( 'The temporary photograph could not be prepared.', 'digitalpulse-family-archive' ) ); }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Plaintext is readable only by the private temporary directory owner.
			if ( ! chmod( $source_file, 0600 ) ) { return new WP_Error( 'image_temp', __( 'The temporary photograph could not be prepared.', 'digitalpulse-family-archive' ) ); }
			$quality = max( 60, min( 92, (int) ( wpmft_settings()['image_quality'] ?? 82 ) ) );
			// Core resets quality when converting formats; keep the chosen quality through every save.
			$quality_filter = static function ( $value ) use ( $quality ) { return $quality; };
			add_filter( 'wp_editor_set_quality', $quality_filter, PHP_INT_MAX );
			$previews = self::encode_previews( $source_file, $directory, $mime, $quality, strlen( $bytes ) );
			// A complete WebP set is preferable to a broken or undersized AVIF source set.
			if ( is_wp_error( $previews ) && 'image/avif' === $mime && $support['webp'] ) { $previews = self::encode_previews( $source_file, $directory, 'image/webp', $quality, strlen( $bytes ) ); }
			if ( is_wp_error( $previews ) ) { return $previews; }
			$key = self::key( true );
			if ( ! $key || ! wp_mkdir_p( trailingslashit( $uploads['basedir'] ) . 'wpmft-private' ) ) { return new WP_Error( 'image_vault', __( 'The optimized photograph could not be stored privately.', 'digitalpulse-family-archive' ) ); }
			foreach ( $previews as $preview ) {
				$plain = file_get_contents( $preview['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Private temporary image.
				if ( false === $plain ) { return new WP_Error( 'image_read', __( 'The optimized photograph could not be read.', 'digitalpulse-family-archive' ) ); }
				$variant = 'wpmft-opt-' . substr( $signature, 0, 12 ) . '-' . $preview['width'];
				$iv = random_bytes( 12 ); $tag = ''; $cipher = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
				if ( false === $cipher ) { return new WP_Error( 'image_encrypt', __( 'The optimized photograph could not be encrypted.', 'digitalpulse-family-archive' ) ); }
				$file = 'wpmft-private/' . bin2hex( random_bytes( 24 ) ) . '.bin'; $destination = trailingslashit( $uploads['basedir'] ) . $file;
				$created[$variant] = array( 'file' => $file, 'width' => $preview['width'], 'height' => $preview['height'], 'bytes' => strlen( $plain ), 'mime' => $preview['mime'] );
				$container = "WPMFT1\0" . $iv . $tag . $cipher;
				if ( file_put_contents( $destination, $container, LOCK_EX ) !== strlen( $container ) || self::decrypt( $file ) !== $plain ) { return new WP_Error( 'image_verify', __( 'The optimized photograph could not be verified.', 'digitalpulse-family-archive' ) ); }
			}
			if ( self::optimization_signature( $id ) !== $signature ) { return new WP_Error( 'image_changed', __( 'This photograph changed during optimization. Please try again.', 'digitalpulse-family-archive' ) ); }
			$record = array( 'signature' => $signature, 'variants' => $created );
			update_post_meta( $id, '_wpmft_optimized_images', $record );
			if ( get_post_meta( $id, '_wpmft_optimized_images', true ) !== $record ) { return new WP_Error( 'image_metadata', __( 'The optimized photograph index could not be saved.', 'digitalpulse-family-archive' ) ); }
			$committed = true;
			self::delete_variants( $old['variants'] ?? array() );
			return true;
		} catch ( Throwable $error ) {
			return new WP_Error( 'image_processing', __( 'The photograph could not be optimized. Existing images are retained.', 'digitalpulse-family-archive' ) );
		} finally {
			if ( $quality_filter ) { remove_filter( 'wp_editor_set_quality', $quality_filter, PHP_INT_MAX ); }
			if ( ! $committed ) { self::delete_variants( $created ); }
			if ( $directory ) {
				foreach ( glob( $directory . '/*' ) ?: array() as $temporary ) { wp_delete_file( $temporary ); }
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove the private temporary directory after all encoding paths.
				rmdir( $directory );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Release only this task's lock.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $lock_name, $token ) );
			wp_cache_delete( $lock_name, 'options' );
		}
	}

	/** Status is read-only, so refresh can recover real archive counts without starting conversion. */
	public static function optimization_status() {
		if ( ! current_user_can( 'manage_options' ) ) { return array( 'pending' => 0, 'total' => 0 ); }
		$eligible = array_filter( self::private_ids(), static function ( $id ) { return in_array( get_post_mime_type( $id ), array( 'image/jpeg', 'image/png' ), true ); } );
		return array( 'pending' => count( array_filter( $eligible, array( __CLASS__, 'needs_optimization' ) ) ), 'total' => count( $eligible ) );
	}

	public static function optimize_batch( $limit = 1, $after = 0 ) {
		$result = array( 'processed' => 0, 'attempted' => 0, 'cursor' => absint( $after ), 'remaining' => 0, 'errors' => array() );
		if ( ! current_user_can( 'manage_options' ) ) { $result['errors'][] = __( 'Access denied.', 'digitalpulse-family-archive' ); return $result; }
		if ( 'off' === ( wpmft_settings()['image_optimization'] ?? 'auto' ) ) { $result['errors'][] = __( 'Image optimization is disabled. Enable automatic optimization and save the settings first.', 'digitalpulse-family-archive' ); return $result; }
		if ( ! array_filter( self::optimization_support() ) ) { $result['errors'][] = __( 'This server does not support AVIF or WebP encoding. Existing photographs remain available.', 'digitalpulse-family-archive' ); return $result; }
		$pending = array_values( array_filter( self::private_ids(), static function ( $id ) use ( $result ) { return $id > $result['cursor'] && self::needs_optimization( $id ); } ) );
		sort( $pending, SORT_NUMERIC );
		foreach ( array_slice( $pending, 0, max( 1, min( 3, (int) $limit ) ) ) as $id ) {
			$optimized = self::optimize_attachment( $id );
			$result['attempted']++; $result['cursor'] = (int) $id;
			// Failed or busy files advance this pass, but remain pending for an explicit retry later.
			if ( is_wp_error( $optimized ) ) {
				/* translators: 1: WordPress attachment ID, 2: the reason its optimization failed. */
				$result['errors'][] = sprintf( __( 'Attachment %1$d: %2$s', 'digitalpulse-family-archive' ), $id, $optimized->get_error_message() );
			} else { $result['processed']++; }
		}
		$result['remaining'] = max( 0, count( $pending ) - $result['attempted'] );
		return $result;
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
		$manifest = self::manifest( $id ); $meta = wp_get_attachment_metadata( $id );
		$optimized = get_post_meta( $id, '_wpmft_optimized_images', true ); $optimized_request = strpos( $size, 'wpmft-opt-' ) === 0;
		$entry = $optimized_request ? ( $optimized['variants'][$size] ?? null ) : ( $manifest[$size] ?? ( $manifest['full'] ?? null ) );
		if ( $optimized_request && ! $entry ) { status_header( 404 ); exit; }
		$actual_size = $entry && ! $optimized_request && ! isset( $manifest[$size] ) ? 'full' : $size;
		$mime = $entry['mime'] ?? ( $meta['sizes'][$actual_size]['mime-type'] ?? get_post_mime_type( $id ) );
		if ( $entry ) { $bytes = self::decrypt( $entry['file'] ); }
		else {
			// Existing family imports remain readable while an administrator protects their files.
			$uploads = wp_upload_dir();
			$relative = get_post_meta( $id, '_wp_attached_file', true );
			if ( isset( $meta['sizes'][$size]['file'] ) ) { $relative = dirname( $relative ) . '/' . $meta['sizes'][$size]['file']; }
			$base = realpath( $uploads['basedir'] ); $file = realpath( $uploads['basedir'] . '/' . $relative );
			$bytes = $base && $file && strpos( $file, $base . DIRECTORY_SEPARATOR ) === 0 && is_readable( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		// Older imports or third-party converters may remove a WordPress derivative while its index remains.
		// Fall back only for ordinary sizes after all access checks; unknown optimized keys still return 404.
		if ( ( false === $bytes || '' === $bytes ) && ! $optimized_request && 'full' !== $size ) {
			if ( isset( $manifest['full']['file'] ) ) { $bytes = self::decrypt( $manifest['full']['file'] ); }
			else {
				$uploads = wp_upload_dir(); $base = realpath( $uploads['basedir'] ); $file = get_attached_file( $id, true ); $file = $file ? realpath( $file ) : false;
				$bytes = $base && $file && strpos( $file, $base . DIRECTORY_SEPARATOR ) === 0 && is_readable( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Authenticated local original.
			}
			$mime = $manifest['full']['mime'] ?? get_post_mime_type( $id );
		}
		if ( false === $bytes || ( '' === $bytes && 0 === strpos( $mime, 'image/' ) ) ) { status_header( 404 ); exit; }
		$allowed = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/x-icon', 'image/vnd.microsoft.icon' );
		$inline = in_array( $mime, $allowed, true );
		header( 'Content-Type: ' . ( $inline ? $mime : 'application/octet-stream' ) ); header( 'Content-Length: ' . strlen( $bytes ) );
		// Other attachments protected in a batch remain downloadable; never render active content inline.
		$original = (string) get_post_meta( $id, '_wp_attached_file', true );
		$extension = strtolower( preg_replace( '/[^a-z0-9]/i', '', pathinfo( $original, PATHINFO_EXTENSION ) ) );
		$extensions = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif' );
		if ( isset( $extensions[$mime] ) ) { $extension = $extensions[$mime]; }
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
			if ( 'publish' === get_post_status( $person ) && wpmft_is_person( $person ) && in_array( (int) $id, WPMFT_Data::referenced_image_ids( $person, false ), true ) ) { return true; }
		}
		// Legacy, not-yet-encrypted imports: check stored references, never a visitor-supplied owner ID.
		if ( ! self::manifest( $id ) ) {
			foreach ( WPMFT_Data::ids() as $person ) { if ( in_array( (int) $id, WPMFT_Data::referenced_image_ids( $person, false ), true ) ) { return true; } }
		}
		return false;
	}
	private static function record_owner( $photo, $person ) {
		$owners = get_post_meta( $photo, '_wpmft_media_people', true );
		$owners = is_array( $owners ) ? array_map( 'absint', $owners ) : array();
		if ( ! in_array( (int) $person, $owners, true ) ) { $owners[] = (int) $person; update_post_meta( $photo, '_wpmft_media_people', $owners ); }
	}
	public static function protect_attachment( $id ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) { return new WP_Error( 'openssl', __( 'OpenSSL is required to protect photographs.', 'digitalpulse-family-archive' ) ); }
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) { return new WP_Error( 'uploads', $uploads['error'] ); }
		$key = self::key( true );
		if ( ! $key ) { return new WP_Error( 'key', __( 'The private media key could not be created.', 'digitalpulse-family-archive' ) ); }
		update_post_meta( $id, '_wpmft_vault_complete', 0 );
		$relative = (string) get_post_meta( $id, '_wp_attached_file', true );
		if ( ! $relative ) { return new WP_Error( 'missing_file', __( 'An attachment has no source file.', 'digitalpulse-family-archive' ) ); }
		$meta = wp_get_attachment_metadata( $id ); $dir = dirname( $relative );
		$files = array( 'full' => $relative );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size => $info ) { if ( ! empty( $info['file'] ) ) { $files[$size] = $dir . '/' . $info['file']; } }
		if ( ! empty( $meta['original_image'] ) ) { $files['original'] = $dir . '/' . $meta['original_image']; }
		foreach ( (array) get_post_meta( $id, '_wp_attachment_backup_sizes', true ) as $size => $info ) { if ( is_array( $info ) && ! empty( $info['file'] ) ) { $files['backup_' . $size] = $dir . '/' . $info['file']; } }
		$vault = trailingslashit( $uploads['basedir'] ) . 'wpmft-private';
		if ( ! wp_mkdir_p( $vault ) ) { return new WP_Error( 'directory', __( 'The private media folder could not be created.', 'digitalpulse-family-archive' ) ); }
		// The ciphertext is safe even where these extra web-server rules are not supported.
		if ( ! file_exists( $vault . '/.htaccess' ) ) { file_put_contents( $vault . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); }
		if ( ! file_exists( $vault . '/index.php' ) ) { file_put_contents( $vault . '/index.php', '<?php http_response_code(404); exit;' ); }
		$manifest = self::manifest( $id ); $base = realpath( $uploads['basedir'] ); $remove = array();
		foreach ( $files as $size => $rel ) {
			$path = realpath( trailingslashit( $uploads['basedir'] ) . $rel );
			if ( ! $path || ! $base || strpos( $path, $base . DIRECTORY_SEPARATOR ) !== 0 ) {
				if ( $size === 'full' && ! isset( $manifest['full'] ) ) { return new WP_Error( 'missing_file', __( 'A source photograph is missing. Restore the matching uploads backup.', 'digitalpulse-family-archive' ) ); }
				continue;
			}
			$plain = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $plain ) { return new WP_Error( 'read', __( 'A photograph could not be read.', 'digitalpulse-family-archive' ) ); }
			if ( isset( $manifest[$size] ) && self::decrypt( $manifest[$size]['file'] ) === $plain ) { $remove[] = $path; continue; }
			$iv = random_bytes( 12 ); $tag = '';
			$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false === $cipher ) { return new WP_Error( 'encrypt', __( 'A photograph could not be encrypted.', 'digitalpulse-family-archive' ) ); }
			$file = 'wpmft-private/' . bin2hex( random_bytes( 24 ) ) . '.bin';
			$destination = trailingslashit( $uploads['basedir'] ) . $file;
			$container = "WPMFT1\0" . $iv . $tag . $cipher;
			if ( file_put_contents( $destination, $container, LOCK_EX ) !== strlen( $container ) || self::decrypt( $file ) !== $plain ) { wp_delete_file( $destination ); return new WP_Error( 'write', __( 'The encrypted photograph could not be verified.', 'digitalpulse-family-archive' ) ); }
			if ( ! class_exists( 'WP_Filesystem_Base' ) ) { require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php'; }
			if ( ! class_exists( 'WP_Filesystem_Direct' ) ) { require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php'; }
			$filesystem = new WP_Filesystem_Direct( null );
			$filesystem->chmod( $destination, 0600 );
			$manifest[$size] = array( 'file' => $file, 'original' => $rel, 'bytes' => strlen( $plain ) );
			$remove[] = $path;
		}
		if ( ! $manifest ) { return new WP_Error( 'empty', __( 'There are no photograph files to protect.', 'digitalpulse-family-archive' ) ); }
		update_post_meta( $id, '_wpmft_vault', $manifest ); update_post_meta( $id, '_wpmft_private', 1 );
		if ( self::manifest( $id ) !== $manifest ) { return new WP_Error( 'metadata', __( 'The private file index could not be saved. Originals were retained.', 'digitalpulse-family-archive' ) ); }
		foreach ( array_unique( $remove ) as $path ) { wp_delete_file( $path ); if ( file_exists( $path ) ) { return new WP_Error( 'delete_original', __( 'An original photograph could not be removed. Check file permissions before sharing the site.', 'digitalpulse-family-archive' ) ); } }
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
		foreach ( WPMFT_Data::referenced_image_ids( $id ) as $photo ) {
			if ( self::is_public_demo_media( $photo ) ) { continue; }
			if ( ! current_user_can( 'edit_post', $photo ) ) { $errors[] = __( 'A photograph could not be protected because you do not have permission to edit it. Ask an administrator to protect it or upload your own copy.', 'digitalpulse-family-archive' ); continue; }
			self::record_owner( $photo, $id );
			if ( ! self::needs_protection( $photo ) ) { continue; }
			$result = self::protect_attachment( $photo ); if ( is_wp_error( $result ) ) { $errors[] = $result->get_error_message(); }
		}
		if ( $errors && get_current_user_id() ) { set_transient( 'wpmft_notice_' . get_current_user_id(), implode( ' ', array_unique( $errors ) ), 60 ); }
	}
	public static function thumbnail_changed( $meta_id, $id, $key, $value ) { if ( in_array( $key, array( '_thumbnail_id', '_wpmft_gallery' ), true ) && wpmft_is_person( $id ) ) { self::protect_person( $id ); self::queue_person( $id ); } }
	public static function protect_batch( $limit = 10 ) {
		foreach ( WPMFT_Data::ids( false ) as $person ) { foreach ( WPMFT_Data::referenced_image_ids( $person ) as $photo ) { self::record_owner( $photo, $person ); } }
		$pending = array_filter( self::private_ids(), function ( $id ) { return self::needs_protection( $id ); } );
		$processed = 0; $errors = array();
		foreach ( array_slice( $pending, 0, max( 1, min( 20, $limit ) ) ) as $id ) {
			$result = self::protect_attachment( $id );
			/* translators: 1: WordPress attachment ID, 2: the reason its protection failed. */
			if ( is_wp_error( $result ) ) { $errors[] = sprintf( __( 'Attachment %1$d: %2$s', 'digitalpulse-family-archive' ), $id, $result->get_error_message() ); } else { $processed++; }
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
		$optimized = get_post_meta( $id, '_wpmft_optimized_images', true );
		self::delete_variants( $optimized['variants'] ?? array() );
		wp_clear_scheduled_hook( 'wpmft_optimize_image', array( (int) $id ) );
		foreach ( self::manifest( $id ) as $entry ) {
			if ( preg_match( '#^wpmft-private/[a-f0-9]+\.bin$#D', $entry['file'] ) ) { wp_delete_file( trailingslashit( $uploads['basedir'] ) . $entry['file'] ); }
		}
	}
	public static function guard_image_editor() {
		$id = isset( $_POST['postid'] ) ? absint( $_POST['postid'] ) : 0;
		if ( $id && self::manifest( $id ) && current_user_can( 'edit_post', $id ) ) { check_ajax_referer( 'image_editor-' . $id ); wp_send_json_error( array( 'message' => __( 'This photograph is encrypted. Download it from the family gallery, edit it on your device, and upload the revised photograph.', 'digitalpulse-family-archive' ) ), 409 ); }
	}
}
