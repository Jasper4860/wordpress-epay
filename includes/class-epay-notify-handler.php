<?php
/**
 * Signed EPay callback handler.
 *
 * @package EPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Validates EPay callbacks and completes matching WooCommerce orders. */
class EPAY_Notify_Handler {

	/**
	 * Singleton instance.
	 *
	 * @var EPAY_Notify_Handler|null
	 */
	private static $instance = null;

	/**
	 * Current callback lock name and ownership value.
	 *
	 * @var array<string, string>
	 */
	private $lock = array();

	/** Get the callback handler singleton. */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Register the WooCommerce callback route. */
	private function __construct() {
		add_action( 'woocommerce_api_epay_notify', array( $this, 'handle' ) );
	}

	/**
	 * Generate the EPay MD5 signature.
	 *
	 * @param array<string, mixed> $params Parameters to sign.
	 * @param string               $key Merchant key.
	 * @return string
	 */
	public static function sign( $params, $key ) {
		$filtered = array();
		foreach ( $params as $name => $value ) {
			if ( 'sign' === $name || 'sign_type' === $name || '' === (string) $value ) {
				continue;
			}
			$filtered[ $name ] = (string) $value;
		}

		ksort( $filtered );
		$pairs = array();
		foreach ( $filtered as $name => $value ) {
			$pairs[] = $name . '=' . $value;
		}

		return md5( implode( '&', $pairs ) . $key );
	}

	/**
	 * Normalize a V1 CNY amount without rounding untrusted input.
	 *
	 * @param mixed $value Amount in yuan.
	 * @return string|false Two decimal places, or false for an invalid amount.
	 */
	public static function normalize_amount( $value ) {
		if ( ! is_scalar( $value ) || ! preg_match( '/^[0-9]{1,12}(?:\.[0-9]{1,2})?$/D', (string) $value ) ) {
			return false;
		}
		$parts   = explode( '.', (string) $value, 2 );
		$whole   = ltrim( $parts[0], '0' );
		$decimal = isset( $parts[1] ) ? str_pad( $parts[1], 2, '0' ) : '00';
		return ( '' === $whole ? '0' : $whole ) . '.' . $decimal;
	}

	/** Validate the callback and acknowledge or reject it. */
	public function handle() {
		$params  = $this->request_params();
		$options = EPAY_Settings::get_options();
		$key     = isset( $options['merchant_key'] ) ? (string) $options['merchant_key'] : '';
		$sign    = isset( $params['sign'] ) ? strtolower( $params['sign'] ) : '';

		if ( '' === $key || ! preg_match( '/^[a-f0-9]{32}$/D', $sign ) || ! hash_equals( self::sign( $params, $key ), $sign ) ) {
			$this->fail( 'Invalid signature.' );
		}

		if ( isset( $params['sign_type'] ) && 'MD5' !== $params['sign_type'] ) {
			$this->fail( 'Unsupported signature type.' );
		}

		if ( empty( $options['merchant_id'] ) || (string) ( $params['pid'] ?? '' ) !== (string) $options['merchant_id'] ) {
			$this->fail( 'Merchant ID mismatch.' );
		}

		if ( ! isset( $params['trade_status'] ) || 'TRADE_SUCCESS' !== $params['trade_status'] ) {
			$this->fail( 'Payment is not successful.' );
		}

		$order_number = isset( $params['out_trade_no'] ) ? $params['out_trade_no'] : '';
		if ( ! preg_match( '/^[1-9][0-9]*$/D', $order_number ) || (string) (int) $order_number !== $order_number ) {
			$this->fail( 'Invalid order number.' );
		}
		$order_id = (int) $order_number;
		if ( ! $this->acquire_lock( $order_id ) ) {
			$this->fail( 'Order callback is being processed; retry later.' );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			$this->fail( 'Order not found.' );
		}

		$payment_method = (string) $order->get_payment_method();
		$notified_type  = isset( $params['type'] ) ? $params['type'] : '';
		$expected_type  = 0 === strpos( $payment_method, 'epay_' ) ? substr( $payment_method, 5 ) : '';
		if ( '' === $expected_type || ! hash_equals( $expected_type, $notified_type ) ) {
			$this->fail( 'Order payment method mismatch.' );
		}

		if ( 'CNY' !== $order->get_currency() ) {
			$this->fail( 'Unsupported order currency.' );
		}
		$received = self::normalize_amount( $params['money'] ?? '' );
		$expected = self::normalize_amount( $order->get_total() );
		if ( false === $received || false === $expected || '0.00' === $expected || ! hash_equals( $expected, $received ) ) {
			// translators: %1$s is the expected order amount; %2$s is the callback amount.
			$order->add_order_note( sprintf( __( '易支付回调金额不匹配；订单金额 %1$s，回调金额 %2$s。', 'epay' ), $expected, $received ) );
			$this->fail( 'Payment amount mismatch.' );
		}

		$transaction_id = isset( $params['trade_no'] ) ? $params['trade_no'] : '';
		if ( '' === $transaction_id || strlen( $transaction_id ) > 128 || ! preg_match( '/^[A-Za-z0-9_-]+$/D', $transaction_id ) ) {
			$this->fail( 'Invalid transaction ID.' );
		}
		$existing_id = (string) $order->get_transaction_id();
		if ( '' !== $existing_id && ! hash_equals( $existing_id, $transaction_id ) ) {
			$this->fail( 'Transaction ID mismatch.' );
		}
		if ( $order->is_paid() || ( 'refunded' === $order->get_status() && '' !== $existing_id ) ) {
			$this->success();
		}

		// A late payment needs a merchant decision after stock has been released.
		if ( ! $order->has_status( array( 'pending', 'on-hold', 'failed' ) ) ) {
			$this->fail( 'Order is not awaiting payment; review the late payment.' );
		}
		$order->payment_complete( $transaction_id );
		if ( ! $order->is_paid() ) {
			$this->fail( 'Order payment could not be completed.' );
		}
		// translators: %s is the platform transaction ID.
		$order->add_order_note( sprintf( __( '易支付付款成功。交易号：%s', 'epay' ), $transaction_id ? $transaction_id : __( '未提供', 'epay' ) ) );

		$this->success();
	}

	/**
	 * Serialize callbacks before loading an order, with atomic database ownership.
	 *
	 * @param int $order_id Order ID.
	 * @return bool Whether this request owns the lock.
	 */
	private function acquire_lock( $order_id ) {
		global $wpdb;
		$name  = 'epay_callback_lock_' . $order_id;
		$value = ( time() + 300 ) . ':' . bin2hex( random_bytes( 16 ) );
		// Direct queries bypass option caches. INSERT IGNORE never replaces an owner.
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic locks cannot use the option API's upsert or cached reads.
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value ) );
			if ( 1 === $inserted ) {
				$this->lock = array(
					'name'  => $name,
					'value' => $value,
				);
				register_shutdown_function( array( $this, 'release_lock' ) );
				return true;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Read the database owner rather than a stale object-cache value.
			$previous = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			if ( ! is_string( $previous ) || ! preg_match( '/^([0-9]+):[a-f0-9]{32}$/D', $previous, $matches ) || (int) $matches[1] >= time() ) {
				return false;
			}
			// Delete only the expired owner we observed; a replacement must survive.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Conditional compare-and-delete protects a concurrent replacement.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $previous ) );
		}
		return false;
	}

	/** Release only this request's callback lock, including on unexpected exit. */
	public function release_lock() {
		global $wpdb;
		if ( empty( $this->lock ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A previous owner must never release another request's lock.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $this->lock['name'], $this->lock['value'] ) );
		wp_cache_delete( $this->lock['name'], 'options' );
		$this->lock = array();
	}

	/** Read and validate callback parameters from the request.
	 *
	 * @return array<string, mixed> Callback parameters.
	 */
	private function request_params() {
		// EPay implementations may notify with either query parameters or form data.
		// This endpoint uses the provider signature instead of a WordPress nonce.
		$get  = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		// WC adds this routing parameter for stores using plain permalinks.
		unset( $get['wc-api'] );
		foreach ( $post as $name => $value ) {
			if ( isset( $get[ $name ] ) && $get[ $name ] !== $value ) {
				$this->fail( 'Conflicting callback parameters.' );
			}
		}
		$params = array_merge( $get, $post );
		$output = array();
		foreach ( $params as $name => $value ) {
			if ( ! is_scalar( $value ) || ! preg_match( '/^[a-z][a-z0-9_]*$/D', (string) $name ) ) {
				$this->fail( 'Invalid callback parameter.' );
			}
			// Validate field values after verifying the original, unmodified signature.
			$output[ $name ] = (string) $value;
		}
		return $output;
	}

	/** Send the provider's success acknowledgement and stop execution. */
	private function success() {
		$this->release_lock();
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'success';
		exit;
	}

	/** Send a failure acknowledgement and stop execution.
	 *
	 * @param string $message Internal failure reason.
	 */
	private function fail( $message ) {
		$this->release_lock();
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $message, array( 'source' => 'epay-callback' ) );
		}
		status_header( 400 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'fail';
		exit;
	}
}
