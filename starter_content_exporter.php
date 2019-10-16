<?php
/**
 * Plugin Name:       Starter Content Exporter
 * Plugin URI:        https://pixelgrade.com/
 * Description:       A plugin which exposes exportable data through the REST API.
 * Version:           0.8.0
 * Author:            Pixelgrade, Vlad Olaru
 * Author URI:        https://pixelgrade.com/
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
		 * @var array A list of meta keys representing all the possible image holders
		 * For example `_thumbnail_id` holds the featured image, which should be replaced with a placeholder
		 * Or `product_image_gallery` which holds a list of attachments ids separated by comma. Also they should be
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
			'options' => array(
				'show_on_front',
				'posts_per_page',
			),
			'mods'    => array(
				'pixelgrade_jetpack_default_active_modules',
			),
		);

		/**
		 * A list of options keys which should be ignored from export
		 * @var array
		 */
		private $ignored_theme_mods = array(
			'pixcare_license', // This is the new key
			'pixassist_license', // This is the new key for Pixelgrade Assistant

			'pixcare_theme_config',
			'pixcare_license_hash',
			'pixcare_license_status',
			'pixcare_license_type',
			'pixcare_license_expiry_date',
			'pixcare_new_theme_version',
			'support',
			'pixcare_support',
			'0',
		);

		public function __construct() {
			add_action( 'init', array( $this, 'init_demo_exporter' ), 100050 );
			add_filter( 'socket_config_for_starter_content_exporter', array( $this, 'add_socket_config' ) );

			// The new standard following endpoints
			add_action( 'rest_api_init', array( $this, 'add_rest_routes_api_v2' ) );

			// internal filters
			add_filter( 'sce_export_prepare_post_content', array( $this, 'parse_content_for_images' ), 10, 2 );
			add_filter( 'sce_export_prepare_post_meta', array( $this, 'prepare_post_meta' ), 10, 2 );

			// widgets
			add_filter( 'pixcare_sce_widget_data_export_text', array( $this, 'prepare_text_widgets' ), 10, 2 );
			add_filter( 'pixcare_sce_widget_data_export_nav_menu', array( $this, 'prepare_menu_widgets' ), 10, 2 );
		}

		public function init_demo_exporter() {
			require_once( plugin_dir_path( __FILE__ ) . 'socket/loader.php' );
			$socket = new WP_Socket( array(
				'plugin'   => 'starter_content_exporter',
				'api_base' => 'sce/v1',
			) );
		}

		/**
		 * This is the management interface config via the Socket options framework
		 *
		 * @param $config
		 *
		 * @return array
		 */
		public function add_socket_config( $config ) {
			$config = array(
				'page_title'  => 'Pick Exports',
				'description' => '',
				'nav_label'   => 'Starter Content Exporter',
				'options_key' => 'starter_content_exporter',
				'sockets'     => array(),
			);

			$config['sockets']['export_media'] = array(
				'label' => 'Media',
				'items' => array(
					'placeholders'   => array(
						'type'        => 'gallery',
						'label'       => 'Placeholders',
						'description' => 'Pick a set of images which should replace the demo images.',
					),
					'ignored_images' => array(
						'type'        => 'gallery',
						'label'       => 'Ignored Images',
						'description' => 'Pick a set of images ignored from replacement. They will be exported as they are.',
					),
				),
			);

			$config['sockets']['export_post_types'] = array(
				'label' => 'Posts & Post Types',
				'items' => array(),
			);

			$post_types = get_post_types( array( 'show_in_rest' => true ), 'objects' );

			foreach ( $post_types as $post_type => $post_type_config ) {

				if ( 'attachment' === $post_type ) {
					continue;
				}

				$config['sockets']['export_post_types']['items'][ 'post_type_' . $post_type . '_start' ] = array(
					'type' => 'divider',
					'html' => $post_type,
				);

				$config['sockets']['export_post_types']['items'][ 'post_type_' . $post_type ] = array(
					'type'  => 'post_select',
					'label' => $post_type_config->label,
					'query' => array(
						'post_type' => $post_type,
					),
				);

				$taxonomy_objects = get_object_taxonomies( $post_type, 'objects' );

				if ( ! empty( $taxonomy_objects ) ) {
					foreach ( $taxonomy_objects as $tax => $tax_config ) {

						if ( in_array( $tax, array(
							'feedback',
							'jp_pay_order',
							'jp_pay_product',
							'post_format',
							'product_type',
							'product_visibility',
							'product_shipping_class',
						) ) ) {
							continue;
						}

						$config['sockets']['export_post_types']['items'][ 'tax_' . $tax ] = array(
							'type'  => 'tax_select',
							'label' => $tax_config->label,
							'query' => array(
								'taxonomy' => $tax,
							),
						);
					}
				}

				$config['sockets']['export_post_types']['items'][ 'post_type_' . $post_type . '_end' ] = array(
					'type' => 'divider',
					'html' => ''//'End of the ' . $post_type,
				);
			}

			$config['sockets']['export_options'] = array(
				'label' => 'Exported Options and Theme Mods',
				'items' => array(
					'exported_pre_options'    => array(
						'type'        => 'tags',
						'label'       => 'Before import Options Keys',
						'description' => 'Select which options keys should be added before importing',
					),
					'exported_post_options'   => array(
						'type'        => 'tags',
						'label'       => 'After import Options Keys',
						'description' => 'Select which options keys should be added after importing',
					),
					'exported_pre_theme_mods' => array(
						'type'        => 'tags',
						'label'       => 'Before import Theme Mods Keys',
						'description' => 'Select which theme_mod keys should be added before importing',
					),
					'ignored_post_theme_mods' => array(
						'type'        => 'tags',
						'label'       => 'Ignored import Theme Mods Keys',
						'description' => 'All the theme mods are exported after import, but you can select ignored keys',
					),
				),
			);

			return $config;
		}

		/**
		 * REST API Endpoints that follow our common standard of response:
		 * - code
		 * - message
		 * - data
		 */
		public function add_rest_routes_api_v2() {
			//The Following registers an api route with multiple parameters.
			register_rest_route( 'sce/v2', '/data', array(
				'methods'  => WP_REST_Server::READABLE,
				'callback' => array( $this, 'rest_export_data_v2' ),
			) );

			//The Following registers an api route with multiple parameters.
			register_rest_route( 'sce/v2', '/media', array(
				'methods'  => WP_REST_Server::READABLE,
				'callback' => array( $this, 'rest_export_media_v2' ),
			) );

			//The Following registers an api route with multiple parameters.
			register_rest_route( 'sce/v2', '/posts', array(
				'methods'  => WP_REST_Server::CREATABLE,
				'callback' => array( $this, 'rest_export_posts_v2' ),
				'args'     => array(
					'include' => array(
						'required' => true,
					),
				),
			) );

			register_rest_route( 'sce/v2', '/terms', array(
				'methods'  => WP_REST_Server::CREATABLE,
				'callback' => array( $this, 'rest_export_terms_v2' ),
				'args'     => array(
					'include' => array(
						'required' => true,
					),
				),
			) );

			register_rest_route( 'sce/v2', '/widgets', array(
				'methods'  => WP_REST_Server::CREATABLE,
				'callback' => array( $this, 'rest_export_widgets_v2' ),
			) );
		}

		public function rest_export_data_v2() {
			$options = get_option( 'starter_content_exporter' );

			$data = array(
				'media'      => array(
					'placeholders' => array(),
					'ignored'      => array(),
				),
				'post_types' => array(),
				'taxonomies' => array(),
				'widgets'    => $this->get_widgets(),
			);

			if ( ! empty( $options['placeholders'] ) ) {
				$data['media']['placeholders'] = $this->validate_attachment_ids( explode( ',', $options['placeholders'] ) );
			}

			$data['media']['ignored'] = $this->get_ignored_images();

			if ( ! empty( $options ) ) {
				foreach ( $options as $key => $option ) {
					if ( strpos( $key, 'post_type_' ) !== false ) {
						$post_type = str_replace( 'post_type_', '', $key );
						$priority  = 10;

						/**
						 * We need to make sure that the navigation items are imported the last
						 * The metadata of a menu item can contain an object_id which should be mapped, but we can only map existing IDS
						 */
						if ( 'nav_menu_item' === $post_type ) {
							$priority = 100;
						}

						$data['post_types'][] = array(
							'name'     => $post_type,
							'ids'      => $option,
							'priority' => $priority, // for now all will have the same priority
						);
					} elseif ( strpos( $key, 'tax_' ) !== false ) {
						$taxonomy             = str_replace( 'tax_', '', $key );
						$data['taxonomies'][] = array(
							'name'     => $taxonomy,
							'ids'      => $option,
							'priority' => 10, // for now all will have the same priority
						);
					}
				}
			}

			$data['pre_settings']  = $this->get_pre_settings();
			$data['post_settings'] = $this->get_post_settings();

			return rest_ensure_response( array(
				'code'    => 'success',
				'message' => '',
				'data'    => $data,
			) );
		}

		protected function validate_attachment_ids( $attachment_ids ) {
			if ( empty( $attachment_ids ) ) {
				$attachment_ids = array();
			}
			// Go through each one and make sure that they exist.
			foreach ( $attachment_ids as $key => $attachment_id ) {
				$attachment_id = absint( $attachment_id );
				$file_path = get_attached_file( $attachment_id );
				if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
					unset( $attachment_ids[ $key ] );
				}
			}

			return $attachment_ids;
		}

		/**
		 * @param WP_REST_Request $request
		 *
		 * @return WP_REST_Response
		 */
		public function rest_export_posts_v2( $request ) {
			$options = get_option( 'starter_content_exporter' );

			$params = $request->get_params();

			$query_args = array(
				'post__in'       => $params['include'],
				'posts_per_page' => 100,
			);

			if ( ! empty( $params['post_type'] ) ) {
				$query_args['post_type'] = $params['post_type'];
			}

			$posts = get_posts( $query_args );

			foreach ( $posts as $key => &$post ) {
				$post->meta         = apply_filters( 'sce_export_prepare_post_meta', get_post_meta( $post->ID ), $post );
				$post->post_content = apply_filters( 'sce_export_prepare_post_content', $post->post_content, $post );

				$post->taxonomies = array();
				foreach ( array_values( get_post_taxonomies( $post ) ) as $taxonomy ) {

					$fields = 'names';
					if ( is_taxonomy_hierarchical( $taxonomy ) ) {
						$fields = 'ids';
					}

					$current_tax = wp_get_object_terms( $post->ID, $taxonomy, array(
						'fields' => $fields,
					) );

					if ( ! is_wp_error( $current_tax ) && ! empty( $current_tax ) ) {
						$post->taxonomies[ $taxonomy ] = $current_tax;
					} else {
						unset( $post->taxonomies[ $taxonomy ] );
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

		public function parse_content_for_images( $content, $post ) {
			$upload_dir = wp_get_upload_dir();

			$explode           = explode( '/wp-content/uploads/', $upload_dir['baseurl'] );
			$base_url = '/wp-content/uploads/';
			if ( ! empty( $explode[1] ) ) {
				$base_url = trailingslashit( '/wp-content/uploads/' . $explode[1] );
			}
			$attachments_regex = '~(?<=src=\").+((' . $base_url . ')|(files\.wordpress\.com)).+(?=[\"\ ])~U';

			preg_match_all( $attachments_regex, $content, $result );
			if ( ! empty( $result[0] ) && is_array( $result[0] ) ) {
				foreach ( $result[0] as $i => $match ) {
					$original_image_url = $match;
					$new_url            = $this->get_rotated_placeholder_url( $original_image_url );
					$content            = str_replace( $original_image_url, $new_url, $content );
				}
			}

			// search for shortcodes with attachments ids like gallery
			if ( has_shortcode( $content, 'gallery' ) ) {
				$content = $this->replace_gallery_shortcodes_ids( $content );
			}

			return $content;
		}

		public function replace_gallery_shortcodes_ids( $content ) {
			// pregmatch only the ids attribute
			$pattern = '((\[gallery.*])?ids=\"(.*)\")';

			$content = preg_replace_callback( $pattern, array(
				$this,
				'replace_gallery_shortcodes_ids_pregmatch_callback',
			), $content );

			return $content;
		}

		public function replace_gallery_shortcodes_ids_pregmatch_callback( $matches ) {
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

		public function prepare_post_meta( $metas, $post ) {

			// useless meta
			unset( $metas['_edit_lock'] );
			unset( $metas['_wp_old_slug'] );
			unset( $metas['_wpas_done_all'] );

			// usually the attachment_metadata will be regenerated
			unset( $metas['_wp_attached_file'] );

			foreach ( $this->gallery_meta_keys as $gallery_key ) {
				if ( isset( $metas[ $gallery_key ] ) ) {
					$selected_images = explode( ',', $metas[ $gallery_key ][0] );

					foreach ( $selected_images as $i => $attach_id ) {
						$selected_images[ $i ] = $this->get_rotated_placeholder_id( $attach_id );
					}

					$metas[ $gallery_key ] = array( join( ',', $selected_images ) );
				}
			}

			return $metas;
		}

		/**
		 * @param WP_REST_Request $request
		 *
		 * @return WP_REST_Response
		 */
		public function rest_export_terms_v2( $request ) {
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
		public function rest_export_media_v2( $request ) {
			$params = $request->get_params();

			if ( empty( $params['id'] ) ) {
				return rest_ensure_response( array(
					'code'    => 'missing_id',
					'message' => 'You need to provide an attachment id.',
					'data'    => array(),
				) );
			}

			$file_path = get_attached_file( absint( $params['id'] ) );
			if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
				return rest_ensure_response( array(
					'code'    => 'missing_attachment',
					'message' => 'The attachment id is missing or it\'s file could not be found.',
					'data'    => array(),
				) );
			}

			// Also allow the svg mime type.
			add_filter( 'upload_mimes', function( $mimes ) {
				$mimes['svg'] = 'image/svg+xml';

				return $mimes;
			}, 10, 1 );

			$file_info = wp_check_filetype_and_ext( $file_path, $file_path );
			if ( empty( $file_info['ext'] ) || empty( $file_info['type'] ) ) {
				return rest_ensure_response( array(
					'code'    => 'mime_error',
					'message' => 'We could not determine the mime type of the media.',
					'data'    => array(),
				) );
			}

			$imageData = file_get_contents( $file_path );
			if ( empty( $imageData ) ) {
				return rest_ensure_response( array(
					'code'    => 'no_image_data',
					'message' => 'We could not get the image contents.',
					'data'    => array(),
				) );
			}

			$base64 = 'data:' . $file_info['type'] . ';base64,' . base64_encode( $imageData );

			return rest_ensure_response( array(
				'code'    => 'success',
				'message' => '',
				'data'    => array(
					'media' => array(
						'title'     => pathinfo( $file_path, PATHINFO_FILENAME ),
						'mime_type' => $file_info['type'],
						'ext'       => $file_info['ext'],
						'data'      => $base64,
					),
				),
			) );
		}

		/**
		 * @param WP_REST_Request $request
		 *
		 * @return WP_REST_Response
		 */
		public function rest_export_widgets_v2( $request ){
			$params = $request->get_params();

			$posted_array = $this->get_available_widgets();

			$sidebars_array = get_option( 'sidebars_widgets' );
			$sidebar_export = array();
			foreach ( $sidebars_array as $sidebar => $widgets ) {
				if ( ! empty( $widgets ) && is_array( $widgets ) ) {
					foreach ( $widgets as $sidebar_widget ) {
						if ( in_array( $sidebar_widget, array_keys( $posted_array ) ) ) {
							$sidebar_export[ $sidebar ][] = $sidebar_widget;
						}
					}
				}
			}
			$widgets = array();
			foreach ( $posted_array as $k => $v ) {
				$widget = array();
				// Extract the widget type and index from the widget instance ID
				$widget['type']       = trim( substr( $k, 0, strrpos( $k, '-' ) ) );
				$widget['type-index'] = trim( substr( $k, strrpos( $k, '-' ) + 1 ) );

				$widget['export_flag'] = ( $v == 'on' ) ? true : false;
				$widgets[]             = $widget;
			}
			$widgets_array = array();
			foreach ( $widgets as $widget ) {
				$widget_val = get_option( 'widget_' . $widget['type'] );

				// Allow others to take action and apply a custom logic to the whole widgets data for a certain widget type, before export (think replacing image ids with new ones).
				$widget_val = apply_filters( 'pixcare_sce_widgets_data_export_' . $widget['type'], $widget_val, $widget['type'], $params );

				$multiwidget_val = $widget_val['_multiwidget'];

				if ( isset( $widget_val[ $widget['type-index'] ] ) ) {
					// Allow others to take action and apply a custom logic to the widget data, before export (think replacing image ids with new ones).
					$widgets_array[ $widget['type'] ][ $widget['type-index'] ] = apply_filters( 'pixcare_sce_widget_data_export_' . $widget['type'], $widget_val[ $widget['type-index'] ], $widget['type'], $params );
				}

				if ( isset( $widgets_array[ $widget['type'] ]['_multiwidget'] ) ) {
					unset( $widgets_array[ $widget['type'] ]['_multiwidget'] );
				}

				$widgets_array[ $widget['type'] ]['_multiwidget'] = $multiwidget_val;
			}
			unset( $widgets_array['export'] );
			$export_array = apply_filters( 'pixcare_sce_widgets_export', array( $sidebar_export, $widgets_array ), $params );

			return rest_ensure_response( array(
				'code'    => 'success',
				'message' => '',
				'data'    => array(
					'widgets' => $export_array,
				),
			) );
		}

		/**
		 * Get all the options and theme mods which should be added before the import action
		 * @return array
		 */
		protected function get_pre_settings(){
			$mods = get_theme_mods();
			$options = get_option('starter_content_exporter');

			$return = array(
				'options' => array(),
				'mods' => array()
			);

			// make the selected options keys exportable
			if ( ! empty( $options['exported_pre_options'] ) ) {
				// Legacy, keep pre_settings until all the demos get their keys in UI
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
		protected function get_post_settings(){
			$mods = get_theme_mods();
			$options = get_option('starter_content_exporter');

			// some theme mods can be imported from the UI.
			if ( ! empty( $options['ignored_post_theme_mods'] ) ) {
				$this->ignored_theme_mods = array_merge( $this->ignored_theme_mods, $options['ignored_post_theme_mods'] );
			}

			// remove the ignored theme mods keys
			$exported_mods = array_diff_key( $mods, array_flip( $this->ignored_theme_mods ) );

			// Remove the ignored subtheme mods keys, that haven't already been removed.
			if ( ! empty( $this->ignored_theme_mods ) ) {
				// We will also treat ignored theme mods that target a specific suboptions, like 'rosa_options[something]'
				foreach ( $this->ignored_theme_mods as $ignored_theme_mod ) {
					if ( false !== strpos( $ignored_theme_mod, '[') ) {
						preg_match( '#(.+)\[(?:[\'\"]*)([^\'\"]+)(?:[\'\"]*)\]#', $ignored_theme_mod,$matches );
						if ( ! empty( $matches ) && ! empty( $matches[1] ) &&
						     ! empty( $matches[2] ) &&
						     isset( $exported_mods[ $matches[1] ] ) &&
						     isset( $exported_mods[ $matches[1] ][ $matches[2] ] ) ) {

							unset( $exported_mods[ $matches[1] ][ $matches[2] ] );
						}
					}
				}
			}

			$returned_options = array(
				// Legacy, keep it until all the demos get their keys in UI
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

		public function prepare_text_widgets( $widget_data, $type ){

			foreach ( $widget_data as $widget_key => $widget ) {
				if ( '_multiwidget' === $widget_key || ! isset( $widget_data[ $widget_key ]['text'] ) ) {
					continue;
				}

				// start processing the widget content
				$content = $widget_data[ $widget_key ]['text'];

				$upload_dir = wp_get_upload_dir();

				$explode           = explode( '/wp-content/uploads/', $upload_dir['baseurl'] );
				$base_url = '/wp-content/uploads/';
				if ( ! empty( $explode[1] ) ) {
					$base_url = trailingslashit( '/wp-content/uploads/' . $explode[1] );
				}
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

		public function prepare_menu_widgets( $widget_data, $type ){
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
		protected function get_widgets() {
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

		protected function get_available_widgets() {

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

		protected function get_sidebar_info( $sidebar_id ) {
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

		protected function get_client_ignored_images(){
			if ( isset( $_POST['ignored_images'] ) && is_array( $_POST['ignored_images'] ) ) {
				return $_POST['ignored_images'];
			}

			return array();
		}

		protected function get_ignored_images(){
			if ( ! empty( $this->ignored_images ) ) {
				return $this->ignored_images;
			}

			$options = get_option('starter_content_exporter');
			if ( ! empty( $options['ignored_images'] ) ) {
				$this->ignored_images = $this->validate_attachment_ids( explode( ',', $options['ignored_images'] ) );
			} else {
				$this->ignored_images = array();
			}

			return $this->ignored_images;
		}

		protected function get_rotated_placeholder_id( $original_id ) {
			$client_placeholders = $this->get_client_placeholders();
			$client_ignored_images = $this->get_client_ignored_images();

			// If the $original_id is among the ignored images, we will just return the new attachment id.
			if ( isset( $client_ignored_images[$original_id]['id'] ) ) {
				return $client_ignored_images[$original_id]['id'];
			}

			// If the attachment is not ignored, we will replace it with a random one from the placeholders list.

			// get a random $client_placeholders key
			$new_thumb_key = array_rand( $client_placeholders, 1 );
			if ( isset ( $client_placeholders[$new_thumb_key]['id'] ) ) {
				return $client_placeholders[$new_thumb_key]['id'];
			}

			// We should never reach this place.
			return $new_thumb_key;
		}

		/**
		 * @param string $original_image_url Original image URL.
		 *
		 * @return string
		 */
		protected function get_rotated_placeholder_url( $original_image_url ) {
			$client_placeholders = $this->get_client_placeholders();
			$client_ignored_images = $this->get_client_ignored_images();
			$attach_id = attachment_url_to_postid( $original_image_url );

			// If the $original_image_url is among the ignored images, we will just return the new attachment URL.
			if ( isset( $client_ignored_images[$attach_id]['sizes']['full'] ) ) {
				return $client_ignored_images[$attach_id]['sizes']['full'];
			}

			// If the attachment is not ignored, we will replace it with a random one from the placeholders list.

			// get a random $client_placeholders key
			$new_thumb_key = array_rand( $client_placeholders, 1 );
			if ( isset ( $client_placeholders[$new_thumb_key]['sizes']['full'] ) ) {
				return $client_placeholders[$new_thumb_key]['sizes']['full'];
			}

			// We should never reach this place.
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
