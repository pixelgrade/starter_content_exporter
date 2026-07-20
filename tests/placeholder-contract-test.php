<?php
/**
 * Regression contract for placeholder and ignored-image remapping.
 *
 * Set SCE_PLUGIN_FILE to exercise another exporter build, for example the
 * currently deployed starter exporter.
 */

error_reporting( E_ALL & ~E_DEPRECATED );

class WP_REST_Request {
	private $params;

	public function __construct( array $params = array() ) {
		$this->params = $params;
	}

	public function get_params(): array {
		return $this->params;
	}
}

function register_activation_hook() {}
function add_action() {}
function add_filter() {}
function get_option( $key, $default = false ) {
	return 'starter_content_exporter' === $key ? array() : $default;
}
function get_theme_mods() {
	return array();
}
function esc_url_raw( $value ) {
	return filter_var( $value, FILTER_SANITIZE_URL );
}
function wp_is_numeric_array( $value ) {
	if ( ! is_array( $value ) ) {
		return false;
	}

	return array_keys( $value ) === range( 0, count( $value ) - 1 );
}
function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, $args );
}
function absint( $value ) {
	return abs( (int) $value );
}

$GLOBALS['sce_attachment_ids'] = array(
	'https://source.test/original.jpg' => 501,
	'https://source.test/ignored.jpg'  => 99,
	'https://source.test/wp-content/uploads/anchor.png' => 257,
);

function attachment_url_to_postid( $url ) {
	return $GLOBALS['sce_attachment_ids'][ $url ] ?? 0;
}

function sce_placeholder_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $message . PHP_EOL );
		fwrite( STDERR, 'Expected: ' . var_export( $expected, true ) . PHP_EOL );
		fwrite( STDERR, 'Actual:   ' . var_export( $actual, true ) . PHP_EOL );
		exit( 1 );
	}
}

$plugin_file = getenv( 'SCE_PLUGIN_FILE' );
if ( empty( $plugin_file ) ) {
	$plugin_file = dirname( __DIR__ ) . '/starter_content_exporter.php';
}

require_once $plugin_file;

class SCE_Placeholder_Contract_Exporter extends Starter_Content_Exporter {
	public function placeholder_details( $original_id, WP_REST_Request $request ): array {
		return $this->get_rotated_placeholder( $original_id, $request );
	}

	public function placeholder_id( $original_id, WP_REST_Request $request ) {
		return $this->get_rotated_placeholder_id( $original_id, $request );
	}

	public function placeholder_url( string $original_url, WP_REST_Request $request ): string {
		return $this->get_rotated_placeholder_url( $original_url, $request );
	}

	public function inline_image_urls( string $content, WP_REST_Request $request ): string {
		return $this->replace_content_image_urls( $content, $request, '/wp-content/uploads/' );
	}
}

$ignored_image = array(
	'id'    => 9099,
	'sizes' => array(
		'full' => array(
			'url'    => 'https://local.test/ignored.jpg',
			'width'  => 1200,
			'height' => 800,
		),
	),
);
$placeholder_image = array(
	'id'    => 6175,
	'sizes' => array(
		'full' => array(
			'url'    => 'https://local.test/placeholder.jpg',
			'width'  => 1200,
			'height' => 800,
		),
	),
);

$no_placeholders_request = new WP_REST_Request(
	array(
		'placeholders'   => array(),
		'ignored_images' => array( 99 => $ignored_image ),
	)
);
$exporter               = new SCE_Placeholder_Contract_Exporter();

sce_placeholder_assert_same( array(), $exporter->placeholder_details( 501, $no_placeholders_request ), 'Ignored images must not be used as fallback placeholders.' );
sce_placeholder_assert_same( 501, $exporter->placeholder_id( 501, $no_placeholders_request ), 'A missing placeholder pool must preserve the original attachment ID.' );

try {
	$empty_placeholder_url = $exporter->placeholder_url( 'https://source.test/original.jpg', $no_placeholders_request );
} catch ( Throwable $exception ) {
	$empty_placeholder_url = get_class( $exception ) . ': ' . $exception->getMessage();
}
sce_placeholder_assert_same( '#', $empty_placeholder_url, 'A missing placeholder pool must return the safe URL sentinel.' );

sce_placeholder_assert_same( $ignored_image, $exporter->placeholder_details( 99, $no_placeholders_request ), 'An explicitly ignored image must keep its imported attachment details.' );
sce_placeholder_assert_same( 9099, $exporter->placeholder_id( 99, $no_placeholders_request ), 'An explicitly ignored image must keep its imported attachment ID.' );
sce_placeholder_assert_same( 'https://local.test/ignored.jpg', $exporter->placeholder_url( 'https://source.test/ignored.jpg', $no_placeholders_request ), 'An explicitly ignored image must keep its imported full URL.' );

$placeholder_request = new WP_REST_Request(
	array(
		'placeholders'   => array( 175 => $placeholder_image ),
		'ignored_images' => array( 99 => $ignored_image ),
	)
);
$exporter            = new SCE_Placeholder_Contract_Exporter();

sce_placeholder_assert_same( $placeholder_image, $exporter->placeholder_details( 501, $placeholder_request ), 'Non-ignored media must rotate through the configured placeholder pool.' );
sce_placeholder_assert_same( 6175, $exporter->placeholder_id( 501, $placeholder_request ), 'Non-ignored media must use the imported placeholder attachment ID.' );
sce_placeholder_assert_same( 'https://local.test/placeholder.jpg', $exporter->placeholder_url( 'https://source.test/original.jpg', $placeholder_request ), 'Non-ignored media must use the imported placeholder full URL.' );

$inline_ignored = array(
	'id'    => 2864,
	'sizes' => array(
		'full' => array(
			'url'    => 'https://local.test/wp-content/uploads/anchor.png',
			'width'  => 12,
			'height' => 13,
		),
	),
);
$mixed_block_request = new WP_REST_Request(
	array(
		'placeholders'   => array( 175 => $placeholder_image ),
		'ignored_images' => array( 257 => $inline_ignored ),
	)
);
$mixed_block_content = '<!-- wp:paragraph --><p><img src="https://source.test/wp-content/uploads/anchor.png" alt="anchor" class="aligncenter wp-image-1199 size-full"></p><!-- /wp:paragraph -->';
$mixed_block_expected = '<!-- wp:paragraph --><p><img src="https://local.test/wp-content/uploads/anchor.png" alt="anchor" class="aligncenter wp-image-2864 size-full"></p><!-- /wp:paragraph -->';

sce_placeholder_assert_same(
	$mixed_block_expected,
	$exporter->inline_image_urls( $mixed_block_content, $mixed_block_request ),
	'Inline images nested in non-image blocks must keep ignored media and repair stale wp-image classes.'
);

$already_local_content = '<!-- wp:image --><figure><img src="https://local.test/wp-content/uploads/already-remapped.jpg" class="wp-image-2864"></figure><!-- /wp:image -->';
sce_placeholder_assert_same(
	$already_local_content,
	$exporter->inline_image_urls( $already_local_content, $mixed_block_request ),
	'An already-remapped requester URL that only resembles the source upload path must not be rotated again.'
);

echo "Starter placeholder media contract OK\n";
