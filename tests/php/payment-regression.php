<?php
/**
 * Focused regression harness for the EPay gateway and notification handler.
 * Run with: php tests/php/payment-regression.php
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );
define( 'EPAY_VERSION', 'test' );
define( 'EPAY_FILE', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR . 'epay.php' );
define( 'EPAY_PATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
define( 'EPAY_URL', 'https://shop.invalid/wp-content/plugins/epay/' );

$GLOBALS['epay_test_options'] = array();
$GLOBALS['epay_test_orders'] = array();
$GLOBALS['epay_test_http_status'] = 200;
$GLOBALS['epay_test_price_decimals'] = 2;
$GLOBALS['epay_test_failures'] = 0;
$GLOBALS['wpdb'] = null;

function add_action( $hook, $callback ) {}
function __( $text ) { return $text; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}
	return is_string( $value ) ? stripslashes( $value ) : $value;
}
function wc_get_price_decimals() { return $GLOBALS['epay_test_price_decimals']; }
function wc_format_decimal( $value, $dp = false ) {
	if ( ! is_numeric( $value ) ) {
		return '';
	}
	return false === $dp ? (string) $value : number_format( (float) $value, $dp, '.', '' );
}
function status_header( $status ) { $GLOBALS['epay_test_http_status'] = $status; }
function wp_cache_delete( $key, $group = '' ) {}
function wc_get_order( $order_id ) {
	return isset( $GLOBALS['epay_test_orders'][ (int) $order_id ] ) ? $GLOBALS['epay_test_orders'][ (int) $order_id ] : null;
}
function wc_add_notice( $message, $type ) {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function WC() {
	return new class {
		public function api_request_url( $endpoint ) { return 'https://shop.invalid/?wc-api=' . rawurlencode( $endpoint ); }
	};
}

class WP_Error {
	private $message;
	public function __construct( $code, $message ) { $this->message = $message; }
	public function get_error_message() { return $this->message; }
}

class Epay_Test_WPDB {
	public $options = 'wp_options';
	public $memory_options = array();
	public $replace_before_delete = false;
	public $replacement_value = '';

	public function prepare( $query, ...$args ) {
		return array(
			'query' => $query,
			'args'  => $args,
		);
	}

	public function query( $prepared ) {
		$query = $prepared['query'];
		$args  = $prepared['args'];
		if ( 0 === strpos( $query, 'INSERT IGNORE' ) ) {
			list( $name, $value ) = $args;
			if ( isset( $this->memory_options[ $name ] ) ) {
				return 0;
			}
			$this->memory_options[ $name ] = $value;
			return 1;
		}

		if ( 0 === strpos( $query, 'DELETE' ) ) {
			list( $name, $value ) = $args;
			if ( $this->replace_before_delete ) {
				$this->memory_options[ $name ] = $this->replacement_value;
				$this->replace_before_delete = false;
			}
			if ( isset( $this->memory_options[ $name ] ) && $this->memory_options[ $name ] === $value ) {
				unset( $this->memory_options[ $name ] );
				return 1;
			}
			return 0;
		}

		throw new RuntimeException( 'Unexpected payment-test database query.' );
	}

	public function get_var( $prepared ) {
		$name = $prepared['args'][0];
		return isset( $this->memory_options[ $name ] ) ? $this->memory_options[ $name ] : null;
	}
}

class WC_Payment_Gateway {
	public $id;
	public $has_fields;
	public $supports = array();
	public $method_title;
	public $method_description;
	public $icon;
	public $form_fields = array();
	public $title;
	public $description;
	public $enabled;
	public function init_settings() {}
	public function get_option( $key, $default = '' ) { return $default; }
	public function get_return_url( $order ) { return 'https://shop.invalid/order-received/' . $order->get_id(); }
}

class EPAY_Settings {
	public static function get_options() { return $GLOBALS['epay_test_options']; }
}

class Epay_Test_Order {
	public $id;
	public $status;
	public $payment_method;
	public $total;
	public $currency;
	public $payment_complete_calls = 0;
	public $completed_count = 0;
	public $transaction_id = null;
	public $notes = array();
	public function __construct( $id, $status = 'pending', $payment_method = 'epay_alipay', $total = '100.00', $currency = 'CNY' ) {
		$this->id = $id;
		$this->status = $status;
		$this->payment_method = $payment_method;
		$this->total = $total;
		$this->currency = $currency;
	}
	public function get_id() { return $this->id; }
	public function get_status() { return $this->status; }
	public function get_payment_method() { return $this->payment_method; }
	public function get_total() { return $this->total; }
	public function get_currency() { return $this->currency; }
	public function get_transaction_id() { return $this->transaction_id; }
	public function has_status( $statuses ) { return in_array( $this->status, (array) $statuses, true ); }
	public function is_paid() { return in_array( $this->status, array( 'processing', 'completed' ), true ); }
	public function payment_complete( $transaction_id = '' ) {
		++$this->payment_complete_calls;
		if ( in_array( $this->status, array( 'on-hold', 'pending', 'failed', 'cancelled' ), true ) ) {
			++$this->completed_count;
			$this->transaction_id = $transaction_id;
			$this->status = 'processing';
		}
	}
	public function update_status( $status, $note = '' ) { $this->status = $status; }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function get_items() { return array( new Epay_Test_Order_Item( 'Sample item' ) ); }
}

class Epay_Test_Order_Item {
	private $name;
	public function __construct( $name ) { $this->name = $name; }
	public function get_name() { return $this->name; }
}

require_once EPAY_PATH . 'includes/class-epay-notify-handler.php';
require_once EPAY_PATH . 'includes/abstract-epay-gateway.php';
require_once EPAY_PATH . 'includes/gateways/class-wc-gateway-epay.php';

function epay_test_fail( $message ) {
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

function epay_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		epay_test_fail( $message );
	}
}

function epay_test_signed_params( $key, $changes = array() ) {
	$params = array_merge(
		array(
			'pid'          => 'merchant-test',
			'type'         => 'alipay',
			'out_trade_no' => '123',
			'money'        => '100.00',
			'trade_no'     => 'platform-trade-test',
			'trade_status' => 'TRADE_SUCCESS',
			'sign_type'    => 'MD5',
		),
		$changes
	);
	$params['sign'] = EPAY_Notify_Handler::sign( $params, $key );
	return $params;
}

function epay_test_child( $case, $key, $params, $method = 'GET', $status = 'pending', $currency = 'CNY', $order_id = 123, $order_ref = '123', $lock_mode = '' ) {
	$GLOBALS['wpdb'] = new Epay_Test_WPDB();
	$lock_name      = 'epay_callback_lock_' . $order_id;
	if ( 'busy' === $lock_mode ) {
		$GLOBALS['wpdb']->memory_options[ $lock_name ] = ( time() + 60 ) . ':' . str_repeat( 'a', 32 );
	}
	if ( 'expired' === $lock_mode ) {
		$GLOBALS['wpdb']->memory_options[ $lock_name ] = ( time() - 60 ) . ':' . str_repeat( 'b', 32 );
	}
	if ( 'replacement-on-release' === $lock_mode ) {
		$GLOBALS['wpdb']->replace_before_delete = true;
		$GLOBALS['wpdb']->replacement_value     = ( time() + 120 ) . ':' . str_repeat( 'c', 32 );
	}
	$GLOBALS['epay_test_options'] = array(
		'api_url'     => 'https://gateway.invalid',
		'merchant_id' => 'merchant-test',
		'merchant_key'=> $key,
	);
	$GLOBALS['epay_test_orders'] = array(
		$order_id => new Epay_Test_Order( $order_id, $status, 'epay_alipay', '100.00', $currency ),
	);
	$_GET = 'GET' === $method ? $params : array();
	$_POST = 'POST' === $method ? $params : array();
	if ( 'ARRAY' === $method ) {
		$_GET = $params;
		$_GET['pid'] = array( 'merchant-test' );
	}
	if ( 'MIXED' === $method ) {
		$_GET = $params;
		$_POST = array( 'pid' => 'wrong-merchant' );
	}
	if ( 'GET_ROUTE' === $method ) {
		$_GET = array_merge( array( 'wc-api' => 'epay_notify' ), $params );
	}

	$body = '';
	register_shutdown_function(
		function () use ( &$body, $order_id, $order_ref, $case, $lock_name ) {
			$body = ob_get_contents();
			ob_end_clean();
			$order = wc_get_order( $order_id );
			echo json_encode(
				array(
					'case'             => $case,
					'http_status'      => $GLOBALS['epay_test_http_status'],
					'body'             => $body,
					'order_status'     => $order ? $order->status : null,
					'payment_complete_calls' => $order ? $order->payment_complete_calls : 0,
					'completed_count'  => $order ? $order->completed_count : 0,
					'transaction_empty'=> $order ? '' === $order->transaction_id : false,
					'lookup_order_id'  => $order_ref,
					'lock_value'       => isset( $GLOBALS['wpdb']->memory_options[ $lock_name ] ) ? $GLOBALS['wpdb']->memory_options[ $lock_name ] : null,
					'replacement_value'=> $GLOBALS['wpdb']->replacement_value,
				)
			);
		}
	);
	ob_start();
	EPAY_Notify_Handler::instance()->handle();
}

if ( isset( $argv[1] ) && '--child' === $argv[1] ) {
	$input = json_decode( file_get_contents( $argv[2] ), true );
	epay_test_child(
		$input['case'],
		$input['key'],
		$input['params'],
		$input['method'],
		$input['status'],
		$input['currency'],
		$input['order_id'],
		$input['order_ref'],
		isset( $input['lock_mode'] ) ? $input['lock_mode'] : ''
	);
	exit( 2 );
}

function epay_test_run_child( $case, $key, $params, $method = 'GET', $status = 'pending', $currency = 'CNY', $order_id = 123, $order_ref = '123', $lock_mode = '' ) {
	$path = tempnam( sys_get_temp_dir(), 'epay-case-' );
	$input = array(
		'case'     => $case,
		'key'      => $key,
		'params'   => $params,
		'method'   => $method,
		'status'   => $status,
		'currency' => $currency,
		'order_id' => $order_id,
		'order_ref' => $order_ref,
		'lock_mode' => $lock_mode,
	);
	file_put_contents( $path, json_encode( $input ) );
	$process = proc_open(
		array( PHP_BINARY, __FILE__, '--child', $path ),
		array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
		$pipes,
		null,
		array()
	);
	if ( ! is_resource( $process ) ) {
		unlink( $path );
		epay_test_fail( 'Could not start PHP callback subprocess.' );
	}
	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$exit_code = proc_close( $process );
	unlink( $path );
	$result = json_decode( $stdout, true );
	if ( ! is_array( $result ) || $exit_code !== 0 ) {
		epay_test_fail( 'Callback subprocess failed for ' . $case . '; diagnostic output was suppressed.' );
	}
	return $result;
}

function epay_test_expect_callback( $label, $case, $key, $params, $expected_body, $expected_count, $method = 'GET', $status = 'pending', $currency = 'CNY', $order_id = 123, $order_ref = '123', $lock_mode = '' ) {
	$result = epay_test_run_child( $case, $key, $params, $method, $status, $currency, $order_id, $order_ref, $lock_mode );
	$ok = $result['body'] === $expected_body && $result['completed_count'] === $expected_count;
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	if ( ! $ok ) {
		++$GLOBALS['epay_test_failures'];
		printf( "  observed response=%s, completions=%d, status=%s\n", $result['body'], $result['completed_count'], $result['order_status'] );
	}
	return $result;
}

$key = bin2hex( random_bytes( 32 ) );

// Independent MD5 contract vector: sorted raw pairs, no URL encoding, key suffix.
$vector = array(
	'zeta'      => 'x+y',
	'alpha'     => '100.00',
	'sign'      => 'ignored',
	'sign_type' => 'MD5',
	'empty'     => '',
);
$expected = md5( 'alpha=100.00&zeta=x+y' . $key );
epay_test_assert( hash_equals( $expected, EPAY_Notify_Handler::sign( $vector, $key ) ), 'MD5 signature must follow the EPay raw-value contract.' );
echo "PASS: MD5 contract\n";

$valid = epay_test_signed_params( $key );
epay_test_expect_callback( 'valid GET notification', 'valid-get', $key, $valid, 'success', 1, 'GET' );
epay_test_expect_callback( 'valid POST notification', 'valid-post', $key, $valid, 'success', 1, 'POST' );
epay_test_expect_callback( 'valid GET notification retains unsigned Woo API route query', 'valid-get-route', $key, $valid, 'success', 1, 'GET_ROUTE' );
epay_test_expect_callback( '100 and 100.00 are equivalent at two decimals', 'decimal-equivalence', $key, epay_test_signed_params( $key, array( 'money' => '100' ) ), 'success', 1 );

$bad = $valid;
$bad['sign'] = str_repeat( '0', 32 );
epay_test_expect_callback( 'invalid signature rejected', 'bad-sign', $key, $bad, 'fail', 0 );
epay_test_expect_callback( 'wrong merchant rejected', 'bad-merchant', $key, epay_test_signed_params( $key, array( 'pid' => 'other-merchant' ) ), 'fail', 0 );
epay_test_expect_callback( 'unsuccessful status rejected', 'bad-status', $key, epay_test_signed_params( $key, array( 'trade_status' => 'TRADE_CLOSED' ) ), 'fail', 0 );
epay_test_expect_callback( 'wrong channel rejected', 'bad-type', $key, epay_test_signed_params( $key, array( 'type' => 'wxpay' ) ), 'fail', 0 );
epay_test_expect_callback( 'wrong amount rejected', 'bad-amount', $key, epay_test_signed_params( $key, array( 'money' => '99.99' ) ), 'fail', 0 );
epay_test_expect_callback( 'array parameter rejected without warning or crash', 'array-param', $key, $valid, 'fail', 0, 'ARRAY' );
epay_test_expect_callback( 'conflicting GET/POST values rejected', 'mixed-duplicate', $key, $valid, 'fail', 0, 'MIXED' );

$duplicate = epay_test_expect_callback( 'repeat notification acknowledged', 'duplicate', $key, $valid, 'success', 0, 'GET', 'processing' );
epay_test_assert( 200 === $duplicate['http_status'], 'Duplicate callback must return HTTP 200.' );

$missing_trade_no_params = epay_test_signed_params( $key );
unset( $missing_trade_no_params['trade_no'] );
$missing_trade_no_params['sign'] = EPAY_Notify_Handler::sign( $missing_trade_no_params, $key );
epay_test_expect_callback( 'missing trade_no rejected', 'missing-trade-no', $key, $missing_trade_no_params, 'fail', 0 );

$malformed_id = epay_test_expect_callback( 'malformed order ID 12abc must not settle order 12', 'malformed-order-id', $key, epay_test_signed_params( $key, array( 'out_trade_no' => '12abc' ) ), 'fail', 0, 'GET', 'pending', 'CNY', 12, '12abc' );
if ( $malformed_id['completed_count'] > 0 ) {
	echo "FINDING: absint-style order lookup can bind malformed ID 12abc to order 12.\n";
}

$precision = epay_test_expect_callback( 'excess precision must not round into a valid amount', 'excess-precision', $key, epay_test_signed_params( $key, array( 'money' => '100.001' ) ), 'fail', 0 );
if ( $precision['completed_count'] > 0 ) {
	echo "FINDING: amount normalization rounded a non-exact value into the order amount.\n";
}

epay_test_expect_callback( 'cancelled order requires manual review', 'cancelled-order', $key, $valid, 'fail', 0, 'GET', 'cancelled' );
epay_test_expect_callback( 'refunded order without an original transaction cannot settle', 'refunded-order', $key, $valid, 'fail', 0, 'GET', 'refunded' );

epay_test_expect_callback( 'non-CNY order rejected for this CNY integration', 'non-cny', $key, $valid, 'fail', 0, 'GET', 'pending', 'USD' );

$busy = epay_test_run_child( 'busy-lock', $key, $valid, 'GET', 'pending', 'CNY', 123, '123', 'busy' );
epay_test_assert( 'fail' === $busy['body'] && 0 === $busy['completed_count'], 'A callback must not complete an order while another unexpired lock owner exists.' );
epay_test_assert( preg_match( '/^([0-9]+):' . str_repeat( 'a', 32 ) . '$/D', $busy['lock_value'], $busy_owner ) && (int) $busy_owner[1] >= time(), 'A busy callback must leave the existing unexpired lock owner untouched.' );
echo "PASS: busy callback lock rejected\n";

$expired = epay_test_run_child( 'expired-lock', $key, $valid, 'GET', 'pending', 'CNY', 123, '123', 'expired' );
epay_test_assert( 'success' === $expired['body'] && 1 === $expired['completed_count'], 'A callback must take over an expired lock and complete the order.' );
epay_test_assert( null === $expired['lock_value'], 'The new owner must release its lock after completion.' );
echo "PASS: expired callback lock taken over\n";

$replacement = epay_test_run_child( 'replacement-lock', $key, $valid, 'GET', 'pending', 'CNY', 123, '123', 'replacement-on-release' );
epay_test_assert( 'success' === $replacement['body'] && 1 === $replacement['completed_count'], 'The replacement-owner callback should still complete normally.' );
epay_test_assert( $replacement['replacement_value'] === $replacement['lock_value'], 'Releasing an old lock owner must not delete a replacement owner.' );
echo "PASS: callback release preserves replacement owner\n";

if ( $GLOBALS['epay_test_failures'] > 0 ) {
	fwrite( STDERR, sprintf( "Regression run failed: %d expectation(s) unmet.\n", $GLOBALS['epay_test_failures'] ) );
	exit( 1 );
}

echo "Regression run passed.\n";
