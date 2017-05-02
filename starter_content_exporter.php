<?php
/**
 * Plugin Name:       Starter Content Exporter
 * Plugin URI:        https://andrei-lupu.com/
 * Description:       This is a Socket Framework Plugin Example
 * Version:           0.0.1
 * Author:            Andrei Lupu
 * Author URI:        https://andrei-lupu.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       socket
 * Domain Path:       /languages
 */

if ( ! class_exists( 'Starter_Content_Exporter' ) ) {

	class Starter_Content_Exporter {

		public function __construct() {

			add_action( 'init', array( $this, 'init_demo_exporter' ), 50 );
			add_filter( 'socket_config_for_starter_content_exporter', array( $this, 'add_socket_config' ) );
			add_action( 'rest_api_init', array( $this, 'add_rest_routes_api' ) );
		}

		function init_demo_exporter(){
			require_once( plugin_dir_path( __FILE__ ) . 'socket/loader.php' );
			$socket = new WP_Socket( array(
				'plugin' => 'starter_content_exporter',
				'api_base' => 'sce/v1'
			) );
		}

		function add_socket_config ( $config ) {
			$config = array(
				'page_title'  => 'Pick Exports',
				'description' => '',
				'nav_label'   => 'Demo Exporter',
				'options_key' => 'starter_content_exporter',
				'sockets'     => array()
			);

			$config['sockets']['export_media'] = array(
				'label' => 'Media',
				'items' => array(
					'placeholders' => array(
						'type'  => 'gallery',
						'label' => 'Placeholder Images',
						'description' => 'Wha sad as das dasdas dasdasadas as as das'
					),
					'ignored_images' => array(
						'type'  => 'gallery',
						'label' => 'Ignored Images',
						'description' => 'Wha sad as das das ddasd  dasdas dasdasadas as as das'
					),
				)
			);

			$config['sockets']['export_post_types'] = array(
				'label' => 'Posts & Post Types',
				'items' => array()
			);

			$post_types = get_post_types( array( 'show_in_rest' => true ), 'objects' );

			foreach ( $post_types as $post_type => $post_type_config ) {

				if ( 'attachment' === $post_type ) {
					continue;
				}

				$config['sockets']['export_post_types']['items']['post_type_' . $post_type . '_start'] = array(
					'type' => 'divider',
					'html' => $post_type,
				);

				$config['sockets']['export_post_types']['items']['post_type_' . $post_type] = array(
					'type'         => 'post_select',
					'label' => $post_type_config->label,
					'query' => array(
						'post_type' => $post_type
					)
				);

				$taxonomy_objects = get_object_taxonomies( $post_type, 'objects' );

				if ( ! empty( $taxonomy_objects ) ) {
					foreach ($taxonomy_objects as $tax => $tax_config ) {

						if ( ! $tax_config->show_ui || in_array( $tax, array('job_listing_type') ) ) {
							continue;
						}

						$config['sockets']['export_post_types']['items']['tax_' . $tax] = array(
							'type'         => 'tax_select',
							'label' => $tax_config->label,
							'query' => array(
								'taxonomy' => $tax
							)
						);
					}
				}

				$config['sockets']['export_post_types']['items']['post_type_' . $post_type . '_end'] = array(
					'type' => 'divider',
					'html' => ''//'End of the ' . $post_type,
				);
			}

			return $config;
		}

		function add_rest_routes_api() {
			//The Following registers an api route with multiple parameters.
			register_rest_route( 'sce/v1', '/data', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_export_data' ),
			) );

			//The Following registers an api route with multiple parameters.
			register_rest_route( 'sce/v1', '/media', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_export_media' ),
				'args' => array(
					'id' => array(
						'validate_callback' => 'is_numeric'
					),
				),
			) );

			//The Following registers an api route with multiple parameters.
			register_rest_route( 'sce/v1', '/posts', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_export_posts' ),
				'args' => array(
					'include' => array(
						'validate_callback' => array( $this, 'is_comma_list'),
						'required' => true
					),
					'placeholders' => array(
						'validate_callback' => array( $this, 'is_numeric_array')
					)
				),
			) );

			register_rest_route( 'sce/v1', '/terms', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_export_terms' ),
				'args' => array(
					'include' => array(
						'validate_callback' => array( $this, 'is_comma_list'),
						'required' => true
					),
					'placeholders' => array(
						'validate_callback' => array( $this, 'is_numeric_array')
					)
				),
			) );
		}

		function is_comma_list( $value,$request,$name) {
			$is_number = false;

			$e = explode(',', $value );

			if ( ! empty( $e ) ) {
				foreach ($e as $val ) {
					if ( ! is_numeric( $val ) ) {
						$is_number = false;
						break;
					}
					$is_number = true;
				}
			}

			return $is_number;
		}

		function is_numeric_array( $value,$request,$name ) {

			if ( ! is_array( $value ) ) {
				return false;
			}

			$is_number = false;

			foreach ($value as $val ) {
				if ( ! is_numeric( $val ) ) {
					$is_number = false;
					break;
				}
				$is_number = true;
			}

			return $is_number;
		}

		function rest_export_posts(){
			$options = get_option('starter_content_exporter');

			$query_args = array(
				'include' => $_GET['include'],
				'posts_per_page' => 100
			);

			if ( ! empty( $_GET['post_type'] ) ) {
				$query_args['post_type'] = $_GET['post_type'];
			}

			$posts = get_posts( $query_args );

			$return = array();

			foreach ( $posts as $key => $post ) {
				$return[$key]['title'] = $post->post_title;
				$return[$key]['meta'] = get_post_meta( $post->ID );
			}

			$placeholders = $options['placeholders'];

			$cliend_placeholders = array();

			if ( isset( $_GET['placeholders'] ) ) {
				$cliend_placeholders = $_GET['placeholders'];
			}

			return rest_ensure_response( $posts );
		}

		function rest_export_terms(){
			$options = get_option('starter_content_exporter');

			$query_args = array(
				'include' => $_GET['include']
			);

			if ( ! empty( $_GET['taxonomy'] ) ) {
				$query_args['taxonomy'] = $_GET['taxonomy'];
			}

			$terms = get_terms( $query_args );

			$return = array();

			foreach ( $posts as $key => $post ) {
				$return[$key]['title'] = $post->post_title;
				$return[$key]['meta'] = get_post_meta( $post->ID );
			}

			$placeholders = $options['placeholders'];

			$cliend_placeholders = array();

			if ( isset( $_GET['placeholders'] ) ) {
				$cliend_placeholders = $_GET['placeholders'];
			}

			return rest_ensure_response( $terms );
		}

		function rest_export_media(){
			if ( empty( $_GET['id'] ) ) {
				return rest_ensure_response( 'I need an id!' );
			}

			$id = $_GET['id'];

			$file = get_attached_file( $id );

			$type = pathinfo($file, PATHINFO_EXTENSION);

			$data = file_get_contents($file);

			$base64 = 'data:image/' . $type . ';base64,' . base64_encode($data);

			return rest_ensure_response( array(
				'title' => get_the_title( $id ),
				'mime_type' => get_post_mime_type($id),
				'ext' => $type,
				'data' => $base64
			) );
		}

		function rest_export_data(){
			$options = get_option('starter_content_exporter');

			$return = array(
				'media' => array(
					'placeholders' => explode(',', $options['placeholders'] ),
					'ignored' => explode(',', $options['ignored_images'] ),
				),
				'post_types' => array(),
				'taxonomies' => array(),
				'options' => array()
			);

			foreach ( $options as $key => $option ) {
				if ( strpos( $key, 'post_type_' ) !== false ) {
					$return['post_types'][ str_replace( 'post_type_', '', $key ) ] = $option;
				}

				if ( strpos( $key, 'tax_' ) !== false ) {
					$return['taxonomies'][ str_replace( 'tax_', '', $key ) ] = $option;
				}
			}

			$options['placeholders'];

			return rest_ensure_response( $return );
		}
	}
}

$starter_content_exporter = new Starter_Content_Exporter();


/**
 * Add REST API support to an already registered post type.
 */
function my_custom_post_type_rest_support() {
	global $wp_post_types, $wp_taxonomies;

	//be sure to set this to the name of your post type!
	if( isset( $wp_post_types[ 'jetpack-portfolio' ] ) ) {
		$wp_post_types['jetpack-portfolio']->show_in_rest = true;
		$wp_post_types['jetpack-portfolio']->rest_base = 'jetpack-portfolio';
		$wp_post_types['jetpack-portfolio']->rest_controller_class = 'WP_REST_Posts_Controller';
	}

	//be sure to set this to the name of your post type!
	if( isset( $wp_post_types[ 'jetpack-testimonial' ] ) ) {
		$wp_post_types['jetpack-testimonial']->show_in_rest = true;
		$wp_post_types['jetpack-testimonial']->rest_base = 'jetpack-testimonial';
		$wp_post_types['jetpack-testimonial']->rest_controller_class = 'WP_REST_Posts_Controller';
	}

	if( isset( $wp_post_types[ 'product' ] ) ) {
		$wp_post_types['product']->show_in_rest = true;
		$wp_post_types['product']->rest_base = 'product';
		$wp_post_types['product']->rest_controller_class = 'WP_REST_Posts_Controller';
	}

	if ( isset( $wp_post_types['job_listing'] ) ) {
		$wp_post_types['job_listing']->show_in_rest = true;
		$wp_post_types['job_listing']->rest_base = 'job_listings';
		$wp_post_types['job_listing']->rest_controller_class = 'WP_REST_Posts_Controller';
	}

	// taxonomies
	if ( isset( $wp_taxonomies['job_listing_category'] ) ) {
		$wp_taxonomies['job_listing_category']->show_in_rest = true;
		$wp_taxonomies['job_listing_category']->rest_base = 'job_listing_categories';
		$wp_taxonomies['job_listing_category']->rest_controller_class = 'WP_REST_Terms_Controller';
	}

	if ( isset( $wp_taxonomies['product_cat'] ) ) {
		$wp_taxonomies['product_cat']->show_in_rest = true;
		$wp_taxonomies['product_cat']->rest_base = 'product_cats';
		$wp_taxonomies['product_cat']->rest_controller_class = 'WP_REST_Terms_Controller';
	}

	if ( isset( $wp_taxonomies['product_tag'] ) ) {
		$wp_taxonomies['product_tag']->show_in_rest = true;
		$wp_taxonomies['product_tag']->rest_base = 'product_tags';
		$wp_taxonomies['product_tag']->rest_controller_class = 'WP_REST_Terms_Controller';
	}
}
add_action( 'init', 'my_custom_post_type_rest_support' );
