<?php
/**
 * Plugin Name: PayPay - Pagamentos Multibanco, Cartão de Crédito/Débito e MB WAY
 * Plugin URI: https://paypayue.github.io/docs/category/woocommerce
 * Description: Comece já a emitir pagamentos por Multibanco, Cartão de Crédito/Débito ou MB WAY na sua loja com o módulo de pagamentos da Paypay para WooCommerce.
 * Version: 1.5.6
 * Requires at least: 4.6
 * Tested up to: 6.6.1
 * Text Domain: paypay-pagamentos-multibanco-e-cartao-de-creditodebito
 * Domain Path: /languages
 * Requires PHP: 5.5
 * Author: PayPay, PAYPAYUE
 * Author URI: https://www.paypay.pt
 * WC requires at least: 2.6.0
 * WC tested up to: 9.2.3
 *
 * @package PayPay
 */

defined( 'ABSPATH' ) || exit;
define( 'PAYPAY_DB_VERSION', '1.2.2' );

/**
 *  Involves all functions to avoid conflicts.
 */
( function () {

	add_option( 'paypay_db_version', '1.0' );

	/**
	 * Init the plugin after plugins_loaded.
	 *
	 * @return void
	 */
	function init() {
		if ( ! class_exists( 'woocommerce' ) ) {
			add_action(
				'admin_notices',
				function() {
					echo '<div class="error"><p>' . esc_html( 'PayPay requires WooCommerce plugin.' ) . '</p></div>';
				}
			);

			return;
		}

		if ( version_compare( PHP_VERSION, '5.5', '<' ) ) {
			add_action(
				'admin_notices',
				function() {
					echo '<div class="error"><p>' . esc_html( 'PayPay requires PHP 5.5 or above.' ) . '</p></div>';
				}
			);

			return;
		}

		if ( ! extension_loaded( 'soap' ) ) {
			add_action(
				'admin_notices',
				function() {
					echo '<div class="error"><p>' . esc_html( 'PayPay requires Soap extension.' ) . '</p></div>';
				}
			);

			return;
		}

		if ( PAYPAY_DB_VERSION !== get_site_option( 'paypay_db_version' ) ) {
			paypay_db_install();
			update_option( 'paypay_db_version', PAYPAY_DB_VERSION );
		}

		if ( ! checkRequiredTablesExist() ) {
			add_action(
				'admin_notices',
				function() {
					echo '<div class="error"><p>' . esc_html( 'PayPay tables not created. Please reinstall the plugin.' ) . '</p></div>';
				}
			);

			return;
		}

		require_once dirname( __FILE__ ) . '/src/class-paypay.php';
		require_once dirname( __FILE__ ) . '/src/class-paypaylogger.php';

		load_plugin_textdomain( 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}

	add_action(
		'plugins_loaded',
		function () {
			init();
		}
	);

	add_filter(
		'woocommerce_payment_gateways',
		function ( $methods ) {
			$methods[] = Paypay::class;
			return $methods;
		}
	);

	add_filter(
		'plugin_action_links_' . plugin_basename( __FILE__ ),
		function ( $links ) {
			$plugin_links = array(
				'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=paypay' ) . '">' . esc_html__( 'Settings', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ) . '</a>',
			);

			return array_merge( $plugin_links, $links );
		}
	);

	register_activation_hook(
		__FILE__,
		function () {
			init();

			$woocommerce_paypay_settings = get_option( 'woocommerce_paypay_settings' );

			if ( $woocommerce_paypay_settings && 'yes' === (string) $woocommerce_paypay_settings['enabled'] ) {
				try {
					$paypay = new Paypay();
					$paypay->subscribeWebhooks();
				} catch ( \Exception $e ) {
					PaypayLogger::log( 'error', $e->getMessage() );
				}
			}
		}
	);

	add_filter(
		'woocommerce_available_payment_gateways',
		function ( $gateways ) {
			// Important not use paypay key in gateways array, because already exist another plugin used that key.
			if ( is_checkout() && isset( $gateways['paypay'] ) ) {
				try {
					$gateways['paypay']->addPaymentGatewaysTo( $gateways );
				} catch ( \Exception $e ) {
					PaypayLogger::log( 'error', $e->getMessage() );
				}
			}
			unset( $gateways['paypay'] );

			return $gateways;
		},
		2000
	);

	add_action(
		'woocommerce_api_paypay_webhook',
		function() {
			$request = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification

			$paypay = new Paypay();
			$paypay->webhookCallback( $request );
		}
	);

	add_action(
		'woocommerce_api_paypay_cancel',
		function() {
			$request = array_map( 'sanitize_text_field', wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( array_key_exists( 'order_id', $request ) ) {
				$paypay = new Paypay();
				$paypay->failureCallback( $request['order_id'] );
			}
		}
	);

	add_action(
		'woocommerce_email_before_order_table',
		function ( $order, $sent_to_admin ) {
			if ( $sent_to_admin ) {
				return;
			}
			$paypay = new Paypay();
			if ( $order->is_paid() || $order->has_status( 'cancelled' ) ) {
				return;
			}

			if ( method_exists( $order, 'get_id' ) ) {
				$order_id = $order->get_id();
			} else {
				$order_id = $order->id;
			}

			$paypay_note = $paypay->getPayPayOrderNote( $order_id );

			if ( false === $paypay_note ) {
				return;
			}

			echo wp_kses_post( $paypay_note->comment_content );

		},
		10,
		2
	);

	add_action(
		'woocommerce_order_details_after_order_table',
		function ( $order ) {
			$paypay = new Paypay();

			if ( $order->is_paid() || $order->has_status( 'cancelled' ) ) {
				return;
			}

			$order_id = $order->id;
			if ( method_exists( $order, 'get_id' ) ) {
				$order_id = $order->get_id();
			}

			$paypay_note = $paypay->getPayPayOrderNote( $order_id );

			if ( false === $paypay_note ) {
				return;
			}

			echo '<h2>' . esc_html__( 'Payment details', 'paypay-pagamentos-multibanco-e-cartao-de-creditodebito' ) . '</h2>';
			echo wp_kses_post( $paypay_note->comment_content );
			echo '<br><br>';
		},
		10,
		1
	);

	add_filter(
		'woocommerce_valid_order_statuses_for_payment',
		function ( $array, $instance ) {
			$array[] = 'on-hold';
			return $array;
		},
		10,
		2
	);

	add_filter(
		'woocommerce_my_account_my_orders_actions',
		function ( $actions, $order ) {
			$order_status = $order->get_status();

			if ( 'on-hold' === $order_status ) {
				unset( $actions['pay'] );
			}

			return $actions;
		},
		10,
		2
	);

	/**
	 * Create tables to record payments
	 *
	 * @return void
	 */
	function paypay_db_install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql1 = "CREATE TABLE IF NOT EXISTS `paypay_payment_type` (
			id_order int(11) UNSIGNED,
			payment_type tinyint(2)
		) $charset_collate;";

		$sql2 = "CREATE TABLE IF NOT EXISTS `paypay_reference` (
			id_order int(11) UNSIGNED,
			refMB varchar(9),
			entity varchar(5),
			amount decimal(15, 2),
			id_transaction int(11) UNSIGNED,
			paid tinyint(1) DEFAULT '0' NOT NULL,
			comment_id int(11)
		) $charset_collate;";

		$sql3 = "CREATE TABLE IF NOT EXISTS `paypay_payment` (
			id_order int(11) UNSIGNED,
			id_transaction int(11) UNSIGNED,
			redunicre_token varchar(40),
			url varchar(300),
			history_id int(11),
			paid tinyint(1) DEFAULT '0' NOT NULL,
			comment_id int(11)
		) $charset_collate;";

		$sql4 = "CREATE TABLE IF NOT EXISTS `paypay_config` (
			hooked tinyint(2),
			action varchar(100),
			url varchar(500),
			nif varchar(9)
		) $charset_collate;";

		dbDelta( $sql1 );
		dbDelta( $sql2 );
		dbDelta( $sql3 );
		dbDelta( $sql4 );
	}

	function checkRequiredTablesExist() {
		global $wpdb;

		$tables = array(
			'paypay_payment_type',
			'paypay_reference',
			'paypay_payment',
			'paypay_config',
		);

		foreach ( $tables as $table ) {
			$table_name = $table;
			if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) !== $table_name ) {
				return false;
			}
		}

		return true;
	}
} )();
