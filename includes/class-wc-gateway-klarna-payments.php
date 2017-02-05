<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * WC_Gateway_Stripe class.
 *
 * @extends WC_Payment_Gateway
 */
class WC_Gateway_Klarna_Payments extends WC_Payment_Gateway {

	/**
	 * Client token retrieved from Klarna when session is created.
	 *
	 * @var string
	 */
	public $client_token;

	/**
	 * Sets Klarna Payments in test mode.
	 *
	 * @var string
	 */
	public $testmode = 'no';

	/**
	 * Klarna payments server base url.
	 *
	 * @var string
	 */
	public $server_base = '';

	/**
	 * Klarna merchant ID.
	 *
	 * @var string
	 */
	public $merchant_id = '';

	/**
	 * Klarna shared secret.
	 *
	 * @var string
	 */
	public $shared_secret = '';

	/**
	 * Klarna country.
	 *
	 * @var string
	 */
	public $shop_country = 'US';

	/**
	 * Turns on logging.
	 *
	 * @var string
	 *
	 * @TODO: Add logging.
	 */
	public $logging = false;

	/**
	 * Klarna Payments create session error.
	 *
	 * @var bool|WP_Error
	 */
	public $session_error = false;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->id                   = 'klarna_payments';
		$this->method_title         = __( 'Klarna Payments', 'woocommerce-gateway-klarna-payments' );
		$this->method_description   = __( 'Klarna Payments is our umbrella name for Klarna\'s payment methods.', 'woocommerce-gateway-klarna-payments' );
		$this->has_fields           = true;
		$this->supports             = apply_filters( 'wc_klarna_payments_supports', array( 'products' ) ); // Make this filterable.

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Get setting values.
		$this->title                  = $this->get_option( 'title' );
		$this->description            = $this->get_option( 'description', '' );
		$this->enabled                = $this->get_option( 'enabled' );
		$this->testmode               = 'yes' === $this->get_option( 'testmode' );
		$this->merchant_id            = $this->testmode ? $this->get_option( 'test_merchant_id_us' ) : $this->get_option( 'merchant_id_us', '' ); // @TODO: Test if live credentials are pulled when needed.
		$this->shared_secret          = $this->testmode ? $this->get_option( 'test_shared_secret_us' ) : $this->get_option( 'shared_secret_us', '' );
		$this->logging                = 'yes' === $this->get_option( 'logging' );

		if ( $this->testmode ) {
			$this->description .= ' ' . __( '<p>TEST MODE ENABLED.</p>', 'woocommerce-gateway-klarna-payments' );
			$this->description  = trim( $this->description );

			$this->server_base = 'https://api-na.playground.klarna.com/';
		} else {
			$this->server_base = 'https://api-na.klarna.com/';
		}

		// Hooks.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'wp_head', array( $this, 'klarna_payments_session' ), 10, 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'check_authorization_token' ) );
		add_action( 'woocommerce_api_wc_gateway_klarna_payments', array( $this, 'notification_listener' ) );
		add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'preserve_iframe_on_order_review_update' ) );
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = apply_filters( 'wc_gateway_klarna_payments_settings', array(
			'enabled' => array(
				'title'       => __( 'Enable/Disable', 'woocommerce' ),
				'label'       => __( 'Enable Klarna Payments', 'woocommerce' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'title' => array(
				'title'       => __( 'Title', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method description that the customer will see on your checkout.', 'woocommerce' ),
				'default'     => __( 'Klarna Payments', 'woocommerce' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description that the customer will see on your website.', 'woocommerce' ),
				'default'     => __( 'Pay with Klarna Payments.', 'woocommerce' ),
				'desc_tip'    => true,
			),
			/*
			'instructions' => array(
				'title'       => __( 'Instructions', 'woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Instructions that will be added to the thank you page.', 'woocommerce' ),
				'default'     => __( 'Pay with Klarna Payments.', 'woocommerce' ),
				'desc_tip'    => true,
			),
			*/
			'test_merchant_id_us' => array(
				'title'       => __( 'Test merchant ID (US)', 'woocommerce-gateway-klarna-payments' ),
				'type'        => 'text',
				'description' => __( 'Get your API keys from your Klarna Payments merchant account.', 'woocommerce-gateway-klarna-payments' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'test_shared_secret_us' => array(
				'title'       => __( 'Test shared secret (US)', 'woocommerce-gateway-klarna-payments' ),
				'type'        => 'text',
				'description' => __( 'Get your API keys from your Klarna Payments merchant account.', 'woocommerce-gateway-klarna-payments' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'merchant_id_us' => array(
				'title'       => __( 'Live merchant ID (US)', 'woocommerce-gateway-klarna-payments' ),
				'type'        => 'text',
				'description' => __( 'Get your API keys from your Klarna Payments merchant account.', 'woocommerce-gateway-klarna-payments' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'shared_secret_us' => array(
				'title'       => __( 'Live shared secret (US)', 'woocommerce-gateway-klarna-payments' ),
				'type'        => 'text',
				'description' => __( 'Get your API keys from your Klarna Payments merchant account.', 'woocommerce-gateway-klarna-payments' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'testmode' => array(
				'title'       => __( 'Test mode', 'woocommerce-gateway-klarna-payments' ),
				'label'       => __( 'Enable Test Mode', 'woocommerce-gateway-klarna-payments' ),
				'type'        => 'checkbox',
				'description' => __( 'Place the payment gateway in test mode using test API keys.', 'woocommerce-gateway-klarna-payments' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
			/*
			'logging' => array(
				'title'       => __( 'Logging', 'woocommerce-gateway-klarna-payments' ),
				'label'       => __( 'Log debug messages', 'woocommerce-gateway-klarna-payments' ),
				'type'        => 'checkbox',
				'description' => __( 'Save debug messages to the WooCommerce System Status log.', 'woocommerce-gateway-klarna-payments' ),
				'default'     => 'no',
				'desc_tip'    => true,
			),
			*/
		) );
	}

	/**
	 * Check if Klarna Payments should be available
	 */
	public function is_available() {
		if ( is_wp_error( $this->session_error ) ) {
			return false;
		}

		if ( '' === $this->merchant_id || '' === $this->shared_secret ) {
			return false;
		}

		return true;
	}

	/**
	 * Create Klarna Payments session.
	 */
	public function klarna_payments_session() {
		if ( ! is_checkout() || is_order_received_page() ) {
			return;
		}

		WC()->session->__unset( 'klarna_payments_session_id' );
		WC()->session->__unset( 'klarna_payments_client_token' );

		// Need to calculate these here, because WooCommerce hasn't done it yet.
		WC()->cart->calculate_fees();
		WC()->cart->calculate_shipping();
		WC()->cart->calculate_totals();

		$klarna_payments_params = array();
		$klarna_payments_params['testmode'] = $this->testmode;

		$order_lines_processor = new WC_Klarna_Payments_Order_Lines( $this->shop_country );
		$order_lines = $order_lines_processor->order_lines();
		$request_args = array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $this->merchant_id . ':' . $this->shared_secret ),
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( array(
				'purchase_country'  => 'US',
				'purchase_currency' => 'USD',
				'locale'            => 'en-US',
				'order_amount'      => $order_lines['order_amount'],
				'order_tax_amount'  => $order_lines['order_tax_amount'],
				'order_lines'       => $order_lines['order_lines'],
			) ),
		);

		if ( WC()->session->get( 'klarna_payments_session_id' ) ) { // Check if we have session ID.
			if ( $this->logging ) {
				WC_Klarna_Payments::log( 'reload session update args: ' . var_export( $request_args, true ) );
			}

			// Try to update the session, if it fails try to create new session.
			$update_request_url = $this->server_base . 'credit/v1/sessions/' . WC()->session->get( 'klarna_payments_session_id' );
			$update_response = $this->update_session_request( $update_request_url, $request_args );

			if ( $this->logging ) {
				WC_Klarna_Payments::log( 'reload session update response: ' . var_export( $update_response, true ) );
			}

			if ( is_wp_error( $update_response ) ) { // If update session failed try to create new session.
				WC()->session->__unset( 'klarna_payments_session_id' );
				WC()->session->__unset( 'klarna_payments_client_token' );

				$create_request_url = $this->server_base . 'credit/v1/sessions';
				$create_response = $this->create_session_request( $create_request_url, $request_args );

				if ( is_wp_error( $create_response ) ) { // Create failed, make Klarna Payments unavailable.
					$this->session_error = $create_response;
					wc_add_notice( 'Could not create Klarna session, please refresh the page to try again', 'error' );

					WC()->session->__unset( 'klarna_payments_session_id' );
					WC()->session->__unset( 'klarna_payments_client_token' );
				} else { // Store session ID and client token in WC session.
					WC()->session->set( 'klarna_payments_session_id', $create_response->session_id );
					WC()->session->set( 'klarna_payments_client_token', $create_response->client_token );
				}
			}
		} else {
			// If we dont have a session already, create one now.
			$create_request_url = $this->server_base . 'credit/v1/sessions';
			$create_response = $this->create_session_request( $create_request_url, $request_args );

			if ( is_wp_error( $create_response ) ) { // If update session failed try to create new session.
				$this->session_error = $create_response;
				wc_add_notice( 'Could not create Klarna session, please refresh the page to try again', 'error' );

				WC()->session->__unset( 'klarna_payments_session_id' );
				WC()->session->__unset( 'klarna_payments_client_token' );
			} else {
				WC()->session->set( 'klarna_payments_session_id', $create_response->session_id );
				WC()->session->set( 'klarna_payments_client_token', $create_response->client_token );
			}
		}

		// If we have a client token now, initialize Klarna Credit.
		if ( WC()->session->get( 'klarna_payments_client_token' ) ) {
			?>
			<script type="text/javascript" id="klarna-credit-lib-x">
				/* <![CDATA[ */
				(function (w, d) {
					var url = "https://credit.klarnacdn.net/lib/v1/api.js";
					n = d.createElement("script");
					c = d.getElementById("klarna-credit-lib-x");
					n.async = !0;
					n.src = url + "?" + (new Date()).getTime();
					c.parentNode.replaceChild(n, c);
				})(this, document);

				var klarnaLoadedInterval = setInterval(function () {
					var Klarna = false;

					try {
						Klarna = window.Klarna;
					} catch (e) {
						console.log('not yet')
					}

					if (Klarna && Klarna.Credit) {
						clearInterval(klarnaLoadedInterval);
						clearTimeout(klarnaLoadedTimeout);

						var data = {client_token: "<?php echo esc_attr( WC()->session->get( 'klarna_payments_client_token' ) ); ?>"};

						console.log('****** Klarna Credit - Klarna.Credit.init() ******');
						console.log(data);

						Klarna.Credit.init(data);
					}
				}, 100);

				var klarnaLoadedTimeout = setTimeout(function () {
					clearInterval(klarnaLoadedInterval);
				}, 3000);
				/* ]]> */
			</script>
			<?php
		}
	}


	/**
	 * Update Klarna session on AJAX update_checkout.
	 */
	public function klarna_payments_session_ajax_update() {
		if ( is_ajax() ) { // On AJAX update_checkout, just try to update the session.
			// Need to calculate these here, because WooCommerce hasn't done it yet.
			WC()->cart->calculate_fees();
			WC()->cart->calculate_shipping();
			WC()->cart->calculate_totals();

			$order_lines_processor = new WC_Klarna_Payments_Order_Lines( $this->shop_country );
			$order_lines = $order_lines_processor->order_lines();
			$request_args = array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $this->merchant_id . ':' . $this->shared_secret ),
					'Content-Type'  => 'application/json',
				),
				'body' => wp_json_encode( array(
					'purchase_country'  => 'US',
					'purchase_currency' => 'USD',
					'locale'            => 'en-US',
					'order_amount'      => $order_lines['order_amount'],
					'order_tax_amount'  => $order_lines['order_tax_amount'],
					'order_lines'       => $order_lines['order_lines'],
				) ),
			);

			if ( $this->logging ) {
				WC_Klarna_Payments::log( 'ajax session update args: ' . var_export( $request_args, true ) );
			}

			// Try to update the session, if it fails try to create new session.
			$update_request_url = $this->server_base . 'credit/v1/sessions/' . WC()->session->get( 'klarna_payments_session_id' );
			$update_response = $this->update_session_request( $update_request_url, $request_args );

			if ( $this->logging ) {
				WC_Klarna_Payments::log( 'ajax session update response: ' . var_export( $update_response, true ) );
			}

			if ( is_wp_error( $update_response ) ) { // If update session failed try to create new session.
				$this->session_error = $update_response;
				wc_add_notice( 'Could not update Klarna session, please refresh the page to try again', 'error' );

				WC()->session->__unset( 'klarna_payments_session_id' );
				WC()->session->__unset( 'klarna_payments_client_token' );
			}
		}
	}

	/**
	 * Create Klarna Payments session.
	 *
	 * @param $request_url
	 * @param $request_args
	 *
	 * @return array|mixed|object|WP_Error
	 */
	public function create_session_request( $request_url, $request_args ) {
		$response = wp_safe_remote_post( $request_url, $request_args );
		$decoded = json_decode( $response['body'] );

		if ( 200 === $response['response']['code'] ) {
			return $decoded;
		} else {
			return new WP_Error( $response['response']['code'], $response['response']['message'] );
		}
	}

	/**
	 * Update Klarna Payments session.
	 *
	 * @param $request_url
	 * @param $request_args
	 *
	 * @return array|mixed|object|WP_Error
	 */
	public function update_session_request( $request_url, $request_args ) {
		$response = wp_safe_remote_post( $request_url, $request_args );
		$decoded = json_decode( $response['body'] );

		if ( 204 === $response['response']['code'] ) {
			return $decoded;
		} else {
			return new WP_Error( $response['response']['code'], $response['response']['message'] );
		}
	}

	/**
	 * Adds Klarna Payments container to checkout page.
	 */
	public function payment_fields() {
		if ( $description = $this->get_description() ) {
			echo wpautop( wptexturize( $description ) );
		}

		echo '<div id="klarna_container"></div>';
	}

	/**
	 * Enqueue payment scripts.
	 *
	 * @access public
	 */
	public function enqueue_scripts() {
		if ( ! is_checkout() || is_order_received_page() ) {
			return;
		}

		wp_register_script(
			'klarna_payments',
			plugins_url( 'assets/js/klarna-payments.js', WC_KLARNA_PAYMENTS_MAIN_FILE ),
			array( 'jquery' ),
			WC_KLARNA_PAYMENTS_VERSION,
			true
		);

		// Localize the script.
		$klarna_payments_params = array();
		$klarna_payments_params['testmode'] = $this->testmode;
		wp_localize_script( 'klarna_payments', 'klarna_payments_params', $klarna_payments_params );
		wp_enqueue_script( 'klarna_payments' );
	}

	/**
	 * Check posted data for authorization token.
	 *
	 * If authorization token is missing, we'll add error notice and bail.
	 * Authorization token field is added to the form in JavaScript, when Klarna.Credit.authorize is completed.
	 */
	public function check_authorization_token( $posted ) {
		if ( 'klarna_payments' !== $posted['payment_method'] ) {
			return;
		}

		if ( ! $_POST['klarna_payments_authorization_token'] ) { // Input var okay.
			wc_add_notice( __( 'Could not create Klarna Payments authorization token.' ), 'error' );
		}
	}

	/**
	 * Place Klarna Payments order, after authorization.
	 *
	 * Uses authorization token to place the order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		// Place order.
		$response = $this->place_order( $order_id, $_POST['klarna_payments_authorization_token'] );

		// Process the response.
		if ( ! is_wp_error( $response ) && 200 === $response['response']['code'] ) {
			$decoded = json_decode( $response['body'] );

			if ( 'ACCEPTED' === $decoded->fraud_status ) {
				$order->payment_complete( $decoded->order_id );
				$order->add_order_note( 'Payment via Klarna Payments, order ID: ' . $decoded->order_id );
				add_post_meta( $order_id, '_wc_klarna_payments_order_id', $decoded->order_id, true );

				do_action( 'wc_klarna_payments_accepted', $order_id, $decoded );
			} elseif ( 'PENDING' === $decoded->fraud_status ) {
				// @TODO: Add meta field using this hook from Order Management plugin.
				$order->update_status( 'on-hold', 'Klarna order is under review.' );
				add_post_meta( $order_id, '_wc_klarna_payments_pending', 'yes', true );

				do_action( 'wc_klarna_payments_pending', $order_id, $decoded );
			} elseif ( 'REJECTED' === $decoded->fraud_status ) {
				$order->update_status( 'on-hold', 'Klarna order was rejected.' );

				do_action( 'wc_klarna_payments_rejected', $order_id, $decoded );

				return array(
					'result'   => 'failure',
					'redirect' => '',
					'messages' => '<div class="woocommerce-error">Klarna payment rejected</div>',
				);
			}

			if ( true === $this->testmode ) {
				update_post_meta( $order_id, '_wc_klarna_payments_env', 'test' );
			} else {
				update_post_meta( $order_id, '_wc_klarna_payments_env', 'live' );
			}

			WC()->session->__unset( 'klarna_payments_session_id' );
			WC()->session->__unset( 'klarna_payments_client_token' );

			return array(
				'result' => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		} else {
			if ( is_wp_error( $response ) ) {
				$error_message = $response->get_error_message();
			} else {
				$error_message = 'Klarna error failed. ' . $response['response']['code'] . ' - ' . $response['response']['message'] . '.';
			}

			wc_add_notice( $error_message, 'error' );

			// Return failure if something went wrong.
			return array(
				'result'   => 'failure',
				'redirect' => '',
			);
		}
	}

	/**
	 * Places the order with Klarna
	 *
	 * @TODO: Ask about shipping phone and email. OK to use billing instead?
	 *
	 * @param string $auth_token Klarna Payments authorization token.
	 *
	 * @return array|WP_Error
	 */
	public function place_order( $order_id, $auth_token ) {
		$order                 = wc_get_order( $order_id );
		$order_lines_processor = new WC_Klarna_Payments_Order_Lines( $this->shop_country );
		$order_lines           = $order_lines_processor->order_lines();
		$posted_data           = $_POST; // Input var okay.

		$billing_address = array(
			'given_name' => $posted_data['billing_first_name'],
			'family_name' => $posted_data['billing_last_name'],
			'email' => $posted_data['billing_email'],
			'phone' => $posted_data['billing_phone'],
			// 'title' => 'Mr',
			'street_address' => $posted_data['billing_address_1'],
			'street_address2' => $posted_data['billing_address_2'],
			'postal_code' => $posted_data['billing_postcode'],
			'city' => $posted_data['billing_city'],
			'region' => $posted_data['billing_state'],
			'country' => $posted_data['billing_country'],
		);

		if ( ! empty( $_POST['ship_to_different_address'] ) && ! wc_ship_to_billing_address_only() ) {
			$shipping_address = array(
				'given_name' => $posted_data['shipping_first_name'],
				'family_name' => $posted_data['shipping_last_name'],
				'email' => $posted_data['billing_email'],
				'phone' => $posted_data['shipping_email'],
				// 'title' => 'Mr',
				'street_address' => $posted_data['shipping_address_1'],
				'street_address2' => $posted_data['shipping_address_2'],
				'postal_code' => $posted_data['shipping_postcode'],
				'city' => $posted_data['shipping_city'],
				'region' => $posted_data['shipping_state'],
				'country' => $posted_data['shipping_country'],
			);
		} else {
			$shipping_address = $billing_address;
		}

		$request_url  = $this->server_base . 'credit/v1/authorizations/' . $auth_token . '/order';
		$request_args = array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $this->merchant_id . ':' . $this->shared_secret ),
				'Content-Type'  => 'application/json',
			),
			// @TODO: Make args filterable, so order management plugin can hook in.
			'body' => wp_json_encode( array(
				'purchase_country'    => 'US',
				'purchase_currency'   => 'USD',
				'locale'              => 'en-US',
				'billing_address'     => $billing_address,
				'shipping_address'    => $shipping_address,
				'order_amount'        => $order_lines['order_amount'],
				'order_tax_amount'    => $order_lines['order_tax_amount'],
				'order_lines'         => $order_lines['order_lines'],
				'merchant_reference1' => $order_id, // @TODO: Add support for Sequential Numbers plugins.
				'merchant_urls'       => array(
					'confirmation' => $order->get_checkout_order_received_url(),
					'notification' => get_home_url() . '/wc-api/WC_Gateway_Klarna_Payments/?order_id=' . $order_id, // @TODO: Add filter here, so OM plugin can read the URL
				),
			) ),
		);

		if ( $this->logging ) {
			WC_Klarna_Payments::log( 'place order args: ' . var_export( $request_args, true ) );
		}

		$response = wp_safe_remote_post( $request_url, $request_args );

		if ( $this->logging ) {
			WC_Klarna_Payments::log( 'place order response: ' . var_export( $response, true ) );
		}

		return $response;
	}

	/**
	 * Preserve Klarna Payments Iframe on order review update.
	 *
	 * Hacky, but it works, looking for a better way to handle this. Klarna Payments method never gets refreshed,
	 * JS code is used to hide/show it based on availability.
	 *
	 * Other payment methods are replaced by their empty <li> element when unavailable, so we can target that <li> element
	 * once the payment method becomes available again.
	 *
	 * @param array $elements Array of elements to update on order review update.
	 *
	 * @return array
	 */
	function preserve_iframe_on_order_review_update( $elements ) {
		// Update Klarna session here.
		$this->klarna_payments_session_ajax_update();

		unset( $elements['.woocommerce-checkout-payment'] );

		if ( WC()->cart->needs_payment() ) {
			$available_gateways = WC()->payment_gateways()->get_available_payment_gateways();
			WC()->payment_gateways()->set_current_gateway( $available_gateways );

			foreach ( $available_gateways as $gateway_key => $gateway ) {
				if ( 'klarna_payments' !== $gateway_key ) {
					if ( ! $gateway->is_available() ) {
						$woocommerce_gateway = '<li style="display:none !important" class="payment_method_' . $gateway_key . '"></li>';
					} else {
						ob_start();
						wc_get_template( 'checkout/payment-method.php', array( 'gateway' => $gateway ) );
						$woocommerce_gateway = ob_get_clean();
					}

					$elements[ 'li.payment_method_' . $gateway_key ] = $woocommerce_gateway;
				}
			}
		}

		return $elements;
	}

	/**
	 * Notification listener for Pending orders.
	 *
	 * @TODO: MOVE TO ORDER MANAGEMENT PLUGIN. Use wc_klarna_payments_pending hook defined in this file.
	 *
	 * @link https://developers.klarna.com/en/us/kco-v3/pending-orders
	 */
	public function notification_listener() {
		if ( $_GET['order_id'] ) { // Input var okay.
			$order_id = intval( $_GET['order_id'] ); // Input var okay.
			$order = wc_get_order( $order_id );

			$post_body = file_get_contents( 'php://input' );
			$data = json_decode( $post_body, true );

			if ( 'FRAUD_RISK_ACCEPTED' === $data['event_type'] ) {
				$order->payment_complete( $data['order_id'] );
				$order->add_order_note( 'Payment via Klarna Payments, order ID: ' . $data['order_id'] );
				add_post_meta( $order_id, '_wc_klarna_payments_order_id', $data['order_id'], true );
			} elseif ( 'FRAUD_RISK_REJECTED' === $data['event_type'] || 'FRAUD_RISK_STOPPED' === $data['event_type'] ) {
				$order->cancel_order( 'Klarna order rejected' );
			}
		}
	}

}