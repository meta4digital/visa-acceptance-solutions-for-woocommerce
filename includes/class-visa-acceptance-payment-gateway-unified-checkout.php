<?php
/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __DIR__ ) . 'includes/api/payments/payer_auth/class-visa-acceptance-setup.php';
require_once plugin_dir_path( __DIR__ ) . 'includes/api/payments/payer_auth/class-visa-acceptance-enrollment.php';
require_once plugin_dir_path( __DIR__ ) . 'includes/api/payments/payer_auth/class-visa-acceptance-validation.php';

require_once plugin_dir_path( __DIR__ ) . 'includes/api/payments/class-visa-acceptance-payment-methods.php';
require_once plugin_dir_path( __DIR__ ) . 'includes/api/payments/class-visa-acceptance-reporting.php';

/**
 *
 * Visa Acceptance Payment Gateway Unified Checkout Class
 *
 * Handles hooks, dependencies and other functionality
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes
 */
class Visa_Acceptance_Payment_Gateway_Unified_Checkout extends \WC_Payment_Gateway {

	use Visa_Acceptance_Payment_Gateway_Admin_Trait;
	use Visa_Acceptance_Payment_Gateway_Public_Trait;

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @var      Visa_Acceptance_Payment_Gateway_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The current version of the plugin.
	 *
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * The Public gateway object of this plugin.
	 *
	 * @var      object    $plugin_public    The current payment gateways public object.
	 */
	protected $plugin_public;

	/**
	 * The Admin object of this plugin.
	 *
	 * @var      object    $plugin_admin    The current payment gateways admin object.
	 */
	protected $plugin_admin;

	/**
	 * The gateway object of this plugin.
	 *
	 * @var      object    $plugin_public    The current payment gateways public object.
	 */
	public $is_subscriptions_activated = false;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 */
	public function __construct() {
		$this->version = defined( 'VISA_ACCEPTANCE_PLUGIN_VERSION' ) ? VISA_ACCEPTANCE_PLUGIN_VERSION : VISA_ACCEPTANCE_FALLBACK_VERSION;

		// Setup general properties.
		$this->setup_properties();

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// trigger payment_fields function.
		$this->has_fields = true;

		// Get settings.
		$this->enabled = $this->get_option( 'enabled' );
		if ( 'yes' === $this->enabled ) {
			$this->title = $this->get_option( 'title', VISA_ACCEPTANCE_PLUGIN_DISPLAY_NAME );
		} else {
		$plugin_url = plugin_dir_url( __DIR__ );

		$this->title = '<span class="gateway-subtitle">';
		// phpcs:disable PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage
		$this->title .= '<img style="width:31px" src="' . $plugin_url . 'public/img/card-visa.svg" alt="Visa">
		<img style="width:31px" src="' . $plugin_url . 'public/img/card-mastercard.svg" alt="Mastercard">
		<img style="width:31px" src="' . $plugin_url . 'public/img/card-amex.svg" alt="AMEX">
		<img style="width:31px" src="' . $plugin_url . 'public/img/card-discover.svg" alt="Discover">
		<img style="width:31px" src="' . $plugin_url . 'public/img/card-dinersclub.svg" alt="DinersClub">
		<img style="width:31px" src="' . $plugin_url . 'public/img/card-jcb.svg" alt="JCB">
		</span>';
		}

		$this->description = $this->get_option( 'description' );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		// Added.
		add_action( 'init', array( $this, 'schedule_order_updates' ) );
		add_action( 'wc_payment_gateway_update_orders', array( $this, 'handle_order_updates' ) );
		$this->is_subscriptions_activated = $this->is_wc_subscriptions_activated() ? ( VISA_ACCEPTANCE_YES === $this->enabled && ( VISA_ACCEPTANCE_YES === $this->get_option( 'tokenization' ) ) ) : false;
		if ( $this->is_subscriptions_activated ) {
			$this->supports = array(
				'products',
				'tokenization',
				'subscriptions',
				'subscription_cancellation',
				'subscription_suspension',
				'subscription_reactivation',
				'subscription_amount_changes',
				'subscription_date_changes',
				'subscription_payment_method_change',
				'subscription_payment_method_change_customer',
				'subscription_payment_method_change_admin',
				'multiple_subscriptions',
			);

		}
	}

	/**
	 * Is wc subscriptions activated.
	 *
	 * @return boolean $is_actived
	 */
	public function is_wc_subscriptions_activated() {
		$is_actived     = false;
		$active_plugins = (array) get_option( 'active_plugins', array() );
		foreach ( $active_plugins as $active_plugin ) {
			$active_plugin = explode( '/', $active_plugin );
			if ( in_array( 'woocommerce-subscriptions.php', $active_plugin, true ) ) {
				$is_actived = true;
				break;
			}
		}
		return $is_actived;
	}

	/**
	 * Function for `woocommerce_bulk_action_ids` filter-hook.
	 *
	 * @param array  $ids    Array of order IDs.
	 * @param string $action action.
	 * @param string $text text.
	 *
	 * @return array
	 */
	public function wp_kama_woocommerce_bulk_action_ids_filter( $ids, $action, $text ) {
		foreach ( $ids as $order_id ) {
			$order      = wc_get_order( $order_id );
			$order_type = $order->get_payment_method();

			// If order type is of Visa Acceptance Solutions, proceed with the logic.
			if ( in_array( $order_type, array( VISA_ACCEPTANCE_UC_ID, VISA_ACCEPTANCE_SV_GATEWAY_ID ), true ) ) {
				$settings = $this->get_gateway_settings();

				// Check if the service call is enabled and the action is valid.
				if ( VISA_ACCEPTANCE_YES === $settings['enable_paid_capture'] && ( VISA_ACCEPTANCE_MARK_PROCESSING === $action || VISA_ACCEPTANCE_MARK_COMPLETED === $action ) ) {
					// Use a transient to prevent duplicate service calls for the same order.
					$transient_key = "visa_acceptance_solutions_service_call_in_progress_{$order_id}";

					// Use atomic locking to prevent race conditions.
					if ( false === get_transient( $transient_key ) && set_transient( $transient_key, true, 50 * MINUTE_IN_SECONDS ) ) {
						// Set a transient to lock the service call for this order.
						try {
							// Perform the service call based on the order type.
							if ( VISA_ACCEPTANCE_UC_ID === $order_type ) {
								$this->init_process_capture( $order_id, VISA_ACCEPTANCE_UC_ID );
							} else {
								$this->init_process_capture( $order_id, VISA_ACCEPTANCE_SV_GATEWAY_ID );
							}
						} catch ( Exception $e ) {
							// Log the error and ensure the transient is deleted.
							wc_get_logger()->error( "Error processing capture for order {$order_id}: " . $e->getMessage() );
						} finally {
							// Delete the transient after the service call is completed.
							delete_transient( $transient_key );
						}
					}
				}
			}
		}
		// Return the IDs after the bulk action.
		return $ids;
	}

	/**
	 * Handles the order updates when scheduler is run.
	 *
	 * @param object $gateway gateway.
	 *
	 * @return void
	 */
	public function handle_order_updates( $gateway ) {

		$reporting = new Visa_Acceptance_Reporting( $this );
		$response  = $reporting->get_conversion_details( $this->get_merchant_id() );
	}

	/**
	 * Schedules the order update routines.
	 *
	 * Only schedule a routine for a gateway if it's
	 * - Not inheriting another's settings
	 * - Configured and Decision Manager is enabled
	 *
	 * @return void
	 */
	public function schedule_order_updates() {
			$args = array( $this->get_title_dasherized( $this->method_title ) );
		if ( false === as_next_scheduled_action( 'wc_payment_gateway_update_orders', $args ) ) {
			as_schedule_recurring_action( time() + $this->get_order_update_interval(), $this->get_order_update_interval(), 'wc_payment_gateway_update_orders', $args, VISA_ACCEPTANCE_PLUGIN_VERSION );
		}
	}

	/**
	 * Gets the order update interval.
	 *
	 * @return int
	 */
	private function get_order_update_interval() {
		return max( VISA_ACCEPTANCE_VAL_ONE, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * Prevents the order status change without triggering any follow-on transactions.
	 *
	 * @param int    $order_id order id.
	 * @param string $status_from the status to be changed from.
	 * @param string $status_to the status to be changed to.
	 * @param object $that class object.
	 * @throws Exception Exception to prevent order status change.
	 */
	public function prevent_order_status_change( $order_id, $status_from, $status_to, $that ) {
			$order = wc_get_order( $order_id );
		if ( in_array( $order->get_payment_method( VISA_ACCEPTANCE_EDIT ), array( VISA_ACCEPTANCE_UC_ID, VISA_ACCEPTANCE_SV_GATEWAY_ID ), true ) ) {
			if ( ( $status_from !== $status_to ) && is_admin() ) {
				if ( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_ON_HOLD === $status_from && ( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING === $status_to || VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_COMPLETED === $status_to ) ) {
					$settings = $this->get_gateway_settings();
					if ( ( VISA_ACCEPTANCE_YES === $settings['enable_paid_capture'] ) && ( $order->get_payment_method( VISA_ACCEPTANCE_EDIT ) === VISA_ACCEPTANCE_UC_ID ) ) {
						$this->init_process_capture( $order_id, VISA_ACCEPTANCE_UC_ID );
					} else {
						$this->init_process_capture( $order_id, VISA_ACCEPTANCE_SV_GATEWAY_ID );
					}
				}
			}
		}
	}

/**
  * Process the admin options.
  *
  * Validates the title field and prevents saving if it does not meet the criteria.
  *
  * @return bool
  */
 public function process_admin_options() {
		$post_data = $this->get_post_data();

		$enable_mle = isset($post_data['woocommerce_' . $this->get_id() . '_enable_mle']) ? $post_data['woocommerce_' . $this->get_id() . '_enable_mle'] : 0;

		$raw_environment = isset($post_data['woocommerce_' . $this->get_id() . '_environment']) ? $post_data['woocommerce_' . $this->get_id() . '_environment'] : '';

		$raw_title = isset($post_data['woocommerce_' . $this->get_id() . '_title']) ? $post_data['woocommerce_' . $this->get_id() . '_title'] : '';
		
		$trimmed_title = trim($raw_title);

		$raw_test_merchant_id = isset($post_data['woocommerce_' . $this->get_id() . '_test_merchant_id']) ? $post_data['woocommerce_' . $this->get_id() . '_test_merchant_id'] : '';

		$raw_test_api_key = isset($post_data['woocommerce_' . $this->get_id() . '_test_api_key']) ? $post_data['woocommerce_' . $this->get_id() . '_test_api_key'] : '';

		$raw_test_api_shared_secret = isset($post_data['woocommerce_' . $this->get_id() . '_test_api_shared_secret']) ? $post_data['woocommerce_' . $this->get_id() . '_test_api_shared_secret'] : '';

		$raw_merchant_id = isset($post_data['woocommerce_' . $this->get_id() . '_merchant_id']) ? $post_data['woocommerce_' . $this->get_id() . '_merchant_id'] : '';

		$raw_api_key = isset($post_data['woocommerce_' . $this->get_id() . '_api_key']) ? $post_data['woocommerce_' . $this->get_id() . '_api_key'] : '';

		$raw_api_shared_secret = isset($post_data['woocommerce_' . $this->get_id() . '_api_shared_secret']) ? $post_data['woocommerce_' . $this->get_id() . '_api_shared_secret'] : '';

		$raw_mle_certificate_path = isset($post_data['woocommerce_' . $this->get_id() . '_mle_certificate_path']) ? $post_data['woocommerce_' . $this->get_id() . '_mle_certificate_path'] : '';

		$raw_mle_filename = isset($post_data['woocommerce_' . $this->get_id() . '_mle_filename']) ? $post_data['woocommerce_' . $this->get_id() . '_mle_filename'] : '';
		
		$raw_mle_key_password = isset($post_data['woocommerce_' . $this->get_id() . '_mle_key_password']) ? $post_data['woocommerce_' . $this->get_id() . '_mle_key_password'] : '';
		$title_key = 'woocommerce_' . $this->get_id() . '_title';
		$_POST[$title_key] = $trimmed_title;
		static $error_added = false;

		if ('' === $raw_title)  {
			if (!$error_added) {
				if ( method_exists('WC_Admin_Settings', 'add_error') ) {
				WC_Admin_Settings::add_error( __( 'Title is required. Please fill out this field.', 'visa-acceptance-solutions' ) );
				} else {
				add_action('admin_notices', function() {
				echo '<div class="notice notice-error"><p>' . esc_html__('Title is required. Please fill out this field.', 'visa-acceptance-solutions') . '</p></div>';
				});
				}
				$error_added = true;
			}
			return false;
		}

		if (preg_match('/[^A-Za-z0-9 ]/', $trimmed_title)) {
			if (!$error_added) {
				if ( method_exists('WC_Admin_Settings', 'add_error') ) {
				WC_Admin_Settings::add_error( __( 'Title is invalid. Please fill out this field.', 'visa-acceptance-solutions' ) );
				} else {
				add_action('admin_notices', function() {
				echo '<div class="notice notice-error"><p>' . esc_html__('Title is invalid. Please fill out this field.', 'visa-acceptance-solutions') . '</p></div>';
				});
				}
				$error_added = true;
			}
			return false;
		}

		if('test' === $raw_environment) {
			$trimmed_raw_test_merchant_id = trim($raw_test_merchant_id);
			$test_merchant_id = 'woocommerce_' . $this->get_id() . '_test_merchant_id';
			$_POST[$test_merchant_id] = $trimmed_raw_test_merchant_id;
			
			if ('' === $raw_test_merchant_id) {
				if (!$error_added) {
					if ( method_exists('WC_Admin_Settings', 'add_error') ) {
					WC_Admin_Settings::add_error( __( 'Merchant ID is required. Please fill out this field.', 'visa-acceptance-solutions' ) );
					} else {
					add_action('admin_notices', function() {
					echo '<div class="notice notice-error"><p>' . esc_html__('Merchant ID is required. Please fill out this field.', 'visa-acceptance-solutions') . '</p></div>';
					});
					}
					$error_added = true;
				}
				return false;
			}

			if ((preg_match('/[^[:alnum:]\-_]/', $trimmed_raw_test_merchant_id))) {
				if (!$error_added) {
					if ( method_exists('WC_Admin_Settings', 'add_error') ) {
					WC_Admin_Settings::add_error( __( 'Merchant ID is invalid. Please fill out this field.', 'visa-acceptance-solutions' ) );
					} else {
					add_action('admin_notices', function() {
					echo '<div class="notice notice-error"><p>' . esc_html__('Merchant ID is invalid. Please fill out this field.', 'visa-acceptance-solutions') . '</p></div>';
					});
					}
					$error_added = true;
				}
				return false;
			}

			if ('' === $raw_test_api_key) {
				if (!$error_added) {
					if ( method_exists('WC_Admin_Settings', 'add_error') ) {
					WC_Admin_Settings::add_error( __( 'API Key Detail is required. Please fill out this field.', 'visa-acceptance-solutions' ) );
					} else {
					add_action('admin_notices', function() {
					echo '<div class="notice notice-error"><p>' . esc_html__('API Key Detail is required. Please fill out this field.', 'visa-acceptance-solutions') . '</p></div>';
					});
					}
					$error_added = true;
				}
				return false;
			}

			if ('' === $raw_test_api_shared_secret) {
				if (!$error_added) {
					if ( method_exists('WC_Admin_Settings', 'add_error') ) {
					WC_Admin_Settings::add_error( __( 'API Shared Secret Key is required. Please fill out this field.', 'visa-acceptance-solutions' ) );
					} else {
					add_action('admin_notices', function() {
					echo '<div class="notice notice-error"><p>' . esc_html__('API Shared Secret Key is required. Please fill out this field.', 'visa-acceptance-solutions') . '</p></div>';
					});
					}
					$error_added = true;
				}
				return false;
			}
		} else {
			$trimmed_raw_merchant_id = trim($raw_merchant_id);
			$merchant_id = 'woocommerce_' . $this->get_id() . '_merchant_id';
			$_POST[$merchant_id] = $trimmed_raw_merchant_id;
			if ('' === $raw_merchant_id) {
			if (!$error_added) {
				if ( method_exists('WC_Admin_Settings', 'add_error') ) {
				WC_Admin_Settings::add_error( __( 'Merchant ID is required. Please fill out this field.', 'visa-acceptance-solutions' ) );
				} else {
				add_action('admin_notices', function() {
				echo '<div class="notice notice-error"><p>' . esc_html__('Merchant ID is required. Please fill out this field.', 'visa-acceptance-solutions') . '</p></div>';
				});
				}
				$error_added = true;
			}
			return false;
		}

		if (preg_match('/[^[:alnum:]\-_]/', $trimmed_raw_merchant_id)) {
			if (!$error_added) {
				if ( method_exists('WC_Admin_Settings', 'add_error') ) {
				WC_Admin_Settings::add_error( __( 'Merchant ID is invalid. Please fill out this field.', 'visa-acceptance-solutions' ) );
				} else {
				add_action('admin_notices', function() {
				echo '<div class="notice notice-error"><p>' . esc_html__('Merchant ID is invalid. Please fill out this field.', 'visa-acceptance-solutions') . '</p></div>';
				});
				}
				$error_added = true;
			}
			return false;
		}

		if ('' === $raw_api_key) {
			if (!$error_added) {
				if ( method_exists('WC_Admin_Settings', 'add_error') ) {
				WC_Admin_Settings::add_error( __( 'API Key Detail is required. Please fill out this field.', 'visa-acceptance-solutions' ) );
				} else {
					add_action('admin_notices', function() {
					echo '<div class="notice notice-error"><p>' . esc_html__('API Key Detail is required. Please fill out this field.', 'visa-acceptance-solutions') . '</p></div>';
					});
				}
				$error_added = true;
			}
			return false;
		}

		if ('' === $raw_api_shared_secret) {
			if (!$error_added) {
				if ( method_exists('WC_Admin_Settings', 'add_error') ) {
				WC_Admin_Settings::add_error( __( 'API Shared Secret Key is required. Please fill out this field.', 'visa-acceptance-solutions' ) );
				} else {
				add_action('admin_notices', function() {
				echo '<div class="notice notice-error"><p>' . esc_html__('API Shared Secret Key is required. Please fill out this field.', 'visa-acceptance-solutions') . '</p></div>';
				});
				}
				$error_added = true;
			}
			return false;
			}
		}
		if ('1' === $enable_mle) {
			$trimmed_raw_mle_certificate_path = trim($raw_mle_certificate_path);
			$mle_certificate_path = 'woocommerce_' . $this->get_id() . '_raw_mle_certificate_path';
			$_POST[$mle_certificate_path] = $trimmed_raw_mle_certificate_path;

			$trimmed_raw_mle_filename = trim($raw_mle_filename);
			$mle_filename = 'woocommerce_' . $this->get_id() . '_raw_mle_filename';
			$_POST[$mle_filename] = $trimmed_raw_mle_filename;

			if ('' === $trimmed_raw_mle_certificate_path) {
			if (!$error_added) {
				if ( method_exists('WC_Admin_Settings', 'add_error') ) {
				WC_Admin_Settings::add_error( __( 'Key Directory Path is required. Please fill out this field.', 'visa-acceptance-solutions' ) );
				} else {
				add_action('admin_notices', function() {
				echo '<div class="notice notice-error"><p>' . esc_html__('Key Directory Path is required. Please fill out this field.', 'visa-acceptance-solutions') . '</p></div>';
				});
				}
				$error_added = true;
			}
			return false;
			}

		if ('' === $raw_mle_filename) {
			if (!$error_added) {
				if ( method_exists('WC_Admin_Settings', 'add_error') ) {
				WC_Admin_Settings::add_error( __( 'Key File Name is required. Please fill out this field.', 'visa-acceptance-solutions' ) );
				} else {
				add_action('admin_notices', function() {
				echo '<div class="notice notice-error"><p>' . esc_html__('Key File Name is requiredsss. Please fill out this field.', 'visa-acceptance-solutions') . '</p></div>';
				});
				}
				$error_added = true;
			}
			return false;
		}
		if ((preg_match('/[^[:alnum:]\-_]/', $trimmed_raw_mle_filename))) {
			if (!$error_added) {
				if ( method_exists('WC_Admin_Settings', 'add_error') ) {
				WC_Admin_Settings::add_error( __( 'Key File Name is invalid. Please fill out this field.', 'visa-acceptance-solutions' ) );
				} else {
				add_action('admin_notices', function() {
				echo '<div class="notice notice-error"><p>' . esc_html__('Key File Name is invalid. Please fill out this field.', 'visa-acceptance-solutions') . '</p></div>';
				});
				}
				$error_added = true;
			}
			return false;
		}

		if ('' === $raw_mle_key_password) {
			if (!$error_added) {
				if ( method_exists('WC_Admin_Settings', 'add_error') ) {
				WC_Admin_Settings::add_error( __( 'Key Password is required. Please fill out this field.', 'visa-acceptance-solutions' ) );
				} else {
				add_action('admin_notices', function() {
				echo '<div class="notice notice-error"><p>' . esc_html__('Key Password is required. Please fill out this field.', 'visa-acceptance-solutions') . '</p></div>';
				});
				}
				$error_added = true;
			}
			return false;
		}
		}
		

  		return parent::process_admin_options();
 }

	/**
	 * Gets merchant id for particular gateway.
	 *
	 * @return string|null
	 */
	public function get_merchant_id() {
		$settings = $this->get_gateway_settings();
		if ( isset( $settings['environment'] ) && VISA_ACCEPTANCE_ENVIRONMENT_PRODUCTION === $settings['environment'] ) {
			$merchant_id = isset( $settings['merchant_id'] ) ? $settings['merchant_id'] : null;
		} else {
			$merchant_id = isset( $settings['test_merchant_id'] ) ? $settings['test_merchant_id'] : null;
		}
		return $merchant_id;
	}

	/**
	 * Setup general properties for the gateway.
	 *
	 * @return void
	 */
	protected function setup_properties() {
		$this->id                 = VISA_ACCEPTANCE_UC_ID;
		$this->method_title       = __( 'Visa Acceptance Solutions', 'visa-acceptance-solutions' );
		$this->method_description = __( 'Choose from a range of secure payment options.', 'visa-acceptance-solutions' );
		$this->icon               = plugins_url( '/../public/img/brand-icon.png', __FILE__ );
		$this->has_fields         = true;
	}

	/**
	 * Returns the method title for admin page.
	 *
	 * @return string
	 */
	public function get_title() {
		return $this->title;
	}

	/**
	 * Gets the configuration settings for the particular gateway
	 *
	 * @return array
	 */
	public function get_config_settings() {
		$settings = $this->get_gateway_settings();
		return $settings;
	}

	/**
	 * Returns settings stored in current gateway.
	 *
	 * @return array
	 */
	public function get_gateway_settings( $order = false ) {

		$settings = get_option( VISA_ACCEPTANCE_WOOCOMMERCE_UNDERSCORE . $this->get_id() . VISA_ACCEPTANCE_UNDERSCORE_SETTINGS, array() );
		return apply_filters( 'visa_acceptance_solutions_get_gateway_settings', $settings, $order, $this->get_id() );
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Visa_Acceptance_Payment_Gateway_Loader. Orchestrates the hooks of the plugin.
	 * - Visa_Acceptance_Solutions_Admin. Defines all hooks for the admin area.
	 * - Visa_Acceptance_Solutions_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @return void
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-visa-acceptance-payment-gateway-loader.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-visa-acceptance-payment-gateway-unified-checkout-admin.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'public/class-visa-acceptance-payment-gateway-unified-checkout-public.php';

		/**
		 * The class responsible for defining all actions that occur in the CURL API's
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/api/payments/class-visa-acceptance-payment-uc.php';

		$this->loader = new Visa_Acceptance_Payment_Gateway_Loader();
	}

	/**
	 * Define the locale for this plugin for internationalization.
	 * with WordPress.
	 *
	 * @return void
	 */
	private function set_locale() {

		$this->loader->add_action( 'plugins_loaded', $this, 'visa_acceptance_initialize' );
	}

	/**
	 * Load the plugin text domain for translation.
	 * Initialize the plugin.
	 */
	private function visa_acceptance_initialize() {
		require_once 'includes/class-visa-acceptance-solutions.php';

		if ( class_exists( VISA_ACCEPTANCE_WOOCOMMERCE_CONSTANT ) ) {
			// Initialize the Visa_Acceptance_Solutions instance.
			$GLOBALS[ VISA_ACCEPTANCE_GATEWAY_ID ] = Visa_Acceptance_Solutions::instance();
		} else {
			return;
		}
	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @return void
	 */
	private function define_admin_hooks() {

		$this->plugin_admin = new Visa_Acceptance_Payment_Gateway_Unified_Checkout_Admin( $this->get_id(), $this->get_version(), $this );
		$settings           = $this->get_gateway_settings();
		if ( isset( $settings['enabled'] ) && VISA_ACCEPTANCE_YES === $settings['enabled'] ) {
			$this->loader->add_action( 'admin_enqueue_scripts', $this->plugin_admin, 'enqueue_styles' );
			$this->loader->add_action( 'admin_enqueue_scripts', $this->plugin_admin, 'enqueue_scripts' );
			$this->loader->add_action( 'woocommerce_order_item_add_action_buttons', $this->plugin_admin, 'add_capture_button', VISA_ACCEPTANCE_ACTION_HOOK_DEFAULT_PRIORITY, VISA_ACCEPTANCE_VAL_ONE );
			$this->loader->add_action( 'wp_ajax_wc_capture_action', $this->plugin_admin, 'ajax_process_capture' );
			$this->loader->add_action( 'woocommerce_order_status_changed', $this, 'prevent_order_status_change', VISA_ACCEPTANCE_ACTION_HOOK_DEFAULT_PRIORITY, VISA_ACCEPTANCE_VAL_FOUR );

			$this->loader->add_filter( 'woocommerce_bulk_action_ids', $this, 'wp_kama_woocommerce_bulk_action_ids_filter', VISA_ACCEPTANCE_ACTION_HOOK_DEFAULT_PRIORITY, VISA_ACCEPTANCE_VAL_THREE );

		}
	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @return void
	 */
	private function define_public_hooks() {

		// if payment gateway is disabled, then no need to enqueue JS too.
		if ( VISA_ACCEPTANCE_NO === $this->enabled ) {
			return;
		}

		$this->plugin_public = new Visa_Acceptance_Payment_Gateway_Unified_Checkout_Public( $this->get_id(), $this->get_version(), $this );
		$settings            = $this->get_gateway_settings();
		if ( isset( $settings['enabled'] ) && VISA_ACCEPTANCE_YES === $settings['enabled'] ) {
			$this->loader->add_action( 'wp_enqueue_scripts', $this->plugin_public, 'enqueue_styles' );
			$this->loader->add_action( 'wp_enqueue_scripts', $this->plugin_public, 'enqueue_scripts' );
			$this->loader->add_action( 'wp_ajax_' . $this->get_id() . '_action', $this->plugin_public, $this->get_id() . '_action' );
			$this->loader->add_filter( 'woocommerce_payment_methods_list_item', $this->plugin_public, 'wp_kama_woocommerce_saved_payment_methods_list_filter', 10, 2 );

			// For Delete Card data.
			$wc_version = defined( 'WC_VERSION' ) && WC_VERSION ? WC_VERSION : null;
			if ( version_compare( $wc_version, VISA_ACCEPTANCE_WC_VERSION_EIGHT_ONE_ZERO, VISA_ACCEPTANCE_GREATER_THAN_OR_EQUAL_TO ) ) {
				$this->loader->add_filter( 'woocommerce_pre_delete_data', $this->plugin_public, 'wp_kama_woocommerce_pre_delete_object_type_filter', VISA_ACCEPTANCE_ACTION_HOOK_DEFAULT_PRIORITY, VISA_ACCEPTANCE_VAL_THREE );
			} else {
				$this->loader->add_action( 'woocommerce_payment_token_deleted', $this->plugin_public, 'uc_payment_token_deleted', VISA_ACCEPTANCE_ACTION_HOOK_DEFAULT_PRIORITY, VISA_ACCEPTANCE_VAL_TWO );
			}
			// For Dafault Card data.
			$this->loader->add_filter( 'woocommerce_payment_token_set_default', $this->plugin_public, 'wp_kama_woocommerce_set_default', VISA_ACCEPTANCE_ACTION_HOOK_DEFAULT_PRIORITY, VISA_ACCEPTANCE_VAL_TWO );

			$this->loader->add_filter( 'woocommerce_account_payment_methods_columns', $this->plugin_public, 'uc_add_payment_methods_columns' );
			$this->loader->add_action( 'woocommerce_account_payment_methods_column_title', $this->plugin_public, 'uc_add_payment_method_title' );
			$this->loader->add_action( 'woocommerce_account_payment_methods_column_details', $this->plugin_public, 'uc_add_payment_method_details' );
			$this->loader->add_action( 'woocommerce_account_payment_methods_column_default', $this->plugin_public, 'uc_add_payment_method_default' );

			// For Accepted Card Logos.
			$this->loader->add_filter( 'woocommerce_gateway_icon', $this->plugin_public, 'custom_gateway_icon', VISA_ACCEPTANCE_ACTION_HOOK_DEFAULT_PRIORITY, VISA_ACCEPTANCE_VAL_TWO );

			/*** Payer Auth AJAX */
			$this->loader->add_action( 'wp_ajax_wc_call_uc_payer_auth_setup_action', $this->plugin_public, 'call_setup_action' );
			$this->loader->add_action( 'wp_ajax_wc_call_uc_payer_auth_enrollment_action', $this->plugin_public, 'call_enrollment_action' );
			$this->loader->add_action( 'wp_ajax_wc_call_uc_payer_auth_validation_action', $this->plugin_public, 'call_validation_action' );
			$this->loader->add_action( 'wp_ajax_nopriv_wc_call_uc_payer_auth_setup_action', $this->plugin_public, 'call_setup_action' );
			$this->loader->add_action( 'wp_ajax_nopriv_wc_call_uc_payer_auth_enrollment_action', $this->plugin_public, 'call_enrollment_action' );
			$this->loader->add_action( 'wp_ajax_nopriv_wc_call_uc_payer_auth_validation_action', $this->plugin_public, 'call_validation_action' );
			/*** Payer Auth Custom Endpoint(return url) */
			$this->loader->add_action( 'rest_api_init', $this->plugin_public, 'payment_gateway_register_endpoint' );

			$this->loader->add_action( 'wp_ajax_wc_call_uc_payer_auth_error_handler', $this->plugin_public, 'call_error_handler' );
			$this->loader->add_action( 'wp_ajax_nopriv_wc_call_uc_payer_auth_error_handler', $this->plugin_public, 'call_error_handler' );
			$this->loader->add_action( 'wp_ajax_wc_call_uc_update_price_action', $this->plugin_public, 'call_updates_action' );
			$this->loader->add_action( 'wp_ajax_nopriv_wc_call_uc_update_price_action', $this->plugin_public, 'call_updates_action' );
		}
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @return    Visa_Acceptance_Payment_Gateway_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Handles the admin options.
	 *
	 * @return void
	 */
	public function admin_options() {
		parent::admin_options();
		$this->plugin_admin->admin_options();
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

	/**
	 * Get Plugin URL.
	 *
	 * @return string
	 */
	public function get_plugin_url() {

		$plugin_url = untrailingslashit( plugins_url( VISA_ACCEPTANCE_SLASH, __FILE__ ) );

		return $plugin_url;
	}

	/**
	 * Initialize Form Fields.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = $this->plugin_admin->init_form_fields();
	}



	/**
	 * Returns the current environment.
	 *
	 * @return string
	 */
	public function get_environment() {
		$settings = $this->get_gateway_settings();
		return ! empty( $settings['environment'] ) ? $settings['environment'] : VISA_ACCEPTANCE_STRING_EMPTY;
	}

	/**
	 * Display the payment fields on the checkout page
	 */
	public function payment_fields() {
		$this->plugin_public->payment_fields();
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param  int $order_id Order ID.
	 * @return array|null
	 */
	public function process_payment( $order_id ) {
		$response = null;
		// Condition to check order_id exists and valid or not.
		if ( $order_id || wc_get_order( $order_id ) ) {
			// Simply return if Invalid or non-existent order ID.
			$response = $this->plugin_public->process_payment( $order_id );
		}
		return $response;
	}

	/**
	 * Get feature supports for plugin.
	 *
	 * @param array $feature feature.
	 *
	 * @return boolean
	 */
	public function supports( $feature ) {
		$services_supported = array( VISA_ACCEPTANCE_ADD_PAYMENT_METHOD, VISA_ACCEPTANCE_TOKENIZATION, VISA_ACCEPTANCE_REFUNDS );
		$settings           = $this->get_gateway_settings();
		if ( VISA_ACCEPTANCE_ADD_PAYMENT_METHOD === $feature || VISA_ACCEPTANCE_TOKENIZATION === $feature ) {
			return VISA_ACCEPTANCE_YES === $settings['tokenization'] ? in_array( $feature, $services_supported, true ) : false;
		} elseif ( in_array( $feature, $this->supports, true ) ) {
			return true;
		} else {
			return in_array( $feature, $services_supported, true );
		}
	}

	/**
	 * Validates order.
	 *
	 * @param string|int $order_id order id.
	 * @param any        $order order object.
	 * @return array
	 */
	public function validate_order( $order_id, $order ) {
		$response_data = array();
		if ( ! $order ) {
			$response_data[ VISA_ACCEPTANCE_STRING_ERROR ] = VISA_ACCEPTANCE_CALLBACK_INVALID_ID_ERROR;
		} elseif ( ! current_user_can( VISA_ACCEPTANCE_EDIT_SHOP_ORDER, $order_id ) ) {
			$response_data[ VISA_ACCEPTANCE_STRING_ERROR ] = VISA_ACCEPTANCE_CALLBACK_INVALID_PERMISSIONS_ERROR;
		} elseif ( $order->get_payment_method( VISA_ACCEPTANCE_EDIT ) !== $this->id ) {
			$response_data[ VISA_ACCEPTANCE_STRING_ERROR ] = VISA_ACCEPTANCE_CALLBACK_INVALID_PAYMENT_METHOD_ERROR;
		}
		return $response_data;
	}

	/**
	 * Initiates refund process.
	 *
	 * @param int    $order_id order id.
	 * @param string $amount refund amount.
	 * @param string $reason efund reason.
	 *
	 * @return array
	 */
	public function process_refund( $order_id, $amount = null, $reason = VISA_ACCEPTANCE_STRING_EMPTY ) {
		$order         = wc_get_order( $order_id );
		$response_data = array();
		$response_data = $this->validate_order( $order_id, $order );
		if ( ! $response_data[ VISA_ACCEPTANCE_STRING_ERROR ] ) {
			$response_data = $this->plugin_admin->process_refund( $order_id, $amount, $reason );
		}
		return $response_data;
	}

	/**
	 * Checks whether order can be refunded or not.
	 *
	 * @param object $order order details.
	 *
	 * @return boolean
	 */
	public function can_refund_order( $order ) {
		return ! in_array( $order->get_status(), array( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_CANCELLED, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_REFUNDED, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_FAILED, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PENDING ), true ) && ( $this->get_order_meta( $order, VISA_ACCEPTANCE_TRANSACTION_ID ) || $this->get_order_meta( $order, VISA_ACCEPTANCE_CAPTURE_TRANSACTION_ID ) );
	}

	/**
	 * Initializes capture process
	 *
	 * @param int    $order_id order id.
	 * @param string $gateway_id gateway id.
	 *
	 * @return array
	 */
	public function init_process_capture( $order_id, $gateway_id ) {
		$order         = wc_get_order( $order_id );
		$response_data = array();
		if ( ! $order ) {
			$response_data['error'] = 'Invalid order ID';
		} elseif ( ! current_user_can( VISA_ACCEPTANCE_EDIT_SHOP_ORDER, $order_id ) ) {
			$response_data[ VISA_ACCEPTANCE_STRING_ERROR ] = VISA_ACCEPTANCE_CALLBACK_INVALID_PERMISSIONS_ERROR;
		} elseif ( $order->get_payment_method( 'edit' ) !== $gateway_id ) {
			$response_data['error'] = 'Invalid payment method';
		}
		require_once plugin_dir_path( __DIR__ ) . 'includes/api/payments/class-visa-acceptance-capture.php';
		if ( empty( $response_data ) ) {
			$capture       = new Visa_Acceptance_Capture( $this );
			$response_data = $capture->perform_capture( $order, $response_data );
		}
		return $response_data;
	}

	/**
	 * Mask response.
	 *
	 * @param string $response response.
	 *
	 * @return string $response.
	 */
	public function mask_response( $response ) {
		$mask_data   = new Visa_Acceptance_Payment_Adapter( $this );
		$decode_data = ! empty( $response ) ? json_decode( $response, true ) : false;

		if ( $decode_data ) {
			$fields_to_mask = array(
				'paymentInformation.card' => array( 'expirationYear', 'expirationMonth', 'prefix', 'suffix', 'securityCode' ),
				'orderInformation.billTo' => array( 'firstName', 'lastName', 'address1', 'address2', 'postalCode', 'locality', 'phoneNumber', 'email' ),
				'orderInformation.shipTo' => array( 'firstName', 'lastName', 'address1', 'address2', 'postalCode', 'locality', 'phoneNumber', 'email' ),
				'deviceInformation'       => array( 'fingerprintSessionId' ),
			);

			foreach ( $fields_to_mask as $section => $fields ) {
				$data = &$decode_data;
				foreach ( explode( VISA_ACCEPTANCE_FULL_STOP, $section ) as $key ) {
					if ( isset( $data[ $key ] ) ) {
						$data = &$data[ $key ];
					} else {
						continue 2; // Skip to the next iteration of the outer loop.
					}
				}
				foreach ( $fields as $field ) {
					if ( ! empty( $data[ $field ] ) ) {
						$data[ $field ] = $mask_data->mask_value( $data[ $field ] );
					}
				}
			}
			$response = wp_json_encode( $decode_data );
		}
		return $response;
	}

	/**
	 * Adds data to the logs
	 *
	 * @param any     $data request/response data.
	 * @param boolean $is_request indicates whether the data is request.
	 * @param string  $log_header log header.
	 * @param boolean $is_catch_error indicates whether the error to log.
	 * @return void
	 */
	public function add_logs_header_response( $data, $is_request, $log_header, $is_catch_error = false ) {
		$settings = $this->get_gateway_settings();
		if ( isset( $settings['enable_logs'] ) && ( VISA_ACCEPTANCE_YES === $settings['enable_logs'] ) ) {
			$logger = wc_get_logger();
			// Change Log name as Title.
			$context = array( 'source' => $this->get_title_dasherized( $this->method_title ) );
			if ( $is_request ) {
				//$data = $this->mask_response( $data );
				$log  = $this->get_title() . VISA_ACCEPTANCE_SPACE . $log_header . VISA_ACCEPTANCE_RESPONSE . PHP_EOL . wp_json_encode($data) . PHP_EOL;

			}
			$logger->info( $log, $context );
		}
	}

	/**
	 * Adds data to the logs
	 *
	 * @param any     $data request/response data.
	 * @param string  $correlation_id correlation id.
	 * @param boolean $is_request indicates whether the data is request.
	 * @param string  $log_header log header.
	 * @param boolean $is_catch_error indicates whether the error to log.
	 * @return void
	 */
	public function add_logs_service_response( $data, $correlation_id, $is_request, $log_header, $is_catch_error = false ) {
		$settings = $this->get_gateway_settings();
		if ( isset( $settings['enable_logs'] ) && ( VISA_ACCEPTANCE_YES === $settings['enable_logs'] ) ) {
			$logger = wc_get_logger();
			// Change Log name as Title.
			$context = array( 'source' => $this->get_title_dasherized( $this->method_title ) );
			if ( $is_request ) {
				$data = $this->mask_response( $data );
				$log  = $this->get_title() . VISA_ACCEPTANCE_SPACE . $log_header . VISA_ACCEPTANCE_RESPONSE . PHP_EOL . '{"correlation id":' .$correlation_id.'}'.$data . PHP_EOL;

			}
			$logger->info( $log, $context );
		}
	}

	/**
	 * Adds data to the logs
	 *
	 * @param array   $data request/response data.
	 * @param boolean $is_request indicates whether the data is request.
	 * @param string  $log_header log header.
	 * @param boolean $is_catch_error indicates whether the error to log.
	 * @return void
	 */
	public function add_logs_data( $data, $is_request, $log_header, $is_catch_error = false ) {
		$settings = $this->get_gateway_settings();
		if ( isset( $settings['enable_logs'] ) && ( VISA_ACCEPTANCE_YES === $settings['enable_logs'] ) ) {
			$logger = wc_get_logger();
			// Change Log name as Title.
			$context = array( 'source' => $this->get_title_dasherized( $this->method_title ) );
			if ( $is_request ) {
				$data = $this->mask_response( $data );
				$log  = $this->get_title() . VISA_ACCEPTANCE_SPACE . $log_header . VISA_ACCEPTANCE_REQUEST . PHP_EOL . $data . PHP_EOL;

			} elseif ( is_wp_error( $data ) ) {
				$error_messages = $data->get_error_messages();
				$error_message  = array( 'Could not receive/fetch API response.' );
				if ( in_array( __( 'A valid URL was not provided.', 'visa-acceptance-solutions' ), $error_messages, true ) ) {
					$error_messages = $error_message;
				}
				$log = $this->get_title() . VISA_ACCEPTANCE_SPACE . $log_header . VISA_ACCEPTANCE_RESPONSE . PHP_EOL . is_string( $error_messages ) ? $error_messages : wp_json_encode( $error_messages, JSON_UNESCAPED_SLASHES ) . PHP_EOL;
			} elseif ( $is_catch_error ) {
				$data = is_string( $data ) ? $data : wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
				$log  = $this->get_title() . VISA_ACCEPTANCE_SPACE . $log_header . PHP_EOL . $data . PHP_EOL;
			} else {
				$data = $this->mask_response( $data['body'] );
				$data = is_string( $data ) ? $data : wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
				$log  = $this->get_title() . VISA_ACCEPTANCE_SPACE . $log_header . VISA_ACCEPTANCE_RESPONSE . PHP_EOL . $data . PHP_EOL;
			}
			$logger->info( $log, $context );
		}
	}


		/**
		 * Initiates the payer auth setup process.
		 *
		 * @param string            $token token.
		 * @param \WC_Payment_Token $saved_token saved token.
		 * @param int               $order_id order id.
		 *
		 * @return array
		 */
	public function payer_auth_setup( $token, $saved_token, $order_id ) {

		$setup         = new Visa_Acceptance_Setup( $this );
		$response_data = $setup->do_setup( $token, $saved_token, $order_id );

		return $response_data;
	}

		/**
		 * Initiates the payer auth enrollment process.
		 *
		 * @param int               $order_id order id.
		 * @param string            $token jwt token .
		 * @param \WC_Payment_Token $saved_token saved token.
		 * @param string            $token_check_box_value indicates whether save token checkbox is checked.
		 * @param string            $reference_id reference id.
		 * @param string            $sca_case Verify the SCA case.
		 *
		 * @return array
		 */
	public function payer_auth_enrollment( $order_id, $token, $saved_token, $token_check_box_value, $reference_id, $sca_case ) {

		$enrollment = new Visa_Acceptance_Enrollment( $this );

		// Creating Order Object from ID.
		$order                       = wc_get_order( $order_id );
		$order_token_check_box_value = $this->get_order_meta( $order, VISA_ACCEPTANCE_SAVED_CARD_BLOCKS . $order_id );
		if ( ! $token_check_box_value && ! empty( $order_token_check_box_value ) ) {
			$token_check_box_value = $order_token_check_box_value;
		}
		$response_data = $enrollment->do_enrollment( $order, $token, $saved_token, $token_check_box_value, $reference_id, $sca_case );

		return $response_data;
	}

		/**
		 * Initiates payer auth validation process.
		 *
		 * @param int               $order_id order id.
		 * @param string            $token jwt token .
		 * @param \WC_Payment_Token $saved_token saved token.
		 * @param string            $token_check_box_value indicates whether save token checkbox is checked.
		 * @param string            $auth_id auth id.
		 * @param string            $pareq Payer Auth Request Signature.
		 * @param string            $sca_case Verify the SCA case.
		 *
		 * @return array
		 */
	public function payer_auth_validation( $order_id, $token, $saved_token, $token_check_box_value, $auth_id, $pareq, $sca_case ) {

		$validation = new Visa_Acceptance_Validation( $this );

		$order                       = wc_get_order( $order_id );
		$order_token_check_box_value = $this->get_order_meta( $order, VISA_ACCEPTANCE_SAVED_CARD_BLOCKS . $order_id );
		if ( ! $token_check_box_value && ! empty( $order_token_check_box_value ) ) {
			$token_check_box_value = $order_token_check_box_value;
		}
		$response_data = $validation->do_validation( $order, $token, $saved_token, $token_check_box_value, $auth_id, $pareq, $sca_case );

		return $response_data;
	}

		/**
		 * Initiates the Add Payment Method flow.
		 *
		 * @return array
		 */
	public function add_payment_method() {
		return $this->plugin_public->add_payment_method();
	}

		/**
		 * Initiates the token delete process.
		 *
		 * @param array $core_token_id core token id.
		 * @param array $core_token core token.
		 *
		 * @return array
		 */
	public function init_payment_token_deleted( $core_token_id, $core_token ) {
		$payment_method = new Visa_Acceptance_Payment_Methods( $this );
		$response       = $payment_method->delete_token_from_gateway( $core_token_id, $core_token );
		return $response;
	}
}
