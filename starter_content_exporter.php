<?php
/**
 * Plugin Name:       Starter Content Exporter
 * Plugin URI:        https://pixelgrade.com/
 * Description:       A plugin which exposes exportable data through the REST API.
 * Version:           1.1.0
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
		private array $gallery_meta_keys = [
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
		];

		/**
		 * A list of options and theme mods which are supposed to be imported at the start
		 * For example, some options like "which Jetpack modules should be enabled" must be imported before we
		 * even start to import posts or categories.
		 *
		 * The remaining theme mods (which aren't ignored) will be imported at the end of the process
		 *
		 * @var array
		 */
		private array $pre_settings = [
			'mi_options' => [],
			'mi_mods'    => [],
			'options'    => [
				'show_on_front',
				'posts_per_page',
			],
			'mods'       => [
				'pixelgrade_jetpack_default_active_modules',
			],
		];

		/**
		 * A list of options keys which should be ignored from export
		 * @var array
		 */
		private array $ignored_theme_mods = [
			'pixcare_license',   // This is the new key
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
		];

		public function __construct() {
			add_action( 'init', [ $this, 'init_demo_exporter' ], 100050 );
			add_filter( 'socket_config_for_starter_content_exporter', [ $this, 'add_socket_config' ] );

			// The new standard following endpoints
			add_action( 'rest_api_init', [ $this, 'add_rest_routes_api_v2' ] );

			// internal filters
			add_filter( 'sce_export_prepare_post_content', [ $this, 'parse_content_for_images' ], 10, 1 );
			add_filter( 'sce_export_prepare_post_meta', [ $this, 'prepare_post_meta' ], 10, 1 );

			// widgets
			add_filter( 'pixcare_sce_widget_data_export_text', [ $this, 'prepare_text_widgets' ], 10, 1 );
			add_filter( 'pixcare_sce_widget_data_export_nav_menu', [ $this, 'prepare_menu_widgets' ], 10, 1 );
		}

		public function init_demo_exporter() {
			require_once( plugin_dir_path( __FILE__ ) . 'socket/loader.php' );
			$socket = new WP_Socket( [
				'plugin'   => 'starter_content_exporter',
				'api_base' => 'sce/v1',
			] );

			require_once( plugin_dir_path( __FILE__ ) . 'safe-svg.php' );
		}

		/**
		 * This is the management interface config via the Socket options framework
		 *
		 * @param array $config
		 *
		 * @return array
		 */
		public function add_socket_config( array $config ): array {
			$config = array_merge( $config, [
				'page_title'  => 'Set Up Content/Data Exports',
				'description' => '',
				'nav_label'   => 'Starter Content Exporter',
				'options_key' => 'starter_content_exporter',
				'display'     => [
					// Group the sockets
					'group'  => true,
					// Provide the groups of socket keys. The order will be respected.
					'groups' => [
						[
							'title'   => 'Must-Import Data',
							'desc'    => 'This data will be <strong>automatically imported upon theme setup.</strong><br>This should not be focused on content, but on data that is important for the theme to function as expected.',
							'sockets' => [
								'export_mi_media',
								'export_mi_post_types',
								'export_mi_options',
							],
						],
						[
							'title'   => 'Optional Content/Data',
							'desc'    => 'This content/data will only be imported if the user chooses so.',
							'sockets' => [
								'export_media',
								'export_post_types',
								'export_options',
							],
						],
					],
				],
				'sockets'     => [],
			] );

			$config['sockets']['export_media'] = [
				'label' => 'Media',
				'items' => [
					'placeholders'   => [
						'type'        => 'gallery',
						'label'       => 'Placeholder Images',
						'description' => 'Pick a set of images which should <strong>replace post content images.</strong> Assume the order in which these images will be chosen is <em>random.</em>',
					],
					'ignored_images' => [
						'type'        => 'gallery',
						'label'       => 'Ignored Images',
						'description' => 'Pick a set of images to be <strong>ignored from replacement</strong> in the exported content. They will be exported as they are.',
					],
				],
			];

			$config['sockets']['export_post_types'] = [
				'label' => 'Posts & Taxonomies',
				'items' => [],
			];

			$post_types = get_post_types( [ 'show_in_rest' => true ], 'objects' );

			foreach ( $post_types as $post_type => $post_type_config ) {
				if ( 'attachment' === $post_type ) {
					continue;
				}

				$config['sockets']['export_post_types']['items'][ 'post_type_' . $post_type . '_start' ] = [
					'type' => 'divider',
					'html' => $post_type,
				];

				$config['sockets']['export_post_types']['items'][ 'post_type_' . $post_type ] = [
					'type'  => 'post_select',
					'label' => $post_type_config->label,
					'query' => [
						'post_type' => $post_type,
					],
				];

				$taxonomy_objects = get_object_taxonomies( $post_type, 'objects' );
				if ( ! empty( $taxonomy_objects ) ) {
					foreach ( $taxonomy_objects as $tax => $tax_config ) {

						if ( in_array( $tax,[
							'feedback',
							'jp_pay_order',
							'jp_pay_product',
							'post_format',
							'product_type',
							'product_visibility',
							'product_shipping_class',
						] ) ) {
							continue;
						}

						$config['sockets']['export_post_types']['items'][ 'tax_' . $tax ] = [
							'type'  => 'tax_select',
							'label' => $tax_config->label,
							'query' => [
								'taxonomy' => $tax,
							],
						];
					}
				}

				$config['sockets']['export_post_types']['items'][ 'post_type_' . $post_type . '_end' ] = [
					'type' => 'divider',
					'html' => ''
				];
			}

			$config['sockets']['export_options'] = [
				'label' => 'Site Options and Theme Mods',
				'items' => [
					'exported_pre_options'    => [
						'type'        => 'tags',
						'label'       => 'Pre-content Import Options Keys',
						'description' => 'Select which site options keys should be imported before importing the content.',
					],
					'exported_post_options'   => [
						'type'        => 'tags',
						'label'       => 'Post-content Import Options Keys',
						'description' => 'Select which site options keys should be imported after the content has been imported.',
					],
					'exported_pre_theme_mods' => [
						'type'        => 'tags',
						'label'       => 'Pre-content Import Theme-Mods Keys',
						'description' => 'Select which theme_mod keys should be imported before importing content.',
					],
					'ignored_post_theme_mods' => [
						'type'        => 'tags',
						'label'       => 'Ignored Theme-Mods Keys',
						'description' => 'All the theme mods are exported after import, but you can chose to ignore certain keys.',
					],
				],
			];

			// Must-Import media.
			$config['sockets']['export_mi_media'] = [
				'label' => 'Media',
				'items' => [
					'mi_placeholders'   => [
						'type'        => 'gallery',
						'label'       => 'Placeholder Images',
						'description' => 'Pick a set of images which should <strong>replace post content images.</strong> Assume the order in which these images will be chosen is <em>random.</em>',
					],
					'mi_ignored_images' => [
						'type'        => 'gallery',
						'label'       => 'Ignored Images',
						'description' => 'Pick a set of images to be <strong>ignored from replacement</strong> in the exported content. They will be exported as they are.',
					],
				],
			];

			// Must-Import post types.
			$config['sockets']['export_mi_post_types'] = [
				'label' => 'Posts & Taxonomies',
				'items' => [],
			];
			foreach ( $post_types as $post_type => $post_type_config ) {
				if ( 'attachment' === $post_type ) {
					continue;
				}

				$config['sockets']['export_mi_post_types']['items'][ 'mi_post_type_' . $post_type . '_start' ] = [
					'type' => 'divider',
					'html' => $post_type,
				];

				$config['sockets']['export_mi_post_types']['items'][ 'mi_post_type_' . $post_type ] = [
					'type'  => 'post_select',
					'label' => $post_type_config->label,
					'query' => [
						'post_type' => $post_type,
					],
				];

				$taxonomy_objects = get_object_taxonomies( $post_type, 'objects' );
				if ( ! empty( $taxonomy_objects ) ) {
					foreach ( $taxonomy_objects as $tax => $tax_config ) {

						if ( in_array( $tax, [
							'feedback',
							'jp_pay_order',
							'jp_pay_product',
							'post_format',
							'product_type',
							'product_visibility',
							'product_shipping_class',
						] ) ) {
							continue;
						}

						$config['sockets']['export_mi_post_types']['items'][ 'mi_tax_' . $tax ] = [
							'type'  => 'tax_select',
							'label' => $tax_config->label,
							'query' => [
								'taxonomy' => $tax,
							],
						];
					}
				}

				$config['sockets']['export_mi_post_types']['items'][ 'mi_post_type_' . $post_type . '_end' ] = [
					'type' => 'divider',
					'html' => '',
				];
			}

			// Must-Import options.
			$config['sockets']['export_mi_options'] = [
				'label' => 'Site Options and Theme Mods',
				'items' => [
					'mi_exported_pre_options'     => [
						'type'        => 'tags',
						'label'       => 'Pre-content Import Options Keys',
						'description' => 'Select which site options keys should be imported before importing the content.',
					],
					'mi_exported_post_options'    => [
						'type'        => 'tags',
						'label'       => 'Post-content Import Options Keys',
						'description' => 'Select which site options keys should be imported after the content has been imported.',
					],
					'mi_exported_pre_theme_mods'  => [
						'type'        => 'tags',
						'label'       => 'Pre-content Import Theme-Mods Keys',
						'description' => 'Select which theme_mod keys should be imported before importing content.',
					],
					'mi_exported_post_theme_mods' => [
						'type'        => 'tags',
						'label'       => 'Post-content Import Theme-Mods Keys',
						'description' => 'Select which theme_mod keys should be imported after importing content.',
					],
				],
			];

			return $config;
		}

		/**
		 * REST API Endpoints that follow our common standard of response:
		 * - code
		 * - message
		 * - data
		 */
		public function add_rest_routes_api_v2() {

			register_rest_route( 'sce/v2', '/mi-data', [
				'methods'  => WP_REST_Server::READABLE,
				'callback' => [ $this, 'rest_export_mi_data_v2' ],
			] );

			register_rest_route( 'sce/v2', '/data', [
				'methods'  => WP_REST_Server::READABLE,
				'callback' => [ $this, 'rest_export_data_v2' ],
			] );

			register_rest_route( 'sce/v2', '/media', [
				'methods'  => WP_REST_Server::READABLE,
				'callback' => [ $this, 'rest_export_media_v2' ],
			] );

			register_rest_route( 'sce/v2', '/posts', [
				'methods'  => WP_REST_Server::CREATABLE,
				'callback' => [ $this, 'rest_export_posts_v2' ],
				'args'     => [
					'include' => [
						'required' => true,
					],
				],
			] );

			register_rest_route( 'sce/v2', '/terms', [
				'methods'  => WP_REST_Server::CREATABLE,
				'callback' => [ $this, 'rest_export_terms_v2' ],
				'args'     => [
					'include' => [
						'required' => true,
					],
				],
			] );

			register_rest_route( 'sce/v2', '/widgets', [
				'methods'  => WP_REST_Server::CREATABLE,
				'callback' => [ $this, 'rest_export_widgets_v2' ],
			] );
		}

		/**
		 * Handle requests for must-import data.
		 *
		 * @return WP_Error|WP_HTTP_Response|WP_REST_Response
		 */
		public function rest_export_mi_data_v2() {
			$options = get_option( 'starter_content_exporter' );

			$data = [
				'media'         => [
					'placeholders' => [],
					'ignored'      => [],
				],
				'post_types'    => [],
				'taxonomies'    => [],
				'pre_settings'  => [],
				'post_settings' => [],
			];

			if ( ! empty( $options['mi_placeholders'] ) ) {
				$data['media']['placeholders'] = $this->validate_attachment_ids( wp_parse_id_list( $options['mi_placeholders'] ) );
			}

			if ( ! empty( $options['mi_ignored_images'] ) ) {
				$data['media']['ignored'] = $this->validate_attachment_ids( wp_parse_id_list( $options['mi_ignored_images'] ) );
			} else {
				$data['media']['ignored'] = [];
			}

			if ( ! empty( $options ) ) {
				foreach ( $options as $key => $option ) {
					if ( strpos( $key, 'mi_post_type_' ) !== false ) {
						$post_type = str_replace( 'mi_post_type_', '', $key );
						$priority  = 10;

						/**
						 * We need to make sure that the navigation items are imported last.
						 * The metadata of a menu item can contain an object_id which should be mapped, but we can only map existing IDs.
						 */
						if ( 'nav_menu_item' === $post_type ) {
							$priority = 100;
						}

						$data['post_types'][] = [
							'name'     => $post_type,
							'ids'      => wp_parse_id_list( $option ),
							'priority' => $priority, // for now all will have the same priority
						];
					} elseif ( strpos( $key, 'mi_tax_' ) !== false ) {
						$taxonomy             = str_replace( 'mi_tax_', '', $key );
						$data['taxonomies'][] = [
							'name'     => $taxonomy,
							'ids'      => wp_parse_id_list( $option ),
							'priority' => 10, // for now all will have the same priority
						];
					}
				}
			}

			$data['pre_settings']  = $this->get_mi_pre_settings();
			$data['post_settings'] = $this->get_mi_post_settings();

			return rest_ensure_response( [
				'code'    => 'success',
				'message' => '',
				'data'    => $data,
			] );
		}

		public function rest_export_data_v2() {
			$options = get_option( 'starter_content_exporter' );

			$data = [
				'media'      => [
					'placeholders' => [],
					'ignored'      => [],
				],
				'post_types' => [],
				'taxonomies' => [],
				'widgets'    => $this->get_widgets(),
				'pre_settings'  => [],
				'post_settings' => [],
			];

			if ( ! empty( $options['placeholders'] ) ) {
				$data['media']['placeholders'] = $this->validate_attachment_ids( wp_parse_id_list( $options['placeholders'] ) );
			}

			$data['media']['ignored'] = $this->get_ignored_images();

			if ( ! empty( $options ) ) {
				foreach ( $options as $key => $option ) {
					if ( strpos( $key, 'post_type_' ) !== false && strpos( $key, 'mi_post_type_' ) === false ) {
						$post_type = str_replace( 'post_type_', '', $key );
						$priority  = 10;

						/**
						 * We need to make sure that the navigation items are imported the last
						 * The metadata of a menu item can contain an object_id which should be mapped, but we can only map existing IDS
						 */
						if ( 'nav_menu_item' === $post_type ) {
							$priority = 100;
						}

						$data['post_types'][] = [
							'name'     => $post_type,
							'ids'      => wp_parse_id_list( $option ),
							'priority' => $priority, // For now on will have the same priority
						];
					} elseif ( strpos( $key, 'tax_' ) !== false && strpos( $key, 'mi_tax_' ) === false ) {
						$taxonomy             = str_replace( 'tax_', '', $key );
						$data['taxonomies'][] = [
							'name'     => $taxonomy,
							'ids'      => wp_parse_id_list( $option ),
							'priority' => 10, // For now on will have the same priority.
						];
					}
				}
			}

			$data['pre_settings']  = $this->get_pre_settings();
			$data['post_settings'] = $this->get_post_settings();

			return rest_ensure_response( [
				'code'    => 'success',
				'message' => '',
				'data'    => $data,
			] );
		}

		protected function validate_attachment_ids( $attachment_ids ) : array {
			if ( empty( $attachment_ids ) ) {
				$attachment_ids = [];
			}
			// Go through each one and make sure that they exist.
			foreach ( $attachment_ids as $key => $attachment_id ) {
				$attachment_id = absint( $attachment_id );
				$file_path     = get_attached_file( $attachment_id );
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
		public function rest_export_posts_v2( WP_REST_Request $request ): WP_REST_Response {
			$params = $request->get_params();

			$query_args = array(
				'post__in'       => empty( $params['include'] ) ? [] : wp_parse_id_list( $params['include'] ),
				'posts_per_page' => 100,
				'post_type' => 'any',
				'no_found_rows' => true,
				'ignore_sticky_posts' => true,
			);

			if ( ! empty( $params['post_type'] ) ) {
				$query_args['post_type'] = sanitize_text_field( $params['post_type'] );
			}

			$get_posts = new WP_Query;
			$posts = $get_posts->query( $query_args );
			foreach ( $posts as &$post ) {
				$post->meta         = apply_filters( 'sce_export_prepare_post_meta', get_post_meta( $post->ID ), $post );
				$post->post_content = apply_filters( 'sce_export_prepare_post_content', $post->post_content, $post );

				$post->taxonomies = [];
				foreach ( array_values( get_post_taxonomies( $post ) ) as $taxonomy ) {

					$fields = 'names';
					if ( is_taxonomy_hierarchical( $taxonomy ) ) {
						$fields = 'ids';
					}

					$current_tax = wp_get_object_terms( $post->ID, $taxonomy, [
						'fields' => $fields,
					] );

					if ( ! is_wp_error( $current_tax ) && ! empty( $current_tax ) ) {
						$post->taxonomies[ $taxonomy ] = $current_tax;
					} else {
						unset( $post->taxonomies[ $taxonomy ] );
					}
				}
			}

			return rest_ensure_response( [
				'code'    => 'success',
				'message' => '',
				'data'    => [
					'posts' => $posts,
				],
			] );
		}

		public function parse_content_for_images( string $content ) : string {
			$upload_dir = wp_get_upload_dir();

			$explode  = explode( '/wp-content/uploads/', $upload_dir['baseurl'] );
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

		public function replace_gallery_shortcodes_ids( string $content ) : string {
			// pregmatch only the ids attribute
			$pattern = '((\[gallery.*])?ids=\"(.*)\")';

			$content = preg_replace_callback( $pattern, array(
				$this,
				'replace_gallery_shortcodes_ids_pregmatch_callback',
			), $content );

			return $content;
		}

		public function replace_gallery_shortcodes_ids_pregmatch_callback( $matches ) {
			if ( ! empty( $matches[2] ) ) {
				$replace_ids = [];
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

		public function prepare_post_meta( array $metas ) : array {

			// useless meta
			unset( $metas['_edit_lock'] );
			unset( $metas['_wp_old_slug'] );
			unset( $metas['_wpas_done_all'] );
			unset( $metas['imported_with_pixcare'] );
			unset( $metas['_edit_last'] );
			unset( $metas['_yoast_wpseo_content_score'] );

			// usually the attachment_metadata will be regenerated
			unset( $metas['_wp_attached_file'] );

			foreach ( $this->gallery_meta_keys as $gallery_key ) {
				if ( isset( $metas[ $gallery_key ] ) ) {
					$selected_images = explode( ',', $metas[ $gallery_key ][0] );

					foreach ( $selected_images as $i => $attach_id ) {
						$selected_images[ $i ] = $this->get_rotated_placeholder_id( $attach_id );
					}

					$metas[ $gallery_key ] = [ join( ',', $selected_images ) ];
				}
			}

			return $metas;
		}

		/**
		 * @param WP_REST_Request $request
		 *
		 * @return WP_REST_Response
		 */
		public function rest_export_terms_v2( WP_REST_Request $request ): WP_REST_Response {
			$options = get_option( 'starter_content_exporter' );

			$params = $request->get_params();

			$query_args = [
				'include'    => wp_parse_id_list( $params['include'] ),
				'hide_empty' => false,
			];

			if ( ! empty( $params['taxonomy'] ) ) {
				$query_args['taxonomy'] = sanitize_text_field( $params['taxonomy'] );
			}

			$terms = get_terms( $query_args );
			if ( is_wp_error( $terms ) ) {
				return rest_ensure_response( $terms );
			}

			foreach ( $terms as $key => $term ) {
				$term->meta = get_term_meta( $term->term_id );
			}

			return rest_ensure_response( [
				'code'    => 'success',
				'message' => '',
				'data'    => [
					'terms' => $terms,
				],
			] );
		}

		/**
		 * @param WP_REST_Request $request
		 *
		 * @return WP_REST_Response
		 */
		public function rest_export_media_v2( WP_REST_Request $request ): WP_REST_Response {
			$params = $request->get_params();

			if ( empty( $params['id'] ) ) {
				return rest_ensure_response( [
					'code'    => 'missing_id',
					'message' => 'You need to provide an attachment id.',
					'data'    => [],
				] );
			}

			$file_path = get_attached_file( absint( $params['id'] ) );
			if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
				return rest_ensure_response( [
					'code'    => 'missing_attachment',
					'message' => 'The attachment id is missing or it\'s file could not be found.',
					'data'    => [],
				] );
			}

			$file_info = wp_check_filetype_and_ext( $file_path, $file_path );
			if ( empty( $file_info['ext'] ) || empty( $file_info['type'] ) ) {
				return rest_ensure_response( [
					'code'    => 'mime_error',
					'message' => 'We could not determine the mime type of the media.',
					'data'    => [],
				] );
			}

			$imageData = file_get_contents( $file_path );
			if ( empty( $imageData ) ) {
				return rest_ensure_response( [
					'code'    => 'no_image_data',
					'message' => 'We could not get the image contents.',
					'data'    => [],
				] );
			}

			$base64 = 'data:' . $file_info['type'] . ';base64,' . base64_encode( $imageData );

			return rest_ensure_response( [
				'code'    => 'success',
				'message' => '',
				'data'    => [
					'media' => [
						'title'     => pathinfo( $file_path, PATHINFO_FILENAME ),
						'mime_type' => $file_info['type'],
						'ext'       => $file_info['ext'],
						'data'      => $base64,
					],
				],
			] );
		}

		/**
		 * @param WP_REST_Request $request
		 *
		 * @return WP_REST_Response
		 */
		public function rest_export_widgets_v2( WP_REST_Request $request ): WP_REST_Response {
			$params = $request->get_params();

			$posted_array = $this->get_available_widgets();

			$sidebars_array = get_option( 'sidebars_widgets' );
			$sidebar_export = [];
			foreach ( $sidebars_array as $sidebar => $widgets ) {
				if ( ! empty( $widgets ) && is_array( $widgets ) ) {
					foreach ( $widgets as $sidebar_widget ) {
						if ( in_array( $sidebar_widget, array_keys( $posted_array ) ) ) {
							$sidebar_export[ $sidebar ][] = $sidebar_widget;
						}
					}
				}
			}
			$widgets = [];
			foreach ( $posted_array as $k => $v ) {
				$widget = [];
				// Extract the widget type and index from the widget instance ID
				$widget['type']       = trim( substr( $k, 0, strrpos( $k, '-' ) ) );
				$widget['type-index'] = trim( substr( $k, strrpos( $k, '-' ) + 1 ) );

				$widget['export_flag'] = ( $v == 'on' ) ? true : false;
				$widgets[]             = $widget;
			}
			$widgets_array = [];
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
			$export_array = apply_filters( 'pixcare_sce_widgets_export', [
				$sidebar_export,
				$widgets_array,
			], $params );

			return rest_ensure_response( [
				'code'    => 'success',
				'message' => '',
				'data'    => [
					'widgets' => $export_array,
				],
			] );
		}

		/**
		 * Get all the must-import options and theme mods which should be added before the import action
		 * @return array
		 */
		protected function get_mi_pre_settings(): array {
			$mods    = get_theme_mods();
			$options = get_option( 'starter_content_exporter' );

			$return = array(
				'options' => [],
				'mods'    => [],
			);

			// Make the selected options keys exportable.
			$mi_pre_options = $this->pre_settings['mi_options'];
			if ( ! empty( $options['mi_exported_pre_options'] ) ) {
				// Legacy, keep pre_settings until all the demos get their keys in UI
				$mi_pre_options = array_merge( $mi_pre_options, $options['mi_exported_pre_options'] );
			}

			foreach ( $mi_pre_options as $key ) {
				$option_value = get_option( $key, null );

				// We need to check if the option key really exists and ignore the nonexistent.
				if ( $option_value !== null ) {
					$return['options'][ $key ] = $option_value;
				}
			}

			$mi_theme_mods = $this->pre_settings['mods'];
			if ( ! empty( $options['mi_exported_pre_theme_mods'] ) ) {
				$mi_theme_mods = array_merge( $mi_theme_mods, $options['mi_exported_pre_theme_mods'] );
			}

			// @TODO make this work with values from UI
			foreach ( $mi_theme_mods as $key ) {
				if ( isset( $mods[ $key ] ) ) {
					$return['mods'][ $key ] = $mods[ $key ];
				}
			}

			return $return;
		}

		/**
		 * Get all the must-import options and theme mods which should be added after the import action
		 * @return array
		 */
		protected function get_mi_post_settings(): array {
			$mods    = get_theme_mods();
			$options = get_option( 'starter_content_exporter' );

			$returned_options = [
				// Legacy, keep it until all the demos get their keys in UI.
				'options' => [
					'page_on_front'  => get_option( 'page_on_front' ),
					'page_for_posts' => get_option( 'page_for_posts' ),
				],
				'mods'    => [],
			];


			$mi_post_theme_mods = [];
			if ( ! empty( $options['mi_exported_post_theme_mods'] ) ) {
				$mi_post_theme_mods = array_merge( $mi_post_theme_mods, $options['mi_exported_post_theme_mods'] );
			}

			foreach ( $mi_post_theme_mods as $key ) {
				if ( isset( $mods[ $key ] ) ) {
					$return['mods'][ $key ] = $mods[ $key ];
				}
			}

			// Make the selected options keys exportable.
			if ( ! empty( $options['mi_exported_post_options'] ) ) {
				foreach ( $options['mi_exported_post_options'] as $option ) {
					$option_value = get_option( $option, null );

					// We need to check if the option key really exists and ignore the nonexistent.
					if ( $option_value !== null ) {
						$returned_options['options'][ $option ] = $option_value;
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

		/**
		 * Get all the options and theme mods which should be added before the import action
		 * @return array
		 */
		protected function get_pre_settings(): array {
			$mods    = get_theme_mods();
			$options = get_option( 'starter_content_exporter' );

			$return = array(
				'options' => [],
				'mods'    => [],
			);

			// make the selected options keys exportable
			if ( ! empty( $options['exported_pre_options'] ) ) {
				// Legacy, keep pre_settings until all the demos get their keys in UI
				$this->pre_settings['options'] = array_merge( $this->pre_settings['options'], $options['exported_pre_options'] );
			}

			foreach ( $this->pre_settings['options'] as $key ) {
				$option_value = get_option( $key, null );

				// we need to check if the option key really exists and ignore the unexistent
				if ( $option_value !== null ) {
					$return['options'][ $key ] = $option_value;
				}
			}

			if ( ! empty( $options['exported_pre_theme_mods'] ) ) {
				$this->pre_settings['mods'] = array_merge( $this->pre_settings['mods'], $options['exported_pre_theme_mods'] );
			}

			// @TODO make this work with values from UI
			foreach ( $this->pre_settings['mods'] as $key ) {
				if ( isset( $mods[ $key ] ) ) {
					$return['mods'][ $key ]     = $mods[ $key ];
					$this->ignored_theme_mods[] = $key;
				}
			}

			return $return;
		}

		/**
		 * Get all the options and theme mods which should be added after the import action
		 * @return array
		 */
		protected function get_post_settings(): array {
			$mods    = get_theme_mods();
			$options = get_option( 'starter_content_exporter' );


			if ( ! empty( $options['ignored_post_theme_mods'] ) ) {
				$this->ignored_theme_mods = array_merge( $this->ignored_theme_mods, $options['ignored_post_theme_mods'] );
			}
			// remove the ignored theme mods keys
			$exported_mods = array_diff_key( $mods, array_flip( $this->ignored_theme_mods ) );

			// Remove the ignored subtheme mods keys, that haven't already been removed.
			if ( ! empty( $this->ignored_theme_mods ) ) {
				// We will also treat ignored theme mods that target a specific suboption, like 'rosa_options[something]'
				foreach ( $this->ignored_theme_mods as $ignored_theme_mod ) {
					if ( false !== strpos( $ignored_theme_mod, '[' ) ) {
						preg_match( '#(.+)\[(?:[\'\"]*)([^\'\"]+)(?:[\'\"]*)\]#', $ignored_theme_mod, $matches );
						if ( ! empty( $matches ) && ! empty( $matches[1] ) &&
						     ! empty( $matches[2] ) &&
						     isset( $exported_mods[ $matches[1] ] ) &&
						     isset( $exported_mods[ $matches[1] ][ $matches[2] ] ) ) {

							unset( $exported_mods[ $matches[1] ][ $matches[2] ] );
						}
					}
				}
			}

			$returned_options = [
				// Legacy, keep it until all the demos get their keys in UI
				'options' => [
					'page_on_front'  => get_option( 'page_on_front' ),
					'page_for_posts' => get_option( 'page_for_posts' ),
				],
				'mods'    => $exported_mods,
			];

			// make the selected options keys exportable
			if ( ! empty( $options['exported_post_options'] ) ) {
				foreach ( $options['exported_post_options'] as $option ) {
					$option_value = get_option( $option, null );

					// we need to check if the option key really exists and ignore the nonexistent
					if ( $option_value !== null ) {
						$returned_options['options'][ $option ] = $option_value;
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

		public function prepare_text_widgets( array $widget_data ): array {

			foreach ( $widget_data as $widget_key => $widget ) {
				if ( '_multiwidget' === $widget_key || ! isset( $widget_data[ $widget_key ]['text'] ) ) {
					continue;
				}

				// start processing the widget content
				$content = $widget_data[ $widget_key ]['text'];

				$upload_dir = wp_get_upload_dir();

				$explode  = explode( '/wp-content/uploads/', $upload_dir['baseurl'] );
				$base_url = '/wp-content/uploads/';
				if ( ! empty( $explode[1] ) ) {
					$base_url = trailingslashit( '/wp-content/uploads/' . $explode[1] );
				}
				$attachments_regex = '~(?<=src=\").+((' . $base_url . ')|(files\.wordpress\.com)).+(?=[\"\ ])~U';

				preg_match_all( $attachments_regex, $content, $result );

				foreach ( $result[0] as $i => $match ) {
					$original_image_url = $match;
					$new_url            = $this->get_rotated_placeholder_url( $original_image_url );
					$content            = str_replace( $original_image_url, $new_url, $content );
				}

				// search for shortcodes with attachments ids like gallery
				if ( has_shortcode( $content, 'gallery' ) ) {
					$content = $this->replace_gallery_shortcodes_ids( $content );
				}

				$widget_data[ $widget_key ]['text'] = $content;
				// end processing the widget content
			}

			return $widget_data;
		}

		public function prepare_menu_widgets( array $widget_data ): array {
			foreach ( $widget_data as $widget_key => $widget ) {
				if ( '_multiwidget' === $widget_key || ! isset( $widget_data[ $widget_key ]['nav_menu'] ) ) {
					continue;
				}

				$id = $widget_data[ $widget_key ]['nav_menu'];

				$widget_data[ $widget_key ]['nav_menu'] = $this->get_new_term_id( $id );
			}

			return $widget_data;
		}

		/**
		 * Widget functions inspired from Widget Data - Setting Import/Export Plugin
		 * by Voce Communications - Kevin Langley, Sean McCafferty, Mark Parolisi
		 */
		protected function get_widgets(): string {
			$posted_array = $this->get_available_widgets();

			$sidebars_array = get_option( 'sidebars_widgets' );
			$sidebar_export = [];
			foreach ( $sidebars_array as $sidebar => $widgets ) {
				if ( ! empty( $widgets ) && is_array( $widgets ) ) {
					foreach ( $widgets as $sidebar_widget ) {
						if ( in_array( $sidebar_widget, array_keys( $posted_array ) ) ) {
							$sidebar_export[ $sidebar ][] = $sidebar_widget;
						}
					}
				}
			}
			$widgets = [];
			foreach ( $posted_array as $k => $v ) {
				$widget                = [];
				$widget['type']        = trim( substr( $k, 0, strrpos( $k, '-' ) ) );
				$widget['type-index']  = trim( substr( $k, strrpos( $k, '-' ) + 1 ) );
				$widget['export_flag'] = ( $v == 'on' ) ? true : false;
				$widgets[]             = $widget;
			}
			$widgets_array = [];
			foreach ( $widgets as $widget ) {
				$widget_val      = get_option( 'widget_' . $widget['type'] );
				$widget_val      = apply_filters( 'widget_data_export', $widget_val, $widget['type'] );
				$multiwidget_val = $widget_val['_multiwidget'];

				if ( isset( $widget_val[ $widget['type-index'] ] ) ) {
					$widgets_array[ $widget['type'] ][ $widget['type-index'] ] = $widget_val[ $widget['type-index'] ];
				}

				if ( isset( $widgets_array[ $widget['type'] ]['_multiwidget'] ) ) {
					unset( $widgets_array[ $widget['type'] ]['_multiwidget'] );
				}

				$widgets_array[ $widget['type'] ]['_multiwidget'] = $multiwidget_val;
			}
			unset( $widgets_array['export'] );
			$export_array = array( $sidebar_export, $widgets_array );

			$json = json_encode( $export_array );

			return base64_encode( $json );
		}

		protected function get_available_widgets(): array {

			$sidebar_widgets = wp_get_sidebars_widgets();
			unset( $sidebar_widgets['wp_inactive_widgets'] );

			$availableWidgets = [];

			foreach ( $sidebar_widgets as $sidebar_name => $widget_list ) {
				if ( empty( $widget_list ) ) {
					continue;
				}

				$sidebar_info = $this->get_sidebar_info( $sidebar_name );

				if ( empty( $sidebar_info ) ) {
					continue;
				}

				foreach ( $widget_list as $widget ) {
					$widget_type       = trim( substr( $widget, 0, strrpos( $widget, '-' ) ) );
					$widget_type_index = trim( substr( $widget, strrpos( $widget, '-' ) + 1 ) );
					$widget_options    = get_option( 'widget_' . $widget_type );
					$widget_title      = isset( $widget_options[ $widget_type_index ]['title'] ) ? $widget_options[ $widget_type_index ]['title'] : $widget_type_index;

					$availableWidgets[ $widget ] = 'on';
				}
			}

			return $availableWidgets;
		}

		protected function get_sidebar_info( $sidebar_id ) {
			global $wp_registered_sidebars;

			// Since wp_inactive_widget is only used in widgets.php.
			if ( $sidebar_id == 'wp_inactive_widgets' ) {
				return [ 'name' => 'Inactive Widgets', 'id' => 'wp_inactive_widgets' ];
			}

			foreach ( $wp_registered_sidebars as $sidebar ) {
				if ( isset( $sidebar['id'] ) && $sidebar['id'] == $sidebar_id ) {
					return $sidebar;
				}
			}

			return false;
		}

		protected function get_client_ignored_images(): array {
			if ( isset( $_POST['ignored_images'] ) && is_array( $_POST['ignored_images'] ) ) {
				return $_POST['ignored_images'];
			}

			return [];
		}

		protected function get_ignored_images(): array {
			if ( ! empty( $this->ignored_images ) ) {
				return $this->ignored_images;
			}

			$options = get_option( 'starter_content_exporter' );
			if ( ! empty( $options['ignored_images'] ) ) {
				$this->ignored_images = $this->validate_attachment_ids( wp_parse_id_list( $options['ignored_images'] ) );
			} else {
				$this->ignored_images = [];
			}

			return $this->ignored_images;
		}

		protected function get_rotated_placeholder_id( $original_id ) {
			$client_placeholders   = $this->get_client_placeholders();
			$client_ignored_images = $this->get_client_ignored_images();

			// If the $original_id is among the ignored images, we will just return the new attachment id.
			if ( isset( $client_ignored_images[ $original_id ]['id'] ) ) {
				return $client_ignored_images[ $original_id ]['id'];
			}

			// If the attachment is not ignored, we will replace it with a random one from the placeholders list.

			// get a random $client_placeholders key
			$new_thumb_key = array_rand( $client_placeholders, 1 );
			if ( isset ( $client_placeholders[ $new_thumb_key ]['id'] ) ) {
				return $client_placeholders[ $new_thumb_key ]['id'];
			}

			// We should never reach this place.
			return $new_thumb_key;
		}

		/**
		 * @param string $original_image_url Original image URL.
		 *
		 * @return string
		 */
		protected function get_rotated_placeholder_url( string $original_image_url ): string {
			$client_placeholders   = $this->get_client_placeholders();
			$client_ignored_images = $this->get_client_ignored_images();
			$attach_id             = attachment_url_to_postid( $original_image_url );

			// If the $original_image_url is among the ignored images, we will just return the new attachment URL.
			if ( isset( $client_ignored_images[ $attach_id ]['sizes']['full'] ) ) {
				return $client_ignored_images[ $attach_id ]['sizes']['full'];
			}

			// If the attachment is not ignored, we will replace it with a random one from the placeholders list.

			// get a random $client_placeholders key
			$new_thumb_key = array_rand( $client_placeholders, 1 );
			if ( isset ( $client_placeholders[ $new_thumb_key ]['sizes']['full'] ) ) {
				return $client_placeholders[ $new_thumb_key ]['sizes']['full'];
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
		private function get_client_placeholders(): array {
			if ( ! isset( $_POST['placeholders'] ) || ! is_array( $_POST['placeholders'] ) ) {
				return [];
			}

			if ( empty( $this->client_placeholders ) ) {
				$this->client_placeholders = $_POST['placeholders'];
			} else {
				$keys = array_keys( $this->client_placeholders );
				$val  = $this->client_placeholders[ $keys[0] ];
				unset( $this->client_placeholders[ $keys[0] ] );
				$this->client_placeholders[ $keys[0] ] = $val;
			}

			return $this->client_placeholders;
		}

		private function get_client_posts(): array {
			if ( empty( $_POST['post_types'] ) || ! is_array( $_POST['post_types'] ) ) {
				return [];
			}

			$types = (array) $_POST['post_types'];

			foreach ( $types as $key => $posts ) {
				$types[ $key ] = array_map( 'intval', $posts );
			}

			return $types;
		}

		private function get_client_terms(): array {
			if ( empty( $_POST['taxonomies'] ) || ! is_array( $_POST['taxonomies'] ) ) {
				return [];
			}

			$terms = (array) $_POST['taxonomies'];

			foreach ( $terms as $key => $term ) {
				$terms[ $key ] = array_map( 'intval', $term );
			}

			return $terms;
		}

		private function get_new_post_id( $id ) {
			$types = $this->get_client_posts();

			if ( isset( $types[ $id ] ) ) {
				return $types[ $id ];
			}

			return $id;
		}

		private function get_new_term_id( $id ) {
			$terms = $this->get_client_terms();

			if ( isset( $terms[ $id ] ) ) {
				return $terms[ $id ];
			}

			return $id;
		}
	}
}

$starter_content_exporter = new Starter_Content_Exporter();
