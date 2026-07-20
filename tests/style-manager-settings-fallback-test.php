<?php
/**
 * Compatibility contract for Style Manager versions without a schema API.
 */

function register_activation_hook() {}
function add_action() {}
function add_filter() {}
function apply_filters( $hook, $value ) {
	return $value;
}
function wp_load_alloptions() {
	return array(
		'sm_collection_hover_effect'   => 'pile',
		'sm_perf_autoload_migrated_v1' => '1',
		'unrelated_option'              => 'ignore-me',
	);
}
function get_option( $key, $default = false ) {
	$options = array(
		'starter_content_exporter'     => array(),
		'sm_collection_hover_effect'   => 'pile',
		'sm_perf_autoload_migrated_v1' => '1',
	);

	return $options[ $key ] ?? $default;
}
function get_theme_mods() {
	return array();
}

require_once dirname( __DIR__ ) . '/starter_content_exporter.php';

$exporter         = new Starter_Content_Exporter();
$get_pre_settings = new ReflectionMethod( $exporter, 'get_pre_settings' );
$get_pre_settings->setAccessible( true );
$settings = $get_pre_settings->invoke( $exporter );

if ( 'pile' !== ( $settings['options']['sm_collection_hover_effect'] ?? null ) ) {
	fwrite( STDERR, "Stored Style Manager options must export without the schema API.\n" );
	exit( 1 );
}

if ( isset( $settings['options']['sm_perf_autoload_migrated_v1'] ) ) {
	fwrite( STDERR, "Internal Style Manager options must remain excluded in fallback mode.\n" );
	exit( 1 );
}

echo "Style Manager settings fallback contract OK\n";
