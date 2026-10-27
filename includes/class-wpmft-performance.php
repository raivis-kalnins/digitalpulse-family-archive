<?php
/** Reversible WordPress cleanup without altering stored content. @package WPMyFamilyTree */
defined( 'ABSPATH' ) || exit;

final class WPMFT_Performance {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'configure' ), 100 );
		add_action( 'template_redirect', array( __CLASS__, 'standalone_head' ), 2 );
		add_filter( 'comments_open', array( __CLASS__, 'comments_open' ), 100, 2 );
		add_filter( 'pings_open', array( __CLASS__, 'comments_open' ), 100, 2 );
		add_filter( 'get_comments_number', array( __CLASS__, 'comments_number' ), 100, 2 );
		add_filter( 'comments_array', array( __CLASS__, 'comments_array' ), 100, 2 );
		add_filter( 'comments_template', array( __CLASS__, 'comments_template' ), 100 );
		add_filter( 'widget_comments_args', array( __CLASS__, 'widget_comments_args' ), 100 );
		add_filter( 'comment_feed_where', array( __CLASS__, 'comment_feed_where' ), 100 );
		add_filter( 'render_block', array( __CLASS__, 'comment_block' ), 100, 3 );
		add_filter( 'rest_pre_insert_comment', array( __CLASS__, 'rest_comment' ), 100, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'comment_meta_boxes' ), 100 );
		foreach ( array( 'manage_post_posts_columns', 'manage_page_posts_columns', 'manage_media_columns' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'comment_columns' ) );
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_unused_assets' ), 100 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'dequeue_unused_assets' ), 100 );
		// A theme can enqueue comment-reply during its render, after wp_enqueue_scripts.
		add_action( 'wp_print_footer_scripts', array( __CLASS__, 'dequeue_unused_assets' ), 1 );
		add_action( 'admin_print_footer_scripts', array( __CLASS__, 'dequeue_unused_assets' ), 1 );
	}

	private static function enabled( $key ) { return ! empty( wpmft_settings()[$key] ); }

	/** Standard editorial comments only. Product reviews and other custom types remain available. */
	private static function affected_post( $post_id ) {
		return self::enabled( 'disable_comments' ) && in_array( get_post_type( $post_id ), array( 'post', 'page', 'attachment' ), true );
	}

	public static function configure() {
		if ( self::enabled( 'disable_comments' ) ) {
			foreach ( array( 'post', 'page', 'attachment' ) as $type ) {
				remove_post_type_support( $type, 'comments' );
				remove_post_type_support( $type, 'trackbacks' );
			}
		}
		if ( self::enabled( 'disable_emojis' ) ) {
			self::remove_emoji_hooks();
			add_filter( 'tiny_mce_plugins', array( __CLASS__, 'tinymce_plugins' ) );
			add_filter( 'emoji_svg_url', '__return_false' );
			add_filter( 'emoji_url', '__return_false' );
			add_filter( 'wp_resource_hints', array( __CLASS__, 'emoji_resource_hints' ), 10, 2 );
		}
		if ( self::enabled( 'clean_head' ) ) {
			foreach ( array( 'rsd_link', 'wlwmanifest_link', 'wp_generator', 'wp_shortlink_wp_head', 'adjacent_posts_rel_link_wp_head' ) as $callback ) {
				remove_action( 'wp_head', $callback );
			}
			remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		}
	}

	/** Stop both the legacy head loader and the newer deferred footer loader. */
	private static function remove_emoji_hooks() {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'embed_head', 'print_emoji_detection_script' );
		foreach ( array( 'wp_print_footer_scripts', 'admin_print_footer_scripts' ) as $hook ) {
			remove_action( $hook, 'print_emoji_detection_script' );
			remove_action( $hook, '_print_emoji_detection_script' );
		}
		foreach ( array( 'wp_enqueue_scripts', 'admin_enqueue_scripts', 'enqueue_embed_scripts' ) as $hook ) {
			remove_action( $hook, 'wp_enqueue_emoji_styles' );
		}
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
	}

	public static function tinymce_plugins( $plugins ) {
		return is_array( $plugins ) && self::enabled( 'disable_emojis' ) ? array_values( array_diff( $plugins, array( 'wpemoji' ) ) ) : $plugins;
	}

	public static function emoji_resource_hints( $urls, $relation_type ) {
		if ( ! self::enabled( 'disable_emojis' ) || ! in_array( $relation_type, array( 'dns-prefetch', 'preconnect' ), true ) ) { return $urls; }
		return array_values( array_filter( $urls, function ( $item ) {
			$url = is_array( $item ) ? ( $item['href'] ?? '' ) : $item;
			$host = wp_parse_url( 0 === strpos( (string) $url, '//' ) ? 'https:' . $url : $url, PHP_URL_HOST );
			// This comparison removes a core emoji resource hint; it never loads a remote resource.
			// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Matching an existing hint for removal, not offloading assets.
			return 's.w.org' !== $host && 's.w.org' !== $url;
		} ) );
	}

	public static function dequeue_unused_assets() {
		if ( self::enabled( 'disable_emojis' ) ) {
			self::remove_emoji_hooks();
			wp_dequeue_style( 'wp-emoji-styles' );
			wp_dequeue_script( 'wp-emoji-release' );
			if ( function_exists( 'wp_dequeue_script_module' ) ) { wp_dequeue_script_module( '@wordpress/emoji' ); }
		}
		if ( ! is_admin() && is_singular() && self::affected_post( get_queried_object_id() ) ) {
			wp_dequeue_script( 'comment-reply' );
		}
	}

	/** Keep discovery on normal theme pages. The standalone app has no feed or oEmbed view. */
	public static function standalone_head() {
		$s = wpmft_settings();
		if ( empty( $s['clean_head'] ) || empty( $s['enabled'] ) || empty( $s['standalone'] ) || ! wpmft_is_tree_page() ) { return; }
		remove_action( 'wp_head', 'feed_links', 2 );
		remove_action( 'wp_head', 'feed_links_extra', 3 );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'rest_output_link_wp_head' );
		remove_action( 'template_redirect', 'rest_output_link_header', 11 );
	}

	public static function comments_open( $open, $post_id ) { return self::affected_post( $post_id ) ? false : $open; }
	public static function comments_number( $count, $post_id ) { return ! is_admin() && self::affected_post( $post_id ) ? 0 : $count; }
	public static function comments_array( $comments, $post_id ) { return ! is_admin() && self::affected_post( $post_id ) ? array() : $comments; }

	public static function comments_template( $template ) {
		return ! is_admin() && self::affected_post( get_the_ID() ) ? WPMFT_DIR . 'templates/comments-disabled.php' : $template;
	}

	/** Recent-comment widgets and blocks must not redisplay comments hidden on their posts. */
	public static function widget_comments_args( $args ) {
		if ( is_admin() || ! self::enabled( 'disable_comments' ) ) { return $args; }
		$allowed = array_diff( get_post_types(), array( 'post', 'page', 'attachment' ) );
		if ( ! empty( $args['post_type'] ) ) { $allowed = array_intersect( $allowed, (array) $args['post_type'] ); }
		$args['post_type'] = $allowed ? array_values( $allowed ) : array( 'wpmft_no_comments' );
		return $args;
	}

	public static function comment_feed_where( $where ) {
		if ( ! self::enabled( 'disable_comments' ) ) { return $where; }
		global $wpdb;
		return $where . " AND {$wpdb->comments}.comment_post_ID NOT IN (SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('post','page','attachment'))";
	}

	public static function comment_block( $content, $block, $instance ) {
		if ( is_admin() || ! self::enabled( 'disable_comments' ) ) { return $content; }
		$name = $block['blockName'] ?? '';
		if ( 0 !== strpos( $name, 'core/comment' ) && ! in_array( $name, array( 'core/post-comments', 'core/post-comments-form', 'core/post-comments-link', 'core/comments-count' ), true ) ) { return $content; }
		$id = $instance->context['postId'] ?? get_the_ID();
		return self::affected_post( $id ) ? '' : $content;
	}

	public static function rest_comment( $prepared, $request ) {
		if ( is_wp_error( $prepared ) ) { return $prepared; }
		// This setting closes new discussions; existing comments remain available for moderation.
		if ( $request->get_param( 'id' ) ) { return $prepared; }
		if ( self::affected_post( $prepared['comment_post_ID'] ?? $request->get_param( 'post' ) ) ) {
			return new WP_Error( 'wpmft_comments_disabled', __( 'Comments are disabled for posts, pages and media.', 'digitalpulse-family-archive' ), array( 'status' => 403 ) );
		}
		return $prepared;
	}

	public static function comment_meta_boxes( $post_type ) {
		if ( ! self::enabled( 'disable_comments' ) || ! in_array( $post_type, array( 'post', 'page', 'attachment' ), true ) ) { return; }
		foreach ( array( 'commentstatusdiv', 'commentsdiv', 'trackbacksdiv' ) as $id ) { remove_meta_box( $id, $post_type, 'normal' ); }
	}

	public static function comment_columns( $columns ) {
		if ( self::enabled( 'disable_comments' ) ) { unset( $columns['comments'] ); }
		return $columns;
	}
}
