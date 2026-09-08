# 0.19.0 实际测试与交付记录

日期：2026-09-08；基线 0.18.0；数据库结构 v11。以下区分本地最新实现、较早提交的远端 CI 和未完成的浏览器工作。历史报告保留在 history，不作为本版通过证据。

| 检查 | 结果 | 证据 |
| --- | --- | --- |
| Windows 原生 PDO SQLite | PHP 7.4/8.0/8.1/8.2/8.3/8.4/8.5 各 575/575，stderr 为空 | evidence/019/native-matrix.json 与 php-*.json |
| Linux 原生 MySQL 8.0 + SQLite | 14/14 CI 作业通过，各 573/573 | evidence/019/ci-native.json；提交 2d0579b |
| 真实 PHP HTTP 请求 | 838/838 | evidence/019/http-final.json |
| 弹簧精确解与不变量 | 42/42 | evidence/019/spring-final.json |
| PHP 语法 | 7.4 与 8.5 各 271 文件无错误 | evidence/019/syntax.json |
| JavaScript / VPS Bash | 17 JS 语法通过；VPS 脚本 Bash 语法通过 | evidence/019/syntax.json |
| 真实浏览器 | 已验证本地 URL、首页、登录后台、站标、部分响应式/主题交互；完整套件未通过 | evidence/019/browser-incomplete.json，019 前缀截图 |

远端运行：[GitHub Actions 原生矩阵](https://github.com/yiran168/chengyu/actions/runs/34212804124)。该次 CI 对应较早的 0.19 实现提交；之后添加的轮播、按钮组、安装工具测试及文档修订使用最新本地 575 项与 HTTP 838 项证据，不能把旧 CI 说成最新源码重新执行的结果。

补充升级演练：使用原始 0.18 upload 代码建立有真实购买记录的隔离 SQLite 数据库，在 PHP 7.4 和 8.5 下由新代码升级至 v11，余额、订单、正文和付费权限均保留，新资源表正常创建（evidence/019/upgrade.json）。

## 真实验证范围

HTTP 测试在临时复制站点使用原生 PDO SQLite 和实际 requests 请求，从公开空验证文件、CLI 生成独立口令、拒绝错误口令开始安装；测试真实 Cookie/CSRF、管理权限、商品结算、支付协议夹具、退款、文件 multipart/Range、邮件本地 TLS、资源多版本与反馈、模板发布及无 JavaScript 服务端内容。外部商户与网盘没有被自动下单或请求。

核心测试包含真实数据库事务、幂等、竞争条件、购买权限、退款撤权、备份认证与恢复；新增 SKU 标价、Windows URL、JSON 布尔、媒体探测与镜像额度回归。7.4–8.5 的所有业务功能使用同一路径。

## 未完成与未实测

完整浏览器套件在动效实验室截图步骤超时，最后一次结果为 19/20 后停止，不是整体通过。已调整截图与等待方式，但最新完整重跑尚未执行；新增轮播的浏览器自动播放/键盘流程仍需补充验收。网页真实 HTTP 渲染通过不能替代这些交互测试。

没有登录蓝队云、萌哒云实例，没有运行实际 VPS 装机、IIS/Apache/Nginx 生产规则或真实商户支付/退款/代付、真实外部 SMTP/S3/物流/翻译服务。两家免费套餐必须提供运行时扩展、数据库和网络权限；不承诺所有免费套餐无条件可运行。

正式 ZIP 的 CRC、路径、PHP 语法、源码清单、生产字节一致性与 SHA-256 在包外 package-verification.json。报告不能放入它自己正在校验的归档摘要内。打包检查仅验证交付结构，不提升未实测项的状态。
