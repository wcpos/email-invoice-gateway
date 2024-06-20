<?php
/**
 * Plugin Name: WooCommerce POS Email Invoice Gateway
 * Plugin URI: https://github.com/wcpos/email-invoice-gateway
 * Description: Send an invoice email to the customer with a link to pay for the order.
 * Version: 0.0.5
 * Author: kilbot
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: woocommerce-pos-email-invoice-gateway
 */

add_action( 'plugins_loaded', 'woocommerce_pos_email_invoice_gateway_init', 0 );

function woocommerce_pos_email_invoice_gateway_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	/**
	 * Localisation
	 */
	load_plugin_textdomain( 'woocommerce-pos-email-invoice-gateway', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	/**
	 * Gateway class
	 */
	class WCPOS_Email_Invoice extends WC_Payment_Gateway {

		public function __construct() {
			$this->id                 = 'wcpos_email_invoice';
			$this->icon               = '';
			$this->has_fields         = false;
			$this->method_title       = 'Email Invoice Gateway';
			$this->method_description = 'Send an invoice email to the customer with a link to pay for the order.';

			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();

			// Define user set variables
			$this->title       = $this->get_option( 'title' );
			$this->description = $this->get_option( 'description' );

			// only allow in the POS
			$this->enabled = false;

			// Actions
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		}

		public function init_form_fields() {
			$this->form_fields = array(
				'title'       => array(
					'title'       => __( 'Title', 'wcpos-email-invoice' ),
					'type'        => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'wcpos-email-invoice' ),
					'default'     => __( 'Send Invoice Email', 'wcpos-email-invoice' ),
					'desc_tip'    => true,
				),
				'description' => array(
					'title'       => __( 'Description', 'wcpos-email-invoice' ),
					'type'        => 'textarea',
					'description' => __( 'This controls the description which the user sees during checkout.', 'wcpos-email-invoice' ),
					'default'     => __( 'An email will be sent to the customer email with \'Pay for this order\' link.', 'wcpos-email-invoice' ),
				),
			);
		}

		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );

			// Mark as pending (we're awaiting the payment)
			$order->update_status( 'pending', __( 'Awaiting custom payment. ', 'woocommerce-pos-email-invoice-gateway' ) );

			// Reduce stock levels
			wc_reduce_stock_levels( $order_id );

			// Trigger the 'email customer invoice' action
			$mailer        = WC()->mailer();
			$email_to_send = 'WC_Email_Customer_Invoice';
			$emails        = $mailer->get_emails();

			// Ensure the email class exists and send the email
			if ( ! empty( $emails ) && isset( $emails[ $email_to_send ] ) ) {
				$emails[ $email_to_send ]->trigger( $order->get_id(), $order );
				$order->add_order_note( __( 'Customer invoice email sent via POS. ', 'woocommerce-pos-email-invoice-gateway' ) );
			} else {
				$order->add_order_note( __( 'Failed to send customer invoice email. ', 'woocommerce-pos-email-invoice-gateway' ) );
			}

			// Return thankyou redirect
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}
	}

	/**
	 * Add the Gateway to WooCommerce
	 */
	add_filter(
		'woocommerce_payment_gateways',
		function ( $methods ) {
			$methods[] = 'WCPOS_Email_Invoice';
			return $methods;
		}
	);
}
