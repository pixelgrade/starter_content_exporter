<?php
/**
 * Pins the Assistant Catalog source-side curation contract.
 *
 * Standalone: run with `php tests/assistant-catalog-test.php`.
 */

class WP_Post {
	public function __construct( $props = array() ) {
		foreach ( $props as $key => $value ) {
			$this->{$key} = $value;
		}
	}
}

class WP_REST_Request {
	private array $params;

	public function __construct( $params = array() ) {
		$this->params = $params;
	}

	public function get_params(): array {
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

class WP_Query {
	public function query( $args ) {
		$posts    = array_values( $GLOBALS['sce_posts'] );
		$include  = empty( $args['post__in'] ) ? array() : array_map( 'intval', (array) $args['post__in'] );
		$type     = empty( $args['post_type'] ) ? 'any' : $args['post_type'];
		$filtered = array();

		foreach ( $posts as $post ) {
			if ( 'any' !== $type && $post->post_type !== $type ) {
				continue;
			}
			if ( ! empty( $include ) && ! in_array( (int) $post->ID, $include, true ) ) {
				continue;
			}
			$filtered[] = $post;
		}

		return $filtered;
	}
}

$GLOBALS['sce_posts']      = array();
$GLOBALS['sce_post_meta']  = array();
$GLOBALS['sce_filters']    = array();
$GLOBALS['sce_actions']    = array();

function register_activation_hook() {}
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['sce_actions'][ $hook ][] = $callback; }
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['sce_filters'][ $hook ][] = $callback; }
function apply_filters( $hook, $value ) { return $value; }
function rest_ensure_response( $value ) { return new WP_REST_Response( $value ); }
function get_option( $key, $default = false ) { return $default; }
function get_post_meta( $post_id, $key = '', $single = false ) {
	$post_id = (int) $post_id;
	$meta    = isset( $GLOBALS['sce_post_meta'][ $post_id ] ) ? $GLOBALS['sce_post_meta'][ $post_id ] : array();
	if ( '' === $key ) {
		return $meta;
	}
	if ( ! isset( $meta[ $key ] ) ) {
		return $single ? '' : array();
	}
	if ( $single ) {
		return is_array( $meta[ $key ] ) ? reset( $meta[ $key ] ) : $meta[ $key ];
	}

	return is_array( $meta[ $key ] ) ? $meta[ $key ] : array( $meta[ $key ] );
}
function update_post_meta( $post_id, $key, $value ) { $GLOBALS['sce_post_meta'][ (int) $post_id ][ $key ] = array( $value ); }
function delete_post_meta( $post_id, $key ) { unset( $GLOBALS['sce_post_meta'][ (int) $post_id ][ $key ] ); }
function get_post_taxonomies() { return array(); }
function wp_get_object_terms() { return array(); }
function is_taxonomy_hierarchical() { return false; }
function is_wp_error( $value ) { return false; }
function sanitize_text_field( $value ) { return trim( wp_strip_all_tags( (string) $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function wp_strip_all_tags( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_parse_id_list( $value ) { return array_values( array_filter( array_map( 'absint', is_array( $value ) ? $value : explode( ',', (string) $value ) ) ) ); }
function absint( $value ) { return abs( (int) $value ); }
function esc_html__( $value ) { return $value; }
function has_blocks( $content ) { return false !== strpos( (string) $content, '<!-- wp:' ); }

function sce_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

function sce_assert_true( $actual, $message ) {
	sce_assert_same( true, (bool) $actual, $message );
}

require dirname( __DIR__ ) . '/starter_content_exporter.php';

$exporter = new Starter_Content_Exporter();

sce_assert_true( method_exists( $exporter, 'save_assistant_catalog_record' ), 'Exporter must expose a production save method for Assistant Catalog records.' );

$GLOBALS['sce_posts'][52] = new WP_Post( array(
	'ID'                    => 52,
	'post_title'            => 'Work',
	'post_content'          => '<!-- wp:heading --><h2>Work</h2><!-- /wp:heading -->',
	'post_content_filtered' => '',
	'post_excerpt'          => '',
	'post_status'           => 'publish',
	'post_name'             => 'work',
	'post_type'             => 'page',
	'post_date'             => '2026-01-01 00:00:00',
	'post_date_gmt'         => '2026-01-01 00:00:00',
	'post_modified'         => '2026-01-01 00:00:00',
	'post_modified_gmt'     => '2026-01-01 00:00:00',
	'post_parent'           => 0,
	'menu_order'            => 0,
	'guid'                  => 'https://starter.test/?page_id=52',
) );

$exporter->save_assistant_catalog_record( 52, array(
	'enabled'     => '0',
	'title'       => 'Selected Work',
	'description' => 'A curated portfolio index.',
	'order'       => '30',
	'group'       => 'portfolio',
	'tags'        => 'portfolio, case-study',
	'reason'      => 'Title-only page; intentionally hidden.',
) );

sce_assert_same( '0', get_post_meta( 52, '_sce_pixassist_page_pattern_enabled', true ), 'Enabled state must persist as post meta.' );
sce_assert_same( 'Selected Work', get_post_meta( 52, '_sce_pixassist_page_pattern_title', true ), 'Catalog title override must persist as post meta.' );
sce_assert_same( array( 'portfolio', 'case-study' ), get_post_meta( 52, '_sce_pixassist_page_pattern_tags', true ), 'Tags must persist as a normalized array.' );

$response = $exporter->rest_export_posts_v2( new WP_REST_Request( array(
	'post_type' => 'page',
	'include'   => '52',
) ) );
$payload  = $response->get_data();
$pattern  = $payload['data']['posts'][0]->pixassist['pagePattern'];

sce_assert_same( false, $pattern['enabled'], 'REST payload must expose explicit hidden state under pixassist.pagePattern.enabled.' );
sce_assert_same( 30, $pattern['order'], 'REST payload must expose catalog order as an integer.' );
sce_assert_same( 'portfolio', $pattern['group'], 'REST payload must expose catalog group.' );
sce_assert_same( array( 'portfolio', 'case-study' ), $pattern['tags'], 'REST payload must expose catalog tags.' );
sce_assert_same( 'Selected Work', $pattern['title'], 'REST payload must expose catalog title override.' );
sce_assert_same( 'A curated portfolio index.', $pattern['description'], 'REST payload must expose catalog description override.' );
sce_assert_same( 'Title-only page; intentionally hidden.', $pattern['reason'], 'REST payload must expose editorial reason.' );

$GLOBALS['sce_posts'][53] = new WP_Post( array(
	'ID'                    => 53,
	'post_title'            => 'Legacy Default',
	'post_content'          => '<!-- wp:paragraph --><p>Reusable content.</p><!-- /wp:paragraph -->',
	'post_content_filtered' => '',
	'post_excerpt'          => '',
	'post_status'           => 'publish',
	'post_name'             => 'legacy-default',
	'post_type'             => 'page',
	'post_date'             => '2026-01-01 00:00:00',
	'post_date_gmt'         => '2026-01-01 00:00:00',
	'post_modified'         => '2026-01-01 00:00:00',
	'post_modified_gmt'     => '2026-01-01 00:00:00',
	'post_parent'           => 0,
	'menu_order'            => 0,
	'guid'                  => 'https://starter.test/?page_id=53',
) );

$response = $exporter->rest_export_posts_v2( new WP_REST_Request( array(
	'post_type' => 'page',
	'include'   => '53',
) ) );
$payload  = $response->get_data();
$pattern  = $payload['data']['posts'][0]->pixassist['pagePattern'];

sce_assert_same( true, $pattern['enabled'], 'Missing Assistant Catalog metadata must default to visible for backward compatibility.' );
sce_assert_same( '', $pattern['title'], 'Missing title override must stay empty so consumers keep source titles.' );
sce_assert_same( array(), $pattern['tags'], 'Missing tags must normalize to an empty array.' );
sce_assert_true( isset( $pattern['quality']['warnings'] ), 'REST payload must include quality warnings for editorial review.' );

$GLOBALS['sce_posts'][54] = new WP_Post( array(
	'ID'                    => 54,
	'post_title'            => 'Work',
	'post_content'          => '<!-- wp:heading --><h2>Work</h2><!-- /wp:heading -->',
	'post_content_filtered' => '',
	'post_excerpt'          => '',
	'post_status'           => 'publish',
	'post_name'             => 'work-with-media',
	'post_type'             => 'page',
	'post_date'             => '2026-01-01 00:00:00',
	'post_date_gmt'         => '2026-01-01 00:00:00',
	'post_modified'         => '2026-01-01 00:00:00',
	'post_modified_gmt'     => '2026-01-01 00:00:00',
	'post_parent'           => 0,
	'menu_order'            => 0,
	'guid'                  => 'https://starter.test/?page_id=54',
) );
$GLOBALS['sce_post_meta'][54] = array(
	'_thumbnail_id' => array( '100' ),
);

$response = $exporter->rest_export_posts_v2( new WP_REST_Request( array(
	'post_type' => 'page',
	'include'   => '54',
) ) );
$payload  = $response->get_data();
$warnings = array_column( $payload['data']['posts'][0]->pixassist['pagePattern']['quality']['warnings'], 'code' );

sce_assert_true( in_array( 'title_only', $warnings, true ), 'Quality hints must flag title-only pages even when media meta exists.' );
sce_assert_true( in_array( 'near_empty_content', $warnings, true ), 'Quality hints must flag near-empty content even when media meta exists.' );

$response = $exporter->rest_export_posts_v2( new WP_REST_Request( array(
	'post_type' => 'page',
	'include'   => '52',
) ) );
$payload  = $response->get_data();
$warnings = array_column( $payload['data']['posts'][0]->pixassist['pagePattern']['quality']['warnings'], 'code' );

sce_assert_true( in_array( 'title_only', $warnings, true ), 'Quality hints must flag title-only pages as warnings.' );
sce_assert_true( in_array( 'near_empty_content', $warnings, true ), 'Quality hints must flag empty/near-empty content as warnings.' );
sce_assert_true( in_array( 'no_meaningful_blocks_media', $warnings, true ), 'Quality hints must flag records with no meaningful blocks/media.' );

echo "Assistant Catalog contract OK\n";
