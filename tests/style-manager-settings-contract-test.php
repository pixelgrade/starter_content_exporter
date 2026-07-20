<?php
/**
 * Regression contract for automatically exporting persisted Style Manager settings.
 */

namespace Pixelgrade\StyleManager {
	function get_customizer_config() {
		return $GLOBALS['sce_style_manager_config'];
	}
}

namespace {
	$GLOBALS['sce_style_manager_config'] = array(
		'sections' => array(
			'tweak_board' => array(
				'options' => array(
					'hover' => array(
						'type'         => 'select',
						'setting_type' => 'option',
						'setting_id'   => 'sm_collection_hover_effect',
					),
				),
			),
			'motion' => array(
				'options' => array(
					'transitions' => array(
						'type'         => 'checkbox',
						'setting_type' => 'option',
						'setting_id'   => 'sm_page_transitions_enable',
					),
					'intro_style' => array(
						'type'         => 'select',
						'setting_type' => 'option',
						'setting_id'   => 'sm_intro_animations_style',
					),
					'unsaved_default' => array(
						'type'         => 'select',
						'setting_type' => 'option',
						'setting_id'   => 'sm_page_transition_style',
					),
					'ui_hint' => array(
						'type'         => 'html',
						'setting_type' => 'option',
						'setting_id'   => 'sm_motion_intro',
					),
					'ui_action' => array(
						'type'         => 'button',
						'setting_type' => 'option',
						'setting_id'   => 'sm_motion_action',
					),
					'internal' => array(
						'type'         => 'checkbox',
						'setting_type' => 'option',
						'setting_id'   => 'sm_perf_autoload_migrated_v1',
					),
				),
			),
		),
	);

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
		return array(
			'sm_collection_hover_effect'   => 'pile',
			'sm_intro_animations_style'    => 'kinetic',
			'sm_legacy_unrelated_setting'  => 'legacy',
			'sm_perf_autoload_migrated_v1' => '1',
		);
	}
	function get_option( $key, $default = false ) {
		$options = array(
			'starter_content_exporter'     => array(
				'exported_post_options' => array( 'sm_intro_animations_style' ),
			),
			'sm_collection_hover_effect'   => 'pile',
			'sm_page_transitions_enable'   => '1',
			'sm_intro_animations_style'    => 'kinetic',
			'sm_motion_intro'               => 'presentation-only',
			'sm_motion_action'              => 'presentation-only',
			'sm_perf_autoload_migrated_v1' => '1',
			'sm_legacy_unrelated_setting'  => 'legacy',
		);

		return $options[ $key ] ?? $default;
	}
	function get_theme_mods() {
		return array();
	}

	require_once dirname( __DIR__ ) . '/starter_content_exporter.php';

	$exporter        = new \Starter_Content_Exporter();
	$get_pre_settings = new \ReflectionMethod( $exporter, 'get_pre_settings' );
	$get_pre_settings->setAccessible( true );
	$pre_settings = $get_pre_settings->invoke( $exporter );

	$expected_pre_options = array(
		'sm_collection_hover_effect' => 'pile',
		'sm_page_transitions_enable' => '1',
	);

	foreach ( $expected_pre_options as $key => $value ) {
		if ( $value !== ( $pre_settings['options'][ $key ] ?? null ) ) {
			fwrite( STDERR, "Expected {$key} to be exported automatically before content import.\n" );
			exit( 1 );
		}
	}

	$forbidden_pre_options = array(
		'sm_intro_animations_style',
		'sm_page_transition_style',
		'sm_motion_intro',
		'sm_motion_action',
		'sm_perf_autoload_migrated_v1',
		'sm_legacy_unrelated_setting',
	);

	foreach ( $forbidden_pre_options as $key ) {
		if ( array_key_exists( $key, $pre_settings['options'] ) ) {
			fwrite( STDERR, "Did not expect {$key} in automatic pre-import options.\n" );
			exit( 1 );
		}
	}

	$get_post_settings = new \ReflectionMethod( $exporter, 'get_post_settings' );
	$get_post_settings->setAccessible( true );
	$post_settings = $get_post_settings->invoke( $exporter );

	if ( 'kinetic' !== ( $post_settings['options']['sm_intro_animations_style'] ?? null ) ) {
		fwrite( STDERR, "An explicitly post-import Style Manager option must remain post-import.\n" );
		exit( 1 );
	}

	$get_options_select_list = new \ReflectionMethod( $exporter, 'get_options_select_list' );
	$get_options_select_list->setAccessible( true );
	$select_options = $get_options_select_list->invoke( $exporter );

	if ( ! isset( $select_options['sm_page_transitions_enable'] ) ) {
		fwrite( STDERR, "Schema-backed Style Manager options must be available in the exporter selector.\n" );
		exit( 1 );
	}

	if ( isset( $select_options['sm_perf_autoload_migrated_v1'] ) ) {
		fwrite( STDERR, "Internal Style Manager options must not be available in the exporter selector.\n" );
		exit( 1 );
	}

	echo "Style Manager settings export contract OK\n";
}
