<?php
/** Script dependencies for the readable, unbundled block editor source. */
return array(
	'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
	'version' => function_exists( 'wpmft_asset_version' ) ? wpmft_asset_version() : '1.1.2',
);
