<?php
/**
 * Paypay gateway class file for Credit Card.
 *
 * @package PayPay
 */

defined( 'ABSPATH' ) || exit;

use PayPay\Structure\RequestCreditCardPayment;
use PayPay\Structure\RequestPaymentOrder;
use PayPay\Structure\RequestReferenceDetails;
use PayPay\Structure\RequestWebhook;
use PayPay\Structure\RequestBuyerInfo;
use PayPay\Structure\RequestBillingAddress;
use PayPay\Structure\RequestShippingAddress;
use PayPay\Configuration;
use PayPay\PayPayWebservice;
use PayPay\WebhookHandler;
use PayPay\Exception\Webhook as ExceptionWebhook;

require_once dirname( __FILE__ ) . '/../vendor/autoload.php';

/**
 * Paypay class.
 */
class Paypay extends WC_Payment_Gateway {

	const TESTING    = 1;
	const PRODUCTION = 2;
	const ID         = 'paypay';

	/**
	 * Constructor responsible for:
	 * - Setting the general variables;
	 * - Initializing those settings;
	 * - Setting the URL to which the requests should be sent;
	 * - Subscribing to the webhook;
	 * - Checking the pending payments.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'PayPay', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' );
		$this->method_description = __( 'PayPay Plug-in for WooCommerce', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' );
		$this->title              = __( 'PayPay', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' );
		$this->icon               = null;
		$this->has_fields         = false;
		$this->paypay_method_code = '';
		$this->init_form_fields();
		$this->init_settings();

		if ( is_admin() ) {
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		}
	}

	/**
	 * Configuration setup to authenticate in PayPay Webservice
	 *
	 * @return PayPay\Configuration
	 */
	private function getPayPayConfig() {
		global $wp_version;
		global $woocommerce;

		return Configuration::setup(
			array(
				'environment'  => $this->getEnvironment(),
				'platformCode' => $this->settings['platformCode'],
				'privateKey'   => $this->settings['hash'],
				'clientId'     => $this->settings['nif'],
				'langCode'     => $this->getLangCode(),
				'platformInfo' => array(
					'Wordpress'   => $wp_version,
					'WooCommerce' => $woocommerce->version,
				),
			)
		);
	}

	/**
	 * SOAP client for PayPay Webservice
	 *
	 * @return PayPay\PayPayWebservice
	 */
	protected function getSoapClient() {
		return PayPayWebservice::init( $this->getPayPayConfig() );
	}

	/**
	 * Environment according plugin settings
	 *
	 * @return string
	 */
	private function getEnvironment() {
		if ( self::PRODUCTION === (int) $this->settings['environment'] ) {
			return 'production';
		}

		if ( defined( 'PAYPAY_WEBSERVICE_URL' ) ) {
			return 'development';
		}

		return 'testing';
	}

	/**
	 * Shop Language
	 *
	 * @return string
	 */
	private function getLangCode() {
		return strtoupper( substr( get_bloginfo( 'language' ), 0, 2 ) );
	}

	/**
	 * Add the available payment gateway according to the amount
	 *
	 * @param array $checkout_gateways available checkout gateways in shop.
	 *
	 * @throws \Exception Error in payment reference validation.
	 *
	 * @return array
	 */
	public function addPaymentGatewaysTo( &$checkout_gateways ) {
		$payment_webservice = $this->getSoapClient();

		$amount = number_format( $this->get_order_total(), 2, '', '' );

		$request_reference_details = new RequestReferenceDetails(
			array(
				'amount' => $amount,
			)
		);

		try {
			$response = $payment_webservice->validatePaymentReference( $request_reference_details );
		} catch ( \Exception $ex ) {
			PaypayLogger::log( 'error', $ex->getMessage() );
		}

		foreach ( $response->paymentOptions as $payment_option ) { // phpcs:ignore
			$paypay_gateway                     = $this->getClassForMethod( $payment_option->code );
			$paypay_gateway->id                 = 'paypay_' . strtolower( $payment_option->code );
			$paypay_gateway->title              = $payment_option->name;
			$paypay_gateway->method_title       = $payment_option->name;
			$paypay_gateway->method_description = $payment_option->name;
			$paypay_gateway->paypay_method_code = $payment_option->code;
			$paypay_gateway->icon               = $payment_option->iconUrl; // phpcs:ignore
			$paypay_gateway->description        = $payment_option->description;

			$checkout_gateways[ $paypay_gateway->id ] = $paypay_gateway;
		}

		return $checkout_gateways;
	}

	/**
	 * Create payment gateway instance according the payment code
	 *
	 * @param string $code method code.
	 * @return mixed
	 */
	private function getClassForMethod( $code ) {
		$method_class_file = dirname( __FILE__ ) . '/class-paypay' . strtolower( $code ) . '.php';

		if ( file_exists( $method_class_file ) ) {
			include_once $method_class_file;
			$class_name = 'PayPay' . strtoupper( $code );

			return new $class_name();
		}

		return new PayPay();
	}

	/**
	 * Processes the payment communicated through the webhook
	 *
	 * @param array $request payments.
	 *
	 * @throws ExceptionWebhook Error in webhook callback.
	 *
	 * @return void
	 */
	public function webhookCallback( $request ) {
		try {
			$webhook      = new WebhookHandler( $this->getPayPayConfig(), $request );
			$status_codes = array();

			$webhook->eachPayment(
				function( $payment ) use ( &$status_codes, $request ) {
					$handler        = $this->getHandlerFor( $request['hookAction'] );
					$status_codes[] = $this->{$handler}( $payment );
				}
			);

			rsort( $status_codes );
			status_header( $status_codes[0] );
		} catch ( ExceptionWebhook $e ) {
			PaypayLogger::log( 'error', $e->getMessage() );

			status_header( $e->getCode() );
		}
	}

	/**
	 * Webhook Handler according request action
	 *
	 * @param string $action webhook action.
	 *
	 * @throws InvalidArgumentException Invalid action.
	 *
	 * @return string
	 */
	private function getHandlerFor( $action ) {
		switch ( $action ) {
			case 'payment_confirmed':
				return 'webhookPaymentsConfirmed';

			case 'payment_expired':
				return 'webhookPaymentsExpired';

			case 'payment_cancelled':
				return 'webhookPaymentsCancelled';

			default:
				throw new InvalidArgumentException( 'Invalid webhook action in webhookCallback' );
		}
	}

	/**
	 * Processes the payment communicated through the webhook
	 *
	 * @param array $payment payment data.
	 * @return int
	 */
	private function webhookPaymentsExpired( $payment ) {
		global $wpdb;

		$res = $wpdb->get_row( $wpdb->prepare( 'SELECT id_order, paid, comment_id FROM paypay_reference WHERE id_transaction = %d', (int) $payment['paymentId'] ) ); // WPCS: cache ok, db call ok.

		if ( ! isset( $res ) ) {
			$res = $wpdb->get_row( $wpdb->prepare( 'SELECT id_order, paid, comment_id FROM paypay_payment WHERE id_transaction = %d', (int) $payment['paymentId'] ) ); // WPCS: cache ok, db call ok.
		}

		if ( ! isset( $res ) ) {
			PaypayLogger::log( 'error', 'WebhookPaymentsExpired -> Webhook Payment Order not found ' . $payment['paymentId'] );

			return 400;
		}

		if ( 0 !== (int) $res->paid ) {
			PaypayLogger::log( 'warning', 'WebhookPaymentsExpired -> Order already processed/paid/cancelled {state=' . $res->paid . ', paymentId=' . $payment['paymentId'] . '}' );

			return 200;
		}

		try {
			$customer_order = new WC_Order( $res->id_order );
			$is_order_cancelled = $this->cancelOrderPayment( $customer_order, $this->getNoteByType( 2 ) );

			if ( $is_order_cancelled ) {
				$this->addCustomerNote( $res->id_order, 2 );
			}
		} catch (\Exception $ex) {
			PaypayLogger::log( 'error', 'WebhookPaymentsExpired -> ' . $ex->getMessage() . ' (paymentId: ' . $payment['paymentId'] . ', orderId: ' . $res->id_order . ')' );

			return 400;
		}

		return 200;
	}

	/**
	 * Processes the payment communicated through the webhook
	 *
	 * @param array $payment payment data.
	 * @return int
	 */
	private function webhookPaymentsConfirmed( $payment ) {
		global $wpdb;

		PaypayLogger::log( 'debug', 'WebhookPaymentsConfirmed -> Payment Received ' . implode( '||', $payment ) );

		$res = $wpdb->get_row( $wpdb->prepare( 'SELECT id_order, paid, comment_id FROM paypay_reference WHERE id_transaction = %d', (int) $payment['paymentId'] ) ); // WPCS: cache ok, db call ok.

		if ( ! isset( $res ) ) {
			$res = $wpdb->get_row( $wpdb->prepare( 'SELECT id_order, paid, comment_id FROM paypay_payment WHERE id_transaction = %d', (int) $payment['paymentId'] ) ); // WPCS: cache ok, db call ok.
		}

		if ( ! isset( $res ) ) {
			PaypayLogger::log( 'warning', 'WebhookPaymentsConfirmed -> Payment Order not found {paymentId=' . $payment['paymentId'] . '}' );

			return 400;
		}

		try {
			$customer_order = new WC_Order( $res->id_order );
			$this->confirmOrderPayment( $customer_order, $payment['paymentId'], $payment['paymentDate'] );
		} catch ( Exception $ex ) {
			PaypayLogger::log( 'error', 'WebhookPaymentsConfirmed -> ' . ' (paymentId: ' . $payment['paymentId'] . ', orderId: ' . $res->id_order . ')' );

			return 400;
		}

		return 200;
	}

	/**
	 * Processes the payment communicated through the webhook
	 *
	 * @param array $payment payment data.
	 * @return int
	 */
	private function webhookPaymentsCancelled( $payment ) {
		global $wpdb;

		$res = $wpdb->get_row( $wpdb->prepare( 'SELECT id_order, paid, comment_id FROM paypay_reference WHERE id_transaction = %d', (int) $payment['paymentId'] ) ); // WPCS: cache ok, db call ok.

		if ( ! isset( $res ) ) {
			$res = $wpdb->get_row( $wpdb->prepare( 'SELECT id_order, paid, comment_id FROM paypay_payment WHERE id_transaction = %d', (int) $payment['paymentId'] ) ); // WPCS: cache ok, db call ok.
		}

		if ( ! isset( $res ) ) {
			PaypayLogger::log( 'error', 'WebhookPaymentsCancelled -> Webhook Payment Order not found {paymentId=' . $payment['paymentId'] . '}' );

			return 400;
		}

		if ( 0 !== (int) $res->paid ) {
			PaypayLogger::log( 'warning', 'WebhookPaymentsCancelled -> Order already processed/paid/cancelled {state=' . $res->paid . ', paymentId=' . $payment['paymentId'] . '}' );

			return 200;
		}


		try {
			$customer_order = new WC_Order( $res->id_order );
			$this->cancelOrderPayment( $customer_order, $this->getNoteByType( 0 ) );
		} catch (\Exception $ex) {
			PaypayLogger::log( 'error', 'WebhookPaymentsCancelled -> ' . $ex->getMessage() . ' (paymentId: ' . $payment['paymentId'] . ', orderId: ' . $res->id_order . ')' );

			return 400;
		}

		return 200;
	}

	/**
	 * Validates if the purchase amount is equal to the payment
	 *
	 * @param \WC_Order $customer_order order data.
	 * @param string    $payment_amount paymeny amount.
	 * @return boolean
	 */
	private function isValidPaymentAmount( $customer_order, $payment_amount ) {
		$order_amount = number_format( $customer_order->get_total(), 2, '', '' );

		return (int) $order_amount === (int) $payment_amount;
	}

	/**
	 * Confirm payment
	 *
	 * @param \WC_Order $customer_order order data.
	 * @param string    $payment_id payment id.
	 * @param string    $payment_date payment date.
	 * @return boolean
	 */
	private function confirmOrderPayment( $customer_order, $payment_id, $payment_date ) {
		global $wpdb;

		$order_id = $customer_order->get_id();

		$wpdb->query( $wpdb->prepare( 'UPDATE paypay_reference SET paid = 1 WHERE id_order = %d', $order_id ) ); // WPCS: cache ok, db call ok.
		$wpdb->query( $wpdb->prepare( 'UPDATE paypay_payment SET paid = 1 WHERE id_order = %d', $order_id ) ); // WPCS: cache ok, db call ok.

		if ( ! $customer_order->needs_payment() ) {
			PaypayLogger::log( 'warning', 'Order already processed/paid/cancelled {orderId=' . $order_id . ', paymentId=' . $payment_id . '}' );
		}

		$server_timezone  = new DateTimeZone( 'Europe/London' );
		$payment_datetime = new DateTime( '@' . strtotime( $payment_date ), $server_timezone );

		$customer_order->set_date_paid( $payment_datetime->format( DateTime::ATOM ) );
		return $customer_order->payment_complete( $payment_id );
	}

	/**
	 * Cancels the payment and updates the respective order if applicable.
	 *
	 * @param \WC_Order $customer_order order data.
	 * @param string    $note order comment.
	 * @return boolean
	 */
	private function cancelOrderPayment( $customer_order, $note = '' ) {
		global $wpdb;

		$order_id = $customer_order->get_id();

		$wpdb->query( $wpdb->prepare( 'UPDATE paypay_reference SET paid = 2 WHERE id_order = %d', $order_id ) ); // WPCS: cache ok, db call ok.
		$wpdb->query( $wpdb->prepare( 'UPDATE paypay_payment SET paid = 2 WHERE id_order = %d', $order_id ) ); // WPCS: cache ok, db call ok.

		if ( ! $customer_order->is_paid() ) {
			return $customer_order->update_status( 'cancelled', $note );
		}

		return false;
	}

	/**
	 * Cancels the payment and redirects
	 *
	 * @param int $order_id order identificator.
	 * @return void
	 */
	public function failureCallback( $order_id ) {
		$customer_order = new WC_Order( $order_id );
		$this->cancelOrderPayment( $customer_order, $this->getNoteByType( 3 ) );

		// Redirects the user to the order he cancelled.
		wp_safe_redirect( $this->getReturnUrlFromCustomerOrder( $customer_order ) );
	}

	/**
	 * Processes the admin options inserted by the store admin and checks the integration status.
	 *
	 * @return mixed Processed options if integration is successful, false otherwise.
	 */
	public function process_admin_options() {
		$this->init_settings();

		$payments_check_is_checked = $this->get_field_value( 'checkPayments', 'checkbox' );

		parent::process_admin_options();

		if ( ! $this->validateSettings() ) {
			return false;
		}

		try {
			$this->subscribeWebhooks();
		} catch ( Exception $ex ) {
			WC_Admin_Settings::add_error( $ex->getMessage() );
			return false;
		}

		if ( ! $payments_check_is_checked ) {
			return true;
		}

		$response = $this->checkPayments();
		if ( 0 === $response ) {
			WC_Admin_Settings::add_message( __( 'No payment was processed.', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ) );
		} else {
			WC_Admin_Settings::add_message( sprintf( __( 'Some payments were processed.', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ), $response ) );
		}

		return true;
	}

	/**
	 * Posted data, used to save settings
	 *
	 * @return array
	 */
	public function get_post_data() {
		$post_data = parent::get_post_data();

		unset( $post_data[ $this->get_field_key( 'checkPayments' ) ] );

		return $post_data;
	}

	/**
	 * Subscribe webhooks
	 *
	 * @throws Exception Subscribe webhook error.
	 *
	 * @return void
	 */
	public function subscribeWebhooks() {
		global $wpdb;

		$success = false;

		$payment_webservice = $this->getSoapClient();

		// The url is the home page because it is on the homepage that the payment is checked.
		$url = get_site_url() . '/?wc-api=paypay_webhook';

		$actions_to_subscribe = array(
			'payment_confirmed',
			'payment_expired',
			'payment_cancelled',
		);

		foreach ( $actions_to_subscribe as $action ) {
			try {
				$request_webhook = new RequestWebhook(
					array(
						'action' => $action,
						'url'    => $url,
					)
				);

				$response = $payment_webservice->subscribeToWebhook( $request_webhook );
				if ( $response->integrationState->state ) { // phpcs:ignore

					$wpdb->insert(
						'paypay_config',
						array(
							'hooked' => 1,
							'action' => $action,
							'url'    => $url,
							'nif'    => $this->settings['nif'],
						),
						array(
							'%d',
							'%s',
							'%s',
							'%s',
						)
					); // WPCS: cache ok, db call ok.

					$success = true;
				}
			} catch ( Exception $e ) {
				PaypayLogger::log( 'error', 'Your credentials are not correct. Please, insert the correct credentials and try again.' );
				throw new Exception( __( 'Your credentials are not correct. Please, insert the correct credentials and try again.', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ) );
			}

			if ( true !== $success ) {
				PaypayLogger::log( 'error', $response->integrationState->message ); // phpcs:ignore
				throw new Exception( $response->integrationState->message ); // phpcs:ignore
			}
		}
	}

	/**
	 * Checks the pending payments to check if they were already paid.
	 *
	 * @return int Number of payments checked
	 */
	private function checkPayments() {
		$result = $this->checkEntityPayments();

		return ( $result['paid'] + $result['cancelled'] );
	}

	/**
	 * Checks the MB payments.
	 *
	 * @return int Result of the payment check
	 */
	private function checkEntityPayments() {
		global $wpdb;

		$payment_webservice = $this->getSoapClient();

		$pending_mb_payments = $wpdb->get_results( "SELECT id_transaction as paymentId, id_order FROM paypay_reference WHERE paid = '0' GROUP BY id_order" ); // WPCS: cache ok, db call ok.
		$pending_cc_payments = $wpdb->get_results( "SELECT id_transaction as paymentId, id_order FROM paypay_payment WHERE paid = '0' GROUP BY id_order" ); // WPCS: cache ok, db call ok.

		if ( count( $pending_mb_payments ) === 0 && count( $pending_cc_payments ) === 0 ) {
			return array(
				'paid'      => 0,
				'cancelled' => 0,
			);
		}

		$pending_payments = array_merge( $pending_mb_payments, $pending_cc_payments );

		$payment_order_data = array();
		foreach ( $pending_payments as $index => $payment ) {
			$payment_order_data[ (int) $payment->paymentId ] = $payment->id_order; // phpcs:ignore

			unset( $pending_payments[ $index ]->id_order );

			$pending_payments[ $index ] = (array) $payment;
		}

		$response = $payment_webservice->checkEntityPayments( $pending_payments );

		if ( empty( $response->payments ) ) {
			return array(
				'paid'      => 0,
				'cancelled' => 0,
			);
		}

		$paid      = 0;
		$cancelled = 0;
		foreach ( $response->payments as $index => $payment ) {
			$payment_id = $payment->paymentId; // phpcs:ignore
			$payment_state = $payment->paymentState; // phpcs:ignore
			$payment_cancelled = $payment->paymentCancelled; // phpcs:ignore
			$payment_amount = $payment->paymentAmount; // phpcs:ignore
			$payment_date = $payment->paymentDate; // phpcs:ignore

			// Não foi encontrado o pagamento.
			if ( empty( $payment_state ) && '0062' === $payment->code ) {
				$id_transaction = $pending_payments[ $index ]['paymentId'];
				WC_Admin_Settings::add_error( sprintf( __( 'Payment was not found. ', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ), $id_transaction ) );
				$this->markInvalidPayment( $id_transaction );
				continue;
			}

			$order_id = $payment_order_data[ (int) $payment_id ];

			try {
				$wc_order = new WC_Order( $order_id );
			} catch (\Exception $ex) {
				$exceptionMessage = $ex->getMessage() . ' (paymentId: ' . $payment_id . ', orderId: ' . $order_id . ')';
				WC_Admin_Settings::add_error( $exceptionMessage );
				PaypayLogger::log( 'warning', $exceptionMessage ); // phpcs:ignore

				continue;
			}

			if ( 0 === (int) $payment_state && 0 === (int) $payment_cancelled ) {
				add_action(
					'woocommerce_sections_checkout',
					function() use ( $wc_order ) {
						$order_anchor = $wc_order->get_order_number();

						if ( method_exists( $wc_order, 'get_edit_order_url' ) ) {
							$order_anchor = sprintf( __( 'Order admin anchor link. ', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ), $wc_order->get_edit_order_url(), $wc_order->get_order_number() );
						}

						echo wp_kses_post( sprintf( __( 'Order payment is pending. ', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ), $order_anchor ) );
					}
				);
				continue;
			}

			if ( 1 !== (int) $payment_state ) {
				continue;
			}

			if ( ! $this->isValidPaymentAmount( $wc_order, $payment_amount ) ) {
				WC_Admin_Settings::add_error( sprintf( __( 'Payment amount differs from order. ', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ), $payment_id, $order_id ) );
				$this->markInvalidPayment( $payment_id );
				continue;
			}

			if ( ! $this->confirmOrderPayment( $wc_order, $payment_id, $payment_date ) ) {
				continue;
			}

			add_action(
				'woocommerce_sections_checkout',
				function() use ( $wc_order ) {
					$order_anchor = $wc_order->get_order_number();

					if ( method_exists( $wc_order, 'get_edit_order_url' ) ) {
						$order_anchor = sprintf( __( 'Order admin anchor link. ', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ), $wc_order->get_edit_order_url(), $wc_order->get_order_number() );
					}

					echo wp_kses_post( sprintf( __( 'Order payment has been confirmed. ', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ), $order_anchor ) );
				}
			);

			$paid++;
		}

		foreach ( $response->payments as $payment ) {
			$payment_id = $payment->paymentId; // phpcs:ignore
			$payment_state = $payment->paymentState; // phpcs:ignore
			$payment_cancelled = $payment->paymentCancelled; // phpcs:ignore

			if ( 1 === (int) $payment_state || 0 === (int) $payment_cancelled ) {
				continue;
			}

			$order_id = $payment_order_data[ (int) $payment_id ];

			try {
				$wc_order = new WC_Order( $order_id );
			} catch (\Exception $ex) {
				$exceptionMessage = $ex->getMessage() . ' (paymentId: ' . $payment_id . ', orderId: ' . $order_id . ')';
				WC_Admin_Settings::add_error( $exceptionMessage );
				PaypayLogger::log( 'warning', $exceptionMessage ); // phpcs:ignore

				continue;
			}

			if ( ! $this->cancelOrderPayment( $wc_order ) ) {
				continue;
			}

			add_action(
				'woocommerce_sections_checkout',
				function() use ( $wc_order ) {
					$order_anchor = $wc_order->get_order_number();

					if ( method_exists( $wc_order, 'get_edit_order_url' ) ) {
						$order_anchor = sprintf( __( 'Order admin anchor link. ', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ), $wc_order->get_edit_order_url(), $wc_order->get_order_number() );
					}

					echo wp_kses_post( sprintf( __( 'Order payment has been cancelled. ', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ), $order_anchor ) );
				}
			);

			$cancelled++;
		}

		return array(
			'paid'      => $paid,
			'cancelled' => $cancelled,
		);
	}

	/**
	 * Marks the payment as invalid.
	 *
	 * @param int $id_transaction transaction id.
	 * @return void
	 */
	private function markInvalidPayment( $id_transaction ) {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( "UPDATE paypay_reference SET paid = '-1' WHERE id_transaction = %d", array( $id_transaction ) ) ); // WPCS: cache ok, db call ok.
		$wpdb->query( $wpdb->prepare( "UPDATE paypay_payment SET paid = '-1' WHERE id_transaction = %d", array( $id_transaction ) ) ); // WPCS: cache ok, db call ok.
	}

	/**
	 * Sets the fields present in the settings of the plugin
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'       => array(
				'title'    => esc_html__( 'Activate / Deactivate', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ),
				'label'    => esc_html__( 'Activates the payment gateway', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ),
				'type'     => 'checkbox',
				'desc_tip' => esc_html__( 'Activates the payment gateway', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ),
				'default'  => 'no',
			),
			'nif'           => array(
				'title'    => esc_html__( 'NIF', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ),
				'type'     => 'text',
				'desc_tip' => esc_html__( 'NIF that is associated to you PayPay account.', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ),
				'default'  => '510542700',
				'css'      => 'max-width:175px;',
			),
			'platformCode'  => array(
				'title'    => esc_html__( 'Platform Code', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ),
				'type'     => 'text',
				'desc_tip' => esc_html__( 'Platform Code that should be requested from the PayPay support team.', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ),
				'default'  => '0009',
				'css'      => 'max-width:175px;',
			),
			'hash'          => array(
				'title'    => esc_html__( 'Encription Key', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ),
				'type'     => 'password',
				'desc_tip' => esc_html__( 'Encription Key that should be requested from the PayPay support team.', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ),
				'default'  => '4F16A63E4ABA1',
			),
			'environment'   => array(
				'title'    => esc_html__( 'Environment', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ),
				'type'     => 'select',
				'desc_tip' => esc_html__( 'Environment that should be used.', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ),
				'css'      => 'min-width:175px;',
				'options'  => array(
					'1' => esc_html__( 'Test', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ),
					'2' => esc_html__( 'Production', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ),
				),
			),
			'checkPayments' => array(
				'title'    => __( 'Force update?', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ),
				'label'    => __( 'Update pending payments', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ),
				'type'     => 'checkbox',
				'desc_tip' => __( 'Use when order status has not updated automatically.', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ),
				'default'  => 'no',
			),
		);
	}

	/**
	 * Validate settings field
	 *
	 * @return boolean
	 */
	private function validateSettings() {
		$settings = $this->settings;

		$has_errors = false;

		if ( empty( $settings['nif'] ) ) {
			WC_Admin_Settings::add_error( __( 'NIF cannot be empty', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ) );
			$has_errors = true;
		} elseif ( ! self::validateNIF( $settings['nif'] ) ) {
			WC_Admin_Settings::add_error( __( 'NIF is not valid', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ) );
			$has_errors = true;
		}

		if ( empty( $settings['platformCode'] ) ) {
			WC_Admin_Settings::add_error( __( 'Platform Code cannot be empty', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ) );
			$has_errors = true;
		}

		if ( empty( $settings['hash'] ) ) {
			WC_Admin_Settings::add_error( __( 'Encryption Key cannot be empty', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ) );
			$has_errors = true;
		}

		return false === $has_errors;
	}

	/**
	 * Validate NIF
	 *
	 * @param string $nif nif number.
	 * @return boolean
	 */
	private static function validateNIF( $nif ) {
		$nif                  = trim( $nif );
		$nif_split            = str_split( $nif );
		$nif_primeiros_digito = array( 1, 2, 3, 5, 6, 7, 8, 9 );

		if ( is_numeric( $nif ) && 9 === strlen( $nif ) && in_array( (int) $nif_split[0], $nif_primeiros_digito, true ) ) {
			$check_digit = 0;
			for ( $i = 0; $i < 8; $i++ ) {
				$check_digit += $nif_split[ $i ] * ( 10 - $i - 1 );
			}
			$check_digit = 11 - ( $check_digit % 11 );
			$check_digit = $check_digit >= 10 ? 0 : $check_digit;
			if ( $check_digit === (int) $nif_split[8] ) {
				return true;
			}
		}
		return false;

	}

	/**
	 * Processes the payments by performing the requests to the PayPay.
	 *
	 * @param integer $order_id ID of the order to be processed.
	 * @return array Result of the processment and return URL
	 */
	public function process_payment( $order_id ) {
		global $woocommerce;

		// Loads the data relative to the order.
		// This data is loaded automatically by passing the order_id.
		$customer_order = new WC_Order( $order_id );
		try {
			$payment = $this->generateCC( $customer_order );
		} catch ( Exception $ex ) {
			PaypayLogger::log( 'error', $ex->getMessage() );

			return array(
				'result'   => 'failed',
				'redirect' => $this->get_return_url( $customer_order ),
			);
		}

		$customer_order->update_status( 'on-hold', __( 'Awaiting Payment', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ) );

		// Remove cart.
		$woocommerce->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $payment['url'],
		);
	}

	/**
	 * Returns the layout to show the reference / link to the payment.
	 *
	 * @param int   $method    Method of the payment.
	 * @param array $payment Data relative to the payment.
	 * @return string HTML relative to the layout
	 */
	protected function getPaymentLayout( $method, $payment ) {
		if ( 1 === $method ) {
			$html  = esc_html__( 'You can use the following information to pay your order in an ATM.', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' );
			$html .= '<table cellpadding="3" cellspacing="0" style="padding: 5px 0 20px 0; border: 0; margin: 0;">';
			$html .= '	<tr>';
			$html .= '		<td style="font-weight:bold; text-align:left; padding: 15px 0 0 0; border: 0;">' . esc_html__( 'Entity:', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ) . '</td>';
			$html .= '		<td style="padding: 15px 0 0 0; border: 0;">' . $payment['atmEntity'] . '</td>';
			$html .= '	</tr>';
			$html .= '	<tr>';
			$html .= '	    <td style="font-weight:bold; text-align:left; padding: 5px 0 0 0; border: 0;">' . esc_html__( 'Reference:', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ) . '</td>';
			$html .= '		<td style="padding: 5px 0 0 0; border: 0;">' . chunk_split( $payment['reference'], 3, ' ' ) . '</td>';
			$html .= '	</tr>';
			$html .= '	<tr>';
			$html .= '		<td style="font-weight:bold; text-align:left; padding: 5px 0 0 0; border: 0;">' . esc_html__( 'Amount:', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ) . '</td>';
			$html .= '		<td style="padding: 5px 0 0 0; border: 0;">' . wc_price( $payment['amount'] ) . '</td>';
			$html .= '	</tr>';
			$html .= '	<tr>';
			$html .= '		<td style="font-weight:bold; text-align:left; padding: 5px 10px 0 0; border: 0;">' . esc_html__( 'Expiration date', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ) . ':</td>';
			$html .= '		<td style="padding: 5px 0 0 0; border: 0;">' . (
				sprintf(
					'%1$s ' . esc_html__( 'At.', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ) . ' %2$s',
					date_i18n( wc_date_format(), strtotime( $payment['expirationDate'] ) ),
					date_i18n( wc_time_format(), strtotime( $payment['expirationDate'] ) )
				)
			) . '</td>';
			$html .= '	</tr>';
			$html .= '</table>';

			return $html;
		}

		if ( 2 === $method ) {
			return esc_html__( 'Click', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ) . ' <a href="' . esc_url( $payment['url'] ) . '">' . esc_html__( 'here', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ) . '</a> ' . esc_html__( 'to pay your order.', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' );
		}

		return '';
	}

	/**
	 * Order comments
	 *
	 * @param int $order_id order id.
	 * @return mixed
	 */
	public function getPayPayOrderNote( $order_id ) {
		global $wpdb;

		$res = $wpdb->get_row( $wpdb->prepare( 'SELECT comment_id FROM paypay_reference WHERE id_order = %d', array( $order_id ) ) ); // WPCS: cache ok, db call ok.

		if ( ! isset( $res ) ) {
			$res = $wpdb->get_row( $wpdb->prepare( 'SELECT comment_id FROM paypay_payment WHERE id_order = %d', array( $order_id ) ) ); // WPCS: cache ok, db call ok.
		}

		if ( ! isset( $res ) ) {
			return false;
		}

		return get_comment( $res->comment_id );
	}

	/**
	 * Add order comments
	 *
	 * @param int    $order_id order id.
	 * @param string $type message type.
	 * @return mixed
	 */
	private function addCustomerNote( $order_id, $type ) {
		$paypay_order_note = $this->getPayPayOrderNote( $order_id );

		if ( false === $paypay_order_note ) {
			return false;
		}

		// Removes previous comment and adds the new thank you comment.
		wp_delete_comment( $paypay_order_note->comment_ID );

		$customer_order = new WC_Order( $order_id );
		return $customer_order->add_order_note( $this->getNoteByType( $type ), 1, true );
	}

	/**
	 * Returns the layout to show the thank you sentence.
	 *
	 * @param  boolean $success Whether the message is positive or not.
	 * @return string HTML relative to the layout
	 */
	private function getNoteByType( $success ) {
		switch ( $success ) {
			case 3:
				$message = __( 'Payment cancelled by customer. ', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' );
				break;
			case 2:
				$message = __( 'Unpaid order cancelled – time limit reached.', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' );
				break;
			case 1:
				$message = __( 'Thank you for your payment. Your order will be processed as soon as possible.', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' );
				break;
			default:
				$message = __( 'Your order was cancelled. Please, contact the store owner.', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' );
				break;
		}

		return $message;
	}

	/**
	 * Generates the CC payment data.
	 *
	 * @param WC_Order $customer_order  Order object.
	 * @return array Payment data
	 */
	protected function generateCC( $customer_order ) {
		$payment_webservice = $this->getSoapClient();

		$order_id = $customer_order->get_id();

		$amount = number_format( $this->get_order_total(), 2, '', '' );

		$billing_data  = array();
		$shipping_data = array();

		// Get Billing State.
		if ( $customer_order->has_billing_address() ) {
			$billing = $customer_order->get_address( 'billing' );

			// Get the Billing State ISO 3166-2 code when is a State from US.
			$billing_state_iso = $customer_order->get_billing_state();

			// Get Billing State Name from the Country State.
			$country            = $customer_order->get_billing_country();
			$billing_state_name = WC()->countries->get_states( $country );

			if ( $billing_state_name ) {
				$billing_state_name = $billing_state_name[ $billing_state_iso ];
			} else {
				$billing_state_name = $billing_state_iso;
			}

			$billing_data = array(
				'country'   => $billing['country'],
				'state'     => $billing_state_iso,
				'stateName' => $billing_state_name,
				'city'      => $billing['city'],
				'street1'   => $billing['address_1'],
				'street2'   => $billing['address_2'],
				'postCode'  => $billing['postcode'],
			);
		}

		// Get Shipping State.
		if ( $customer_order->has_shipping_address() ) {
			$shipping = $customer_order->get_address( 'shipping' );

			// Get Shipping State ISO 3166-2 code when is a State from US.
			$shipping_state_iso = $customer_order->get_shipping_state();

			// Get Shipping State Name from the Country State.
			$country             = $customer_order->get_shipping_country();
			$shipping_state_name = WC()->countries->get_states( $country );

			if ( $shipping_state_name ) {
				$shipping_state_name = $shipping_state_name[ $shipping_state_iso ];
			} else {
				$shipping_state_name = $shipping_state_iso;
			}

			$shipping_data = array(
				'country'   => $shipping['country'],
				'state'     => $shipping_state_iso,
				'stateName' => $shipping_state_name,
				'city'      => $shipping['city'],
				'street1'   => $shipping['address_1'],
				'street2'   => $shipping['address_2'],
				'postCode'  => $shipping['postcode'],
			);
		}

		$request_credit_card_payment = new RequestCreditCardPayment(
			new RequestPaymentOrder(
				array(
					'amount'      => $amount,
					'productCode' => $customer_order->get_order_number(),
				)
			),
			$this->getReturnUrlFromCustomerOrder( $customer_order ),
			get_site_url() . '/?wc-api=paypay_cancel&order_id=' . $order_id,
			$this->paypay_method_code,
			new RequestBuyerInfo(
				array(
					'lastName'   => $customer_order->get_billing_last_name(),
					'firstName'  => $customer_order->get_billing_first_name(),
					'customerId' => $customer_order->get_user_id(),
					'email'      => $customer_order->get_billing_email(),
				)
			),
			! empty( $billing_data ) ?
				new RequestBillingAddress( $billing_data ) : null,
			! empty( $shipping_data ) ?
				new RequestShippingAddress( $shipping_data ) : null
		);

		$request_credit_card_payment->returnUrlBack = $this->getReturnUrlFromCustomerOrder( $customer_order ); // phpcs:ignore

		$response = $payment_webservice->doWebPayment( $request_credit_card_payment );

		$id_transaction = $response->idTransaction; // phpcs:ignore

		$payment = array(
			'url'           => $response->url,
			'idTransaction' => $id_transaction,
			'token'         => $response->token,
			'paid'          => '0',
		);

		$customer_order->set_transaction_id( $id_transaction );

		$comment_id            = $customer_order->add_order_note( $this->getPaymentLayout( 2, $payment ), 0, true );
		$payment['comment_id'] = $comment_id;

		$this->saveCC( $order_id, $payment );

		return $payment;
	}

	/**
	 * Saves the data relative to the CC payments
	 *
	 * @param  integer $order_id ID of the order.
	 * @param  array   $payment  Payment data.
	 * @return void
	 */
	protected function saveCC( $order_id, $payment ) {
		global $wpdb;

		$wpdb->insert(
			'paypay_payment',
			array(
				'id_order'        => $order_id,
				'id_transaction'  => $payment['idTransaction'],
				'redunicre_token' => $payment['token'],
				'url'             => $payment['url'],
				'history_id'      => '1',
				'paid'            => '0',
				'comment_id'      => $payment['comment_id'],
			),
			array(
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
			)
		); // WPCS: cache ok, db call ok.

		$wpdb->insert(
			'paypay_payment_type',
			array(
				'id_order'     => $order_id,
				'payment_type' => '1',
			),
			array(
				'%d',
				'%s',
			)
		); // WPCS: cache ok, db call ok.
	}

	/**
	 * Customer order URL
	 *
	 * @param WC_Order $customer_order order.
	 * @return string
	 */
	protected function getReturnUrlFromCustomerOrder( $customer_order ) {
		if ( is_user_logged_in() ) {
			return $customer_order->get_view_order_url();
		}

		return $this->get_return_url( $customer_order );
	}

	/**
	 * Log message if WC logger is available (requires woocommerce 2.7+).
	 *
	 * @param string $level log level.
	 * @param string $message log messsage.
	 * @return void
	 */
	protected function log( $level, $message ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger = wc_get_logger();

		$logger->log( $level, $message, array( 'source' => $this->id ) );
	}
}
