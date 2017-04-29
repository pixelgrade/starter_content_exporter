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

			add_action( 'init', array( $this, 'init_demo_exporter' ), 9999);
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

					'whatsupdoc' => array(
						'type'  => 'gallery',
						'label' => 'Ignored Images',
						'description' => 'Wha sad as das das ddasd asdsadsadas dasdas dasdasadas as as das'
					)
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

				$post_type_rest = $post_type;

				if ( property_exists( $post_type_config, 'rest_base' ) ) {
					$post_type_rest = $post_type_config->rest_base;
				}

				$config['sockets']['export_post_types']['items']['post_type_' . $post_type_rest . '_start'] = array(
					'type' => 'divider',
					'html' => $post_type,
				);

				$config['sockets']['export_post_types']['items']['post_type_' . $post_type_rest] = array(
					'type'         => 'post_select',
					'label' => $post_type_config->label,
					'query' => array(
						'post_type' => $post_type
					)
				);

				$taxonomy_objects = get_object_taxonomies( $post_type, 'objects' );

				if ( ! empty( $taxonomy_objects ) ) {
					foreach ($taxonomy_objects as $tax => $tax_config ) {

						if ( ! $tax_config->show_ui ) {
							continue;
						}

						$rest_base = $tax;

						if ( ! empty( $tax_config->rest_base ) ) {
							$rest_base = $tax_config->rest_base;
						}
						$config['sockets']['export_post_types']['items']['tax_' . $tax] = array(
							'type'         => 'tax_select',
							'label' => $tax_config->label,
							'query' => array(
								'taxonomy' => $rest_base
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
				'callback'            => array( $this, 'export_data' ),
			) );

			//The Following registers an api route with multiple parameters.
			register_rest_route( 'sce/v1', '/media', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'export_media' ),
			) );
		}

		function export_media(){
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

		function export_data(){
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





