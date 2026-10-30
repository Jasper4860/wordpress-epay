<?php
/**
 * Real WordPress/WooCommerce tests against a disposable loopback test site.
 * Usage: php -c /path/to/php.ini tests/integration/run.php /path/to/wordpress
 * The PHP server must serve that WordPress at http://127.0.0.1:8765.
 * Never run this script against a production database.
 */
if ( PHP_SAPI !== 'cli' || empty( $argv[1] ) ) {
	exit( 1 );
}
$_SERVER['HTTP_HOST'] = '127.0.0.1:8765';
$_SERVER['REQUEST_URI'] = '/';
require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
if ( 'http://127.0.0.1:8765' !== home_url() || ! defined( 'DB_ENGINE' ) || 'sqlite' !== DB_ENGINE ) {
	fwrite( STDERR, "Requires the disposable loopback SQLite environment.\n" );
	exit( 1 );
}
$results = array();
function epay_assert( $name, $condition ) {
	$GLOBALS['results'][] = array( 'name' => $name, 'passed' => (bool) $condition );
}
function epay_make_order( $currency = 'CNY' ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'Local integration item' );
	$product->set_regular_price( '95.25' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 5 );
	$product->save();
	$order = wc_create_order();
	$order->set_currency( $currency );
	$order->set_payment_method( 'epay_alipay' );
	$order->add_product( $product, 1 );
	$shipping = new WC_Order_Item_Shipping();
	$shipping->set_method_title( 'Local shipping' );
	$shipping->set_total( '6.00' );
	$order->add_item( $shipping );
	$order->calculate_totals( false );
	$order->save();
	return array( $order, $product );
}
function epay_params( $order, $changes = array() ) {
	$params = array_merge(
		array(
			'pid' => $GLOBALS['test_pid'], 'type' => 'alipay',
			'out_trade_no' => (string) $order->get_id(),
			'trade_no' => 'local-' . $order->get_id(), 'trade_status' => 'TRADE_SUCCESS',
			'name' => "测试商品 & a=b 'quoted'", 'money' => '101.25', 'sign_type' => 'MD5',
		),
		$changes
	);
	// Independent reference signer, operating on the raw values before URL encoding.
	$signable = $params;
	unset( $signable['sign'], $signable['sign_type'] );
	$signable = array_filter( $signable, static function ( $value ) { return '' !== $value && null !== $value; } );
	ksort( $signable );
	$pairs = array();
	foreach ( $signable as $key => $value ) {
		$pairs[] = $key . '=' . $value;
	}
	$params['sign'] = md5( implode( '&', $pairs ) . $GLOBALS['test_key'] );
	return $params;
}
function epay_notify( $params, $method = 'GET' ) {
	$url = home_url( '/?wc-api=epay_notify' );
	$args = array( 'method' => $method, 'timeout' => 20, 'redirection' => 0 );
	if ( 'GET' === $method ) {
		$url .= '&' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	} else {
		$args['body'] = $params;
	}
	$response = wp_remote_request( $url, $args );
	if ( is_wp_error( $response ) ) {
		return array( 0, 'transport failure' );
	}
	return array( wp_remote_retrieve_response_code( $response ), wp_remote_retrieve_body( $response ) );
}
function epay_reload( $id ) {
	clean_post_cache( $id );
	$order = wc_get_order( $id );
	$order->read_meta_data( true );
	return $order;
}
$test_key = bin2hex( random_bytes( 32 ) );
$test_pid = (string) random_int( 10000, 99999 );
update_option( 'woocommerce_currency', 'CNY' );
update_option( 'woocommerce_price_num_decimals', 2 );
update_option( 'woocommerce_manage_stock', 'yes' );
update_option( 'epay_settings', array( 'api_url' => 'https://gateway.example.invalid', 'merchant_id' => $test_pid, 'merchant_key' => $test_key ) );
update_option( 'woocommerce_epay_alipay_settings', array( 'enabled' => 'yes', 'title' => '支付宝', 'description' => '本地测试' ) );
update_option( 'woocommerce_epay_wxpay_settings', array( 'enabled' => 'yes', 'title' => '微信支付' ) );
$gateways = WC()->payment_gateways()->payment_gateways();
$gateway = $gateways['epay_alipay'];
epay_assert( 'ten channel classes registered', 10 === count( array_filter( array_keys( $gateways ), static function ( $id ) { return 0 === strpos( $id, 'epay_' ); } ) ) );
epay_assert( 'configured CNY gateway available', $gateway->is_available() );
$blocks = new EPAY_Blocks_Payment_Method( 'epay_alipay' );
$blocks->initialize();
epay_assert( 'Checkout Blocks method active', $blocks->is_active() );
epay_assert( 'Checkout Blocks script registered', array( 'epay-checkout-blocks' ) === $blocks->get_payment_method_script_handles() );
epay_assert( 'Checkout Blocks title follows gateway', '支付宝' === $blocks->get_payment_method_data()['title'] );
$saved = EPAY_Settings::get_options();
update_option( 'epay_settings', array() );
epay_assert( 'missing credentials hide classic and block methods', ! $gateway->is_available() && ! $blocks->is_active() );
update_option( 'epay_settings', $saved );
update_option( 'woocommerce_currency', 'USD' );
epay_assert( 'non-CNY store hides both methods', ! $gateway->is_available() && ! $blocks->is_active() );
update_option( 'woocommerce_currency', 'CNY' );
list( $order, $product ) = epay_make_order();
$payment = $gateway->process_payment( $order->get_id() );
parse_str( parse_url( $payment['redirect'], PHP_URL_QUERY ), $outgoing );
epay_assert( 'payment URL uses configured submit endpoint', 0 === strpos( $payment['redirect'], 'https://gateway.example.invalid/submit.php?' ) );
epay_assert( 'full amount includes shipping', '101.25' === $outgoing['money'] );
epay_assert( 'notify and return URLs have different responsibilities', false !== strpos( $outgoing['notify_url'], 'epay_notify' ) && $gateway->get_return_url( $order ) === $outgoing['return_url'] );
epay_assert( 'merchant key not exposed to redirect', false === strpos( $payment['redirect'], $test_key ) );
$data = $outgoing;
$outgoing_sign = $data['sign'];
unset( $data['sign'], $data['sign_type'] );
ksort( $data );
$pairs = array();
foreach ( $data as $name => $value ) { $pairs[] = $name . '=' . $value; }
epay_assert( 'outgoing signature matches independent reference', hash_equals( md5( implode( '&', $pairs ) . $test_key ), $outgoing_sign ) );
$response = epay_notify( epay_params( $order ) );
epay_assert( 'GET callback via real plain-permalink WC route', array( 200, 'success' ) === $response );
$paid = epay_reload( $order->get_id() );
epay_assert( 'real WC order paid and transaction saved', $paid->is_paid() && 'local-' . $order->get_id() === $paid->get_transaction_id() );
clean_post_cache( $product->get_id() );
$fresh_product = wc_get_product( $product->get_id() );
$fresh_product->read_meta_data( true );
epay_assert( 'stock reduced once', 4 === (int) $fresh_product->get_stock_quantity() );
$notes_before = count( wc_get_order_notes( array( 'order_id' => $order->get_id(), 'limit' => 100 ) ) );
epay_assert( 'sequential duplicate callback acknowledged', array( 200, 'success' ) === epay_notify( epay_params( $order ) ) );
epay_assert( 'duplicate adds no order notes', $notes_before === count( wc_get_order_notes( array( 'order_id' => $order->get_id(), 'limit' => 100 ) ) ) );
clean_post_cache( $product->get_id() );
epay_assert( 'duplicate does not reduce stock again', 4 === (int) wc_get_product( $product->get_id() )->get_stock_quantity() );
epay_assert( 'different transaction cannot replace paid order', array( 400, 'fail' ) === epay_notify( epay_params( $order, array( 'trade_no' => 'different' ) ) ) );
list( $post_order ) = epay_make_order();
epay_assert( 'POST callback via real WC route', array( 200, 'success' ) === epay_notify( epay_params( $post_order ), 'POST' ) );
$bad_cases = array(
	'wrong merchant' => array( 'pid' => '1' ), 'wrong channel' => array( 'type' => 'wxpay' ),
	'wrong status' => array( 'trade_status' => 'TRADE_FINISHED' ), 'wrong amount' => array( 'money' => '1.00' ),
	'excess precision' => array( 'money' => '101.251' ), 'empty transaction' => array( 'trade_no' => '' ),
);
foreach ( $bad_cases as $name => $changes ) {
	list( $unpaid ) = epay_make_order();
	epay_assert( $name . ' rejected', array( 400, 'fail' ) === epay_notify( epay_params( $unpaid, $changes ) ) && ! epay_reload( $unpaid->get_id() )->is_paid() );
}
list( $unpaid ) = epay_make_order();
$bad_sign = epay_params( $unpaid );
$bad_sign['sign'] = str_repeat( '0', 32 );
epay_assert( 'bad signature rejected', array( 400, 'fail' ) === epay_notify( $bad_sign ) );
epay_assert( 'malformed order number cannot alias an order', array( 400, 'fail' ) === epay_notify( epay_params( $unpaid, array( 'out_trade_no' => $unpaid->get_id() . 'abc' ) ) ) );
list( $foreign ) = epay_make_order( 'USD' );
epay_assert( 'non-CNY callback rejected', array( 400, 'fail' ) === epay_notify( epay_params( $foreign ) ) );
epay_assert( 'non-CNY order cannot initiate payment', 'failure' === $gateway->process_payment( $foreign->get_id() )['result'] );
list( $cancelled ) = epay_make_order();
$cancelled->update_status( 'cancelled' );
epay_assert( 'late cancelled payment held for merchant review', array( 400, 'fail' ) === epay_notify( epay_params( $cancelled ) ) && 'cancelled' === epay_reload( $cancelled->get_id() )->get_status() );
$sanitized = EPAY_Settings::instance()->sanitize( array( 'api_url' => 'https://gateway.example.invalid/', 'merchant_id' => $test_pid, 'merchant_key' => '', 'return_url' => '' ) );
epay_assert( 'blank settings password preserves existing key', $test_key === $sanitized['merchant_key'] );
ob_start();
EPAY_Settings::instance()->render_field( array( 'id' => 'merchant_key', 'type' => 'password', 'placeholder' => '', 'description' => '' ) );
$field = ob_get_clean();
epay_assert( 'saved key not rendered in settings HTML', false === strpos( $field, $test_key ) );
update_option( 'epay_settings', array( 'api_url' => array( 'bad' ), 'merchant_id' => $test_pid, 'merchant_key' => $test_key ) );
epay_assert( 'malformed saved settings hide gateway without a TypeError', ! $gateway->is_available() );
update_option( 'epay_settings', $saved );
list( $locked_order ) = epay_make_order();
$lock_name = 'epay_callback_lock_' . $locked_order->get_id();
add_option( $lock_name, ( time() + 300 ) . ':' . bin2hex( random_bytes( 16 ) ), '', false );
epay_assert( 'active callback lock rejects simultaneous settlement', array( 400, 'fail' ) === epay_notify( epay_params( $locked_order ) ) && ! epay_reload( $locked_order->get_id() )->is_paid() );
epay_assert( 'busy callback preserves existing owner', false !== get_option( $lock_name, false ) );
update_option( $lock_name, ( time() - 30 ) . ':' . bin2hex( random_bytes( 16 ) ) );
epay_assert( 'expired callback lock can be recovered', array( 200, 'success' ) === epay_notify( epay_params( $locked_order ) ) );
wp_cache_delete( $lock_name, 'options' );
epay_assert( 'callback lock released after success', false === get_option( $lock_name, false ) );
$storage = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'HPOS' : 'CPT';
$failed = count( array_filter( $results, static function ( $row ) { return ! $row['passed']; } ) );
echo json_encode( array( 'wordpress' => $wp_version, 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION, 'storage' => $storage, 'database' => 'SQLite', 'checks' => count( $results ), 'failed' => $failed, 'results' => $results ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
exit( $failed ? 1 : 0 );
