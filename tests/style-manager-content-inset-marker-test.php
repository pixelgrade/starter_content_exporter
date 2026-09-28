<?php
/**
 * Regression contract for Style Manager's content-inset marker.
 *
 * Style Manager 2.7 treats `sm_content_inset` as explicit only when
 * `style_manager_content_inset_explicit` holds the same value. The marker lives
 * outside the `sm_*` namespace, so the automatic Style Manager export must carry
 * it explicitly, or imported starters render the reading column flush left.
 */

namespace Pixelgrade\StyleManager {
	function get_customizer_config() {
		return array(
			'sections' => array(
				'layout' => array(
					'options' => array(
						'content_inset' => array(
							'type'         => 'range',
							'setting_type' => 'option',
							'setting_id'   => 'sm_content_inset',
							'input_attrs'  => array( 'min' => 0, 'max' => 300 ),
						),
					),
				),
			),
		);
	}
}

namespace {
	$GLOBALS['sce_test_options'] = array();

	function register_activation_hook() {}
	function add_action() {}
	function add_filter() {}
	function apply_filters( $hook, $value ) {
		return $value;
	}
	function maybe_unserialize( $value ) {
		return $value;
	}
	function wp_load_alloptions() {
		return $GLOBALS['sce_test_options'];
	}
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['sce_test_options'] ) ? $GLOBALS['sce_test_options'][ $key ] : $default;
	}
	function get_theme_mods() {
		return array();
	}

	require_once dirname( __DIR__ ) . '/starter_content_exporter.php';

	function sce_test_pre_options( array $options ): array {
		$GLOBALS['sce_test_options'] = $options;

		$exporter = new \Starter_Content_Exporter();
		$method   = new \ReflectionMethod( $exporter, 'get_pre_settings' );
		$method->setAccessible( true );
		$settings = $method->invoke( $exporter );

		return $settings['options'];
	}

	function sce_test_fail( string $message ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}

	const SCE_MARKER = 'style_manager_content_inset_explicit';

	// Saved through Style Manager: the marker travels with the inset, value intact.
	$pre = sce_test_pre_options(
		array(
			'starter_content_exporter' => array(),
			'sm_content_inset'         => '120',
			SCE_MARKER                 => '120',
		)
	);
	if ( '120' !== ( $pre['sm_content_inset'] ?? null ) ) {
		sce_test_fail( 'Expected sm_content_inset in the automatic pre-import options.' );
	}
	if ( ! array_key_exists( SCE_MARKER, $pre ) ) {
		sce_test_fail( 'Expected the content-inset marker in the automatic pre-import options.' );
	}
	if ( '120' !== $pre[ SCE_MARKER ] ) {
		sce_test_fail( 'The exported content-inset marker must keep the source value.' );
	}

	// Legacy inset (never saved through Style Manager): no marker invented.
	$pre = sce_test_pre_options(
		array(
			'starter_content_exporter' => array(),
			'sm_content_inset'         => '230',
		)
	);
	if ( '230' !== ( $pre['sm_content_inset'] ?? null ) ) {
		sce_test_fail( 'Expected a legacy sm_content_inset to stay exportable.' );
	}
	if ( array_key_exists( SCE_MARKER, $pre ) ) {
		sce_test_fail( 'A legacy content inset must not gain a marker on export.' );
	}

	// A marker assigned to post-import by hand stays there only.
	$GLOBALS['sce_test_options'] = array(
		'starter_content_exporter' => array( 'exported_post_options' => array( SCE_MARKER ) ),
		'sm_content_inset'         => '120',
		SCE_MARKER                 => '120',
	);
	$exporter = new \Starter_Content_Exporter();
	$method   = new \ReflectionMethod( $exporter, 'get_pre_settings' );
	$method->setAccessible( true );
	$pre = $method->invoke( $exporter )['options'];
	if ( array_key_exists( SCE_MARKER, $pre ) ) {
		sce_test_fail( 'A marker explicitly assigned to post-import must not also be exported before import.' );
	}
	$method = new \ReflectionMethod( $exporter, 'get_post_settings' );
	$method->setAccessible( true );
	if ( '120' !== ( $method->invoke( $exporter )['options'][ SCE_MARKER ] ?? null ) ) {
		sce_test_fail( 'A marker explicitly assigned to post-import must be exported after import.' );
	}
}
