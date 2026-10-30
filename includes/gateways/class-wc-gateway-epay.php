<?php
/**
 * Direct EPay payment methods.
 *
 * @package EPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/** Direct EPay gateway for WeChat Pay. */
class EPAY_Gateway_Wechat extends EPAY_Abstract_Gateway {
	/**
	 * EPay API channel identifier.
	 *
	 * @var string
	 */
	protected $epay_type = 'wxpay';
	/**
	 * Checkout title.
	 *
	 * @var string
	 */
	protected $epay_title = '微信支付';
	/**
	 * Checkout icon filename.
	 *
	 * @var string
	 */
	protected $epay_icon = 'wx-logo.jpg';
}

/** Direct EPay gateway for Alipay. */
class EPAY_Gateway_Alipay extends EPAY_Abstract_Gateway {
	/**
	 * EPay API channel identifier.
	 *
	 * @var string
	 */
	protected $epay_type = 'alipay';
	/**
	 * Checkout title.
	 *
	 * @var string
	 */
	protected $epay_title = '支付宝';
	/**
	 * Checkout icon filename.
	 *
	 * @var string
	 */
	protected $epay_icon = 'zfb-logo.jpg';
}

/** Direct EPay gateway for UnionPay. */
class EPAY_Gateway_Bank extends EPAY_Abstract_Gateway {
	/**
	 * EPay API channel identifier.
	 *
	 * @var string
	 */
	protected $epay_type = 'bank';
	/**
	 * Checkout title.
	 *
	 * @var string
	 */
	protected $epay_title = '银联支付';
	/**
	 * Checkout icon filename.
	 *
	 * @var string
	 */
	protected $epay_icon = 'bank.svg';
}

/** Direct EPay gateway for Revolut. */
class EPAY_Gateway_Revolut extends EPAY_Abstract_Gateway {
	/**
	 * EPay API channel identifier.
	 *
	 * @var string
	 */
	protected $epay_type = 'revolut';
	/**
	 * Checkout title.
	 *
	 * @var string
	 */
	protected $epay_title = 'Revolut';
	/**
	 * Checkout icon filename.
	 *
	 * @var string
	 */
	protected $epay_icon = 'revolut.svg';
}

/** Direct EPay gateway for PayPal. */
class EPAY_Gateway_Paypal extends EPAY_Abstract_Gateway {
	/**
	 * EPay API channel identifier.
	 *
	 * @var string
	 */
	protected $epay_type = 'paypal';
	/**
	 * Checkout title.
	 *
	 * @var string
	 */
	protected $epay_title = 'PayPal';
	/**
	 * Checkout icon filename.
	 *
	 * @var string
	 */
	protected $epay_icon = 'paypal.svg';
}

/** Direct EPay gateway for AlipayHK. */
class EPAY_Gateway_Alipayhk extends EPAY_Abstract_Gateway {
	/**
	 * EPay API channel identifier.
	 *
	 * @var string
	 */
	protected $epay_type = 'alipayhk';
	/**
	 * Checkout title.
	 *
	 * @var string
	 */
	protected $epay_title = 'AlipayHK';
	/**
	 * Checkout icon filename.
	 *
	 * @var string
	 */
	protected $epay_icon = 'alipayhk.svg';
}

/** Direct EPay gateway for USDT. */
class EPAY_Gateway_Usdt extends EPAY_Abstract_Gateway {
	/**
	 * EPay API channel identifier.
	 *
	 * @var string
	 */
	protected $epay_type = 'usdt';
	/**
	 * Checkout title.
	 *
	 * @var string
	 */
	protected $epay_title = 'USDT';
	/**
	 * Checkout icon filename.
	 *
	 * @var string
	 */
	protected $epay_icon = 'usdt.svg';
}

/** Direct EPay gateway for LINE Pay. */
class EPAY_Gateway_Linepay extends EPAY_Abstract_Gateway {
	/**
	 * EPay API channel identifier.
	 *
	 * @var string
	 */
	protected $epay_type = 'linepay';
	/**
	 * Checkout title.
	 *
	 * @var string
	 */
	protected $epay_title = 'LINE Pay';
	/**
	 * Checkout icon filename.
	 *
	 * @var string
	 */
	protected $epay_icon = 'linepay.svg';
}

/** Direct EPay gateway for PayNow. */
class EPAY_Gateway_Paynow extends EPAY_Abstract_Gateway {
	/**
	 * EPay API channel identifier.
	 *
	 * @var string
	 */
	protected $epay_type = 'paynow';
	/**
	 * Checkout title.
	 *
	 * @var string
	 */
	protected $epay_title = 'PayNow';
	/**
	 * Checkout icon filename.
	 *
	 * @var string
	 */
	protected $epay_icon = 'paynow.svg';
}

/** Direct EPay gateway for credit and debit cards. */
class EPAY_Gateway_Card extends EPAY_Abstract_Gateway {
	/**
	 * EPay API channel identifier.
	 *
	 * @var string
	 */
	protected $epay_type = 'card';
	/**
	 * Checkout title.
	 *
	 * @var string
	 */
	protected $epay_title = '信用卡 / 借记卡';
	/**
	 * Checkout icon filename.
	 *
	 * @var string
	 */
	protected $epay_icon = 'card.svg';
}
