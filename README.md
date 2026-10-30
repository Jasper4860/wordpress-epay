# EPay for WooCommerce

WooCommerce 易支付网关插件，为不同支付通道提供独立的结账选项。

二次开发仓库：[Jasper4860/wordpress-epay](https://github.com/Jasper4860/wordpress-epay)。

基于 [wxcydzcc/epay](https://github.com/wxcydzcc/epay) 开发，保留上游贡献者归属和 GPL-3.0 许可证。

## 支付方式

| 结账名称 | API `type` |
| --- | --- |
| 微信支付 | `wxpay` |
| 支付宝 | `alipay` |
| 银联支付 | `bank` |
| Revolut | `revolut` |
| PayPal | `paypal` |
| AlipayHK | `alipayhk` |
| USDT | `usdt` |
| LINE Pay | `linepay` |
| PayNow | `paynow` |
| 信用卡 / 借记卡 | `card` |

易支付服务端需要支持对应的 `type` 值。

## 设计说明

- 每种支付方式都是独立的 WooCommerce 网关，不使用空 `type` 的聚合收银台。
- 付款请求发送到易支付的 `submit.php`，签名方式为 MD5。
- 异步回调支持 GET 和 POST，并且具有幂等处理。
- 订单操作使用 WooCommerce API，并声明兼容 HPOS 订单存储。
- 同时支持经典 shortcode 结账和 WooCommerce Cart/Checkout Blocks。
- 项目不包含码支付、QQ 支付或聚合收银台。

## 环境要求

- PHP 7.4+
- WordPress 5.0+
- WooCommerce 5.0+

易支付 V1 使用人民币 CNY，WooCommerce 金额小数位数需要设为 2。配置不完整或商店币种不匹配时，经典结账和 Checkout Blocks 都不会展示易支付选项；不会自动换汇。其他通道同样需要服务端支持并以 CNY 计价。

## 安装

1. 下载项目并将目录命名为 `epay`。
2. 将 `epay` 上传到 WordPress 的 `wp-content/plugins/` 目录。
3. 在 WordPress 后台启用 **EPay for WooCommerce**。

## 配置

1. 前往 **WooCommerce → 易支付设置**。
2. 填写易支付网关地址、商户 ID 和商户密钥。
3. 前往 **WooCommerce → 设置 → 付款**。
4. 启用需要的支付方式并设置结账页标题。

启用后的支付方式会同时显示在经典结账页和 Checkout Block 中。每个区块支付方式沿用相同的标题、说明和图标设置。

## 回调验证

插件会先校验原始参数的 MD5 签名，再核对商户 ID、`TRADE_SUCCESS`、订单支付方式、支付通道、人民币完整订单金额和平台交易号。金额按字符串校验，不会把超出两位小数的金额四舍五入后接受。异步通知地址自动提交，兼容普通固定链接中的 `wc-api` 路由参数。

同一订单的并发回调使用数据库原子锁串行处理，重复通知返回纯文本 `success`，不会重复完成支付或扣库存。忙碌时返回 `fail`，由支付平台稍后重试；异常退出留下的锁在 5 分钟后可回收。超过该时间仍在执行的请求不在锁的保护窗口内。

已取消订单收到迟到付款时保持原状态并拒绝自动完成，需商户核实收款后人工处理；已退款订单不会因重复付款通知重新变成已支付。浏览器返回页不作为收款确认。

当前版本不包含主动查单、自动退款或订阅续费，不应把页面跳转成功当作真实支付验证。

## 2.1.1 变更

- 修复普通固定链接回调验签失败、缺少交易号仍入账、非法订单号别名、超精度金额和外币订单误收等问题。
- 增加配置检查、CNY 限制、取消订单迟到付款保护和回调并发锁。
- 增加回归测试、真实 WordPress/WooCommerce 集成测试及安装包构建脚本。

## 开发检查

提交代码前至少运行：

```powershell
.\scripts\check.ps1
.\scripts\build.ps1 -OutputDirectory .\dist
```

`check.ps1` 需要 PHP 和 Node.js 在 PATH 中，检查 PHP/JavaScript 语法并执行支付回归测试。构建包仅包含 `epay/` 下的运行文件、图标、说明和许可证，不包含测试、日志或开发依赖。ZIP 条目采用固定时间戳；手动覆盖解压测试时须主动刷新 OPcache，WordPress 插件更新请使用正常安装/更新流程。

安装外部开发依赖后，可运行 WordPress Coding Standards 和静态兼容性检查：

```bash
phpcs --standard=phpcs.xml.dist --extensions=php --ignore=tests/* .
phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 7.4- --extensions=php --ignore=tests/* .
```

真实集成测试仅可在一次性的 `http://127.0.0.1:8765` WordPress + SQLite 环境中运行：

```bash
php tests/integration/run.php /path/to/disposable/wordpress
```

该脚本会生成测试商品、订单和随机签名配置，禁止对正式数据库运行。已执行环境为 WordPress 7.1.4、WooCommerce 11.2.1、PHP 8.5.7，覆盖传统订单表及 HPOS。PHP 7.4 仅做静态兼容性检查，未执行该版本；支付平台实际收款和手机 App 唤起需使用正式商户配置另行验证。

支付通道、回调签名和金额校验属于高风险区域，修改时请同步补充验证记录。开发约定见 [AGENTS.md](AGENTS.md)。

## 贡献

欢迎提交 Issue 和 Pull Request。提交代码前请确保 PHP 语法检查通过，并说明变更对应的易支付接口行为。

## 许可证

本项目使用 [GNU General Public License v3.0](LICENSE)。
