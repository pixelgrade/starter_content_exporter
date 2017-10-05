<?php
/**
 * Plugin Name:       Starter Content Exporter
 * Plugin URI:        https://andrei-lupu.com/
 * Description:       A plugin which exposes exportable data through REST API
 * Version:           0.1.3
 * Author:            Andrei Lupu
 * Author URI:        https://andrei-lupu.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       socket
 * Domain Path:       /languages
 */

if ( ! class_exists( 'Starter_Content_Exporter' ) ) {

	class Starter_Content_Exporter {

		private $ignored_images;

		private $client_placeholders;

		/**
		 * @var array A list of meta keys representig all the posible image holders
		 * For example `_thumbnail_id` holds the featured image, which should be replaced with a placeholder
		 * Or `product_image_gallery` which holds a list of attachemnts ids separated by comma. Also they should be
		 * replaced with placeholders
		 */
		private $gallery_meta_keys = array(
			'_thumbnail_id',
			'main_image',
			'image_backgrounds',
			'_hero_background_gallery',
			'product_image_gallery',
			// theme specific keys .. gosh these should be automatically detected
			'_border_main_gallery',
			'_bucket_main_gallery',
			'_heap_main_gallery',
			'_lens_main_gallery',
			'_rosa_main_gallery',
			'_mies_second_image',
			'_pile_second_image',
			'_border_portfolio_gallery',
			'_lens_portfolio_gallery',
			'_border_project_gallery',
		);

		/**
		 * A list of options and theme mods which are supposed to be imported at the start
		 * For example, some options like "which Jetpack modules should be enalbed" must be imported before we
		 * even start to import posts or categories.
		 *
		 * The remaining theme mods(which aren't ignored) will be imported at the end of the process
		 *
		 * @var array
		 */
		private $pre_settings = array(
			"options" => array(
				"show_on_front",
				"posts_per_page"
			),
			"mods" => array(
				"pixelgrade_jetpack_default_active_modules"
			)
		);

		/**
		 * A list of options keys which should be ignored from export
		 * @var array
		 */
		private $ignored_theme_mods = array(
			'pixcare_theme_config',
			'support',
			'0'
		);

		public function __construct() {
			add_action( 'init', array( $this, 'init_demo_exporter' ), 50 );
			add_filter( 'socket_config_for_starter_content_exporter', array( $this, 'add_socket_config' ) );
			add_action( 'rest_api_init', array( $this, 'add_rest_routes_api' ) );

			// internal filters
//			add_filter( '', array( $this, '' ) );
			add_filter( 'sce_export_prepare_post_content', array( $this, 'parse_content_for_images' ), 10, 2 );
			add_filter( 'sce_export_prepare_post_meta', array( $this, 'prepare_post_meta' ), 10, 2 );

			// widgets
			add_filter( 'pixcare_sce_widget_data_export_text', array( $this, 'prepare_text_widgets' ), 10, 2 );
			add_filter( 'pixcare_sce_widget_data_export_nav_menu', array( $this, 'prepare_menu_widgets' ), 10, 2 );
		}

		function init_demo_exporter() {
			require_once( plugin_dir_path( __FILE__ ) . 'socket/loader.php' );
			$socket = new WP_Socket( array(
				'plugin'   => 'starter_content_exporter',
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

						if ( in_array( $tax, array('job_listing_type', 'post_format', 'product_type', 'product_visibility', 'product_shipping_class' ) ) ) {
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
			) );

			//The Following registers an api route with multiple parameters.
			register_rest_route( 'sce/v1', '/posts', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_export_posts' ),
				'args' => array(
					'include' => array(
						'validate_callback' => array( $this, 'is_comma_list'),
						'required' => true
					),
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
				),
			) );

			register_rest_route( 'sce/v1', '/widgets', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_export_widgets' )
			) );
		}

		function rest_export_posts(){
			$options = get_option('starter_content_exporter');

			$query_args = array(
				'include' => $_POST['include'],
				'posts_per_page' => 100
			);

			if ( ! empty( $_POST['post_type'] ) ) {
				$query_args['post_type'] = $_POST['post_type'];
			}

			$posts = get_posts( $query_args );

			foreach ( $posts as $key => &$post ) {
				$post->meta = get_post_meta( $post->ID );
				$post->meta = apply_filters( 'sce_export_prepare_post_meta', $post->meta, $post );
				$post->post_content = apply_filters( 'sce_export_prepare_post_content', $post->post_content, $post );

				$post->taxonomies = array();

				foreach ( array_values( get_post_taxonomies( $post ) ) as $taxonomy ) {

					$fields = 'names';
					if ( is_taxonomy_hierarchical( $taxonomy ) ) {
						$fields = 'ids';
					}

					$current_tax = wp_get_object_terms($post->ID, $taxonomy, array(
						'fields' => $fields
					));

					if ( ! is_wp_error($current_tax) && ! empty( $current_tax ) ) {
						$post->taxonomies[$taxonomy] = $current_tax;
					} else {
						unset( $post->taxonomies[$taxonomy] );
					}
				}
			}

			return rest_ensure_response( $posts );
		}

		function parse_content_for_images( $content, $post ){
			$upload_dir = wp_get_upload_dir();

			$explode = explode( '/wp-content/uploads/', $upload_dir['baseurl'] );
			$base_url = '/wp-content/uploads/' . $explode[1];
			$attachments_regex =  '~(?<=src=\").+((' . $base_url . ')|(files\.wordpress\.com)).+(?=[\"\ ])~U';

			preg_match_all( $attachments_regex, $content, $result );

			foreach ( $result[0] as $i => $match ) {
				$original_image_url = $match;
				$new_url = $this->get_rotated_placeholder_url( $original_image_url );
				$content = str_replace( $original_image_url, $new_url, $content );
			}

			// search for shortcodes with attachments ids like gallery
			if ( has_shortcode( $content, 'gallery' ) ) {
				$content = $this->replace_gallery_shortcodes_ids($content);
			}

			return $content;
		}

		function replace_gallery_shortcodes_ids( $content ) {
			// pregmatch only ids attribute
			$pattern = '((\[gallery.*])?ids=\"(.*)\")';

			$content = preg_replace_callback( $pattern, array(
				$this,
				'replace_gallery_shortcodes_ids_pregmatch_callback'
			), $content );

			return $content;
		}

		function replace_gallery_shortcodes_ids_pregmatch_callback( $matches ) {

			if ( isset( $matches[2] ) && ! empty( $matches[2] ) ) {
				$replace_ids = array();
				$matches[2]  = explode( ',', $matches[2] );
				foreach ( $matches[2] as $key => $attach_id ) {
					$replace_ids[ $key ] = $this->get_rotated_placeholder_id( $attach_id );
				}

				$replace_string = implode( ',', $replace_ids );

				return ' ids="' . $replace_string . '"';
			}
		}

		function prepare_post_meta( $metas, $post ){

			// useless meta
			unset( $metas['_edit_lock'] );
			unset( $metas['_wp_old_slug'] );
			unset( $metas['_wpas_done_all'] );

			// usually the attahcment_metadata will be regenerated
			unset( $metas['_wp_attached_file'] );

			foreach ( $this->gallery_meta_keys as $gallery_key ) {
				if ( isset( $metas[$gallery_key] ) ) {
					$selected_images = explode(',', $metas[$gallery_key][0]);

					foreach ( $selected_images as $i => $attach_id ) {
						$selected_images[$i] = $this->get_rotated_placeholder_id( $attach_id );
					}

					$metas[$gallery_key] = array( join( ',', $selected_images ) );
				}
			}

			return $metas;
		}

		function rest_export_terms(){
			$options = get_option('starter_content_exporter');

			$query_args = array(
				'include' => $_GET['include'],
				'hide_empty' => false
			);

			if ( ! empty( $_GET['taxonomy'] ) ) {
				$query_args['taxonomy'] = $_GET['taxonomy'];
			}

			$terms = get_terms( $query_args );

			foreach ( $terms as $key => $term ) {
				$term->meta = get_term_meta( $term->term_id );
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

		function rest_export_widgets(){
			$posted_array = $this->get_available_widgets();

			$sidebars_array = get_option( 'sidebars_widgets' );
			$sidebar_export = array();
			foreach ( $sidebars_array as $sidebar => $widgets ) {
				if ( !empty( $widgets ) && is_array( $widgets ) ) {
					foreach ( $widgets as $sidebar_widget ) {
						if ( in_array( $sidebar_widget, array_keys( $posted_array ) ) ) {
							$sidebar_export[$sidebar][] = $sidebar_widget;
						}
					}
				}
			}
			$widgets = array( );
			foreach ( $posted_array as $k => $v ) {
				$widget = array( );
				$widget['type'] = trim( substr( $k, 0, strrpos( $k, '-' ) ) );
				$widget['type-index'] = trim( substr( $k, strrpos( $k, '-' ) + 1 ) );
				$widget['export_flag'] = ($v == 'on') ? true : false;
				$widgets[] = $widget;
			}
			$widgets_array = array( );
			foreach ( $widgets as $widget ) {
				$widget_val = get_option( 'widget_' . $widget['type'] );
				$widget_val = apply_filters( 'pixcare_sce_widget_data_export_' . $widget['type'], $widget_val, $widget['type'] );
				$multiwidget_val = $widget_val['_multiwidget'];

				if ( isset( $widget_val[$widget['type-index']] ) ) {
					$widgets_array[$widget['type']][$widget['type-index']] = $widget_val[$widget['type-index']];
				}

				if ( isset( $widgets_array[$widget['type']]['_multiwidget'] ) )
					unset( $widgets_array[$widget['type']]['_multiwidget'] );

				$widgets_array[$widget['type']]['_multiwidget'] = $multiwidget_val;
			}
			unset( $widgets_array['export'] );
			$export_array = array( $sidebar_export, $widgets_array );

			return rest_ensure_response( $export_array );
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
				'widgets' => $this->get_widgets()
			);

			if ( ! empty( $options ) ) {
				foreach ( $options as $key => $option ) {
					if ( strpos( $key, 'post_type_' ) !== false ) {
						$return['post_types'][ str_replace( 'post_type_', '', $key ) ] = $option;
					}

					if ( strpos( $key, 'tax_' ) !== false ) {
						$return['taxonomies'][ str_replace( 'tax_', '', $key ) ] = $option;
					}
				}
			}

			$return['pre_settings'] = $this->get_pre_settings();
			$return['post_settings'] = $this->get_post_settings();

			return rest_ensure_response( $return );
		}

		private function get_pre_settings(){
			$mods = get_theme_mods();

			$return = array(
				'options' => array(),
				'mods' => array()
			);

			foreach ($this->pre_settings['options'] as $key ) {
				$return['options'][$key] = get_option( $key );
			}

			foreach ( $this->pre_settings['mods'] as  $key ) {
				if ( isset( $mods[$key] ) ) {
					$return['mods'][$key] = $mods[$key];
					$this->ignored_theme_mods[] = $key;
				}
			}

			return $return;
		}

		private function get_post_settings(){
			$mods = get_theme_mods();

			$exported_mods = array_diff_key( $mods, array_flip( $this->ignored_theme_mods ) );

			$returned_options = array(
				'options' => array(
					'page_on_front' => get_option('page_on_front'),
					'page_for_posts' => get_option('page_for_posts'),
				),
				'mods' => $exported_mods
			);

			$featured_content = get_option( 'featured-content' );

			if ( ! empty( $featured_content ) ) {
				// @TODO maybe replace this with something imported
				unset( $featured_content['tag-id'] );
				$returned_options['options']['featured-content'] = $featured_content;
			}

			return $returned_options;
		}

		function prepare_text_widgets( $widget_data, $type ){

			foreach ( $widget_data as $widget_key => $widget ) {
				if ( '_multiwidget' === $widget_key || ! isset( $widget_data[ $widget_key ]['text'] ) ) {
					continue;
				}

				// start processing the widget content
				$content = $widget_data[ $widget_key ]['text'];

				$upload_dir = wp_get_upload_dir();

				$explode = explode( '/wp-content/uploads/', $upload_dir['baseurl'] );
				$base_url = '/wp-content/uploads/' . $explode[1];
				$attachments_regex =  '~(?<=src=\").+((' . $base_url . ')|(files\.wordpress\.com)).+(?=[\"\ ])~U';

				preg_match_all( $attachments_regex, $content, $result );

				foreach ( $result[0] as $i => $match ) {
					$original_image_url = $match;
					$new_url = $this->get_rotated_placeholder_url( $original_image_url );
					$content = str_replace( $original_image_url, $new_url, $content );
				}

				// search for shortcodes with attachments ids like gallery
				if ( has_shortcode( $content, 'gallery' ) ) {
					$content = $this->replace_gallery_shortcodes_ids($content);
				}

				$widget_data[ $widget_key ]['text'] = $content;
				// end processing the widget content
			}

			return $widget_data;
		}

		function prepare_menu_widgets( $widget_data, $type ){
			foreach ( $widget_data as $widget_key => $widget ) {
				if ( '_multiwidget' === $widget_key || ! isset( $widget_data[ $widget_key ]['nav_menu'] ) ) {
					continue;
				}

				$id = $widget_data[ $widget_key ]['nav_menu'];

				$widget_data[ $widget_key ]['nav_menu'] = $this->get_new_term_id($id);
			}

			return $widget_data;
		}

		/**
		 * Widget functions inspired from Widget Data - Setting Import/Export Plugin
		 * by Voce Communications - Kevin Langley, Sean McCafferty, Mark Parolisi
		 */
		private function get_widgets() {
			$posted_array = $this->get_available_widgets();

			$sidebars_array = get_option( 'sidebars_widgets' );
			$sidebar_export = array( );
			foreach ( $sidebars_array as $sidebar => $widgets ) {
				if ( !empty( $widgets ) && is_array( $widgets ) ) {
					foreach ( $widgets as $sidebar_widget ) {
						if ( in_array( $sidebar_widget, array_keys( $posted_array ) ) ) {
							$sidebar_export[$sidebar][] = $sidebar_widget;
						}
					}
				}
			}
			$widgets = array( );
			foreach ( $posted_array as $k => $v ) {
				$widget = array( );
				$widget['type'] = trim( substr( $k, 0, strrpos( $k, '-' ) ) );
				$widget['type-index'] = trim( substr( $k, strrpos( $k, '-' ) + 1 ) );
				$widget['export_flag'] = ($v == 'on') ? true : false;
				$widgets[] = $widget;
			}
			$widgets_array = array( );
			foreach ( $widgets as $widget ) {
				$widget_val = get_option( 'widget_' . $widget['type'] );
				$widget_val = apply_filters( 'widget_data_export', $widget_val, $widget['type'] );
				$multiwidget_val = $widget_val['_multiwidget'];

				if ( isset( $widget_val[$widget['type-index']] ) ) {
					$widgets_array[$widget['type']][$widget['type-index']] = $widget_val[$widget['type-index']];
				}

				if ( isset( $widgets_array[$widget['type']]['_multiwidget'] ) )
					unset( $widgets_array[$widget['type']]['_multiwidget'] );

				$widgets_array[$widget['type']]['_multiwidget'] = $multiwidget_val;
			}
			unset( $widgets_array['export'] );
			$export_array = array( $sidebar_export, $widgets_array );

			$json = json_encode( $export_array );

			return base64_encode( $json );
		}

		private function get_available_widgets() {
			global $wp_registered_sidebars;

			$sidebar_widgets = wp_get_sidebars_widgets();
			unset( $sidebar_widgets['wp_inactive_widgets'] );

			$return = array();

			foreach ( $sidebar_widgets as $sidebar_name => $widget_list ) {
				if ( empty( $widget_list ) ) {
					continue;
				}

				$sidebar_info = $this->get_sidebar_info( $sidebar_name );

				if( empty($sidebar_info) ) {
					continue;
				}

				foreach ( $widget_list as $widget ) {
					$widget_type = trim( substr( $widget, 0, strrpos( $widget, '-' ) ) );
					$widget_type_index = trim( substr( $widget, strrpos( $widget, '-' ) + 1 ) );
					$widget_options = get_option( 'widget_' . $widget_type );
					$widget_title = isset( $widget_options[$widget_type_index]['title'] ) ? $widget_options[$widget_type_index]['title'] : $widget_type_index;

					$return[$widget] = 'on';
				}
			}

			return $return;
		}

		private function get_sidebar_info( $sidebar_id ) {
			global $wp_registered_sidebars;

			//since wp_inactive_widget is only used in widgets.php
			if ( $sidebar_id == 'wp_inactive_widgets' )
				return array( 'name' => 'Inactive Widgets', 'id' => 'wp_inactive_widgets' );

			foreach ( $wp_registered_sidebars as $sidebar ) {
				if ( isset( $sidebar['id'] ) && $sidebar['id'] == $sidebar_id )
					return $sidebar;
			}

			return false;
		}

		private function get_client_ignored_images(){
			if ( isset( $_POST['ignored_images'] ) && is_array( $_POST['ignored_images'] ) ) {
				return $_POST['ignored_images'];
			}

			return array();
		}

		private function get_ignored_images(){
			if ( ! empty( $this->ignored_images ) ) {
				return $this->ignored_images;
			}

			$options = get_option('starter_content_exporter');

			$this->ignored_images = explode(',', $options['ignored_images'] );

			return $this->ignored_images;
		}

		private function get_rotated_placeholder_id( $original_id ) {
			$client_placeholders = $this->get_client_placeholders();
			$client_ignored_images = $this->get_client_ignored_images();

			if ( isset( $client_ignored_images[$original_id] ) ) {
				return $client_ignored_images[$original_id];
			}

			// get the first key
			reset($client_placeholders);
			$new_thumb = key($client_placeholders);

			if ( isset ( $client_placeholders[$new_thumb]['id'] ) ) {
				return $client_placeholders[$new_thumb]['id'];
			}

			return $new_thumb;
		}

		/**
		 * @param $image_url original image
		 *
		 * @return string
		 */
		private function get_rotated_placeholder_url( $original_image_url ) {
			$client_placeholders = $this->get_client_placeholders();
			$client_ignored_images = $this->get_client_ignored_images();
			$attach_id = attachment_url_to_postid( $original_image_url );

			if ( isset( $client_ignored_images[$attach_id] ) ) {
				return $client_ignored_images[$attach_id]['sizes']['full'];
			}

			// get the first key
			reset($client_placeholders);
			$new_thumb = key($client_placeholders);

			if ( isset ( $client_placeholders[$new_thumb]['sizes'] ) ) {
				$new_attach = $client_placeholders[$new_thumb];
				return $new_attach['sizes']['full'];
			}

			return '#';
		}

		/**
		 * The client may send us a list of imported placeholders.
		 * This methods returns them and the form should be a map like: "old_id" => "new_id"
		 *
		 * @return array
		 */
		private function get_client_placeholders(){
			if ( ! isset( $_POST['placeholders'] ) || ! is_array( $_POST['placeholders'] ) ) {
				return array();
			}

			if ( empty( $this->client_placeholders ) ) {
				$this->client_placeholders = $_POST['placeholders'];
			} else {
				$keys = array_keys($this->client_placeholders);
				$val = $this->client_placeholders[$keys[0]];
				unset($this->client_placeholders[$keys[0]]);
				$this->client_placeholders[$keys[0]] = $val;
			}

			return $this->client_placeholders;
		}

		private function get_client_posts() {
			if ( ! isset( $_POST['post_types'] ) || ! is_array( $_POST['post_types'] ) ) {
				return array();
			}

			$types = (array)$_POST['post_types'];

			foreach ( $types as $key => $posts ) {
				$types[$key] = array_map( 'intval', $posts );
			}

			return $types;
		}

		private function get_client_terms() {
			if ( ! isset( $_POST['taxonomies'] ) || ! is_array( $_POST['taxonomies'] ) ) {
				return array();
			}

			$terms = (array)$_POST['taxonomies'];

			foreach ( $terms as $key => $term ) {
				$terms[$key] = array_map( 'intval', $term );
			}

			return $terms;
		}

		private function get_new_post_id( $id ){
			$types = $this->get_client_posts();

			if ( isset( $types[$id] ) ) {
				return $types[$id];
			}

			return $id;
		}

		private function get_new_term_id( $id ){
			$terms = $this->get_client_terms();

			if ( isset( $terms[$id] ) ) {
				return $terms[$id];
			}

			return $id;
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
	}
}

$starter_content_exporter = new Starter_Content_Exporter();


/**
 * Add REST API support to an already registered post type.
 */
function my_custom_post_type_rest_support() {
	global $wp_post_types, $wp_taxonomies;

	// in case we want to export menus
	$wp_post_types['nav_menu_item']->show_in_rest = true;

	//be sure to set this to the name of your post type!
	if( isset( $wp_post_types[ 'nav_menu_item' ] ) ) {
		$wp_post_types['nav_menu_item']->show_in_rest = true;
		$wp_post_types['nav_menu_item']->rest_base = 'nav_menu_item';
		$wp_post_types['nav_menu_item']->rest_controller_class = 'WP_REST_Posts_Controller';
	}

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
	if ( isset( $wp_taxonomies['nav_menu'] ) ) {
		$wp_taxonomies['nav_menu']->show_in_rest = true;
		$wp_taxonomies['nav_menu']->rest_base = 'nav_menu';
		$wp_taxonomies['nav_menu']->rest_controller_class = 'WP_REST_Terms_Controller';
	}

	if ( isset( $wp_taxonomies['job_listing_category'] ) ) {
		$wp_taxonomies['job_listing_category']->show_in_rest = true;
		$wp_taxonomies['job_listing_category']->rest_base = 'job_listing_categories';
		$wp_taxonomies['job_listing_category']->rest_controller_class = 'WP_REST_Terms_Controller';
	}

	if ( isset( $wp_taxonomies['job_listing_tag'] ) ) {
		$wp_taxonomies['job_listing_tag']->show_in_rest = true;
		$wp_taxonomies['job_listing_tag']->rest_base = 'job_listing_tag';
		$wp_taxonomies['job_listing_tag']->rest_controller_class = 'WP_REST_Terms_Controller';
	}

	if ( isset( $wp_taxonomies['job_listing_region'] ) ) {
		$wp_taxonomies['job_listing_region']->show_in_rest = true;
		$wp_taxonomies['job_listing_region']->rest_base = 'job_listing_region';
		$wp_taxonomies['job_listing_region']->rest_controller_class = 'WP_REST_Terms_Controller';
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
