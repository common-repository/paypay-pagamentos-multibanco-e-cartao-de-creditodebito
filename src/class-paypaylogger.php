<?php
/**
 * Paypay gateway class file for Credit Card.
 *
 * @package PayPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Log all things!
 */
class PaypayLogger {

	/**
	 * Logger wc class.
	 *
	 * @var stdObject
	 */
	public static $logger;

	const WC_LOG_FILENAME = 'woocommerce-paypay';

	/**
	 * Log all.
	 *
	 * @param string $level log level.
	 * @param string $message log message.
	 * @return void
	 */
	public static function log( $level, $message ) {
		if ( ! class_exists( 'WC_Logger' ) ) {
			return;
		}

		if ( empty( self::$logger ) ) {
			self::$logger = wc_get_logger();
		}

		self::$logger->log( $level, $message, array( 'source' => self::WC_LOG_FILENAME ) );
	}
}
