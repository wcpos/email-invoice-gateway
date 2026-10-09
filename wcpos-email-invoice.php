<?php
/**
 * Plugin Name: WooCommerce POS Email Invoice Gateway
 * Plugin URI: https://github.com/wcpos/email-invoice-gateway
 * Description: Send an invoice email to the customer with a link to pay for the order.
 * Version: 0.1.0
 * Author: kilbot
 * Update URI:  https://github.com/wcpos/email-invoice-gateway
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
		/**
		 * Invoice Email Address
		 *
		 * @var string
		 */
		private $invoice_email;

		/**
		 * Constructor for the gateway.
		 */
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
					'default'     => __( 'Email Invoice', 'wcpos-email-invoice' ),
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

		public function validate_fields() {
			// The notice names the field so WooCommerce POS 2.0 shows it under the input; on the
			// Legacy form and in 1.10 it is an ordinary checkout notice.
			$field = array( 'id' => 'woocommerce_pos_invoice_email_address' );
			if ( ! isset( $_POST['woocommerce_pos_invoice_email_address'] ) || empty( $_POST['woocommerce_pos_invoice_email_address'] ) ) {
				wc_add_notice( __( 'Please enter an email address.', 'woocommerce-pos-email-invoice-gateway' ), 'error', $field );
				return false;
			}
			// sanitize_email() turns an invalid address into '' in process_payment(), which would
			// send nothing and, with the checkbox ticked, blank the billing email.
			if ( ! is_email( wp_unslash( $_POST['woocommerce_pos_invoice_email_address'] ) ) ) {
				wc_add_notice( __( 'Please enter a valid email address.', 'woocommerce-pos-email-invoice-gateway' ), 'error', $field );
				return false;
			}
			return true;
		}

		public function payment_fields() {
			global $wp;
			echo wpautop( wptexturize( $this->description ) );

			$order_id = isset( $wp->query_vars['order-pay'] ) ? $wp->query_vars['order-pay'] : null;
			$order    = $order_id ? wc_get_order( $order_id ) : null;

			woocommerce_form_field(
				'woocommerce_pos_invoice_email_address',
				array(
					'type'              => 'email',
					'class'             => array( 'form-row-wide' ),
					'label'             => __( 'Email Address', 'wcpos-email-invoice' ),
					'placeholder'       => __( 'Enter email address', 'wcpos-email-invoice' ),
					'required'          => true,
					'default'           => $order ? $order->get_billing_email() : '',
					'custom_attributes' => array(
						'style' => 'padding: 10px; width: 100%; max-width: 400px; box-sizing: border-box;',
					),
				),
				$order ? $order->get_billing_email() : ''
			);

			woocommerce_form_field(
				'woocommerce_pos_save_billing_email',
				array(
					'type'     => 'checkbox',
					'class'    => array( 'form-row-wide' ),
					'label'    => __( 'Save email to Billing Address', 'wcpos-email-invoice' ),
					'required' => false,
				),
				''
			);
		}

		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );

			// Get the custom email address
			$this->invoice_email = isset( $_POST['woocommerce_pos_invoice_email_address'] ) ? sanitize_email( $_POST['woocommerce_pos_invoice_email_address'] ) : '';

			// Check if the 'Save email to Billing Address' checkbox is checked
			$save_to_billing = isset( $_POST['woocommerce_pos_save_billing_email'] ) && '1' === $_POST['woocommerce_pos_save_billing_email'];

			// If checked, update the order's billing email with the custom field value
			if ( $save_to_billing ) {
				$order->set_billing_email( $this->invoice_email );
				$order->save();
			} else {
				// Allows email to be sent without saving to billing address
				add_filter( 'woocommerce_email_recipient_customer_invoice', array( $this, 'get_recipient' ) );
			}

			// Mark as pending (we're awaiting the payment)
			$order->update_status( 'pending', __( 'Awaiting customer payment. ', 'woocommerce-pos-email-invoice-gateway' ) );

			// Reduce stock levels
			wc_reduce_stock_levels( $order_id );

			// Trigger the 'email customer invoice' action
			$mailer        = WC()->mailer();
			$email_to_send = 'WC_Email_Customer_Invoice';
			$emails        = $mailer->get_emails();

			// Ensure the email class exists and send the email
			if ( ! empty( $emails ) && isset( $emails[ $email_to_send ] ) ) {
				$emails[ $email_to_send ]->trigger( $order->get_id(), $order );
				$order->add_order_note( sprintf( __( 'Customer invoice email sent to %s via POS.', 'woocommerce-pos-email-invoice-gateway' ), $this->invoice_email ) );
			} else {
				$order->add_order_note( __( 'Failed to send customer invoice email. ', 'woocommerce-pos-email-invoice-gateway' ) );
			}

			// Return thankyou redirect
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		/**
		 * Get the recipient for the invoice email
		 *
		 * @param string $email
		 * @return string
		 */
		public function get_recipient( $email ) {
			if ( isset( $this->invoice_email ) ) {
				return $this->invoice_email;
			}
			return $email;
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

	/**
	 * Declare the gateway to WooCommerce POS 2.0 (payments contract 1.2).
	 *
	 * The POS draws the declared components with its own controls, posts their values under
	 * the same keys payment_fields() uses, and runs process_payment() with them in $_POST, so
	 * validate_fields() and process_payment() above serve both paths unchanged. payment_fields()
	 * stays for POS 1.10 and the Legacy tab. Neither filter exists before POS 2.0, where both
	 * are simply never called.
	 */
	add_filter(
		'wcpos_payment_method_capture_mode',
		function ( $mode, $gateway ) {
			return 'wcpos_email_invoice' === $gateway->id ? 'gateway' : $mode;
		},
		10,
		2
	);

	add_filter(
		'wcpos_payment_method_fields',
		function ( $fields, $gateway ) {
			if ( 'wcpos_email_invoice' !== $gateway->id ) {
				return $fields;
			}

			$components = array();
			if ( '' !== trim( (string) $gateway->description ) ) {
				$components[] = array(
					'component' => 'note',
					'text'      => $gateway->description,
				);
			}
			$components[] = array(
				'component' => 'field',
				'id'        => 'woocommerce_pos_invoice_email_address',
				'input'     => 'email',
				'label'     => __( 'Email address', 'woocommerce-pos-email-invoice-gateway' ),
				'required'  => true,
				'default'   => '',
				'prefill'   => 'order.billing.email',
			);
			$components[] = array(
				'component' => 'checkbox',
				'id'        => 'woocommerce_pos_save_billing_email',
				'label'     => __( 'Save email to billing address', 'woocommerce-pos-email-invoice-gateway' ),
				'default'   => false,
				'prefill'   => null,
			);

			return array(
				'schema'     => 1,
				'verb'       => array(
					'kind'  => 'send',
					'label' => __( 'Send invoice', 'woocommerce-pos-email-invoice-gateway' ),
				),
				'components' => $components,
			);
		},
		10,
		2
	);
}
