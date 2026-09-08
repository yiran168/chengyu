# 0.17 支付、退款与代付

本软件提供协议实现，不提供商户账户、免签资金通道、经营资质或平台批准。新外部通道默认关闭；**本次未做真实商户联调/实付**。本地签名和响应夹具不是平台认证。

## 两种交易路径

原易支付/CodePay：外部充值 → 校验通知/查询 → 站内余额 → 站内资产购买。新 Checkout：复核商品/运费/优惠 → 原子预留库存 → 创建支付总单与单位订单 → 站内余额或官方支付 → 服务器核实后履约。会员补差目前仅走站内资产。

金额为整数最小单位；不接受科学计数法、浮动尾数或未绑定订单的金额。站点单一法币由 store_currency 决定，发生金融活动后不能改标签充当换汇。无自动汇率转换。支付宝/微信仅 CNY；PayPal 适配 USD/EUR/GBP/HKD/SGD/CAD/AUD，存量人民币站不可为接 PayPal 随意改币种。

## 官方直连接入

后台设置 → 官方直连支付（direct_payments），按字段填写自己的密钥、商户/应用编号、平台公钥、环境和通知凭据。必须有有效 HTTPS、公网通知地址、出站 DNS/443 和可信 CA。不要把私钥放在公开目录或工单。

| 通道 | 已实现形态 | 边界 |
| --- | --- | --- |
| 支付宝 | RSA2 WAP手机网站或PAGE电脑网站支付，共用查单/关单、退款/查询及app/seller/订单/金额验签 | 按选择形态签约；无当面付、证书模式和服务商进件 |
| 微信 | API v3 H5、商户签名、平台验签、通知 AES-GCM、查单/关单/退款 | 需 H5 权限/正确域名/公网客户端 IP；无 JSAPI/Native/小程序；自行维护平台公钥与序列号 |
| Stripe | Checkout 单次 payment Session、Webhook HMAC、时间窗、金额/币种/交易/环境核验 | 无订阅、Connect 拆账或自动争议处置 |
| PayPal | Orders v2 创建/批准/服务端 capture、商户/金额绑定、平台核验 webhook、Payments v2 退款 | 当前只支持非实物交易，未完成绑定平台收货地址的实物 checkout |

通知例：`https://你的域名/notify-direct.php?gateway=alipay_direct`，其他通道替换为 wechat、stripe、paypal；子目录安装加入实际前缀。Stripe 填对应 endpoint signing secret；PayPal 填对应 webhook ID。测试/正式环境不可混用。支付总单保存加密配置快照，旧单不改用新商户核验。浏览器跳回不是到账依据。

## 原易支付与 CodePay

易支付经典 MD5 排序签名仍保留，配置 HTTPS submit.php、pid、共享密钥和真实可用类型；通知 `/notify.php?gateway=epay`，核对 pid/type/金额/TRADE_SUCCESS/交易号并防重复入账。主动查询需匹配该网关真实经典协议；不是兼容所有同名平台、RSA v2 或任意 JSON 接口。

CodePay 是旧式 id/type/pay_id/price/param 与 pay_no/money 协议，实验性保留，默认关闭；原厂当前可开通性未确认。拒绝浮动尾数或忽略金额。供应商提供易支付兼容协议时选择易支付，不根据商品名猜协议。

## 售后与原路退款

用户从订单申请，管理员以密码及已启用的第二因素审核。支持单位订单退款（含已分摊运费）、退货退款、同 SKU 换货、回寄物流、收货验货后可选恢复库存。已经交付的卡密不回到可销售库存。

审核后入队：站内资产购买退原资产；官方直连购买调用原通道，不能再额外给钱包加余额。稳定退款号、租约、未知结果先查后重试，验证外部成功后才完成本地回滚。超时保持待核实，不用新的退款号重试。旧充值不会通过商品售后自动退回。

未实现任意金额部分退款、跨 SKU 补差换货、拒付争议自动仲裁、收费换汇或删除用户已经下载的内容。作者/推荐收益按原单冲回，已转出收益可能形成债务，软件不能自动追回外部现金。

## 主动核付、调度与代付

收据页/后台可主动查单；Jobs 在待处理付款、退款与代付中选 1–10 个任务，支持后台、CLI、HMAC POST 及重放拒绝。它是订单级主动核付，**不是**平台日账单下载、手续费核算或银行结算。0.17另有标准CSV账单差异报告，但不自动下载平台原始账单，也不据CSV操作资金。免费主机无人值守需要可信外部调度，不存在自动运行的隐藏常驻进程。

自动代付只实现 **PayPal Payouts**，需平台批准权限、原申请接收邮箱与相同币种，管理员审款后排队；固定批次键，未知结果继续冻结。支付宝转账、微信商家转账、银行代付未实现。保留人工打款确认，禁止同笔提现同时人工付款和自动执行。

## 实际上线验收

自行验证正常付款、重复回调、回调丢失查单、错误金额/商户/币种拒绝、库存不足、过期单、旧密钥快照、越权查询、退款超时/重复退款、提现未知状态。核对平台后台、本站总单/单位单/流水，做公网 TLS、通知、真实小额支付和退款。模拟测试不能替代这些步骤。

官方资料：
https://opendocs.alipay.com/open/
https://pay.weixin.qq.com/doc/v3/merchant/4012791834
https://docs.stripe.com/api/checkout/sessions
https://docs.stripe.com/api/refunds
https://docs.stripe.com/webhooks
https://developer.paypal.com/docs/api/orders/v2/
https://developer.paypal.com/docs/api/payments/v2/
https://developer.paypal.com/docs/api/payments.payouts-batch/v1/
https://developer.paypal.com/docs/api/webhooks/v1/

核查日期 2026-09-07，平台开放能力仍以自己的账户/环境为准。

支付宝设置 `alipay_direct_mode=page` 使用 `alipay.trade.page.pay`，业务产品码为 `FAST_INSTANT_TRADE_PAY`；默认保持wap。官方协议参考：
https://aipay.alipay.com/docs/ai-web-app-payment-qianyi/api-list/alipay-trade-page-pay.html
