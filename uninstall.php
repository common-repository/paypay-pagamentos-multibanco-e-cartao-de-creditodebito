<?php

defined( 'ABSPATH' ) || exit;

// if uninstall not called from WordPress exit.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_paypay_settings' );
delete_option( 'paypay_db_version' );

