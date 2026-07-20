<?php
/**
 * Regression contract for exporting the media-backed WordPress site logo.
 */

function register_activation_hook() {}
function add_action() {}
function add_filter() {}
function get_option( $key, $default = false ) {
	$options = array(
		'starter_content_exporter' => array(),
		'page_on_front'            => 37,
		'page_for_posts'           => 31,
		'site_logo'                => 299,
	);

	return $options[ $key ] ?? $default;
}
function get_theme_mods() {
	return array();
}

require_once dirname( __DIR__ ) . '/starter_content_exporter.php';

$exporter         = new Starter_Content_Exporter();
$get_post_settings = new ReflectionMethod( $exporter, 'get_post_settings' );
$get_post_settings->setAccessible( true );
$settings = $get_post_settings->invoke( $exporter );

if ( 299 !== ( $settings['options']['site_logo'] ?? null ) ) {
	fwrite( STDERR, "The site_logo attachment ID must be included in post-import options.\n" );
	fwrite( STDERR, 'Actual options: ' . var_export( $settings['options'], true ) . PHP_EOL );
	exit( 1 );
}

echo "Starter site logo export contract OK\n";
