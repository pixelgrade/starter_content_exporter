<?php
/**
 * Regression check for optional pre-import theme mod settings on PHP 8+.
 *
 * Run with:
 * php tests/php83-optional-pre-theme-mods.php
 */

function register_activation_hook() {}
function add_action() {}
function add_filter() {}
function get_option( $key, $default = false ) {
	if ( 'starter_content_exporter' === $key ) {
		return [];
	}

	return $default;
}
function get_theme_mods() {
	return [
		'pixelgrade_jetpack_default_active_modules' => [ 'carousel' ],
		'required_mi_mod'                          => 'required-value',
	];
}

require dirname( __DIR__ ) . '/starter_content_exporter.php';

$exporter = new Starter_Content_Exporter();

$get_pre_settings = new ReflectionMethod( $exporter, 'get_pre_settings' );
$get_pre_settings->setAccessible( true );

$pre_settings = $get_pre_settings->invoke( $exporter );
if ( ! isset( $pre_settings['mods']['pixelgrade_jetpack_default_active_modules'] ) ) {
	fwrite( STDERR, "Default pre-import theme mod was not exported.\n" );
	exit( 1 );
}

$property = new ReflectionProperty( $exporter, 'pre_settings' );
$property->setAccessible( true );
$settings             = $property->getValue( $exporter );
$settings['mi_mods']  = [ 'required_mi_mod' ];
$property->setValue( $exporter, $settings );

$get_mi_pre_settings = new ReflectionMethod( $exporter, 'get_mi_pre_settings' );
$get_mi_pre_settings->setAccessible( true );

$mi_pre_settings = $get_mi_pre_settings->invoke( $exporter );
if ( ! isset( $mi_pre_settings['mods']['required_mi_mod'] ) ) {
	fwrite( STDERR, "Default must-import theme mod was not exported.\n" );
	exit( 1 );
}

echo "Optional pre-import theme mod settings are handled.\n";
