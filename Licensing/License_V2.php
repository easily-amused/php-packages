<?php

namespace EA\Licensing;

use \stdClass as stdClass;

/**
 * EDD Software Licensing Class
 */
class License_V2 {

	private $product_id;
	private $user_license_key;
	private $show_in_ui;
	private $product_name = '';
	private $license_page = '';
	private $api_url              = '';
	private $api_data             = array();
	private $name                 = '';
	private $slug                 = '';
	private $product_slug         = '';
	private $version              = '';
	private $cache_key            = '';
	private $health_check_timeout = 5;
	private $licence_messages     = array();

	public function __construct( $name = '', $product_id = 0, $admin_slug = '', $plugin_file = '', $version = '', $show_in_ui = true, $init = true ) {
		$this->product_id   = $product_id;
		$this->product_name = $name;
		$this->license_page = $admin_slug . '-settings';
		// admin settings.
		$this->setting_license = 'honors_license';
		$this->show_in_ui      = $show_in_ui;

		$this->api_url      = trailingslashit( 'https://honorswp.com' );
		$this->name         = plugin_basename( $plugin_file );
		$this->slug         = basename( $plugin_file, '.php' );
		$this->product_slug = $admin_slug;
		$this->version      = $version;

		// Get license keys from DB.
		$current_license_keys = get_option( 'honors_license_key', true );
		$current_product      = array_filter( $current_license_keys, function( $products ) {
			return $products['product_id'] === $this->product_id;

		});

		$current_product_licence = array_shift($current_product);
		// $current_product_licence = $current_product_licence['licence_key'] ?? '';
		$this->user_license_key = $current_product_licence['licence_key'] ?? '';
		// $this->user_license_key = ! empty( $current_product_licence ) ? $current_product_licence : '';
		$this->cache_key        = 'edd_sl_' . md5( serialize( $this->slug . $this->user_license_key ) );

		$this->api_data = array(
			'version' => $this->version,
			'license' => $this->user_license_key,
			'item_id' => $product_id,
			'author'  => 'HonorsWP',
			'beta'    => false,
		);

		$edd_plugin_data[ $this->slug ] = $this->api_data;

		/**
		 * Fires after the $edd_plugin_data is setup.
		 *
		 * @since x.x.x
		 *
		 * @param array $edd_plugin_data Array of EDD SL plugin data.
		 */
		do_action( 'post_edd_sl_plugin_updater_setup', $edd_plugin_data );

		// Set up hooks.
		if ( $init ) {
			$this->init();
		}
	}

	/**
	 * Set up WordPress filters to hook into WP's update process.
	 *
	 * @uses add_filter()
	 *
	 * @return void
	 */
	public function init() {
		// Set All Access Pass as product.
		$ea_as_check_license_options = get_option( 'honors_license_key', array() );
		if ( empty( $ea_as_check_license_options ) || empty( $ea_as_check_license_options['all-access'] ) ) {
			$ea_as_check_license_options['all-access']['product_id'] = '4730';
			update_option( 'honors_license_key', $ea_as_check_license_options );
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugins_api_filter' ), 10, 3 );
		remove_action( 'after_plugin_row_' . $this->name, 'wp_plugin_update_row', 10 );
		add_action( 'after_plugin_row_' . $this->name, array( $this, 'show_update_notification' ), 10, 2 );
		// Admin Settings & Menu.
		add_action( 'admin_init', array( $this, 'admin_actions' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu_init' ) );
		add_filter( 'extra_plugin_headers', array( $this, 'extra_headers' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'setting_license_page_style' ) );

		add_filter( 'update_api_params', array( $this, 'update_api_params_based_on_key' ) );
		add_filter( 'update_license_host_url', array( $this, 'update_licensing_host_url' ), 10, 2 );
	}

	/**
	 * Function to update the license item_id.
	 *
	 * @param array $api_params This of api parameters.
	 *
	 * @return array $api_params
	 */
	public function update_api_params_based_on_key( $api_params ) {

		if ( empty( $api_params['license'] ) ) {
			return $api_params;
		}

		// License key format will be EABS_[product_id]_[license_key] and also support fallback EABS_[license_key]
		if ( preg_match( '/EABS_(.*?)_/', $api_params['license'], $match ) === 1 ) {
			$api_params['item_id'] = ! empty( $match[1] ) ? $match[1] : 377;
		} elseif ( strstr( $api_params['license'], '_', true ) === 'EABS' ) {
			$api_params['item_id'] = 377; // This is fallback product id for old blockstyle keys.
		}

		return $api_params;
	}

	/**
	 * Action to update the licensing host url.
	 *
	 * @param string $api_url     API host url.
	 * @param string $license_key License key.
	 *
	 * @return string $api_url
	 */
	public function update_licensing_host_url( $api_url, $license_key ) {

		if ( empty( $license_key ) ) {
			return $this->api_url;
		}

		switch ( strstr( $license_key, '_', true ) ) {
			case 'EABS':
				$this->api_url = trailingslashit( 'https://blockstyles.com' );
				break;
			default:
				$this->api_url = trailingslashit( 'https://honorswp.com' );
				break;
		}

		return $this->api_url;
	}

	/**
	 * Enqueue a script in the WordPress admin on edit.php.
	 *
	 * @param int $hook Hook suffix for the current admin page.
	 */
	public function setting_license_page_style( $hook ) {
		if ( 'settings_page_honorswp-settings' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'honors-license-style', plugin_dir_url( __FILE__ ) . 'assets/license.css', array(), '1.0' );
	}

	/**
	 * Check for Updates at the defined API endpoint and modify the update array.
	 *
	 * This function dives into the update API just when WordPress creates its update array,
	 * then adds a custom API call and injects the custom plugin data retrieved from the API.
	 * It is reassembled from parts of the native WordPress plugin update code.
	 * See wp-includes/update.php line 121 for the original wp_update_plugins() function.
	 *
	 * @uses api_request()
	 *
	 * @param array $_transient_data Update array build by WordPress.
	 * @return array Modified update array with custom plugin data.
	 */
	public function check_update( $_transient_data ) {

		global $pagenow;

		if ( ! is_object( $_transient_data ) ) {
			$_transient_data = new stdClass();
		}

		if ( 'options-general.php' == $pagenow && is_multisite() ) {
			return $_transient_data;
		}

		if ( ! empty( $_transient_data->response ) && ! empty( $_transient_data->response[ $this->name ] ) ) {
			return $_transient_data;
		}

		$current = $this->get_repo_api_data();
		if ( false !== $current && is_object( $current ) && isset( $current->new_version ) ) {
			if ( version_compare( $this->version, $current->new_version, '<' ) ) {
				$_transient_data->response[ $this->name ] = $current;
			} else {
				// Populating the no_update information is required to support auto-updates in WordPress 5.5.
				$_transient_data->no_update[ $this->name ] = $current;
			}
		}
		$_transient_data->last_checked           = time();
		$_transient_data->checked[ $this->name ] = $this->version;

		return $_transient_data;
	}

	/**
	 * Get repo API data from store.
	 * Save to cache.
	 *
	 * @return \stdClass
	 */
	public function get_repo_api_data() {
		$version_info = $this->get_cached_version_info();
		if ( false === $version_info ) {
			$version_info = $this->api_request(
				'plugin_latest_version',
				array(
					'slug' => $this->slug,
					'beta' => false,
				)
			);
			// error_log(print_r(['version_infor', $version_info], true));
			if ( ! $version_info ) {
				return false;
			}

			// This is required for your plugin to support auto-updates in WordPress 5.5.
			$version_info->plugin = $this->name;
			$version_info->id     = $this->name;

			$this->set_version_info_cache( $version_info );
		}
		return $version_info;
	}

	/**
	 * show update nofication row -- needed for multisite subsites, because WP won't tell you otherwise!
	 *
	 * @param string $file
	 * @param array  $plugin
	 */
	public function show_update_notification( $file, $plugin ) {

		if ( is_network_admin() ) {
			return;
		}

		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		if ( ! is_multisite() ) {
			return;
		}

		if ( $this->name != $file ) {
			return;
		}

		// Remove our filter on the site transient
		remove_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ), 10 );

		$update_cache = get_site_transient( 'update_plugins' );

		$update_cache = is_object( $update_cache ) ? $update_cache : new stdClass();

		if ( empty( $update_cache->response ) || empty( $update_cache->response[ $this->name ] ) ) {

			$version_info = $this->get_repo_api_data();

			if ( false === $version_info ) {
				$version_info = $this->api_request(
					'plugin_latest_version',
					array(
						'slug' => $this->slug,
						'beta' => false,
					)
				);

				// Since we disabled our filter for the transient, we aren't running our object conversion on banners, sections, or icons. Do this now:
				if ( isset( $version_info->banners ) && ! is_array( $version_info->banners ) ) {
					$version_info->banners = $this->convert_object_to_array( $version_info->banners );
				}

				if ( isset( $version_info->sections ) && ! is_array( $version_info->sections ) ) {
					$version_info->sections = $this->convert_object_to_array( $version_info->sections );
				}

				if ( isset( $version_info->icons ) && ! is_array( $version_info->icons ) ) {
					$version_info->icons = $this->convert_object_to_array( $version_info->icons );
				}

				if ( isset( $version_info->contributors ) && ! is_array( $version_info->contributors ) ) {
					$version_info->contributors = $this->convert_object_to_array( $version_info->contributors );
				}

				$this->set_version_info_cache( $version_info );
			}

			if ( ! is_object( $version_info ) ) {
				return;
			}

			if ( version_compare( $this->version, $version_info->new_version, '<' ) ) {
				$update_cache->response[ $this->name ] = $version_info;
			} else {
				$update_cache->no_update[ $this->name ] = $version_info;
			}

			$update_cache->last_checked           = time();
			$update_cache->checked[ $this->name ] = $this->version;

			set_site_transient( 'update_plugins', $update_cache );

		} else {

			$version_info = $update_cache->response[ $this->name ];

		}

		// Restore our filter
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );

		if ( ! empty( $update_cache->response[ $this->name ] ) && version_compare( $this->version, $version_info->new_version, '<' ) ) {

			// build a plugin list row, with update notification
			$wp_list_table = _get_list_table( 'WP_Plugins_List_Table' );
			// <tr class="plugin-update-tr"><td colspan="' . $wp_list_table->get_column_count() . '" class="plugin-update colspanchange">
			echo '<tr class="plugin-update-tr" id="' . $this->slug . '-update" data-slug="' . $this->slug . '" data-plugin="' . $this->slug . '/' . $file . '">';
			echo '<td colspan="3" class="plugin-update colspanchange">';
			echo '<div class="update-message notice inline notice-warning notice-alt">';

			$changelog_link = self_admin_url( 'index.php?edd_sl_action=view_plugin_changelog&plugin=' . $this->name . '&slug=' . $this->slug . '&TB_iframe=true&width=772&height=911' );

			if ( empty( $version_info->download_link ) ) {
				printf(
					__( 'There is a new version of %1$s available. %2$sView version %3$s details%4$s.', 'easy-digital-downloads' ),
					esc_html( $version_info->name ),
					'<a target="_blank" class="thickbox" href="' . esc_url( $changelog_link ) . '">',
					esc_html( $version_info->new_version ),
					'</a>'
				);
			} else {
				printf(
					__( 'There is a new version of %1$s available. %2$sView version %3$s details%4$s or %5$supdate now%6$s.', 'easy-digital-downloads' ),
					esc_html( $version_info->name ),
					'<a target="_blank" class="thickbox" href="' . esc_url( $changelog_link ) . '">',
					esc_html( $version_info->new_version ),
					'</a>',
					'<a href="' . esc_url( wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' ) . $this->name, 'upgrade-plugin_' . $this->name ) ) . '">',
					'</a>'
				);
			}

			do_action( "in_plugin_update_message-{$file}", $plugin, $version_info );

			echo '</div></td></tr>';
		}
	}

	/**
	 * Updates information on the "View version x.x details" page with custom data.
	 *
	 * @uses api_request()
	 *
	 * @param mixed  $_data
	 * @param string $_action
	 * @param object $_args
	 * @return object $_data
	 */
	public function plugins_api_filter( $_data, $_action = '', $_args = null ) {

		if ( $_action != 'plugin_information' ) {

			return $_data;

		}

		if ( ! isset( $_args->slug ) || ( $_args->slug != $this->slug ) ) {

			return $_data;

		}

		$to_send = array(
			'slug'   => $this->slug,
			'is_ssl' => is_ssl(),
			'fields' => array(
				'banners' => array(),
				'reviews' => false,
				'icons'   => array(),
			),
		);

		// Get the transient where we store the api request for this plugin for 24 hours
		$edd_api_request_transient = $this->get_cached_version_info();

		// If we have no transient-saved value, run the API, set a fresh transient with the API value, and return that value too right now.
		if ( empty( $edd_api_request_transient ) ) {

			$api_response = $this->api_request( 'plugin_information', $to_send );

			// Expires in 3 hours
			$this->set_version_info_cache( $api_response );

			if ( false !== $api_response ) {
				$_data = $api_response;
			}
		} else {
			$_data = $edd_api_request_transient;
		}

		// Convert sections into an associative array, since we're getting an object, but Core expects an array.
		if ( isset( $_data->sections ) && ! is_array( $_data->sections ) ) {
			$_data->sections = $this->convert_object_to_array( $_data->sections );
		}

		// Convert banners into an associative array, since we're getting an object, but Core expects an array.
		if ( isset( $_data->banners ) && ! is_array( $_data->banners ) ) {
			$_data->banners = $this->convert_object_to_array( $_data->banners );
		}

		// Convert icons into an associative array, since we're getting an object, but Core expects an array.
		if ( isset( $_data->icons ) && ! is_array( $_data->icons ) ) {
			$_data->icons = $this->convert_object_to_array( $_data->icons );
		}

		// Convert contributors into an associative array, since we're getting an object, but Core expects an array.
		if ( isset( $_data->contributors ) && ! is_array( $_data->contributors ) ) {
			$_data->contributors = $this->convert_object_to_array( $_data->contributors );
		}

		if ( ! isset( $_data->plugin ) ) {
			$_data->plugin = $this->name;
		}

		return $_data;
	}

	/**
	 * Convert some objects to arrays when injecting data into the update API
	 *
	 * Some data like sections, banners, and icons are expected to be an associative array, however due to the JSON
	 * decoding, they are objects. This method allows us to pass in the object and return an associative array.
	 *
	 * @since 3.6.5
	 *
	 * @param stdClass $data
	 *
	 * @return array
	 */
	private function convert_object_to_array( $data ) {
		if ( ! is_array( $data ) && ! is_object( $data ) ) {
			return array();
		}
		$new_data = array();
		foreach ( $data as $key => $value ) {
			$new_data[ $key ] = is_object( $value ) ? $this->convert_object_to_array( $value ) : $value;
		}

		return $new_data;
	}

	/**
	 * Disable SSL verification in order to prevent download update failures
	 *
	 * @param array  $args
	 * @param string $url
	 * @return object $array
	 */
	public function http_request_args( $args, $url ) {

		$verify_ssl = $this->verify_ssl();
		if ( strpos( $url, 'https://' ) !== false && strpos( $url, 'edd_action=package_download' ) ) {
			$args['sslverify'] = $verify_ssl;
		}
		return $args;

	}

	/**
	 * Calls the API and, if successfull, returns the object delivered by the API.
	 *
	 * @uses get_bloginfo()
	 * @uses wp_remote_post()
	 * @uses is_wp_error()
	 *
	 * @param string $_action The requested action.
	 * @param array  $_data   Parameters for the API action.
	 * @return false|object
	 */
	private function api_request( $_action, $_data ) {

		global $wp_version, $edd_plugin_url_available;

		$verify_ssl = $this->verify_ssl();

		// Do a quick status check on this domain if we haven't already checked it.
		$store_hash = md5( $this->api_url );
		if ( ! is_array( $edd_plugin_url_available ) || ! isset( $edd_plugin_url_available[ $store_hash ] ) ) {
			$test_url_parts = parse_url( $this->api_url );

			$scheme = ! empty( $test_url_parts['scheme'] ) ? $test_url_parts['scheme'] : 'http';
			$host   = ! empty( $test_url_parts['host'] ) ? $test_url_parts['host'] : '';
			$port   = ! empty( $test_url_parts['port'] ) ? ':' . $test_url_parts['port'] : '';

			if ( empty( $host ) ) {
				$edd_plugin_url_available[ $store_hash ] = false;
			} else {
				$test_url                                = $scheme . '://' . $host . $port;
				$response                                = wp_remote_get(
					$test_url,
					array(
						'timeout'   => $this->health_check_timeout,
						'sslverify' => $verify_ssl,
					)
				);
				$edd_plugin_url_available[ $store_hash ] = is_wp_error( $response ) ? false : true;
			}
		}

		if ( false === $edd_plugin_url_available[ $store_hash ] ) {
			return false;
		}

		$data = array_merge( $this->api_data, $_data );
// error_log(print_r(['api data', $data], true));
		if ( $data['slug'] != $this->slug ) {
			return false;
		}

		if ( $this->api_url == trailingslashit( home_url() ) ) {
			return false; // Don't allow a plugin to ping itself
		}

		$api_params = array(
			'edd_action' => 'get_version',
			'license'    => ! empty( $data['license'] ) ? $data['license'] : '',
			'item_name'  => isset( $data['item_name'] ) ? $data['item_name'] : false,
			'item_id'    => isset( $data['item_id'] ) ? $data['item_id'] : false,
			'version'    => isset( $data['version'] ) ? $data['version'] : false,
			'slug'       => $data['slug'],
			'author'     => $data['author'],
			'url'        => home_url(),
			'beta'       => ! empty( $data['beta'] ),
		);

		$api_params = apply_filters( 'update_api_params', $api_params );
		// error_log(print_r(['api params', $api_params], true));

		$request = wp_remote_post(
			apply_filters( 'update_license_host_url', $this->api_url, $api_params['license'] ),
			array(
				'timeout'   => 15,
				'sslverify' => $verify_ssl,
				'body'      => $api_params,
			)
		);
		// error_log(print_r(['api request', $request], true));
		if ( ! is_wp_error( $request ) ) {
			$request = json_decode( wp_remote_retrieve_body( $request ) );
		}

		if ( $request && isset( $request->sections ) ) {
			$request->sections = maybe_unserialize( $request->sections );
		} else {
			$request = false;
		}

		if ( $request && isset( $request->banners ) ) {
			$request->banners = maybe_unserialize( $request->banners );
		}

		if ( $request && isset( $request->icons ) ) {
			$request->icons = maybe_unserialize( $request->icons );
		}

		if ( ! empty( $request->sections ) ) {
			foreach ( $request->sections as $key => $section ) {
				$request->$key = (array) $section;
			}
		}
		return $request;
	}

	/**
	 * Get a plugin's licence messages.
	 *
	 * @param string $product_slug The plugin slug.
	 * @return array
	 */
	public function get_messages( $product_slug ) {
		if ( ! isset( $this->licence_messages[ $product_slug ] ) ) {
			$this->licence_messages[ $product_slug ] = array();
		}

		return $this->licence_messages[ $product_slug ];
	}

	/**
	 * Get a plugin's licence messages.
	 *
	 * @param string $product_slug The plugin slug.
	 * @return array
	 */
	public static function get_messages_static( $product_slug ) {
		$class = (new self);
		if ( ! isset( $class->licence_messages[ $product_slug ] ) ) {
			$class->licence_messages[ $product_slug ] = array();
		}

		return $class->licence_messages[ $product_slug ];
	}

	/**
	 * Set a plugin's licence messages.
	 *
	 * @param string $product_slug The plugin slug.
	 * @return array
	 */
	public function set_messages( $product_slug, $status ) {
		switch ( $status ) {

			case 'expired':
				$this->licence_messages[ $product_slug ][] = sprintf(
					__( 'Your license key expired on %s.' ),
					date_i18n( get_option( 'date_format' ), strtotime( $license_data->expires, current_time( 'timestamp' ) ) )
				);
				break;

			case 'disabled':
			case 'revoked':
				$this->licence_messages[ $product_slug ][] = __( 'Your license key has been disabled.' );
				break;

			case 'missing':
				$this->licence_messages[ $product_slug ][] = __( 'Invalid license.' );
				break;

			case 'invalid':
			case 'site_inactive':
				$this->licence_messages[ $product_slug ][] = __( 'Your license is not active for this URL.' );
				break;

			case 'item_name_mismatch':
				$this->licence_messages[ $product_slug ][] = sprintf( __( 'This appears to be an invalid license key for %s.' ), $this->name );
				break;

			case 'no_activations_left':
				$this->licence_messages[ $product_slug ][] = __( 'Your license key has reached its activation limit.' );
				break;
			case 'invalid_item_id':
				$this->licence_messages[ $product_slug ][] = __( 'The product ID is incorrect.' );
				break;
			default:
				$this->licence_messages[ $product_slug ][] = __( 'An error occurred, please try again.' );
				break;
		}

		return $this->licence_messages[ $product_slug ];
	}

	/**
	 * Admin Actions - fires on admin init
	 *
	 * @return void
	 */
	public function admin_actions() {
		// Register settings.
		// register_setting( $this->setting_license, 'honors_license_key' );
		// register_setting( $this->setting_license, 'honors_license_status' );

		if (
			empty( $_POST )
			|| empty( $_POST['_wpnonce'] )
			|| empty( $_POST['action'] )
			|| empty( $_POST['product_slug'] )
			|| ! wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), 'honorswp-manage-licence' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce should not be modified.
		) {
			return false;
		}
		$product_slug = sanitize_text_field( wp_unslash( $_POST['product_slug'] ) );
		// error_log(print_r(['post', $_POST], true));
		switch ( $_POST['action'] ) {
			case 'activate':
				if ( empty( $_POST['licence_key'] ) ) {
					$this->licence_messages[ $product_slug ] = esc_html( 'Please enter a valid license key in order to activate this plugin\'s license.' );
					// $this->add_error( $product_slug, __( 'Please enter a valid license key in order to activate this plugin\'s license.' ) );
					break;
				}

				$licence_key = sanitize_text_field( wp_unslash( $_POST['licence_key'] ) );
				$this->activate_license( $product_slug, $licence_key );
				break;
			case 'deactivate':
				if ( 'Check License' === $_POST['submit'] ) {
					$this->check_license( $product_slug );
				} else {
					$this->deactivate_license( $product_slug );
				}
				break;
		}
	}

	/**
	 * Admin Settings Menu (Under Settings)
	 *
	 * @return void
	 */
	public function admin_menu_init() {
		global $submenu;

		// changelog
		$this->init_changelog();

		if ( $this->show_in_ui ) {

			$main_menu = 'options-general.php';

			if (
				isset( $submenu[ $main_menu ] ) &&
				! in_array( 'honorswp-settings', wp_list_pluck( $submenu[ $main_menu ], 2 ) )
			) {
				add_options_page(
					'HonorsWP Settings',
					'HonorsWP Settings',
					'manage_options',
					'honorswp-settings',
					array( $this, 'admin_settings_page_new' ) );
			}
		} else {
			add_plugins_page(
				$this->product_name . ' Settings',
				$this->product_name,
				'manage_options',
				$this->license_page,
				array( $this, 'admin_settings_page' ) );
		}
	}

	/**
	 * Activate Licese
	 *
	 * @return void
	 */
	private function activate_license( $product_slug, $licence_key ) {
		// static $run = 0;
		if ( $run ) {
			return;
		}

		$product_id = $this->get( $product_slug, 'product_id' );

		if ( empty( $product_id ) ) {
			$this->licence_messages[ $product_slug ] = __( 'An error occurred. Please install the latest version of the plugin from HonorsWP and try again.' );
			return;
		}

		// data to send in our API request
		$api_params = array(
			'edd_action'  => 'activate_license',
			'license'     => $licence_key,
			'item_id'     => trim( $product_id ), // The ID of our product in EDD.
			'url'         => home_url(),
			'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
		);

		$api_params = apply_filters( 'update_api_params', $api_params );
		// error_log(print_r(['activate license api params', $api_params], true));
		// Call the custom API.
		$response = wp_remote_post(
			apply_filters( 'update_license_host_url', $this->api_url, $api_params['license'] ),
			array(
				'timeout'   => 15,
				'sslverify' => false,
				'body'      => $api_params,
			)
		);

		// make sure the response came back okay
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {

			if ( is_wp_error( $response ) ) {
				$this->licence_messages[ $product_slug ] = $response->get_error_message();
			} else {
				$this->licence_messages[ $product_slug ] = __( 'An error occurred, please try again.' );
			}
		} else {

			$license_data = json_decode( wp_remote_retrieve_body( $response ) );
			// error_log(print_r(['edd response', $license_data], true));
			if ( false === $license_data->success ) {
				$this->licence_messages[ $product_slug ] = $this->set_messages( $product_slug, $license_data->error );
				return;
			}
		}

		// $license_data->license will be either "valid" or "invalid"
		if ( empty( $this->licence_messages[ $product_slug ] ) ) {
			$this->update( $product_slug, 'licence_key', $licence_key );
			$this->update( $product_slug, 'status', $license_data->license );

			if ( 'all-access' === $product_slug ) {
				$config = include_once  dirname( __FILE__ ). '/config.php';
				foreach ( $config as $product_slug => $value ) {
					// error_log(print_r(['value', $value['product_id']], true));
					$this->update( $product_slug, 'product_id', $value['product_id'] );
					$this->update( $product_slug, 'licence_key', $licence_key );
					$this->update( $product_slug, 'status', $license_data->license );
				}
			}
		}
		$run++;
		// error_log(print_r(['runs???', $run], true));
	}

	private function check_license( $product_slug ) {
		static $run = 0;
		if ( $run ) {
			return;
		}
		// Retrieve the license from the database.
		$license = get_option( 'honors_license_key' );

		if ( empty( $license[ $product_slug ]['licence_key'] ) ) {
			return;
		}

		// Data to send in our API request.
		$api_params = array(
			'edd_action'  => 'check_license',
			'license'     => $license[ $product_slug ]['licence_key'],
			'item_id'     => $license[ $product_slug ]['product_id'], // The ID of our product in EDD.
			'url'         => home_url(),
			'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
		);

		$api_params = apply_filters( 'update_api_params', $api_params );

		// Call the custom API.
		$response = wp_remote_post(
			apply_filters( 'update_license_host_url', $this->api_url, $api_params['license'] ),
			array(
				'timeout'   => 15,
				'sslverify' => false,
				'body'      => $api_params,
			)
		);
		// error_log(print_r(['check edd response', $response], true));
		// make sure the response came back okay
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {

			if ( is_wp_error( $response ) ) {
				$this->licence_messages[ $product_slug ] = $response->get_error_message();
			} else {
				$this->licence_messages[ $product_slug ] = __( 'An error occurred, please try again.' );
			}
		}

		// decode the license data.
		$license_data = json_decode( wp_remote_retrieve_body( $response ) );
		// error_log(print_r(['check edd response', $license_data], true));
		$this->licence_messages[ $product_slug ] = 'The license is '. $license_data->license;
	}

	private function deactivate_license( $product_slug ) {
		static $run = 0;
		if ( $run ) {
			return;
		}
		// Retrieve the license from the database.
		$license = get_option( 'honors_license_key' );

		if ( empty( $license[ $product_slug ]['licence_key'] ) ) {
			return;
		}
		// error_log(print_r(['license info', $license[ $product_slug ]], true));
		// Data to send in our API request.
		$api_params = array(
			'edd_action'  => 'deactivate_license',
			'license'     => $license[ $product_slug ]['licence_key'],
			'item_id'     => $license[ $product_slug ]['product_id'], // The ID of our product in EDD.
			'url'         => home_url(),
			'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
		);

		$api_params = apply_filters( 'update_api_params', $api_params );

		// Call the custom API.
		$response = wp_remote_post(
			apply_filters( 'update_license_host_url', $this->api_url, $api_params['license'] ),
			array(
				'timeout'   => 15,
				'sslverify' => false,
				'body'      => $api_params,
			)
		);
		// error_log(print_r(['deactivate edd response', $response], true));
		// make sure the response came back okay
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {

			if ( is_wp_error( $response ) ) {
				$this->licence_messages[ $product_slug ] = $response->get_error_message();
			} else {
				$this->licence_messages[ $product_slug ] = __( 'An error occurred, please try again.' );
			}
		}

		// decode the license data.
		$license_data = json_decode( wp_remote_retrieve_body( $response ) );
		// error_log(print_r(['deactivate edd response', $license_data], true));
		// $license_data->license will be either "deactivated" or "failed"
		if ( 'deactivated' === $license_data->license || 'failed' === $license_data->license ) {
			// error_log(print_r(['deactivated response', $license_data->license], true));
			// $this->delete( $product_slug );
			$this->delete( $product_slug, 'licence_key' );
			$this->delete( $product_slug, 'status' );
			if ( 'all-access' === $product_slug ) {
				$config = include_once  dirname( __FILE__ ). '/config.php';
				foreach ( $config as $product_slug => $value ) {
					$this->delete( $product_slug, 'licence_key' );
					$this->delete( $product_slug, 'status' );
				}
			}
		}
		$run++;
	}

	/**
	 * Returns list of installed HonorsWP plugins with managed licenses indexed by product ID.
	 *
	 * @param bool $active_only Only return active plugins.
	 * @return array
	 */
	public function get_installed_plugins( $active_only = true ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$honors_plugins = array();
		$plugins        = get_plugins();

		foreach ( $plugins as $filename => $data ) {

			if ( true === $active_only && ! is_plugin_active( $filename ) ) {
				continue;
			}

			if ( empty( $data['Author'] ) ) {
				continue;
			}

			if ( in_array( $data['TextDomain'], [ 'learndash-coupons', 'learndash-powerpack', 'sales-tax-for-learndash' ], true ) ) {
				continue;
			}

			if ( ! in_array( $data['Author'], ['Easily Amused, Inc.', 'Easily Amused', 'HonorsWP', 'Honors WP'] , true ) ) {
				continue;
			}

			$data['_filename']                           = $filename;
			$data['_product_slug']                       = strtolower(str_replace(' ', '-', $data['Name']) );
			$data['_product_name']                       = $data['Name'];
			$data['_type']                               = 'plugin';

			$honors_plugins[ $data['_product_slug'] ] = $data;
		}
		// error_log(print_r(['honors plugins', $honors_plugins], true));
		return $honors_plugins;
	}

	/**
	 * Admin Settings Page
	 *
	 * @return void
	 */
	public function admin_settings_page_new() {
		$licenced_plugins = $this->get_installed_plugins();
		include_once dirname( __FILE__ ) . '/html-licences.php';
	}

	/**
	 * Retrieve a HonorsWP plugin's licence data.
	 *
	 * @param string $product_slug
	 * @param string $key
	 * @param mixed  $default
	 *
	 * @return mixed
	 */
	public function get( $product_slug, $key, $default = false ) {
		$this->maybe_convert_options( $product_slug, $key );

		$options = get_option( 'honors_license_key', array() );

		if ( isset( $options[ $product_slug ][ $key ] ) ) {
			return $options[ $product_slug ][ $key ];
		}
		return $default;
	}

	/**
	 * Update a HonorsWP plugin's licence data.
	 *
	 * @param string $product_slug
	 * @param string $key
	 * @param mixed  $value
	 *
	 * @return bool
	 */
	public function update( $product_slug, $key, $value ) {

		$options = get_option( 'honors_license_key', array() );

		if ( ! isset( $options[ $product_slug ] ) ) {
			$options[ $product_slug ] = array();
		}

		$options[ $product_slug ][ $key ] = $value;
		// error_log(print_r(['options to update', $options], true));
		update_option( 'honors_license_key', $options );
	}

	/**
	 * Delete a HonorWP plugin's licence data.
	 *
	 * @param string $product_slug
	 * @param string $key
	 *
	 * @return bool
	 */
	public function delete( $product_slug, $key ) {
		$options = get_option( 'honors_license_key', array() );

		if ( ! isset( $options[ $product_slug ] ) ) {
			$options[ $product_slug ] = array();
		}

		unset( $options[ $product_slug ][ $key ] );

		// error_log(print_r(['unset option?', $options], true));

		return update_option( 'honors_license_key', $options );
	}



	/**
	 * Maybe convert old options
	 *
	 * @param string $product_slug Product slug.
	 *
	 * @return void
	 */
	private function maybe_convert_options( $product_slug ) {
		$old_license_key_key    = 'honors_' . $product_slug . '_license_key';
		$old_license_status_key = 'honors_' . $product_slug . '_license_status';
		// Retrieve the license from the database.
		$existing_license = trim( get_option( $old_license_key_key ) );
		$existing_status  = get_option( $old_license_status_key, false );
		if ( ! empty( $license ) ) {
			$this->update( $product_slug, 'licence_key', $existing_license );
			$this->update( $product_slug, 'status', $existing_status );
			delete_option( $old_license_key_key );
			delete_option( $old_license_status_key );
		}
	}

	/**
	 * Gets the license key and status for a HonorsWP managed plugin.
	 *
	 * @param string $product_slug
	 * @return array|bool
	 */
	public function get_plugin_licence( $product_slug ) {
		$licence_key = $this->get( $product_slug, 'licence_key' );
		$status      = $this->get( $product_slug, 'status' );

		return array(
			'licence_key' => $licence_key,
			'status'      => $status,
		);
	}

	/**
	 * Gets the license key and status for a HonorsWP managed plugin.
	 *
	 * @param string $product_slug
	 * @return array|bool
	 */
	public static function get_plugin_licence_static( $product_slug ) {
		$licence_key = ( new self() )->get( $product_slug, 'licence_key' );
		$status      = ( new self() )->get( $product_slug, 'status' );

		return array(
			'licence_key' => $licence_key,
			'status'      => $status,
		);
	}

	/**
	 * If available, show the changelog for sites in a multisite install.
	 */
	public function init_changelog() {

		global $edd_plugin_data;

		if ( empty( $_REQUEST['edd_sl_action'] ) || 'view_plugin_changelog' != $_REQUEST['edd_sl_action'] ) {
			return;
		}

		if ( empty( $_REQUEST['plugin'] ) ) {
			return;
		}

		if ( empty( $_REQUEST['slug'] ) ) {
			return;
		}

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( __( 'You do not have permission to install plugin updates', 'easy-digital-downloads' ), __( 'Error', 'easy-digital-downloads' ), array( 'response' => 403 ) );
		}

		$data         = $edd_plugin_data[ $_REQUEST['slug'] ];
		$version_info = $this->get_cached_version_info();

		if ( false === $version_info ) {

			$api_params = array(
				'edd_action' => 'get_version',
				'item_name'  => isset( $data['item_name'] ) ? $data['item_name'] : false,
				'item_id'    => isset( $data['item_id'] ) ? $data['item_id'] : false,
				'slug'       => $_REQUEST['slug'],
				'author'     => $data['author'],
				'url'        => home_url(),
				'beta'       => ! empty( $data['beta'] ),
			);

			$verify_ssl = $this->verify_ssl();
			$request    = wp_remote_post(
				$this->api_url,
				array(
					'timeout'   => 15,
					'sslverify' => $verify_ssl,
					'body'      => $api_params,
				)
			);

			if ( ! is_wp_error( $request ) ) {
				$version_info = json_decode( wp_remote_retrieve_body( $request ) );
			}

			if ( ! empty( $version_info ) && isset( $version_info->sections ) ) {
				$version_info->sections = maybe_unserialize( $version_info->sections );
			} else {
				$version_info = false;
			}

			if ( ! empty( $version_info ) ) {
				foreach ( $version_info->sections as $key => $section ) {
					$version_info->$key = (array) $section;
				}
			}

			$this->set_version_info_cache( $version_info );

			// Delete the unneeded option
			delete_option( md5( 'edd_plugin_' . sanitize_key( $_REQUEST['plugin'] ) . '_' . '_version_info' ) );
		}

		if ( isset( $version_info->sections ) ) {
			$sections = $this->convert_object_to_array( $version_info->sections );
			if ( ! empty( $sections['changelog'] ) ) {
				echo '<div style="background:#fff;padding:10px;">' . wp_kses_post( $sections['changelog'] ) . '</div>';
			}
		}

		exit;
	}

	/**
	 * Gets the plugin's cached version information from the database.
	 *
	 * @param string $cache_key
	 * @return boolean|string
	 */
	public function get_cached_version_info( $cache_key = '' ) {

		if ( empty( $cache_key ) ) {
			$cache_key = $this->cache_key;
		}

		$cache = get_option( $cache_key );

		if ( empty( $cache['timeout'] ) || time() > $cache['timeout'] ) {
			return false; // Cache is expired
		}

		// We need to turn the icons into an array, thanks to WP Core forcing these into an object at some point.
		$cache['value'] = json_decode( $cache['value'] );
		if ( ! empty( $cache['value']->icons ) ) {
			$cache['value']->icons = (array) $cache['value']->icons;
		}

		return $cache['value'];

	}

	/**
	 * Adds the plugin version information to the database.
	 *
	 * @param string $value
	 * @param string $cache_key
	 */
	public function set_version_info_cache( $value = '', $cache_key = '' ) {

		if ( empty( $cache_key ) ) {
			$cache_key = $this->cache_key;
		}

		$data = array(
			'timeout' => strtotime( '+3 hours', time() ),
			'value'   => json_encode( $value ),
		);

		update_option( $cache_key, $data, 'no' );

		// Delete the duplicate option
		delete_option( 'edd_api_request_' . md5( serialize( $this->slug . $this->api_data['license'] ) ) );
	}

	/**
	 * Returns if the SSL of the store should be verified.
	 *
	 * @since  1.6.13
	 * @return bool
	 */
	private function verify_ssl() {
		return (bool) apply_filters( 'edd_sl_api_request_verify_ssl', true, $this );
	}

	/**
	 * Adds newly recognized data header in WordPress plugin files.
	 *
	 * @param array $headers
	 * @return array
	 */
	public function extra_headers( $headers ) {
		$headers[] = 'HonorsWP-Product';
		return $headers;
	}
}
