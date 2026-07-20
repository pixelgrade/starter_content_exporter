<?php
/**
 * Resilience contract for a Style Manager schema API that cannot produce a schema.
 */

namespace Pixelgrade\StyleManager {
	function get_customizer_config() {
		throw new \RuntimeException( 'Schema cache unavailable.' );
	}
}

namespace {
	function register_activation_hook() {}
	function add_action() {}
	function add_filter() {}
	function apply_filters( $hook, $value ) {
		return $value;
	}
	function wp_load_alloptions() {
		return array(
			'sm_collection_hover_effect'   => 'pile',
			'sm_site_color_variation'      => '',
			'sm_perf_autoload_migrated_v1' => '1',
		);
	}
	function get_option( $key, $default = false ) {
		$options = array(
			'starter_content_exporter'   => array(),
			'sm_collection_hover_effect' => 'pile',
			'sm_site_color_variation'    => '',
		);

		return $options[ $key ] ?? $default;
	}
	function get_theme_mods() {
		return array();
	}

	require_once dirname( __DIR__ ) . '/starter_content_exporter.php';

	$exporter         = new \Starter_Content_Exporter();
	$get_pre_settings = new \ReflectionMethod( $exporter, 'get_pre_settings' );
	$get_pre_settings->setAccessible( true );

	try {
		$settings = $get_pre_settings->invoke( $exporter );
	} catch ( \Throwable $exception ) {
		fwrite( STDERR, "A failing Style Manager schema API must not break starter export.\n" );
		exit( 1 );
	}

	if ( 'pile' !== ( $settings['options']['sm_collection_hover_effect'] ?? null ) ) {
		fwrite( STDERR, "A failing Style Manager schema API must fall back to stored options.\n" );
		exit( 1 );
	}

	if ( ! array_key_exists( 'sm_site_color_variation', $settings['options'] ) || '' !== $settings['options']['sm_site_color_variation'] ) {
		fwrite( STDERR, "A failing schema API must not guess whether stored values are invalid.\n" );
		exit( 1 );
	}

	echo "Style Manager schema failure fallback contract OK\n";
}
