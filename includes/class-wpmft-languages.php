<?php
/**
 * Extensible interface languages. Content translation remains optional via Polylang.
 *
 * @package WPMyFamilyTree
 */

defined( 'ABSPATH' ) || exit;

final class WPMFT_Languages {

	private static $catalogs = array();
	private static $labels = array();
	private static $request_language = '';

	public static function init() {
		add_action( 'admin_post_wpmft_save_languages', array( __CLASS__, 'save' ) );
		add_filter( 'gettext_my-family-tree', array( __CLASS__, 'translate_request' ), 20, 3 );
	}

	/** Presets have no effect on the site's WordPress or content language. */
	public static function presets() {
		return array(
			'en' => array( 'name' => 'English', 'locale' => 'en_US' ),
			'es' => array( 'name' => 'Español', 'locale' => 'es_ES' ),
			'pl' => array( 'name' => 'Polski', 'locale' => 'pl_PL' ),
			'fr' => array( 'name' => 'Français', 'locale' => 'fr_FR' ),
			'de' => array( 'name' => 'Deutsch', 'locale' => 'de_DE' ),
			'lv' => array( 'name' => 'Latviešu', 'locale' => 'lv' ),
			'lt' => array( 'name' => 'Lietuvių', 'locale' => 'lt_LT' ),
			'et' => array( 'name' => 'Eesti', 'locale' => 'et' ),
			'da' => array( 'name' => 'Dansk', 'locale' => 'da_DK' ),
			'sv' => array( 'name' => 'Svenska', 'locale' => 'sv_SE' ),
			'nb' => array( 'name' => 'Norsk bokmål', 'locale' => 'nb_NO' ),
			'nn' => array( 'name' => 'Norsk nynorsk', 'locale' => 'nn_NO' ),
			'fi' => array( 'name' => 'Suomi', 'locale' => 'fi' ),
			'is' => array( 'name' => 'Íslenska', 'locale' => 'is_IS' ),
		);
	}

	public static function valid_slug( $slug ) {
		return is_string( $slug ) && 1 === preg_match( '/^[a-z]{2,3}(?:[-_][a-z0-9]{2,8})*$/D', $slug ) && strlen( $slug ) <= 35;
	}

	/** A language list is unbounded; validation limits each individual value only. */
	public static function registry( $enabled_only = true ) {
		$all = array();
		foreach ( self::presets() as $slug => $entry ) { $all[$slug] = array_merge( array( 'slug' => $slug, 'enabled' => true, 'title' => '', 'subtitle' => '', 'frontend' => array(), 'admin' => array() ), $entry ); }
		$saved = get_option( 'wpmft_language_registry', array() );
		foreach ( is_array( $saved ) ? $saved : array() as $slug => $entry ) {
			if ( self::valid_slug( $slug ) && is_array( $entry ) ) { $all[$slug] = array_merge( $all[$slug] ?? array( 'slug' => $slug, 'enabled' => true, 'title' => '', 'subtitle' => '', 'frontend' => array(), 'admin' => array() ), $entry ); }
		}
		// Polylang may use regional slugs and languages with no bundled dictionary.
		if ( function_exists( 'pll_languages_list' ) ) {
			$slugs = (array) pll_languages_list( array( 'hide_empty' => 0, 'fields' => 'slug' ) );
			$names = (array) pll_languages_list( array( 'hide_empty' => 0, 'fields' => 'name' ) );
			$locales = (array) pll_languages_list( array( 'hide_empty' => 0, 'fields' => 'locale' ) );
			foreach ( $slugs as $position => $slug ) {
				if ( ! self::valid_slug( $slug ) ) { continue; }
				if ( ! isset( $all[$slug] ) ) { $all[$slug] = array( 'slug' => $slug, 'name' => $names[$position] ?? $slug, 'locale' => $locales[$position] ?? $slug, 'title' => '', 'subtitle' => '', 'frontend' => array(), 'admin' => array() ); }
				$all[$slug]['enabled'] = true;
			}
		}
		$all['en']['enabled'] = true;
		return $enabled_only ? array_filter( $all, function ( $entry ) { return ! empty( $entry['enabled'] ); } ) : $all;
	}

	/** Load only fixed packaged filenames; custom languages never become filesystem paths. */
	private static function bundled( $slug ) {
		$base = preg_split( '/[-_]/', $slug )[0];
		$known = self::presets();
		$base = isset( $known[$slug] ) ? $slug : ( isset( $known[$base] ) ? $base : 'en' );
		if ( ! isset( self::$catalogs[$base] ) ) {
			$file = WPMFT_DIR . 'languages/interfaces/' . $base . '.json';
			$data = is_readable( $file ) ? json_decode( file_get_contents( $file ), true ) : array();
			self::$catalogs[$base] = is_array( $data ) ? $data : array();
		}
		return self::$catalogs[$base];
	}

	public static function labels( $slug, $section = 'frontend' ) {
		if ( ! in_array( $section, array( 'frontend', 'admin' ), true ) ) { return array(); }
		$cache_key = $slug . ':' . $section;
		if ( isset( self::$labels[$cache_key] ) ) { return self::$labels[$cache_key]; }
		$all = self::registry( false );
		$bundled = self::bundled( $slug );
		$labels = isset( $bundled[$section] ) && is_array( $bundled[$section] ) ? $bundled[$section] : array();
		if ( 'admin' === $section && 'lv' === $slug ) {
			if ( ! class_exists( 'MO' ) ) { require_once ABSPATH . WPINC . '/pomo/mo.php'; }
			$catalog = new MO();
			$catalog->import_from_file( WPMFT_DIR . 'languages/my-family-tree-lv.mo' );
			foreach ( $catalog->entries as $entry ) {
				if ( ! $entry->context && ! $entry->is_plural && ! empty( $entry->translations[0] ) ) { $labels[$entry->singular] = $entry->translations[0]; }
			}
		}
		$overrides = isset( $all[$slug][$section] ) && is_array( $all[$slug][$section] ) ? $all[$slug][$section] : array();
		self::$labels[$cache_key] = array_merge( $labels, $overrides );
		return self::$labels[$cache_key];
	}

	public static function dictionaries( $section = 'frontend', $slugs = array() ) {
		$result = array();
		foreach ( $slugs ?: array_keys( self::registry() ) as $slug ) { $result[$slug] = self::labels( $slug, $section ); }
		return $result;
	}

	public static function from_locale( $locale ) {
		$all = self::registry();
		foreach ( $all as $slug => $entry ) { if ( $locale === $entry['locale'] ) { return $slug; } }
		$base = strtolower( preg_split( '/[-_]/', (string) $locale )[0] );
		return isset( $all[$base] ) ? $base : 'en';
	}

	public static function set_request_language( $slug ) { self::$request_language = isset( self::registry()[$slug] ) ? $slug : 'en'; }

	public static function translate_request( $translation, $text, $domain ) {
		if ( ! self::$request_language ) { return $translation; }
		$labels = self::labels( self::$request_language, 'admin' );
		return isset( $labels[$text] ) ? $labels[$text] : $translation;
	}

	private static function notice( $message, $error = false ) {
		set_transient( 'wpmft_languages_notice_' . get_current_user_id(), array( 'text' => $message, 'error' => $error ), MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=wpmft#wpmft-languages' ) );
		exit;
	}

	/** Validate the complete submitted registry before committing any setting. */
	public static function validate( $rows ) {
		if ( ! is_array( $rows ) ) { return new WP_Error( 'languages', __( 'The language settings could not be read.', 'my-family-tree' ) ); }
		$result = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['slug'] ) || ! is_string( $row['slug'] ) ) { continue; }
			$slug = strtolower( trim( $row['slug'] ) );
			if ( '' === $slug ) { continue; }
			if ( ! self::valid_slug( $slug ) || isset( $result[$slug] ) ) { return new WP_Error( 'slug', __( 'Each language needs a unique code such as en, pt-br or zh-hant.', 'my-family-tree' ) ); }
			$name = isset( $row['name'] ) && is_string( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';
			$locale = isset( $row['locale'] ) && is_string( $row['locale'] ) ? trim( $row['locale'] ) : '';
			if ( ! $name || strlen( $name ) > 120 || ! preg_match( '/^[a-zA-Z]{2,3}(?:[-_][a-zA-Z0-9]{2,12})*$/D', $locale ) || strlen( $locale ) > 40 ) { return new WP_Error( 'locale', __( 'Enter a language name and a valid locale, such as English and en_US.', 'my-family-tree' ) ); }
			$entry = array( 'slug' => $slug, 'name' => $name, 'locale' => $locale, 'enabled' => 'en' === $slug || ! empty( $row['enabled'] ) );
			foreach ( array( 'title', 'subtitle' ) as $field ) { $entry[$field] = isset( $row[$field] ) && is_string( $row[$field] ) ? mb_substr( sanitize_text_field( $row[$field] ), 0, 500 ) : ''; }
			foreach ( array( 'frontend', 'admin' ) as $section ) {
				$raw = isset( $row[$section] ) && is_string( $row[$section] ) ? trim( $row[$section] ) : '';
				$labels = $raw ? json_decode( $raw, true ) : array();
				if ( ! is_array( $labels ) || ( $raw && '{' !== substr( $raw, 0, 1 ) ) ) { return new WP_Error( 'json', __( 'Translations must be a JSON object with text keys and text values. No settings were changed.', 'my-family-tree' ) ); }
				$entry[$section] = array();
				foreach ( $labels as $key => $value ) {
					if ( ! is_string( $key ) || ! is_string( $value ) || strlen( $key ) > 2000 || strlen( $value ) > 6000 || preg_match( '/[<>]/', $value ) ) { return new WP_Error( 'labels', __( 'Translation values must be plain text without HTML. Keep placeholders such as {name} unchanged.', 'my-family-tree' ) ); }
					$english = self::bundled( 'en' );
					$source = 'frontend' === $section ? ( $english['frontend'][$key] ?? '' ) : $key;
					preg_match_all( '/\{[a-zA-Z0-9_]+\}|%(?:[0-9]+\$)?[sdf]/', $source, $expected );
					preg_match_all( '/\{[a-zA-Z0-9_]+\}|%(?:[0-9]+\$)?[sdf]/', $value, $actual );
					sort( $expected[0] ); sort( $actual[0] );
					// translators: %s is a literal format placeholder that must remain unchanged in a user translation.
					if ( $source && $expected[0] !== $actual[0] ) { return new WP_Error( 'placeholders', __( 'Keep every translation placeholder unchanged, including {name}, {count} and %s.', 'my-family-tree' ) ); }
					$entry[$section][$key] = sanitize_textarea_field( $value );
				}
			}
			$result[$slug] = $entry;
		}
		if ( ! isset( $result['en'] ) ) { return new WP_Error( 'english', __( 'Keep English enabled as the fallback language.', 'my-family-tree' ) ); }
		return $result;
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You do not have permission to change family tree settings.', 'my-family-tree' ), '', array( 'response' => 403 ) ); }
		if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) { wp_die( esc_html__( 'A POST request is required.', 'my-family-tree' ), '', array( 'response' => 405 ) ); }
		check_admin_referer( 'wpmft_save_languages' );
		// JavaScript submits one JSON field, avoiding PHP's max_input_vars limit for long lists.
		// The final marker also makes a truncated non-JavaScript form fail without losing languages.
		if ( ! isset( $_POST['wpmft_languages_complete'] ) || '1' !== sanitize_text_field( wp_unslash( $_POST['wpmft_languages_complete'] ) ) ) { self::notice( __( 'The server truncated the language form. No languages were changed. Please enable JavaScript and try again.', 'my-family-tree' ), true ); }
		$rows = isset( $_POST['wpmft_languages'] ) && is_array( $_POST['wpmft_languages'] ) ? wp_unslash( $_POST['wpmft_languages'] ) : array();
		if ( isset( $_POST['wpmft_languages_json'] ) && is_string( $_POST['wpmft_languages_json'] ) ) { $rows = json_decode( wp_unslash( $_POST['wpmft_languages_json'] ), true ); }
		$validated = self::validate( $rows );
		if ( is_wp_error( $validated ) ) { self::notice( $validated->get_error_message(), true ); }
		$default = isset( $_POST['wpmft_default_language'] ) && is_string( $_POST['wpmft_default_language'] ) ? sanitize_key( wp_unslash( $_POST['wpmft_default_language'] ) ) : 'en';
		if ( empty( $validated[$default]['enabled'] ) ) { self::notice( __( 'Enable the selected default language before saving.', 'my-family-tree' ), true ); }
		update_option( 'wpmft_language_registry', $validated, false );
		$settings = wpmft_settings(); $settings['language'] = $default;
		update_option( 'wpmft_settings', $settings, false );
		self::notice( __( 'Language settings saved.', 'my-family-tree' ) );
	}

	public static function render_panel() {
		$all = self::registry( false );
		$notice = get_transient( 'wpmft_languages_notice_' . get_current_user_id() );
		if ( is_array( $notice ) ) { delete_transient( 'wpmft_languages_notice_' . get_current_user_id() ); echo '<div class="notice ' . ( ! empty( $notice['error'] ) ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( $notice['text'] ) . '</p></div>'; }
		?>
		<p><?php esc_html_e( 'Choose available interface languages, edit labels or add any number of languages. English is the fallback. Names and biographies are translated separately with optional Polylang.', 'my-family-tree' ); ?></p>
		<div class="wpmft-admin-note">
			<?php if ( function_exists( 'pll_languages_list' ) ) : ?>
				<p><?php esc_html_e( 'Polylang is active. Its configured languages and translated page links control the public language menu. These presets supply interface labels.', 'my-family-tree' ); ?></p>
			<?php else : ?>
				<strong><?php esc_html_e( 'Would you like to translate people and pages too?', 'my-family-tree' ); ?></strong>
				<p><?php esc_html_e( 'Polylang is optional and free to install. The tree and its interface languages work without it. Installing it does not automatically translate your content.', 'my-family-tree' ); ?></p>
				<?php if ( current_user_can( 'install_plugins' ) ) : ?><a class="button" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( admin_url( 'plugin-install.php?tab=plugin-information&plugin=polylang' ) ); ?>"><?php esc_html_e( 'Review / install optional Polylang', 'my-family-tree' ); ?></a><?php endif; ?>
			<?php endif; ?>
		</div>
		<label class="wpmft-field"><span><?php esc_html_e( 'Default interface language', 'my-family-tree' ); ?></span><select name="wpmft_default_language" form="wpmft-language-tool"><?php foreach ( $all as $slug => $entry ) : ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( wpmft_settings()['language'], $slug ); ?>><?php echo esc_html( $entry['name'] ); ?></option><?php endforeach; ?></select></label>
		<p class="description"><?php esc_html_e( 'Presets include translated front-end labels. Missing labels fall back to English. Custom translations are saved in the database and survive plugin updates.', 'my-family-tree' ); ?></p>
		<div id="wpmft-language-rows">
		<?php $number = 0; foreach ( $all as $entry ) { self::render_row( $number++, $entry ); } ?>
		</div>
		<template id="wpmft-language-template"><?php self::render_row( '__INDEX__', array( 'slug' => '', 'name' => '', 'locale' => '', 'enabled' => true, 'frontend' => array(), 'admin' => array(), 'title' => '', 'subtitle' => '' ) ); ?></template>
		<p><button class="button" type="button" id="wpmft-add-language"><?php esc_html_e( 'Add a language', 'my-family-tree' ); ?></button> <button class="button button-primary" form="wpmft-language-tool" type="submit"><?php esc_html_e( 'Save language settings', 'my-family-tree' ); ?></button></p>
		<details class="wpmft-language-reference"><summary><?php esc_html_e( 'English front-end label reference', 'my-family-tree' ); ?></summary><p><?php esc_html_e( 'Copy the labels you want to change into a language’s Front-end labels JSON field. Keep placeholders unchanged, for example {name}, {count} and {days}.', 'my-family-tree' ); ?></p><textarea readonly rows="12" aria-label="<?php esc_attr_e( 'English front-end label reference', 'my-family-tree' ); ?>"><?php echo esc_textarea( wp_json_encode( self::labels( 'en' ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></textarea></details>
		<?php
	}

	private static function render_row( $index, $entry ) {
		$prefix = 'wpmft_languages[' . $index . ']';
		?>
		<details class="wpmft-language-row" <?php echo '' === $entry['slug'] ? 'open' : ''; ?>>
			<summary><span class="wpmft-language-row-title" data-wpmft-user-content><?php echo esc_html( $entry['name'] ?: __( 'New language', 'my-family-tree' ) ); ?></span> <small data-wpmft-user-content><?php echo esc_html( $entry['slug'] ); ?></small></summary>
			<div class="wpmft-language-fields">
				<label class="wpmft-check"><input form="wpmft-language-tool" type="checkbox" name="<?php echo esc_attr( $prefix . '[enabled]' ); ?>" value="1" <?php checked( ! empty( $entry['enabled'] ) ); ?>><span><?php esc_html_e( 'Enable language', 'my-family-tree' ); ?></span></label>
				<?php foreach ( array( 'slug' => __( 'Language code', 'my-family-tree' ), 'name' => __( 'Native language name', 'my-family-tree' ), 'locale' => __( 'Locale', 'my-family-tree' ), 'title' => __( 'Tree title translation', 'my-family-tree' ), 'subtitle' => __( 'Tree introduction translation', 'my-family-tree' ) ) as $key => $label ) : ?>
				<label class="wpmft-field"><span><?php echo esc_html( $label ); ?></span><input form="wpmft-language-tool" type="text" name="<?php echo esc_attr( $prefix . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $entry[$key] ?? '' ); ?>" <?php echo 'slug' === $key && 'en' === $entry['slug'] ? 'readonly' : ''; ?>></label>
				<?php endforeach; ?>
				<?php foreach ( array( 'frontend' => __( 'Front-end labels (JSON)', 'my-family-tree' ), 'admin' => __( 'Admin and server messages (JSON)', 'my-family-tree' ) ) as $key => $label ) : ?>
				<label class="wpmft-field wpmft-language-json"><span><?php echo esc_html( $label ); ?></span><textarea form="wpmft-language-tool" spellcheck="false" rows="5" name="<?php echo esc_attr( $prefix . '[' . $key . ']' ); ?>" placeholder="{}"><?php echo esc_textarea( empty( $entry[$key] ) ? '{}' : wp_json_encode( $entry[$key], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></textarea></label>
				<?php endforeach; ?>
				<p class="description"><?php esc_html_e( 'Only overrides belong here. For admin labels, use the full English sentence as the key. Blank title fields keep the existing title or its Polylang translation.', 'my-family-tree' ); ?></p>
			</div>
		</details>
		<?php
	}

	public static function render_form() {
		?><form id="wpmft-language-tool" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="wpmft_save_languages"><?php wp_nonce_field( 'wpmft_save_languages' ); ?><input type="hidden" name="wpmft_languages_complete" value="1"></form><?php
	}
}
