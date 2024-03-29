<?php
/**
 * Include this file in your plugin to autoload Socket
 *
 * @since   Socket 1.0
 * @package Socket
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WP_Socket' ) ) {

	class WP_Socket {

		const VERSION = '1.0.0';

		private array $config;

		private $values;

		private $defaults;

		private string $plugin = 'socket';

		private string $api_base = 'socket';

		private string $options_key = 'socket';

		private string $page_title;
		private string $page_desc = '';

		private string $nav_label;

		public function __construct( array $args ) {

			if ( empty( $args['api_base'] ) || empty( $args['plugin'] ) ) {
				_doing_it_wrong( __FUNCTION__, esc_html( __( 'You need to provide `api_base` and `plugin` args to initialize WP_Sockets.' ) ), esc_html( self::VERSION ) );
				return;
			}

			$this->plugin = $args['plugin'];
			$this->api_base = $args['api_base'];
			$this->page_title = $this->nav_label = esc_html__( 'Socket Admin Page', 'socket' );

			// All
			$this->config = apply_filters( 'socket_config_for_' . $this->plugin, [] );

			// Change instance config if the configuration provides certain entries.
			if ( ! empty( $this->config['options_key'] ) ) {
				$this->options_key = $this->config['options_key'];
			}
			if ( ! empty( $this->config['page_title'] ) ) {
				$this->page_title = $this->config['page_title'];
			}
			if ( ! empty( $this->config['nav_label'] ) ) {
				$this->nav_label = $this->config['nav_label'];
			}

			add_action( 'rest_api_init', [ $this, 'add_rest_routes_api' ] );

			add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );

			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_styles' ] );

			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

			$this->set_defaults( $this->config );
		}

		/**
		 * Register a settings page
		 */
		public function add_admin_menu() {
			// Show an error notice if pretty permalinks are not in use since REST-API routes will fail.
			if ( $this->is_socket_dashboard() && ! get_option( 'permalink_structure' ) ) {
				add_action( 'admin_notices', [ $this, 'pretty_permalinks_notice' ], 5 );
			}

			add_submenu_page(
				'options-general.php',
				$this->page_title,
				$this->nav_label,
				'manage_options',
				$this->plugin,
				[
					$this,
					'socket_options_page',
				]
			);
		}

		public function pretty_permalinks_notice() {
			printf(
				'<div class="notice notice-warning"><p></p><p><strong>%s</strong><br>%s</p><p><a href="%s">%s</a></p><p></p></div>',
				sprintf(
				/* translators: %s: The navigation label */
					__( '%s relies on the REST-API that, in turn, needs this site to use "pretty" permalinks (custom permalink structure), not query-based (plain) links.' ),
					$this->nav_label
				),
				__( 'Please activate the "pretty" permalinks for your site before you start configuring the options bellow.' ),
				esc_url( admin_url( 'options-permalink.php' ) ),
				__( 'Go to the Permalinks screen' )
			);
		}

		public function socket_options_page() { ?>
			<div class="wrap">
				<div class="socket-wrapper">
					<header class="title">
						<h1 class="page-title"><?php echo $this->page_title ?></h1>
						<?php if ( ! empty( $this->page_desc ) ) { ?>
						<div class="description">
							<?php echo $this->page_desc; ?>
						</div>
						<?php } ?>
					</header>
					<div class="content">
						<div id="socket_dashboard"></div>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Register the stylesheets for the admin area.
		 *
		 * @since    1.0.0
		 */
		public function enqueue_styles() {
			if ( $this->is_socket_dashboard() ) {
				wp_register_style(
					'semantic-ui',
					plugin_dir_url( __FILE__ ) . 'css/semantic-ui/semantic.min.css',
					[],
					filemtime( plugin_dir_path( __FILE__ ) . 'css/semantic-ui/semantic.min.css' ),
					'all'
				);

				wp_enqueue_style(
					'socket-dashboard',
					plugin_dir_url( __FILE__ ) . 'css/socket.css',
					[ 'semantic-ui' ],
					filemtime( plugin_dir_path( __FILE__ ) . 'css/socket.css' ),
					'all'
				);
			}
		}

		/**
		 * Register the JavaScript for the admin area.
		 *
		 * @since    1.0.0
		 */
		public function enqueue_scripts() {
			if ( $this->is_socket_dashboard() ) {

				wp_enqueue_media();

				wp_enqueue_script(
					'socket-dashboard',
					plugin_dir_url( __FILE__ ) . 'js/socket.js',
					[
						'jquery',
						'wp-util',
						'wp-api',
						'shortcode',
					],
					filemtime( plugin_dir_path( __FILE__ ) . 'js/socket.js' ),
					true
				);

				$this->localize_js_data( 'socket-dashboard' );
			}
		}

		protected function localize_js_data( $script ) {
			$values = $this->get_option( 'state' );

			$localized_data = [
				'wp_rest'   => [
					'root'         => esc_url_raw( rest_url() ),
					'api_base'     => $this->api_base,
					'nonce'        => wp_create_nonce( 'wp_rest' ),
					'socket_nonce' => wp_create_nonce( 'socket_rest' ),
				],
				'admin_url' => admin_url(),
				'config'    => $this->config,
				'values'    => $this->cleanup_values( $this->values ),
				'wp'        => [
					'taxonomies' => get_taxonomies( [ 'show_in_rest' => true ], 'objects' ),
					'post_types' => get_post_types( [ 'show_in_rest' => true ], 'objects' ),
				],
			];

			wp_localize_script( $script, 'socket', $localized_data );
		}

		protected function cleanup_values( $values ) {
			if ( empty( $values ) ) {
				return [];
			}

			if ( is_object( $values ) ) {
				// This is a sure way to get multi-dimensional objects as array (converts deep).
				$values = json_decode( json_encode( $values ), true );
			}

			foreach ( $values as $key => $value ) {
				// We don't want null values.
				if ( null === $value ) {
					unset( $values[ $key ] );
				}
			}

			return $values;
		}

		public function add_rest_routes_api() {
			//The Following registers an api route with multiple parameters.
			$route = 'socket';

			register_rest_route( $this->api_base, '/option', [
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_get_state' ],
				'permission_callback' => [ $this, 'permission_nonce_callback' ],
			] );

			register_rest_route( $this->api_base, '/option', [
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_set_state' ],
				'permission_callback' => [ $this, 'permission_nonce_callback' ],
			] );

			// debug tools
			register_rest_route( $this->api_base, '/cleanup', [
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_cleanup' ],
				'permission_callback' => [ $this, 'permission_nonce_callback' ],
			] );
		}

		public function permission_nonce_callback() {
			$nonce = '';

			if ( isset( $_REQUEST['socket_nonce'] ) ) {
				$nonce = $_REQUEST['socket_nonce'];
			} elseif ( isset( $_POST['socket_nonce'] ) ) {
				$nonce = $_POST['socket_nonce'];
			}

			return wp_verify_nonce( $nonce, 'socket_rest' );
		}

		public function rest_get_state() {
			$state = $this->get_option( 'state' );
			wp_send_json_success( $state );
		}

		public function rest_set_state() {
			if ( empty( $_POST['name'] ) ) {
				wp_send_json_error( esc_html__( 'Missing the name of the field', 'socket' ) );
			}

			$this->get_values();

			$option_name = sanitize_text_field( $_POST['name'] );

			if ( ! isset( $_POST['value'] ) ) {
				unset( $this->values[ $option_name ] );
			} else {
				$option_value = $_POST['value'];

				// A little sanitization.
				if ( is_array( $option_value ) ) {
					// $option_value = array_map( 'sanitize_text_field', $option_value );
				} else {
					$option_value = sanitize_text_field( $option_value );
				}
				$this->values[ $option_name ] = $option_value;
			}

			wp_send_json_success( $this->save_values() );
		}

		public function rest_cleanup() {

			if ( empty( $_POST['test1'] ) || empty( $_POST['test2'] ) || empty( $_POST['confirm'] ) ) {
				wp_send_json_error( 'nah' );
			}

			if ( (int) $_POST['test1'] + (int) $_POST['test2'] === (int) $_POST['confirm'] ) {
				$current_user = _wp_get_current_user();

				$this->values = [];
				wp_send_json_success( $this->save_values() );

				wp_send_json_success( 'ok' );
			}

			wp_send_json_error( [
				$_POST['test1'],
				$_POST['test2'],
				$_POST['confirm'],
			] );
		}

		/**
		 * Helpers
		 **/
		public function is_socket_dashboard() {
			if ( is_admin() && ! empty( $_GET['page'] ) && $this->plugin === $_GET['page'] ) {
				return true;
			}

			return false;
		}

		protected function set_values() {
			$this->values = get_option( $this->plugin );
			if ( $this->values === false ) {
				$this->values = $this->defaults;
			} elseif ( ! empty( $this->defaults ) && count( array_diff_key( $this->defaults, $this->values ) ) != 0 ) {
				$this->values = array_merge( $this->defaults, $this->values );
			}
		}

		protected function save_values() {
			return update_option( $this->plugin, $this->values );
		}

		protected function set_defaults( $config ) {

			if ( ! empty( $config ) ) {

				foreach ( $config as $key => $value ) {

					if ( ! is_array( $value ) ) {
						continue;
					}

					$result = array_key_exists( 'default', $value );

					if ( $result ) {
						$this->defaults[ $key ] = $value['default'];
					} elseif ( is_array( $value ) ) {
						$this->set_defaults( $value );
					}
				}
			}
		}

		protected function get_values() {
			if ( empty( $this->values ) ) {
				$this->set_values();
			}

			return $this->values;
		}

		protected function get_option( $option, $default = null ) {
			$values = $this->get_values();

			if ( ! empty( $values[ $option ] ) ) {
				return $values[ $option ];
			}

			if ( $default !== null ) {
				return $default;
			}

			return null;
		}

		protected function array_key_exists_r( $needle, $haystack ) {
			$result = array_key_exists( $needle, $haystack );

			if ( $result ) {
				return $result;
			}

			foreach ( $haystack as $v ) {
				if ( is_array( $v ) ) {
					$result = $this->array_key_exists_r( $needle, $v );
				}
				if ( $result ) {
					return $result;
				}
			}

			return $result;
		}

		/**
		 * Cloning is forbidden.
		 *
		 * @since 1.0.0
		 */
		public function __clone() {
			_doing_it_wrong( __FUNCTION__, esc_html( __( 'Cheatin&#8217; huh?' ) ), esc_html( WP_Socket::VERSION ) );
		}

		/**
		 * Unserializing instances of this class is forbidden.
		 *
		 * @since 1.0.0
		 */
		public function __wakeup() {
			_doing_it_wrong( __FUNCTION__, esc_html( __( 'Cheatin&#8217; huh?' ) ), esc_html( WP_Socket::VERSION ) );
		}
	}
}

/**
 * Add the necessary filter to each post type
 **/
function wp_socket_rest_api_filter_add_filters() {
	$post_types = get_post_types( array( 'show_in_rest' => true ), 'objects' );

	foreach ( $post_types as $name => $post_type ) {
		add_filter( 'rest_' . $name . '_query', 'wp_socket_rest_api_filter_add_filter_param', 10, 2 );
	}
}

add_action( 'rest_api_init', 'wp_socket_rest_api_filter_add_filters', 11 );

/**
 * Add the filter parameter
 *
 * @param array           $args    The query arguments.
 * @param WP_REST_Request $request Full details about the request.
 *
 * @return array $args.
 **/
function wp_socket_rest_api_filter_add_filter_param( array $args, WP_REST_Request $request ): array {
	// Bail out if no filter parameter is set.
	if ( empty( $request['filter'] ) || ! is_array( $request['filter'] ) ) {
		return $args;
	}
	$filter = $request['filter'];
	if ( isset( $filter['per_page'] ) && ( (int) $filter['per_page'] >= 1 && (int) $filter['per_page'] <= 100 ) ) {
		$args['post_per_page'] = $filter['per_page'];
	}
	global $wp;
	$vars = apply_filters( 'query_vars', $wp->public_query_vars );
	foreach ( $vars as $var ) {
		if ( isset( $filter[ $var ] ) ) {
			$args[ $var ] = $filter[ $var ];
		}
	}

	return $args;
}
