<?php
/** A complete document for the plugin canvas, independent of theme templates. @package WPMyFamilyTree */
defined( 'ABSPATH' ) || exit;
WPMFT_Security::no_cache();
$wpmft_s = wpmft_settings();
WPMFT_Output::begin_document();
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="same-origin">
<?php wp_head(); ?>
</head>
<body class="wpmft-standalone">
<?php wp_body_open(); ?>
<?php
while ( have_posts() ) {
	the_post();
	the_content();
}
wp_footer();
?>
</body>
</html>
<?php WPMFT_Output::end_document(); ?>
