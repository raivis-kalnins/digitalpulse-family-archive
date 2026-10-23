<?php
/** Private, single-photo contact attachments. @package WPMyFamilyTree */
defined( 'ABSPATH' ) || exit;

final class WPMFT_Contact_Upload {
	/** Only raster types with a local WordPress image decoder may be accepted. */
	private static function types() {
		return array( 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'avif' => 'image/avif' );
	}

	public static function config() {
		$s = wpmft_settings();
		$configured = max( 1, min( 100, (int) ( $s['contact_upload_max_mb'] ?? 10 ) ) );
		$max = min( $configured * MB_IN_BYTES, max( 0, (int) wp_max_upload_size() ) );
		$upload_max = wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) );
		$post_max = wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) );
		if ( $upload_max > 0 ) { $max = min( $max, $upload_max ); }
		// Reserve room for the bounded message, hCaptcha token, CSRF fields and multipart headers.
		if ( $post_max > 0 ) { $max = min( $max, max( 0, $post_max - 64 * KB_IN_BYTES ) ); }
		$requested = $s['contact_upload_formats'] ?? array( 'jpg', 'jpeg', 'png', 'webp' );
		$requested = is_array( $requested ) ? $requested : preg_split( '/[\s,]+/', (string) $requested );
		$extensions = array(); $supported = array();
		foreach ( self::types() as $extension => $mime ) {
			if ( ! in_array( $extension, $requested, true ) ) { continue; }
			if ( ! isset( $supported[$mime] ) ) { $supported[$mime] = wp_image_editor_supports( array( 'mime_type' => $mime ) ); }
			if ( $supported[$mime] ) { $extensions[] = $extension; }
		}
		return array(
			'enabled' => ! empty( $s['contact_upload_enabled'] ) && (bool) filter_var( ini_get( 'file_uploads' ), FILTER_VALIDATE_BOOLEAN ) && $max > 0 && (bool) $extensions,
			'maxBytes' => (int) $max, 'configuredMaxMb' => $configured, 'extensions' => $extensions,
			'accept' => implode( ',', array_map( function ( $extension ) { return '.' . $extension; }, $extensions ) ), 'maxFiles' => 1,
		);
	}

	private static function invalid() {
		return new WP_Error( 'photo_invalid', __( 'The photo could not be verified. Choose a valid image and try again.', 'my-family-tree' ) );
	}

	private static function too_large( $config ) {
		return new WP_Error( 'photo_size', sprintf(
			/* translators: %s: effective maximum upload size, such as 10 MB. */
			__( 'The photo is too large. The maximum file size is %s.', 'my-family-tree' ), size_format( $config['maxBytes'], 2 )
		) );
	}

	/**
	 * Validate only after the contact request has passed all CSRF, spam and captcha checks.
	 * Returns wp_mail's filename => path map; the file never leaves PHP's upload temporary storage.
	 *
	 * @return array|WP_Error An empty array means that no optional photo was supplied.
	 */
	public static function receive() {
		if ( empty( $_FILES ) ) { return array(); }
		if ( count( $_FILES ) !== 1 || ! isset( $_FILES['photo'] ) || ! is_array( $_FILES['photo'] ) ) {
			return new WP_Error( 'photo_count', __( 'Attach one photo only.', 'my-family-tree' ) );
		}
		$file = $_FILES['photo'];
		foreach ( array( 'name', 'tmp_name', 'size', 'error' ) as $key ) {
			if ( ! isset( $file[$key] ) || ! is_scalar( $file[$key] ) ) { return self::invalid(); }
		}
		if ( ! is_string( $file['name'] ) || ! is_string( $file['tmp_name'] ) || ! is_numeric( $file['size'] ) || ! is_numeric( $file['error'] ) ) { return self::invalid(); }
		$error = (int) $file['error'];
		if ( UPLOAD_ERR_NO_FILE === $error && $file['tmp_name'] === '' && (int) $file['size'] === 0 ) { return array(); }
		$config = self::config();
		if ( ! $config['enabled'] ) { return new WP_Error( 'photo_disabled', __( 'Photo attachments are not available. Remove the photo and send your message again.', 'my-family-tree' ) ); }
		if ( in_array( $error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) { return self::too_large( $config ); }
		if ( UPLOAD_ERR_PARTIAL === $error ) { return new WP_Error( 'photo_partial', __( 'The photo upload was interrupted. Please choose the photo again.', 'my-family-tree' ) ); }
		if ( UPLOAD_ERR_OK !== $error ) { return new WP_Error( 'photo_upload', __( 'The photo could not be uploaded. Please try again, or send your message without a photo.', 'my-family-tree' ) ); }
		$path = $file['tmp_name'];
		// Do not accept arbitrary local paths, even when their contents are valid images.
		if ( ! is_uploaded_file( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) { return self::invalid(); }
		$bytes = filesize( $path );
		if ( false === $bytes || $bytes < 1 || (int) $file['size'] !== $bytes ) { return self::invalid(); }
		if ( $bytes > $config['maxBytes'] ) { return self::too_large( $config ); }
		$name = $file['name'];
		if ( strlen( $name ) > 255 || preg_match( '/[\x00-\x1f\x7f\/\\\\]/', $name ) || preg_match( '/(?:^|\.)(?:php\d*|phtml|phar|cgi|pl|py|exe|com|bat|cmd|sh|ps1|vbs|js|html?|svgz?)(?:\.|$)/i', $name ) ) { return self::invalid(); }
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, $config['extensions'], true ) ) {
			return new WP_Error( 'photo_type', __( 'This photo format is not allowed. Choose one of the listed image formats.', 'my-family-tree' ) );
		}
		$types = self::types(); $mime = $types[$extension];
		// Ignore the browser's Content-Type. Match both the actual raster signature and its extension.
		$actual_mime = wp_get_image_mime( $path );
		$checked = wp_check_filetype_and_ext( $path, $name, array_intersect_key( $types, array_flip( $config['extensions'] ) ) );
		if ( $actual_mime !== $mime || $checked['type'] !== $mime || $checked['ext'] !== $extension || ! empty( $checked['proper_filename'] ) ) { return self::invalid(); }
		$dimensions = wp_getimagesize( $path );
		if ( ! is_array( $dimensions ) || empty( $dimensions[0] ) || empty( $dimensions[1] ) ) { return self::invalid(); }
		$pixels = (float) $dimensions[0] * (float) $dimensions[1];
		// Bound decompression before invoking GD/Imagick, even for a tiny compressed image.
		wp_raise_memory_limit( 'image' );
		$memory = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		$needed = $pixels * 6 + $bytes * 2 + 16 * MB_IN_BYTES;
		if ( max( $dimensions[0], $dimensions[1] ) > 16000 || $pixels > 50000000 || ( $memory > 0 && $needed > $memory - memory_get_usage( true ) ) ) {
			return new WP_Error( 'photo_dimensions', __( 'The photo is too large to process safely. Choose a smaller image.', 'my-family-tree' ) );
		}
		// A matching header alone is insufficient: require the local image decoder to load it.
		$editor = wp_get_image_editor( $path, array( 'mime_type' => $mime ) );
		if ( is_wp_error( $editor ) ) { return self::invalid(); }
		$loaded = $editor->get_size(); unset( $editor );
		if ( ! is_array( $loaded ) || empty( $loaded['width'] ) || empty( $loaded['height'] ) ) { return self::invalid(); }
		$stem = sanitize_file_name( pathinfo( $name, PATHINFO_FILENAME ) );
		$stem = function_exists( 'mb_strcut' ) ? mb_strcut( $stem, 0, 150, 'UTF-8' ) : substr( $stem, 0, 150 );
		// WordPress 6.2+ supports named attachments; our minimum WordPress version is 6.5.
		return array( 'photo-' . ( $stem ?: 'family' ) . '.' . $extension => $path );
	}

	/** Remove only genuine files uploaded in this request, including rejected multipart fields. */
	public static function cleanup() {
		foreach ( $_FILES as $file ) {
			if ( ! is_array( $file ) || ! isset( $file['tmp_name'] ) ) { continue; }
			$paths = (array) $file['tmp_name'];
			array_walk_recursive( $paths, function ( $path ) {
				if ( is_string( $path ) && is_uploaded_file( $path ) && is_file( $path ) ) {
					// This is the exact PHP upload path; no public file or filtered path is ever deleted.
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					unlink( $path );
				}
			} );
		}
	}
}
