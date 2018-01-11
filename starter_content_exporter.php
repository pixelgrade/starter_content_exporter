<?php
/**
 * Plugin Name:       Starter Content Exporter
 * Plugin URI:        https://andrei-lupu.com/
 * Description:       A plugin which exposes exportable data through REST API
 * Version:           0.2.0
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
		 * @TODO this should turn into an option and allow user to select which meta keys are holding images
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
			add_action( 'init', array( $this, 'init_demo_exporter' ), 100050 );
			add_filter( 'socket_config_for_starter_content_exporter', array( $this, 'add_socket_config' ) );

			add_action( 'rest_api_init', array( $this, 'add_rest_routes_api_v1' ) );

			// The new standard following endpoints
			add_action( 'rest_api_init', array( $this, 'add_rest_routes_api_v2' ) );

			// internal filters
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

		/**
		 * This is the management interface config via the Socket options framework
		 *
		 * @param $config
		 *
		 * @return array
		 */
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
						'label' => 'Placeholders',
						'description' => 'Pick a set of images which should replace the demo images'
					),
					'ignored_images' => array(
						'type'  => 'gallery',
						'label' => 'Ignored Images',
						'description' => 'Pixk a set of images ignored from replacement'
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

						if ( in_array( $tax, array( 'feedback', 'jp_pay_order', 'jp_pay_product', 'post_format', 'product_type', 'product_visibility', 'product_shipping_class' ) ) ) {
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

			$config['sockets']['export_options'] = array(
				'label' => 'Exported Options and Theme Mods',
				'items' => array(
					'exported_pre_options' => array(
						'type'  => 'tags',
						'label' => 'Before import Options Keys',
						'description' => 'Select which options keys should be added before importing'
					),
					'exported_post_options' => array(
						'type'  => 'tags',
						'label' => 'After import Options Keys',
						'description' => 'Select which options keys should be added after importing'
					),
					'exported_pre_theme_mods' => array(
						'type'  => 'tags',
						'label' => 'Before import Theme Mods Keys',
						'description' => 'Select which theme_mod keys should be added before importing'
					),
					'ignored_post_theme_mods' => array(
						'type'  => 'tags',
						'label' => 'Ignored import Theme Mods Keys',
						'description' => 'All the theme mods are exported after import, but you can select ignored keys'
					),
				)
			);

			return $config;
		}

		/**
		 * @todo This should be deprecated some time in the future
		 */
		function add_rest_routes_api_v1() {
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
						'required' => true
					),
				),
			) );

			register_rest_route( 'sce/v1', '/terms', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_export_terms' ),
				'args' => array(
					'include' => array(
						'required' => true
					),
				),
			) );

			register_rest_route( 'sce/v1', '/widgets', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_export_widgets' )
			) );
		}

		/**
		 * REST API Endpoints that follow our common standard of response:
		 * - code
		 * - message
		 * - data
		 */
		function add_rest_routes_api_v2() {
			//The Following registers an api route with multiple parameters.
			register_rest_route( 'sce/v2', '/data', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_export_data_v2' ),
			) );

			//The Following registers an api route with multiple parameters.
			register_rest_route( 'sce/v2', '/media', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_export_media_v2' ),
			) );

			//The Following registers an api route with multiple parameters.
			register_rest_route( 'sce/v2', '/posts', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_export_posts_v2' ),
				'args' => array(
					'include' => array(
						'required' => true
					),
				),
			) );

			register_rest_route( 'sce/v2', '/terms', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_export_terms_v2' ),
				'args' => array(
					'include' => array(
						'required' => true
					),
				),
			) );

			register_rest_route( 'sce/v2', '/widgets', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_export_widgets_v2' )
			) );
		}

		/**
		 * @param WP_REST_Request $request
		 *
		 * @return WP_REST_Response
		 */
		function rest_export_posts( $request ) {
			$options = get_option('starter_content_exporter');

			$params = $request->get_params();

			$query_args = array(
				'post__in' => $params['include'],
				'posts_per_page' => 100,
			);

			if ( ! empty( $params['post_type'] ) ) {
				$query_args['post_type'] = $params['post_type'];
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

		/**
		 * @param WP_REST_Request $request
		 *
		 * @return WP_REST_Response
		 */
		function rest_export_posts_v2( $request ){
			$options = get_option('starter_content_exporter');

			$params = $request->get_params();

			$query_args = array(
				'post__in' => $params['include'],
				'posts_per_page' => 100,
			);

			if ( ! empty( $params['post_type'] ) ) {
				$query_args['post_type'] = $params['post_type'];
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

			return rest_ensure_response( array(
				'code'    => 'success',
				'message' => '',
				'data'    => array(
					'posts' => $posts,
				),
			) );
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
			// pregmatch only the ids attribute
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

			// Do not replace anything if we have reached so far
			return $matches[0];
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

		/**
		 * @param WP_REST_Request $request
		 *
		 * @return WP_REST_Response
		 */
		function rest_export_terms( $request ) {
			$options = get_option( 'starter_content_exporter' );

			$params = $request->get_params();

			$query_args = array(
				'include'    => $params['include'],
				'hide_empty' => false,
			);

			if ( ! empty( $params['taxonomy'] ) ) {
				$query_args['taxonomy'] = $params['taxonomy'];
			}

			$terms = get_terms( $query_args );

			foreach ( $terms as $key => $term ) {
				$term->meta = get_term_meta( $term->term_id );
			}

			return rest_ensure_response( $terms );
		}

		/**
		 * @param WP_REST_Request $request
		 *
		 * @return WP_REST_Response
		 */
		function rest_export_terms_v2( $request ) {
			$options = get_option( 'starter_content_exporter' );

			$params = $request->get_params();

			$query_args = array(
				'include'    => $params['include'],
				'hide_empty' => false,
			);

			if ( ! empty( $params['taxonomy'] ) ) {
				$query_args['taxonomy'] = $params['taxonomy'];
			}

			$terms = get_terms( $query_args );
			if ( is_wp_error( $terms ) ) {
				return rest_ensure_response( $terms );
			}

			foreach ( $terms as $key => $term ) {
				$term->meta = get_term_meta( $term->term_id );
			}

			return rest_ensure_response( array(
				'code'    => 'success',
				'message' => '',
				'data'    => array(
					'terms' => $terms,
				),
			) );
		}

		/**
		 * @param WP_REST_Request $request
		 *
		 * @return WP_REST_Response
		 */
		function rest_export_media( $request ) {
			$params = $request->get_params();

			if ( empty( $params['id'] ) ) {
				return rest_ensure_response( array(
					'code'    => 'missing_id',
					'message' => 'You need to provide an attachment id.',
					'data'    => array(),
				) );
			}

			$id = $params['id'];

			$file = get_attached_file( $id );

			$type = pathinfo( $file, PATHINFO_EXTENSION );

			$data = file_get_contents( $file );

			$base64 = 'data:image/' . $type . ';base64,' . base64_encode( $data );

			return rest_ensure_response( array(
				'title'     => get_the_title( $id ),
				'mime_type' => get_post_mime_type( $id ),
				'ext'       => $type,
				'data'      => $base64,
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

		/**
		 * @param WP_REST_Request $request
		 *
		 * @return WP_REST_Response
		 */
		function rest_export_media_v2( $request ) {
			$params = $request->get_params();

			if ( empty( $params['id'] ) ) {
				return rest_ensure_response( array(
					'code'    => 'missing_id',
					'message' => 'You need to provide an attachment id.',
					'data'    => array(),
				) );
			}

			$id = $params['id'];

			$file = get_attached_file( $id );

			$type = pathinfo( $file, PATHINFO_EXTENSION );

			$data = file_get_contents( $file );

			$base64 = 'data:image/' . $type . ';base64,' . base64_encode( $data );

			return rest_ensure_response( array(
				'code'    => 'success',
				'message' => '',
				'data'    => array(
					'media' => array(
						'title'     => get_the_title( $id ),
						'mime_type' => get_post_mime_type( $id ),
						'ext'       => $type,
						'data'      => $base64,
					),
				),
			) );
		}

		function rest_export_widgets_v2(){
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

			return rest_ensure_response( array(
				'code'    => 'success',
				'message' => '',
				'data'    => array(
					'widgets' => $export_array,
				),
			) );
		}

		function rest_export_data(){
			$options = get_option('starter_content_exporter');

			$return = array(
				'media' => array(
					'placeholders' => array(),
					'ignored' => array(),
				),
				'post_types' => array(),
				'taxonomies' => array(),
				'widgets' => $this->get_widgets()
			);

			if ( ! empty( $options['placeholders'] ) ) {
				$return['media']['placeholders'] = explode(',', $options['placeholders'] );
			}

			if ( ! empty( $options['ignored_images'] ) ) {
				$return['media']['ignored'] = explode(',', $options['ignored_images'] );
			}

			if ( ! empty( $options ) ) {
				foreach ( $options as $key => $option ) {
					if ( strpos( $key, 'post_type_' ) !== false ) {
						$return['post_types'][ str_replace( 'post_type_', '', $key ) ] = $option;
					}

					if ( strpos( $key, 'tax_' ) !== false ) {
						$return['taxonomies'][ str_replace( 'tax_', '', $key ) ] = $option;
					}
				}

				/**
				 * Tricky stuff
				 * We need to make sure that the navigation items are imported the last
				 * The metadata of a menu item can contain an object_id which should be mapped, but we can only map existing IDS
				 *
				 * So we will move the nav_menu_item post type to at the end of the data array.
				 */
				$last_post_type_key = end( $return['post_types'] );

				if ( isset( $return['post_types']['nav_menu_item'] ) && 'nav_menu_item' !== $last_post_type_key ) {
					$tmp = $return['post_types']['nav_menu_item'];
					unset( $return['post_types']['nav_menu_item'] );
					$return['post_types']['nav_menu_item'] = $tmp;
				}
			}

			$return['pre_settings'] = $this->get_pre_settings();
			$return['post_settings'] = $this->get_post_settings();

			return rest_ensure_response( $return );
		}

		function rest_export_data_v2(){
			$options = get_option('starter_content_exporter');

			$data = array(
				'media' => array(
					'placeholders' => array(),
					'ignored' => array(),
				),
				'post_types' => array(),
				'taxonomies' => array(),
				'widgets' => $this->get_widgets()
			);

			if ( ! empty( $options['placeholders'] ) ) {
				$data['media']['placeholders'] = explode(',', $options['placeholders'] );
			}

			if ( ! empty( $options['ignored_images'] ) ) {
				$data['media']['ignored'] = explode(',', $options['ignored_images'] );
			}

			if ( ! empty( $options ) ) {
				foreach ( $options as $key => $option ) {
					if ( strpos( $key, 'post_type_' ) !== false ) {
						$data['post_types'][ str_replace( 'post_type_', '', $key ) ] = $option;
					}

					if ( strpos( $key, 'tax_' ) !== false ) {
						$data['taxonomies'][ str_replace( 'tax_', '', $key ) ] = $option;
					}
				}

				/**
				 * Tricky stuff
				 * We need to make sure that the navigation items are imported the last
				 * The metadata of a menu item can contain an object_id which should be mapped, but we can only map existing IDS
				 *
				 * So we will move the nav_menu_item post type to at the end of the data array.
				 */
				$last_post_type_key = end( $data['post_types'] );

				if ( isset( $data['post_types']['nav_menu_item'] ) && 'nav_menu_item' !== $last_post_type_key ) {
					$tmp = $data['post_types']['nav_menu_item'];
					unset( $data['post_types']['nav_menu_item'] );
					$data['post_types']['nav_menu_item'] = $tmp;
				}
			}

			$data['pre_settings'] = $this->get_pre_settings();
			$data['post_settings'] = $this->get_post_settings();

			return rest_ensure_response( array(
				'code'    => 'success',
				'message' => '',
				'data'    => $data,
			) );
		}

		/**
		 * Get all the options and theme mods which should be added before the import action
		 * @return array
		 */
		private function get_pre_settings(){
			$mods = get_theme_mods();
			$options = get_option('starter_content_exporter');

			$return = array(
				'options' => array(),
				'mods' => array()
			);

			// make the selected options keys exportable
			if ( ! empty( $options['exported_pre_options'] ) ) {
				// Legacy, keep pre_settings it untill all the demos get their keys in UI
				$this->pre_settings['options'] = array_merge( $this->pre_settings['options'], $options['exported_pre_options']);
			}

			foreach ($this->pre_settings['options'] as $key ) {
				$option_value = get_option( $key, null );

				// we need to check if the option key really exists and ignore the unexistent
				if ( $option_value !== null ) {
					$return['options'][$key] = $option_value;
				}
			}

			if ( ! empty( $options['exported_pre_theme_mods'] ) ) {
				$this->pre_settings['mods'] = array_merge(  $this->pre_settings['mods'], $options['exported_pre_theme_mods'] );
			}

			// @TODO make this work with values from UI
			foreach ( $this->pre_settings['mods'] as  $key ) {
				if ( isset( $mods[$key] ) ) {
					$return['mods'][$key] = $mods[$key];
					$this->ignored_theme_mods[] = $key;
				}
			}

			return $return;
		}

		/**
		 * Get all the options and theme mods which should be added after the import action
		 * @return array
		 */
		private function get_post_settings(){
			$mods = get_theme_mods();
			$options = get_option('starter_content_exporter');

			// some theme mods can be imported from the UI.
			if ( ! empty( $options['ignored_post_theme_mods'] ) ) {
				$this->ignored_theme_mods = array_merge( $this->ignored_theme_mods, $options['ignored_post_theme_mods'] );
			}

			// remove the ignored theme mods keys
			$exported_mods = array_diff_key( $mods, array_flip( $this->ignored_theme_mods ) );

			$returned_options = array(
				// Legacy, keep it untill all the demos get their keys in UI
				'options' => array(
					'page_on_front' => get_option('page_on_front'),
					'page_for_posts' => get_option('page_for_posts'),
				),
				'mods' => $exported_mods
			);

			// make the selected options keys exportable
			if ( ! empty( $options['exported_post_options'] ) ) {
				foreach ( $options['exported_post_options'] as $option ) {
					$option_value = get_option( $option, null );

					// we need to check if the option key really exists and ignore the unexistent
					if ( $option_value !== null ) {
						$returned_options['options'][$option] = $option_value;
					}
				}
			}

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
	}
}

$starter_content_exporter = new Starter_Content_Exporter();
