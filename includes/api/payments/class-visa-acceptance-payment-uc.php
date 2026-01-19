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

/**
 * Include all the necessary dependencies.
 */
require_once __DIR__ . '/../class-visa-acceptance-request.php';
require_once __DIR__ . '/../class-visa-acceptance-api-client.php';
require_once __DIR__ . '/../request/payments/class-visa-acceptance-authorization-request.php';
require_once __DIR__ . '/../request/payments/class-visa-acceptance-payment-adapter.php';
require_once __DIR__ . '/../response/payments/class-visa-acceptance-authorization-response.php';
require_once __DIR__ . '/class-visa-acceptance-auth-reversal.php';
require_once __DIR__ . '/../../class-visa-acceptance-payment-gateway-subscriptions.php';
require_once plugin_dir_path( __DIR__ ) . '/../../public/class-visa-acceptance-payment-gateway-unified-checkout-public.php';
use CyberSource\Api\PaymentsApi;

/**
 * Visa Acceptance Unified Checkout Authorization Class
 *
 * Handles Unified Checkout Authorization requests
 */
class Visa_Acceptance_Payment_UC extends Visa_Acceptance_Request {

	use Visa_Acceptance_Payment_Gateway_Admin_Trait;

	/**
	 * Gateway object
	 *
	 * @var object $gateway */
	public $gateway;

	/**
	 * PaymentUC constructor.
	 *
	 * @param object $gateway object.
	 */
	public function __construct( $gateway ) {
		parent::__construct( $gateway );
		$this->gateway = $gateway;
	}

	/**
	 * Initiates Unified Checkout transaction
	 *
	 * @param \WC_Order $order order object.
	 * @param string    $transient_token Transient token.
	 * @param string    $is_save_card Represents yes/no.
	 *
	 * @return array
	 */
	public function do_transaction( $order, $transient_token, $is_save_card ) {
		try {
			return $this->do_uc_transaction( $order, $transient_token, $is_save_card );
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to initiates UC payment transaction', true );
		}
	}

	/**
	 * Handles Unified Checkout payment transaction
	 *
	 * @param \WC_Order $order order object.
	 * @param string    $transient_token Transient Token.
	 * @param string    $is_save_card Represents yes/no.
	 *
	 * @return array
	 */
	public function do_uc_transaction( $order, $transient_token, $is_save_card ) {
		$settings                                   = $this->gateway->get_gateway_settings();
		$payment_response                           = $this->get_uc_payment_response( $order, $transient_token, $is_save_card );
		if ( is_array( $payment_response )) {
			$http_code = $payment_response['http_code'];
			$payment_response_array = $this->get_payment_response_array( $http_code, $payment_response['body'], VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED );
			$status                                     = $payment_response_array['status'];
		} 
		$auth_response                              = new Visa_Acceptance_Authorization_Response( $this->gateway );
		$request 									= new Visa_Acceptance_Payment_Adapter( $this->gateway );
		$subscriptions 								= new Visa_Acceptance_Payment_Gateway_Subscriptions();
		$return_response[ VISA_ACCEPTANCE_SUCCESS ] = null;
		$return_response[ VISA_ACCEPTANCE_STRING_ERROR ] = null;
		try {
			if ( is_array( $payment_response )) {
				if ( $auth_response->is_transaction_approved( $payment_response, $payment_response_array['status'] ) ) {
				if ( $auth_response->is_transaction_status_approved( $payment_response_array['status'] ) ) {
					if ( ( VISA_ACCEPTANCE_YES === $is_save_card ) && ( VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED === $payment_response_array['status'] ) ) {
						$response = $this->save_payment_method( $payment_response );
						if ( $this->gateway->is_subscriptions_activated && ( wcs_order_contains_subscription( $order ) || wcs_order_contains_renewal( $order ) ) && $response['status'] && isset( $response['token'] ) ) {
							$subscriptions->update_order_subscription_token( $order, $response['token'] );
						}
					}
					$is_charge_transaction = VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED === $status && ( VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE === $settings['transaction_type'] || $request->check_virtual_order_enabled( $settings, $order ) );
					$transaction_type      = $is_charge_transaction ? VISA_ACCEPTANCE_CHARGE_APPROVED : VISA_ACCEPTANCE_AUTH_APPROVED;
					$this->update_order_notes( $transaction_type, $order, $payment_response_array, null );
					if ( VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED === $status ) {
						if ( $is_charge_transaction ) {
							$this->add_capture_data( $order, $payment_response_array );
							$this->update_order_notes( VISA_ACCEPTANCE_CHARGE_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING );

						} else {
							$this->add_transaction_data( $order, $payment_response_array );
							$this->update_order_notes( VISA_ACCEPTANCE_AUTHORIZE_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_ON_HOLD );
						}
					} else {
						$this->update_order_notes( VISA_ACCEPTANCE_REVIEW_MESSAGE, $order, $payment_response_array, null );
						$this->add_review_transaction_data( $order, $payment_response_array );
						$this->update_order_notes( VISA_ACCEPTANCE_REVIEW_TRANSACTION, $order, $payment_response_array, null );

					}
					$return_response[ VISA_ACCEPTANCE_SUCCESS ] = true;
				} else {
					$this->add_transaction_data( $order, $payment_response_array );
					$this->update_order_notes( VISA_ACCEPTANCE_AUTH_REJECT, $order, $payment_response_array, null );
					$this->update_order_notes( VISA_ACCEPTANCE_REJECT_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_CANCELLED );
					if ( ! $request->auth_reversal_exists( $order, $payment_response_array ) ) {
						$request->do_auth_reversal( $order, $payment_response_array );
					}
					$return_response[ VISA_ACCEPTANCE_SUCCESS ] = false;
				}
			} else {
				$return_response = $request->get_error_message( $payment_response_array, $order );
			}
			return $return_response;
			}
			
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to handles UC payment transaction', true );
		}
	}

	/**
	 * Generate payment response payload for Unified Checkout transaction
	 *
	 * @param \WC_Order $order order object.
	 * @param string    $transient_token Transient Token.
	 * @param string    $is_save_card Represents yes/no.
	 *
	 * @return array
	 */
	public function get_uc_payment_response( $order, $transient_token, $is_save_card ) {
		$settings     = $this->gateway->get_config_settings();
		$gateway_settings = $this->gateway->get_gateway_settings( $order );
		$log_header       = ( VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE === $gateway_settings['transaction_type'] ) ? ucfirst( VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE ) : VISA_ACCEPTANCE_AUTHORIZATION;
		$request          = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		$api_client       = $request->get_api_client();
		$payments_api     = new PaymentsApi( $api_client );

		// Build the payload using CyberSource SDK models.
		$processing_information_data = $request->get_processing_info( $order, $gateway_settings, $is_save_card );
		$processing_information      = new \CyberSource\Model\Ptsv2paymentsProcessingInformation( $processing_information_data );
		
		$payment_request 			 = new \CyberSource\Model\CreatePaymentRequest(
			array(
				'clientReferenceInformation' => $request->client_reference_information( $order ),
				'processingInformation'      => $processing_information,
				'tokenInformation'           => $request->get_cybersource_token_information( $transient_token ),
				'orderInformation'           => $request->get_payment_order_information( $order ),
				'deviceInformation'          => $request->get_device_information(),
				'buyerInformation'           => $request->get_payment_buyer_information( $order ),
			)
		);
		if ( VISA_ACCEPTANCE_YES === $is_save_card ) {
			$payment_request = $request->get_action_token_type( $payment_request );
		}
		if ( ! empty( $payment_request ) ) {
			$this->gateway->add_logs_data( $payment_request, true, $log_header );
			try {
				$api_response = $payments_api->createPayment( $payment_request );
				$this->gateway->add_logs_service_response( $api_response[0], $api_response[2]['v-c-correlation-id'], true, $log_header );
				$return_array = array(
					'http_code' => $api_response[1],
					'body'      => $api_response[0],
				);
				return $return_array;
			} catch ( \CyberSource\ApiException $e ) {
				$this->gateway->add_logs_header_response( array( $e->getMessage() ), true, $log_header );
			}
		}
	}
}
