<?php
/**
 * Regression check for opt-in media source URLs in the /sce/v2/data manifest.
 *
 * Run with:
 * php tests/media-source-urls.php
 */

class WP_REST_Request {
	private $params;

	public function __construct( array $params = [] ) {
		$this->params = $params;
	}

	public function get_param( $key ) {
		return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
	}

	public function get_params() {
		return $this->params;
	}
}

class WP_REST_Response {
	private $data;

	public function __construct( $data ) {
		$this->data = $data;
	}

	public function get_data() {
		return $this->data;
	}
}

function register_activation_hook() {}
function add_action() {}
function add_filter() {}
function apply_filters( $hook, $value ) { return $value; }
function rest_ensure_response( $data ) { return new WP_REST_Response( $data ); }
function wp_get_sidebars_widgets() { return []; }
function get_theme_mods() { return []; }
function wp_parse_args( $args, $defaults = [] ) { return array_merge( $defaults, $args ); }
function wp_parse_id_list( $list ) {
	$list = is_array( $list ) ? $list : explode( ',', (string) $list );
	return array_values( array_filter( array_map( 'absint', $list ) ) );
}
function absint( $value ) { return abs( (int) $value ); }
function esc_url_raw( $url ) { return $url; }
function get_attached_file( $attachment_id ) { return __FILE__; }
function wp_get_attachment_url( $attachment_id ) {
	return 'https://starter.example/uploads/' . absint( $attachment_id ) . '.jpg';
}
function attachment_url_to_postid( $url ) {
	if ( preg_match( '/(\d+)\.jpg$/', $url, $matches ) ) {
		return absint( $matches[1] );
	}

	return 0;
}
function get_option( $key, $default = false ) {
	if ( 'starter_content_exporter' === $key ) {
		return [
			'placeholders'   => '101',
			'ignored_images' => '201',
		];
	}

	if ( 'sidebars_widgets' === $key ) {
		return [];
	}

	return $default;
}

require dirname( __DIR__ ) . '/starter_content_exporter.php';

class Media_Source_URL_Test_Exporter extends Starter_Content_Exporter {
	public function expose_rotated_placeholder_url( string $original_image_url, WP_REST_Request $request ): string {
		return $this->get_rotated_placeholder_url( $original_image_url, $request );
	}

	protected function get_rotated_placeholder_url( string $original_image_url, WP_REST_Request $request ): string {
		return 'https://starter.example/redistributable/placeholder-' . attachment_url_to_postid( $original_image_url ) . '.jpg';
	}
}

$exporter = new Media_Source_URL_Test_Exporter();

$plain_response = $exporter->rest_export_data_v2( new WP_REST_Request() )->get_data();
if ( isset( $plain_response['data']['media']['source_urls'] ) ) {
	fwrite( STDERR, "source_urls should not be present without opt-in.\n" );
	exit( 1 );
}
if ( isset( $plain_response['data']['features'] ) ) {
	fwrite( STDERR, "features should not be present without opt-in.\n" );
	exit( 1 );
}

$opt_in_response = $exporter->rest_export_data_v2( new WP_REST_Request( [ 'media_urls' => '1' ] ) )->get_data();
$data            = $opt_in_response['data'];

if ( empty( $data['features'] ) || ! in_array( 'media_source_urls', $data['features'], true ) ) {
	fwrite( STDERR, "media_source_urls feature flag missing from opt-in manifest.\n" );
	exit( 1 );
}

if ( empty( $data['media']['source_urls'][201] ) || 'https://starter.example/uploads/201.jpg' !== $data['media']['source_urls'][201] ) {
	fwrite( STDERR, "Ignored media source URL missing or incorrect.\n" );
	exit( 1 );
}

if ( empty( $data['media']['source_urls'][101] ) || 'https://starter.example/redistributable/placeholder-101.jpg' !== $data['media']['source_urls'][101] ) {
	fwrite( STDERR, "Placeholder media source URL did not use the redistributable placeholder resolver.\n" );
	exit( 1 );
}

echo "Media source URLs are opt-in and redistributable.\n";
