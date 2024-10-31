<?php
/**
 * Paypay gateway class file for Multibanco.
 *
 * @package PayPay
 */

defined( 'ABSPATH' ) || exit;

use PayPay\Structure\RequestReferenceDetails;
use PayPay\Structure\RequestPaymentOption;
use PayPay\Structure\PaymentMethodType;

/**
 * PaypayMB class.
 */
class PaypayMB extends Paypay {

	const ID = 'paypay_mb';

	/**
	 * Constructor responsible for:
	 * - Setting the general variables;
	 * - Initializing those settings;
	 * - Showing a notice when SSL is not enabled;
	 * - Setting the URL to which the requests should be sent;
	 * - Subscribing to the webhook;
	 * - Checking the pending payments.
	 */
	public function __construct() {
		parent::__construct();

		$this->id           = self::ID;
		$this->method_title = __( 'MULTIBANCO by Paypay', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' );
		$this->description  = null;
		$this->title        = $this->method_title;
		$this->supports     = array();
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
			$this->generateMB( $order_id, $customer_order );
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
			'redirect' => $this->getReturnUrlFromCustomerOrder( $customer_order ),
		);
	}

	/**
	 * Generates the MB payment data
	 *
	 * @param integer  $order_id        ID of the order.
	 * @param WC_Order $customer_order  Order object.
	 *
	 * @throws DomainException Error in MB response.
	 *
	 * @return array Payment data
	 */
	private function generateMB( $order_id, $customer_order ) {
		$payment_webservice = $this->getSoapClient();

		$amount = number_format( $this->get_order_total(), 2, '', '' );

		$request_reference_details = new RequestReferenceDetails(
			array(
				'amount'      => $amount,
				'productCode' => $customer_order->get_order_number(),
			)
		);

		$request_reference_details->withPaymentOptions( [
			RequestPaymentOption::MULTIBANCO(PaymentMethodType::DEFAULT)
		] );

		$response = $payment_webservice->createPaymentReference( $request_reference_details );

		if ( ! isset( $response ) || empty( $response ) || 1 !== (int) $response->integrationState->state || 1 !== (int) $response->state ) { // phpcs:ignore
			throw new DomainException( 'Invalid PayPay MB response' );
		}

		$payment_id = $response->idPayment; // phpcs:ignore

		$payment = array(
			'reference'      => $response->reference,
			'atmEntity'      => $response->atmEntity, // phpcs:ignore
			'amount'         => $response->amount / 100,
			'expirationDate' => $response->validEndDate, // phpcs:ignore
			'idPayment'      => $payment_id,
			'paid'           => '0',
		);

		$customer_order->set_transaction_id( $payment_id );

		$comment_id            = $customer_order->add_order_note( $this->getPaymentLayout( 1, $payment ), 0, true );
		$payment['comment_id'] = $comment_id;

		$this->saveMB( $order_id, $payment );

		return $payment;
	}

	/**
	 * Saves the data relative to the MB payments
	 *
	 * @param integer $order_id ID of the order.
	 * @param array   $payment  Payment data.
	 * @return void
	 */
	private function saveMB( $order_id, $payment ) {
		global $wpdb;

		$wpdb->insert(
			'paypay_reference',
			array(
				'id_order'       => $order_id,
				'refMB'          => $payment['reference'],
				'entity'         => $payment['atmEntity'],
				'amount'         => $payment['amount'],
				'id_transaction' => $payment['idPayment'],
				'paid'           => '0',
				'comment_id'     => $payment['comment_id'],
			),
			array(
				'%d',
				'%s',
				'%s',
				'%s',
				'%d',
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
}
