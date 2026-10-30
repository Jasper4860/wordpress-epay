<?php
/**
 * Shared EPay gateway implementation.
 *
 * @package EPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Shared implementation for direct EPay payment methods. */
abstract class EPAY_Abstract_Gateway extends WC_Payment_Gateway {

	/**
	 * API payment type.
	 *
	 * @var string
	 */
	protected $epay_type = '';

	/**
	 * Default checkout title.
	 *
	 * @var string
	 */
	protected $epay_title = '';

	/**
	 * Optional icon filename.
	 *
	 * @var string
	 */
	protected $epay_icon = 'sy.svg';

	/** Initialize the gateway and its WooCommerce settings. */
	public function __construct() {
		$this->id           = 'epay_' . $this->epay_type;
		$this->has_fields   = false;
		$this->supports     = array( 'products' );
		$this->method_title = $this->epay_title;
		// translators: %s is the configured payment channel name.
		$this->method_description = sprintf( __( '通过易支付的 %s 通道付款。', 'epay' ), $this->epay_title );
		$this->icon               = EPAY_URL . 'assets/logo/' . $this->epay_icon;

		$this->init_form_fields();
		$this->init_settings();

		$this->title = $this->get_option( 'title', $this->epay_title );
		// translators: %s is the configured payment channel name.
		$this->description = $this->get_option( 'description', sprintf( __( '使用 %s 付款。', 'epay' ), $this->epay_title ) );
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/** Define the settings available for this payment channel. */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'woocommerce' ),
				'type'    => 'checkbox',
				// translators: %s is the configured payment channel name.
				'label'   => sprintf( __( '启用 %s', 'epay' ), $this->epay_title ),
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Title', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( '结账页向客户显示的名称。', 'epay' ),
				'default'     => $this->epay_title,
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'woocommerce' ),
				'type'        => 'textarea',
				'description' => __( '结账页向客户显示的说明。', 'epay' ),
				// translators: %s is the configured payment channel name.
				'default'     => sprintf( __( '使用 %s 付款。', 'epay' ), $this->epay_title ),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Hide methods that cannot create a valid V1 CNY payment.
	 *
	 * @return bool
	 */
	public function is_available() {
		return parent::is_available() && EPAY_Settings::is_configured() && 'CNY' === get_woocommerce_currency() && 2 === wc_get_price_decimals();
	}

	/**
	 * Redirect the customer to the selected direct EPay channel.
	 *
	 * @param int $order_id Order ID.
	 * @return array<string, string>
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( '无法找到订单，请重试。', 'epay' ), 'error' );
			return array( 'result' => 'failure' );
		}
		if ( $order->is_paid() ) {
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}
		if ( ! $order->needs_payment() || $order->get_payment_method() !== $this->id ) {
			wc_add_notice( __( '该订单当前无法使用此支付方式付款。', 'epay' ), 'error' );
			return array( 'result' => 'failure' );
		}
		if ( 'CNY' !== $order->get_currency() || 2 !== wc_get_price_decimals() ) {
			wc_add_notice( __( '易支付 V1 仅支持人民币及两位小数金额。', 'epay' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$redirect = $this->build_payment_url( $order );
		if ( is_wp_error( $redirect ) ) {
			// translators: %s is the payment gateway error message.
			$order->add_order_note( sprintf( __( '易支付发起失败：%s', 'epay' ), $redirect->get_error_message() ) );
			wc_add_notice( $redirect->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		// translators: %s is the configured payment channel name.
		$order->update_status( 'pending', sprintf( __( '已发起 %s 支付。', 'epay' ), $this->epay_title ) );

		return array(
			'result'   => 'success',
			'redirect' => $redirect,
		);
	}

	/** Build the payment URL for a WooCommerce order.
	 *
	 * @param WC_Order $order Order object.
	 * @return string|WP_Error
	 */
	private function build_payment_url( $order ) {
		$options = EPAY_Settings::get_options();
		$api_url = isset( $options['api_url'] ) ? $options['api_url'] : '';
		$pid     = isset( $options['merchant_id'] ) ? $options['merchant_id'] : '';
		$key     = isset( $options['merchant_key'] ) ? $options['merchant_key'] : '';

		if ( ! EPAY_Settings::is_configured() ) {
			return new WP_Error( 'epay_not_configured', __( '易支付配置不完整，请联系网站管理员。', 'epay' ) );
		}
		$amount = EPAY_Notify_Handler::normalize_amount( $order->get_total() );
		if ( false === $amount || '0.00' === $amount ) {
			return new WP_Error( 'epay_invalid_amount', __( '订单金额无效，无法发起易支付。', 'epay' ) );
		}

		$return_url = ! empty( $options['return_url'] ) ? $options['return_url'] : $this->get_return_url( $order );
		$params     = array(
			'pid'          => (string) $pid,
			'type'         => $this->epay_type,
			'out_trade_no' => (string) $order->get_id(),
			'notify_url'   => WC()->api_request_url( 'epay_notify' ),
			'return_url'   => $return_url,
			'name'         => $this->get_order_name( $order ),
			'money'        => $amount,
			'sign_type'    => 'MD5',
		);

		$params['sign'] = EPAY_Notify_Handler::sign( $params, $key );

		return rtrim( $api_url, '/' ) . '/submit.php?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/** Build a readable description from the order's product names.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	private function get_order_name( $order ) {
		$names = array();
		foreach ( $order->get_items() as $item ) {
			$names[] = wp_strip_all_tags( $item->get_name() );
		}

		$name = implode( ', ', $names );
		if ( function_exists( 'mb_strimwidth' ) ) {
			return mb_strimwidth( $name, 0, 100, '...', 'UTF-8' );
		}

		return strlen( $name ) > 100 ? substr( $name, 0, 97 ) . '...' : $name;
	}
}
