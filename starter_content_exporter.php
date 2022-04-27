<?php
/**
 * Plugin Name:       Starter Content Exporter
 * Plugin URI:        https://pixelgrade.com/
 * Description:       A plugin which exposes exportable data through the REST API.
 * Version:           1.4.0
 * Author:            Pixelgrade, Vlad Olaru
 * Author URI:        https://pixelgrade.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       socket
 * Domain Path:       /languages
 * Requires at least: 5.5.0
 * Tested up to:      5.9.3
 * Requires PHP:      7.4
 */

if ( ! class_exists( 'Starter_Content_Exporter' ) ) {

	class Starter_Content_Exporter {

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
			register_activation_hook( __FILE__, [ 'Starter_Content_Exporter', 'activate' ] );

			add_action( 'init', [ $this, 'init_demo_exporter' ], 100050 );
			add_filter( 'socket_config_for_starter_content_exporter', [ $this, 'add_socket_config' ] );

			/**
			 * Add REST API support to all registered post types that don't specify a REST API behavior.
			 *
			 * This is OK since we use Starter Content Exporter on demo sites and there is nothing sensitive to expose.
			 */
			add_filter( 'register_post_type_args', [ $this, 'add_restapi_post_type_args' ], 10, 2 );
			// Do the same for taxonomies.
			add_filter( 'register_taxonomy_args', [ $this, 'add_restapi_taxonomy_args' ], 10, 2 );

			// The new standard following endpoints
			add_action( 'rest_api_init', [ $this, 'add_rest_routes_api_v2' ] );

			// internal filters
			add_filter( 'sce_export_prepare_post_content', [ $this, 'parse_content_for_images' ], 10, 1 );
			add_filter( 'sce_export_prepare_post_meta', [ $this, 'prepare_post_meta' ], 10, 1 );

			// widgets
			add_filter( 'pixcare_sce_widget_data_export_text', [ $this, 'prepare_text_widgets' ], 10, 1 );
			add_filter( 'pixcare_sce_widget_data_export_nav_menu', [ $this, 'prepare_menu_widgets' ], 10, 1 );

			// Make sure that queries don't get an unbound `posts_per_page` value (-1), via filtering.
			// since the REST API core controllers (like WP_REST_Posts_Controller) don't support that.
			// @see https://core.trac.wordpress.org/ticket/43998
			add_action( 'rest_api_init', [ $this, 'prevent_unbound_rest_queries' ] );
		}

		/**
		 * Do anything needed at plugin activation.
		 */
		public static function activate() {
			flush_rewrite_rules();
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
		 * Add the `show_in_rest` CPT argument if it is not already present.
		 *
		 * @param $args
		 * @param $post_type
		 *
		 * @return mixed
		 */
		public function add_restapi_post_type_args( $args, $post_type ) {
			// We don't want to mess with these since they are system-related.
			$excluded_cpts = [
				'revision',
				'customize_changeset',
				'oembed_cache',
				'user_request',
				'scheduled-action',
				'schema',
				'shop_order',
				'shop_order_refund',
				'shop_coupon',
				'feedback',
				'jp_pay_order',
				'jp_pay_product',
				'nf_sub',
			];

			if ( ! in_array( $post_type, $excluded_cpts ) && is_array( $args ) && ! isset( $args['show_in_rest'] ) ) {
				$args['show_in_rest'] = true;
			}

			return $args;
		}

		/**
		 * Add the `show_in_rest` taxonomy argument if it is not already present.
		 *
		 * @param $args
		 *
		 * @return mixed
		 */
		public function add_restapi_taxonomy_args( $args ) {
			if ( is_array( $args ) && ! isset( $args['show_in_rest'] ) ) {
				$args['show_in_rest'] = true;
			}

			return $args;
		}

		/**
		 * For REST API requests, prevent unbound `posts_per_page` value (-1).
		 *
		 * Nova_Restaurant CPT is filtering the posts query and imposing a -1 value,
		 * thus making it impossible to fetch food menu items via REST API.
		 *
		 * The REST API core controllers (like WP_REST_Posts_Controller) don't support that.
		 * @see https://core.trac.wordpress.org/ticket/43998
		 *
		 * @return void
		 */
		public function prevent_unbound_rest_queries() {
			add_action( 'parse_query', function ( $query ) {
				if ( isset( $query->query_vars['posts_per_page'] ) && - 1 === $query->query_vars['posts_per_page'] ) {
					// 100 posts is the maximum allowed per page by the core REST controllers.
					$query->query_vars['posts_per_page'] = 100;
				}
			}, 9999 );
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

			// Get all the post types that are shown in the REST API.
			$post_types = get_post_types( [ 'show_in_rest' => true ], 'objects' );

			// Only add post type specific media items to post types that truly need it.
			$post_types_with_specific_media = [
				'product',
			];

			/**
			 * MUST-IMPORT GROUP SOCKETS.
			 */

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
				'label' => 'Content (posts & taxonomies)',
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
							'wp_template_part_area',
							'wp_theme',
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

				// Add post type specific media items.
				if ( in_array( $post_type, $post_types_with_specific_media ) ) {
					$config['sockets']['export_mi_post_types']['items'][ 'mi_post_type_' . $post_type . '_media_placeholders' ]   = [
						'type'        => 'gallery',
						'label'       => 'Placeholder Images',
						'description' => 'Pick a set of images which should <strong>replace post content images, for ' . $post_type . '.</strong> Assume the order in which these images will be chosen is <em>random.</em><br>Leave empty to use the general placeholder images, for this post type.',
					];
					$config['sockets']['export_mi_post_types']['items'][ 'mi_post_type_' . $post_type . '_media_ignored_images' ] = [
						'type'        => 'gallery',
						'label'       => 'Ignored Images',
						'description' => 'Pick a set of images to be <strong>ignored from replacement</strong> in the exported content, for ' . $post_type . '. They will be exported as they are.<br>Leave empty to use the general ignored images, for this post type.',
					];
				}

				$config['sockets']['export_mi_post_types']['items'][ 'mi_post_type_' . $post_type . '_end' ] = [
					'type' => 'divider',
					'html' => '',
				];
			}

			// Must-Import options.
			$config['sockets']['export_mi_options'] = [
				'label' => 'Site Options and Theme-Mods',
				'items' => [
					'mi_exported_pre_options'     => [
						'type'        => 'tags',
						'label'       => 'Pre Content-Import Site Options Keys',
						'description' => 'Select which site options keys should be imported before importing the must-import content.',
						'options'     => $this->get_options_select_list(),
					],
					'mi_exported_post_options'    => [
						'type'        => 'tags',
						'label'       => 'Post Content-Import Site Options Keys',
						'description' => 'Select which site options keys should be imported after the must-import content has been imported.',
						'options'     => $this->get_options_select_list(),
					],
					'mi_exported_pre_theme_mods'  => [
						'type'        => 'tags',
						'label'       => 'Pre Content-Import Theme-Mods Keys',
						'description' => 'Select which theme_mod keys should be imported before importing must-import content.',
						'options'     => $this->get_theme_mods_select_list(),
					],
					'mi_exported_post_theme_mods' => [
						'type'        => 'tags',
						'label'       => 'Post Content-Import Theme-Mods Keys',
						'description' => 'Select which theme_mod keys should be imported after importing must-import content.',
						'options'     => $this->get_theme_mods_select_list(),
					],
				],
			];

			/**
			 * OPTIONAL-IMPORT GROUP SOCKETS.
			 */

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
				'label' => 'Content (posts & taxonomies)',
				'items' => [],
			];

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

						if ( in_array( $tax, [
							'feedback',
							'jp_pay_order',
							'jp_pay_product',
							'post_format',
							'product_type',
							'product_visibility',
							'product_shipping_class',
							'wp_template_part_area',
							'wp_theme',
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

				// Add post type specific media items.
				if ( in_array( $post_type, $post_types_with_specific_media ) ) {
					$config['sockets']['export_post_types']['items'][ 'post_type_' . $post_type . '_media_placeholders' ]   = [
						'type'        => 'gallery',
						'label'       => 'Placeholder Images',
						'description' => 'Pick a set of images which should <strong>replace post content images, for ' . $post_type . '.</strong> Assume the order in which these images will be chosen is <em>random.</em><br>Leave empty to use the general placeholder images, for this post type.',
					];
					$config['sockets']['export_post_types']['items'][ 'post_type_' . $post_type . '_media_ignored_images' ] = [
						'type'        => 'gallery',
						'label'       => 'Ignored Images',
						'description' => 'Pick a set of images to be <strong>ignored from replacement</strong> in the exported content, for ' . $post_type . '. They will be exported as they are.<br>Leave empty to use the general ignored images, for this post type.',
					];
				}

				$config['sockets']['export_post_types']['items'][ 'post_type_' . $post_type . '_end' ] = [
					'type' => 'divider',
					'html' => '',
				];
			}

			$config['sockets']['export_options'] = [
				'label' => 'Site Options and Theme-Mods',
				'items' => [
					'exported_pre_options'    => [
						'type'        => 'tags',
						'label'       => 'Pre Content-Import Site Options Keys',
						'description' => 'Select which site options keys should be imported before importing the content. There is no need to include must-import options.',
						'options'     => $this->get_options_select_list(),
					],
					'exported_post_options'   => [
						'type'        => 'tags',
						'label'       => 'Post Content-Import Site Options Keys',
						'description' => 'Select which site options keys should be imported after the content has been imported. There is no need to include must-import options.',
						'options'     => $this->get_options_select_list(),
					],
					'exported_pre_theme_mods' => [
						'type'        => 'tags',
						'label'       => 'Pre Content-Import Theme-Mods Keys',
						'description' => 'Select which theme_mod keys should be imported before importing content. There is no need to include must-import theme_mods.',
						'options'     => $this->get_theme_mods_select_list(),
					],
					'ignored_post_theme_mods' => [
						'type'        => 'tags',
						'label'       => 'Ignored Theme-Mods Keys',
						'description' => '<strong>All remaining theme mods are imported after the content import,</strong> but you can chose to ignore certain keys.',
						'options'     => $this->get_theme_mods_select_list(),
					],
				],
			];

			return $config;
		}

		/**
		 * Get the list of site options to be available in selects.
		 *
		 * We will not include all site options, but only those that we believe are relevant for export.
		 *
		 * Others may be manually "selected" if the control allows it (like the `tags` control does).
		 *
		 * @return array An array with key => value pairs. The key is the select option value, while the value is the select option label.
		 */
		protected function get_options_select_list(): array {
			$select_options = [];

			// Do not include the current theme theme_mods entry, since we provide dedicated fields for theme_mods.

			$options = wp_load_alloptions();

			// If the theme's Style Manager option key is present, include it.
			// This key is used when Style Manager is instructed to save customization option in the site options instead of theme_mods.
			if ( function_exists( '\Pixelgrade\StyleManager\get_options_key' ) ) {
				$sm_options_key = \Pixelgrade\StyleManager\get_options_key();
				if ( isset( $options[ $sm_options_key ] ) ) {
					$select_options[ $sm_options_key ] = $sm_options_key;

					// Include all relevant sub-entries.
					$options[ $sm_options_key ] = maybe_unserialize( $options[ $sm_options_key ] );
					if ( ! empty( $options[ $sm_options_key ] ) && is_array( $options[ $sm_options_key ] ) ) {
						foreach ( $options[ $sm_options_key ] as $mod_name => $mod_value ) {
							$select_options[ $sm_options_key . '[' . $mod_name . ']' ] = $sm_options_key . '[' . $mod_name . ']';
						}
					}
				}
			}

			// Include all Style Manager options.
			foreach ( $options as $option_name => $option_value ) {
				if ( 0 === strpos( $option_name, 'sm_' ) ) {
					$select_options[ $option_name ] = $option_name;
				}
			}

			return $select_options;
		}

		/**
		 * Get the list of theme_mods options to be available in selects.
		 *
		 * We will not include all theme_mods options, but only those that we believe are relevant for export.
		 *
		 * Others may be manually "selected" if the control allows it (like the `tags` control does).
		 *
		 * @return array
		 */
		protected function get_theme_mods_select_list(): array {

			$select_options = [];

			$theme_mods = get_theme_mods();

			// Include all theme_mods options, except those that should be ignored.
			foreach ( $theme_mods as $mod_name => $mod_value ) {
				if ( ! in_array( $mod_name, $this->ignored_theme_mods ) ) {
					$select_options[ $mod_name ] = $mod_name;
				}
			}

			// If the theme's Style Manager option key is present, include it.
			// This key is used when Style Manager is instructed to save customization option in the site options instead of theme_mods.
			if ( function_exists( '\Pixelgrade\StyleManager\get_options_key' ) ) {
				$sm_options_key = \Pixelgrade\StyleManager\get_options_key();

				// Include all relevant sub-entries.
				if ( ! empty( $theme_mods[ $sm_options_key ] ) ) {
					foreach ( $theme_mods[ $sm_options_key ] as $mod_name => $mod_value ) {
						$select_options[ $sm_options_key . '[' . $mod_name . ']' ] = $sm_options_key . '[' . $mod_name . ']';
					}
				}
			}

			return $select_options;
		}

		/**
		 * REST API Endpoints that follow our common standard of response:
		 * - code
		 * - message
		 * - data
		 */
		public function add_rest_routes_api_v2() {
			/**
			 * Register endpoints for fetching the overall data.
			 *
			 * One for must-import data, and the another one for optional data.
			 */
			register_rest_route( 'sce/v2', '/mi-data', [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_export_mi_data_v2' ],
				'permission_callback' => '__return_true',
			] );
			register_rest_route( 'sce/v2', '/data', [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_export_data_v2' ],
				'permission_callback' => '__return_true',
			] );

			/**
			 * Register endpoints for fetching individual data details.
			 */
			register_rest_route( 'sce/v2', '/media', [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_export_media_v2' ],
				'permission_callback' => '__return_true',
			] );
			register_rest_route( 'sce/v2', '/posts', [
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rest_export_posts_v2' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'include' => [
						'required' => true,
					],
				],
			] );
			register_rest_route( 'sce/v2', '/terms', [
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rest_export_terms_v2' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'include' => [
						'required' => true,
					],
				],
			] );
			register_rest_route( 'sce/v2', '/widgets', [
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rest_export_widgets_v2' ],
				'permission_callback' => '__return_true',
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
			}

			if ( ! empty( $options ) ) {
				foreach ( $options as $key => $option ) {
					if ( strpos( $key, 'mi_post_type_' ) !== false ) {
						// We are dealing with a post type related item,
						// but we need to differentiate between posts ids items and media items.
						$post_type = str_replace( 'mi_post_type_', '', $key );
						if ( strpos( $post_type, '_media_placeholders' ) !== false ) {
							$post_type = str_replace( '_media_placeholders', '', $post_type );
						} else if ( strpos( $post_type, '_media_ignored_images' ) !== false ) {
							$post_type = str_replace( '_media_ignored_images', '', $post_type );
						}
						$priority = 10;

						/**
						 * We need to make sure that the navigation items are imported last.
						 * The metadata of a menu item can contain an object_id which should be mapped, but we can only map existing IDs.
						 */
						if ( 'nav_menu_item' === $post_type ) {
							$priority = 100;
						}

						if ( empty( $data['post_types'][ $post_type ] ) ) {
							$data['post_types'][ $post_type ] = [];
						}

						$data['post_types'][ $post_type ] = wp_parse_args( $data['post_types'][ $post_type ], [
							'name'     => $post_type,
							'ids'      => [],
							'media'    => [
								'placeholders' => [],
								'ignored'      => [],
							],
							'priority' => $priority, // for now all will have the same priority
						] );

						if ( strpos( $key, '_media_placeholders' ) !== false ) {
							$data['post_types'][ $post_type ]['media']['placeholders'] = $this->validate_attachment_ids( wp_parse_id_list( $option ) );
						} else if ( strpos( $key, '_media_ignored_images' ) !== false ) {
							$data['post_types'][ $post_type ]['media']['ignored'] = $this->validate_attachment_ids( wp_parse_id_list( $option ) );
						} else {
							$data['post_types'][ $post_type ]['ids'] = wp_parse_id_list( $option );
						}
					} elseif ( strpos( $key, 'mi_tax_' ) !== false ) {
						$taxonomy = str_replace( 'mi_tax_', '', $key );
						$data['taxonomies'][ $taxonomy ] = [
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
				'media'         => [
					'placeholders' => [],
					'ignored'      => [],
				],
				'post_types'    => [],
				'taxonomies'    => [],
				'widgets'       => $this->get_widgets(),
				'pre_settings'  => [],
				'post_settings' => [],
			];

			if ( ! empty( $options['placeholders'] ) ) {
				$data['media']['placeholders'] = $this->validate_attachment_ids( wp_parse_id_list( $options['placeholders'] ) );
			}

			if ( ! empty( $options['ignored_images'] ) ) {
				$data['media']['ignored'] = $this->validate_attachment_ids( wp_parse_id_list( $options['ignored_images'] ) );
			}

			if ( ! empty( $options ) ) {
				foreach ( $options as $key => $option ) {
					if ( strpos( $key, 'post_type_' ) !== false && strpos( $key, 'mi_post_type_' ) === false ) {
						// We are dealing with a post type related item,
						// but we need to differentiate between posts ids items and media items.
						$post_type = str_replace( 'post_type_', '', $key );
						if ( strpos( $post_type, '_media_placeholders' ) !== false ) {
							$post_type = str_replace( '_media_placeholders', '', $post_type );
						} else if ( strpos( $post_type, '_media_ignored_images' ) !== false ) {
							$post_type = str_replace( '_media_ignored_images', '', $post_type );
						}
						$priority = 10;

						/**
						 * We need to make sure that the navigation items are imported last.
						 * The metadata of a menu item can contain an object_id which should be mapped, but we can only map existing IDs.
						 */
						if ( 'nav_menu_item' === $post_type ) {
							$priority = 100;
						}

						if ( empty( $data['post_types'][ $post_type ] ) ) {
							$data['post_types'][ $post_type ] = [];
						}

						$data['post_types'][ $post_type ] = wp_parse_args( $data['post_types'][ $post_type ], [
							'name'     => $post_type,
							'ids'      => [],
							'media'    => [
								'placeholders' => [],
								'ignored'      => [],
							],
							'priority' => $priority, // for now all will have the same priority
						] );

						if ( strpos( $key, '_media_placeholders' ) !== false ) {
							$data['post_types'][ $post_type ]['media']['placeholders'] = $this->validate_attachment_ids( wp_parse_id_list( $option ) );
						} else if ( strpos( $key, '_media_ignored_images' ) !== false ) {
							$data['post_types'][ $post_type ]['media']['ignored'] = $this->validate_attachment_ids( wp_parse_id_list( $option ) );
						} else {
							$data['post_types'][ $post_type ]['ids'] = wp_parse_id_list( $option );
						}
					} elseif ( strpos( $key, 'tax_' ) !== false && strpos( $key, 'mi_tax_' ) === false ) {
						$taxonomy             = str_replace( 'tax_', '', $key );
						$data['taxonomies'][ $taxonomy ] = [
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

		protected function validate_attachment_ids( $attachment_ids ): array {
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
				'post__in'            => empty( $params['include'] ) ? [] : wp_parse_id_list( $params['include'] ),
				'posts_per_page'      => 100,
				'post_type'           => 'any',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			);

			if ( ! empty( $params['post_type'] ) ) {
				$query_args['post_type'] = sanitize_text_field( $params['post_type'] );
			}

			$get_posts = new WP_Query();
			$posts     = $get_posts->query( $query_args );
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

		public function parse_content_for_images( string $content ): string {
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

		public function replace_gallery_shortcodes_ids( string $content ): string {
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

		public function prepare_post_meta( array $metas ): array {

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
			$options = get_option( 'starter_content_exporter' );

			$settings = array(
				'options' => [],
				'mods'    => [],
			);

			// Make the selected options keys exportable.
			if ( ! empty( $options['mi_exported_pre_options'] ) ) {
				$mi_pre_options = $options['mi_exported_pre_options'];
			} else {
				$mi_pre_options = [];
			}
			if ( ! empty( $this->pre_settings['mi_options'] ) ) {
				// Merge with any default options.
				$mi_pre_options = array_merge( $this->pre_settings['mi_options'], $mi_pre_options );
			}

			foreach ( $mi_pre_options as $key ) {
				$key          = trim( $key );
				$option_value = get_option( $key, null );

				// We need to check if the option key really exists and ignore the nonexistent.
				if ( $option_value !== null ) {
					$settings['options'][ $key ] = $option_value;
				}
			}

			if ( ! empty( $options['mi_exported_pre_theme_mods'] ) ) {
				$mi_theme_mods = $options['mi_exported_pre_theme_mods'];
			} else {
				$mi_theme_mods = [];
			}
			if ( ! empty( $this->pre_settings['mi_mods'] ) ) {
				$mi_theme_mods = array_merge( $this->pre_settings['mi_mods'], $options['mi_exported_pre_theme_mods'] );
			}

			$current_theme_mods = get_theme_mods();
			foreach ( $mi_theme_mods as $key ) {
				$key = trim( $key );
				if ( isset( $current_theme_mods[ $key ] ) ) {
					$settings['mods'][ $key ] = $current_theme_mods[ $key ];
					continue;
				}

				// Check if the key refers to a sub-entry.
				if ( false !== strpos( $key, '[' ) ) {
					preg_match( '#(.+)\[(?:[\'\"]*)([^\'\"]+)(?:[\'\"]*)\]#', $key, $matches );

					if ( ! empty( $matches )
					     && ! empty( $matches[1] )
					     && ! empty( $matches[2] )
					     && isset( $current_theme_mods[ $matches[1] ] )
					     && isset( $current_theme_mods[ $matches[1] ][ $matches[2] ] )
					) {

						$settings['mods'][ $key ] = $current_theme_mods[ $matches[1] ][ $matches[2] ];
					}
				}
			}

			return $settings;
		}

		/**
		 * Get all the must-import options and theme mods which should be added after the import action
		 * @return array
		 */
		protected function get_mi_post_settings(): array {
			$options = get_option( 'starter_content_exporter' );

			$settings = [
				'options' => [],
				'mods'    => [],
			];

			// Make the selected options keys exportable.
			if ( ! empty( $options['mi_exported_post_options'] ) ) {
				foreach ( $options['mi_exported_post_options'] as $option ) {
					$option       = trim( $option );
					$option_value = get_option( $option, null );

					// We need to check if the option key really exists and ignore the nonexistent.
					if ( $option_value !== null ) {
						$settings['options'][ $option ] = $option_value;
					}
				}
			}

			$mi_post_theme_mods = [];
			if ( ! empty( $options['mi_exported_post_theme_mods'] ) ) {
				$mi_post_theme_mods = array_merge( $mi_post_theme_mods, $options['mi_exported_post_theme_mods'] );
			}
			$current_theme_mods = get_theme_mods();
			foreach ( $mi_post_theme_mods as $key ) {
				$key = trim( $key );
				if ( isset( $current_theme_mods[ $key ] ) ) {
					$settings['mods'][ $key ] = $current_theme_mods[ $key ];
					continue;
				}

				// Check if the key refers to a sub-entry.
				if ( false !== strpos( $key, '[' ) ) {
					preg_match( '#(.+)\[(?:[\'\"]*)([^\'\"]+)(?:[\'\"]*)\]#', $key, $matches );

					if ( ! empty( $matches )
					     && ! empty( $matches[1] )
					     && ! empty( $matches[2] )
					     && isset( $current_theme_mods[ $matches[1] ] )
					     && isset( $current_theme_mods[ $matches[1] ][ $matches[2] ] )
					) {
						$settings['mods'][ $key ] = $current_theme_mods[ $matches[1] ][ $matches[2] ];
					}
				}
			}

			return $settings;
		}

		/**
		 * Get all the options and theme mods which should be added before the import action
		 * @return array
		 */
		protected function get_pre_settings(): array {
			$options = get_option( 'starter_content_exporter' );

			$settings = array(
				'options' => [],
				'mods'    => [],
			);

			// Make the selected options keys exportable.
			if ( ! empty( $options['exported_pre_options'] ) ) {
				$pre_options = $options['exported_pre_options'];
			} else {
				$pre_options = [];
			}
			if ( ! empty( $this->pre_settings['options'] ) ) {
				// Merge with any default options.
				$pre_options = array_merge( $this->pre_settings['options'], $pre_options );
			}

			foreach ( $pre_options as $key ) {
				$key          = trim( $key );
				$option_value = get_option( $key, null );

				// We need to check if the option key really exists and ignore the nonexistent.
				if ( $option_value !== null ) {
					$settings['options'][ $key ] = $option_value;
				}
			}

			if ( ! empty( $options['exported_pre_theme_mods'] ) ) {
				$theme_mods_keys = $options['exported_pre_theme_mods'];
			} else {
				$theme_mods_keys = [];
			}
			if ( ! empty( $this->pre_settings['mods'] ) ) {
				$theme_mods_keys = array_merge( $this->pre_settings['mods'], $options['mi_exported_pre_theme_mods'] );
			}

			$current_theme_mods = get_theme_mods();
			foreach ( $theme_mods_keys as $key ) {
				$key = trim( $key );
				if ( isset( $current_theme_mods[ $key ] ) ) {
					$settings['mods'][ $key ] = $current_theme_mods[ $key ];
					continue;
				}

				// Check if the key refers to a sub-entry.
				if ( false !== strpos( $key, '[' ) ) {
					preg_match( '#(.+)\[(?:[\'\"]*)([^\'\"]+)(?:[\'\"]*)\]#', $key, $matches );

					if ( ! empty( $matches ) && ! empty( $matches[1] )
					     && ! empty( $matches[2] )
					     && isset( $current_theme_mods[ $matches[1] ] )
					     && isset( $current_theme_mods[ $matches[1] ][ $matches[2] ] )
					) {

						if ( ! isset( $settings['mods'][ $matches[1] ] ) ) {
							$settings['mods'][ $matches[1] ] = [];
						}
						if ( ! isset( $settings['mods'][ $matches[1] ][ $matches[2] ] ) ) {
							$settings['mods'][ $matches[1] ][ $matches[2] ] = $current_theme_mods[ $matches[1] ][ $matches[2] ];
						}
					}
				}
			}

			return $settings;
		}

		/**
		 * Get all the options and theme mods which should be added after the import action
		 * @return array
		 */
		protected function get_post_settings(): array {
			$options = get_option( 'starter_content_exporter' );

			$settings = [
				'options' => [
					'page_on_front'  => get_option( 'page_on_front' ),
					'page_for_posts' => get_option( 'page_for_posts' ),
				],
				'mods'    => [],
			];

			$ignored_theme_mods = $this->ignored_theme_mods;
			// First, add the manually ignored theme_mods.
			if ( ! empty( $options['ignored_post_theme_mods'] ) ) {
				$ignored_theme_mods = array_merge( $ignored_theme_mods, $options['ignored_post_theme_mods'] );
			}
			// Now, add the theme mods that should be imported before content-import.
			$pre_settings = $this->get_pre_settings();
			if ( ! empty( $pre_settings['mods'] ) ) {
				$ignored_theme_mods = array_merge( $ignored_theme_mods, array_keys( $pre_settings['mods'] ) );
			}
			$ignored_theme_mods = array_unique( $ignored_theme_mods );

			$current_theme_mods = get_theme_mods();
			if ( empty( $current_theme_mods ) ) {
				$current_theme_mods = [];
			}
			// Remove the ignored theme mods.
			$theme_mods_to_export = array_diff_key( $current_theme_mods, array_flip( $ignored_theme_mods ) );

			// Remove the ignored theme_mods sub-entries (like 'rosa_options[something]'), that haven't already been removed.
			foreach ( $ignored_theme_mods as $ignored_theme_mod ) {
				if ( false === strpos( $ignored_theme_mod, '[' ) ) {
					continue;
				}

				preg_match( '#(.+)\[(?:[\'\"]*)([^\'\"]+)(?:[\'\"]*)\]#', $ignored_theme_mod, $matches );

				if ( ! empty( $matches )
				     && ! empty( $matches[1] )
				     && ! empty( $matches[2] )
				     && isset( $theme_mods_to_export[ $matches[1] ] )
				     && isset( $theme_mods_to_export[ $matches[1] ][ $matches[2] ] )
				) {

					unset( $theme_mods_to_export[ $matches[1] ][ $matches[2] ] );
				}
			}

			if ( ! empty( $theme_mods_to_export ) ) {
				$settings['mods'] = $theme_mods_to_export;
			}

			// Make the selected options keys exportable.
			if ( ! empty( $options['exported_post_options'] ) ) {
				foreach ( $options['exported_post_options'] as $option ) {
					$option       = trim( $option );
					$option_value = get_option( $option, null );

					// we need to check if the option key really exists and ignore the nonexistent
					if ( $option_value !== null ) {
						$settings['options'][ $option ] = $option_value;
					}
				}
			}

			$featured_content = get_option( 'featured-content' );
			if ( ! empty( $featured_content ) ) {
				// @TODO maybe replace this with something imported
				unset( $featured_content['tag-id'] );
				$settings['options']['featured-content'] = $featured_content;
			}

			return $settings;
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
