<?php
/**
 * Standalone contract for starter-source to Cloud registry relays.
 */

class WP_REST_Request {}
class WP_REST_Response {}

$GLOBALS['sce_registry_requests'] = array();

function register_activation_hook() {}
function add_action() {}
function add_filter() {}
function apply_filters( $hook, $value ) { return $value; }
function esc_url_raw( $value ) { return filter_var( $value, FILTER_SANITIZE_URL ); }
function wp_parse_url( $value ) { return parse_url( $value ); }
function is_wp_error() { return false; }
function wp_remote_post( $url, $args ) {
	$GLOBALS['sce_registry_requests'][] = array( 'url' => $url, 'args' => $args );

	return array( 'response' => array( 'code' => 202 ) );
}

function sce_registry_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $message . PHP_EOL );
		fwrite( STDERR, 'Expected: ' . var_export( $expected, true ) . PHP_EOL );
		fwrite( STDERR, 'Actual:   ' . var_export( $actual, true ) . PHP_EOL );
		exit( 1 );
	}
}

require_once dirname( __DIR__ ) . '/starter_content_exporter.php';

sce_registry_assert_same( true, method_exists( 'Starter_Content_Exporter', 'prepare_service_registry_payload' ), 'Starter sources must expose the allowlisted registry payload contract.' );

$params = array(
	'site_url'   => 'https://starter-client.example/subsite/',
	'theme_data' => array( 'slug' => 'anima', 'name' => 'Anima', 'version' => '1.4.2', 'secret' => 'drop me' ),
	'site_data'  => array(
		'url'                  => 'https://starter-client.example/subsite/',
		'is_ssl'               => true,
		'environment_type'     => 'production',
		'wp'                   => array( 'version' => '6.9.1', 'language' => 'en-US', 'rtl' => false ),
		'pixelgrade_assistant' => array( 'version' => '2.0.0', 'token' => 'drop me' ),
	),
	'customer_data' => array( 'id' => 15, 'email' => 'drop@example.test' ),
	'content'       => 'drop page content',
);

$payload = Starter_Content_Exporter::prepare_service_registry_payload( $params, 'Starter Manifest Requested!' );
sce_registry_assert_same( 'starter_manifest_requested', $payload['service'], 'Starter sources must sanitize the endpoint-owned observed service.' );
sce_registry_assert_same( 15, $payload['customer_data']['id'], 'Starter sources may relay an existing numeric customer ID.' );
sce_registry_assert_same( false, false !== strpos( strtolower( json_encode( $payload ) ), 'drop' ), 'Starter sources must not relay content, credentials, or unapproved identity fields.' );
sce_registry_assert_same( array(), Starter_Content_Exporter::prepare_service_registry_payload( array( 'site_url' => 'file:///etc/passwd' ), 'invalid' ), 'Starter sources must reject non-HTTP(S) site URLs.' );

class SCE_Registry_Test_Exporter extends Starter_Content_Exporter {
	public function relay( $context, $service ) {
		return $this->maybe_record_service_request( $context, $service );
	}
}

$exporter = new SCE_Registry_Test_Exporter();
sce_registry_assert_same( true, $exporter->relay( $params, 'layout_units_requested' ), 'Valid starter-source context must be relayed without affecting content delivery.' );
sce_registry_assert_same( 1, count( $GLOBALS['sce_registry_requests'] ), 'Starter sources must make one registry relay request.' );
sce_registry_assert_same( false, $GLOBALS['sce_registry_requests'][0]['args']['blocking'], 'Starter-source registry relays must not block content delivery.' );
sce_registry_assert_same( 'layout_units_requested', $GLOBALS['sce_registry_requests'][0]['args']['body']['service'], 'Starter sources must relay the observed endpoint service.' );

echo "Starter source service registry relay contract OK\n";
