<?php
/**
 * Include this file in your plugin to autoload Socket
 *
 * @package Socket
 * @since Socket 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WP_Socket' ) ) {

	class WP_Socket {

		private $config;

		private $values;

		private $defaults;

		private $plugin = 'socket';

		private $api_base = 'socket';

		private $options_key = 'socket';

		private $page_title;

		private $nav_label;

		public function __construct( $args ) {

			if ( empty( $args['api_base'] ) || empty( $args['plugin'] ) ) {
				return ;
			}

			$this->plugin = $args['plugin'];

			$this->api_base = $args['api_base'];

			$this->page_title = $this->nav_label = esc_html__( 'Socket Admin Page', 'socket' );

			$this->config = apply_filters( 'socket_config_for_' . $this->plugin, array() );

			if ( ! empty( $this->config['options_key'] ) ) {
				$this->options_key = $this->config['options_key'];
			}

			if ( ! empty( $this->config['page_title'] ) ) {
				$this->page_title = $this->config['page_title'];
			}

			if ( ! empty( $this->config['nav_label'] ) ) {
				$this->nav_label = $this->config['nav_label'];
			}

			add_action( 'rest_api_init', array( $this, 'add_rest_routes_api' ) );

			add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );

			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

			$this->set_defaults( $this->config );
		}

		/**
		 * Cloning is forbidden.
		 *
		 * @since 1.0.0
		 */
		public function __clone() {
			_doing_it_wrong( __FUNCTION__, esc_html( __( 'Cheatin&#8217; huh?' ) ), esc_html( $this->_version ) );
		} // End __clone ()

		/**
		 * Unserializing instances of this class is forbidden.
		 *
		 * @since 1.0.0
		 */
		public function __wakeup() {
			_doing_it_wrong( __FUNCTION__, esc_html( __( 'Cheatin&#8217; huh?' ) ), esc_html( $this->_version ) );
		} // End __wakeup ()

		// Register a settings page
		function add_admin_menu() {
			$admin_page = add_submenu_page(
				'options-general.php',
				$this->page_title,
				$this->nav_label,
				'manage_options',
				$this->plugin,
				array(
					$this,
					'socket_options_page'
				)
			);
		}

		function socket_options_page() {
			$state = $this->get_option( 'state' ); ?>
			<link rel="stylesheet" href="//cdnjs.cloudflare.com/ajax/libs/semantic-ui/2.2.2/semantic.min.css"></link>
			<div class="wrap">
				<div class="socket-wrapper">
					<header class="title">
						<h1 class="page-title"><?php echo $this->page_title ?></h1>
<!--						<div class="description">--><?php //echo $this->description ?><!--</div>-->
					</header>
					<div class="content">
						<div id="socket_dashboard"></div>
					</div>
				</div>
			</div>
			<?php
		}

		function settings_init() {
			register_setting( 'socket', 'socket_settings' );

			add_settings_section(
				'socket_section',
				$this->page_title . esc_html__( ' My plugin description description', 'socket' ),
				null,
				'socket'
			);
		}

		/**
		 * Register the stylesheets for the admin area.
		 *
		 * @since    1.0.0
		 */
		public function enqueue_styles() {
			if ( $this->is_socket_dashboard() ) {
				wp_enqueue_style(
					'socket-dashboard',
					plugin_dir_url( __FILE__ ) . 'css/socket.css',
					array(),
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

				wp_enqueue_script( 'socket-dashboard', plugin_dir_url( __FILE__ ) . 'js/socket.js', array(
					'jquery',
					'wp-util',
					'wp-api',
					'shortcode'
				),
					filemtime( plugin_dir_path( __FILE__ ) . 'js/socket.js' ), true );

				$this->localize_js_data( 'socket-dashboard' );
			}
		}

		function localize_js_data( $script ) {
			$values = $this->get_option( 'state' );

			$localized_data = array(
				'wp_rest'   => array(
					'root'         => esc_url_raw( rest_url() ),
					'api_base'     => $this->api_base,
					'nonce'        => wp_create_nonce( 'wp_rest' ),
					'socket_nonce' => wp_create_nonce( 'socket_rest' )
				),
				'admin_url' => admin_url(),
				'config'    => $this->config,
				'values'    => $this->values,
				'wp' => array(
					'taxonomies' => get_taxonomies( array( 'show_in_rest' => true ), 'objects' ),
					'post_types' =>get_post_types( array( 'show_in_rest' => true ), 'objects' )
				)
			);


			wp_localize_script( $script, 'socket', $localized_data );
		}

		function add_rest_routes_api() {
			//The Following registers an api route with multiple parameters.
			$route = 'socket';

			register_rest_route( $this->api_base, '/option', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_state' ),
				'permission_callback' => array( $this, 'permission_nonce_callback' )
			) );

			register_rest_route( $this->api_base, '/option', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_set_state' ),
				'permission_callback' => array( $this, 'permission_nonce_callback' )
			) );

			// debug tools
			register_rest_route( $this->api_base, '/cleanup', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_cleanup' ),
				'permission_callback' => array( $this, 'permission_nonce_callback' ),
			) );
		}

		function permission_nonce_callback() {
			$nonce = '';

			if ( isset( $_REQUEST['socket_nonce'] ) ) {
				$nonce = $_REQUEST['socket_nonce'];
			} elseif ( isset( $_POST['socket_nonce'] ) ) {
				$nonce = $_POST['socket_nonce'];
			}

			return wp_verify_nonce( $nonce, 'socket_rest' );
		}

		function rest_get_state() {
			$state = $this->get_option( 'state' );
			wp_send_json_success( $state );
		}

		function rest_set_state() {
			if ( empty( $_POST['name'] ) ) {
				wp_send_json_error( esc_html__( 'Missing the name of the field', 'socket' ) );
			}

			$this->get_values();

			$option_name = sanitize_text_field( $_POST['name'] );

			if ( ! isset( $_POST['value'] ) ) {
				$this->values[ $option_name ] = null;
			} else {
				$option_value = $_POST['value'];

				// a little sanitize
				if ( is_array( $option_value ) ) {
//					$option_value = array_map( 'sanitize_text_field', $option_value );
				} else {
					$option_value = sanitize_text_field($option_value);
				}
				$this->values[ $option_name ] = $option_value;
			}

			wp_send_json_success( $this->save_values() );
		}

		function rest_cleanup() {

			if ( empty( $_POST['test1'] ) || empty( $_POST['test2'] ) || empty( $_POST['confirm'] ) ) {
				wp_send_json_error( 'nah' );
			}

			if ( (int) $_POST['test1'] + (int) $_POST['test2'] === (int) $_POST['confirm'] ) {
				$current_user = _wp_get_current_user();

				$this->values = array();
				wp_send_json_success( $this->save_values() );

				wp_send_json_success( 'ok' );
			}

			wp_send_json_error( array(
				$_POST['test1'],
				$_POST['test2'],
				$_POST['confirm']
			) );
		}

		/**
		 * Helpers
		 **/
		function is_socket_dashboard() {
			if ( ! empty( $_GET['page'] ) && $this->plugin === $_GET['page'] ) {
				return true;
			}

			return false;
		}

		function set_values() {
			$this->values = get_option( $this->plugin );
			if ( $this->values === false ) {
				$this->values = $this->defaults;
			} elseif ( ! empty( $this->defaults ) && count( array_diff_key( $this->defaults, $this->values ) ) != 0 ) {
				$this->values = array_merge( $this->defaults, $this->values );
			}
		}

		function save_values() {
			return update_option( $this->plugin, $this->values );
		}

		function set_defaults( $array ) {

			if ( ! empty( $array ) ) {

				foreach ( $array as $key => $value ) {

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

		function get_values() {
			if ( empty( $this->values ) ) {
				$this->set_values();
			}

			return $this->values;
		}

		function get_option( $option, $default = null ) {
			$values = $this->get_values();

			if ( ! empty( $values[ $option ] ) ) {
				return $values[ $option ];
			}

			if ( $default !== null ) {
				return $default;
			}

			return null;
		}

		function array_key_exists_r( $needle, $haystack ) {
			$result = array_key_exists( $needle, $haystack );

			if ( $result ) {
				return $result;
			}

			foreach ( $haystack as $v ) {
				if ( is_array( $v ) ) {
					$result = array_key_exists_r( $needle, $v );
				}
				if ( $result ) {
					return $result;
				}
			}

			return $result;
		}
	}
}

/**
 * Add the necessary filter to each post type
 **/
function rest_api_filter_add_filters() {
	$post_types = get_post_types( array( 'show_in_rest' => true ), 'objects' );

	foreach ( $post_types as $name => $post_type ) {
		add_filter( 'rest_' . $name. '_query', 'rest_api_filter_add_filter_param', 10, 2 );
	}
}
add_action( 'rest_api_init', 'rest_api_filter_add_filters', 11 );

/**
 * Add the filter parameter
 *
 * @param  array           $args    The query arguments.
 * @param  WP_REST_Request $request Full details about the request.
 * @return array $args.
 **/
function rest_api_filter_add_filter_param( $args, $request ) {
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
