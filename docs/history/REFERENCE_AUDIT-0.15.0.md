# 原始源码、公开历史与协议核查

核查日期：2026-09-06；交付澄屿 0.15.0，修改基线为实际 0.14.0 ZIP。原主题只作为功能需求参考，不复制其 PHP、JS、CSS、品牌、图标、截图或数据。

## 原始三个 RAR 分卷

本轮通过 libarchive 重新读取最初上传的 part1、part2、part3 分卷，提取 **99 个目标文本文件，共 2,040,141 字节**供检索；`style.css` 的版本标记为 **V7.8**。

重点搜索 `oauth/`、`zibpay/`、用户与后台配置入口，直接阅读了 OAuth 调度、会员开通/续期/永久权益等相关定义。99 是提取检索范围，**不是逐行审计了全部 99 个文件，也不是审计整个原站备份**。针对查单的关键词未直接定位到原版完整查询实现，本项目查单协议依据支付服务商文档独立编写。

`evidence/reference-index-0.15.json` 仅保存主题相对路径、字节数及摘要，`reference-feature-map-0.15.json` 仅保存关键词命中定位，不含原代码或旧站路径。没有执行原 PHP、启动原站、导入旧数据库、使用备份凭据、绕过授权或把原主题重新包装交付。

## 官方版本记录

| 资料 | 本轮取得与用途 |
| --- | --- |
| https://www.zibll.com/375.html/comment-page-2 | 成功打开主日志正文，解析页 1210 行；列至 V9.1，2026-09-01 发布，核对第三方登录、支付、永久会员、社区与商城功能族 |
| https://www.zibll.com/375.html | 直接入口多次超时，改用同官网评论分页取得主正文；不是借非官方转载替代 |
| https://www.zibll.com/14960.html | 本轮正文请求超时；保留此前 0.14 对该历史档案的核查记录，不声称本轮重新取得全部早期正文 |
| https://www.zibll.com/introduction | 取得官方索引，确认 V1.0–V5.x 档案入口与其展示摘要 |

覆盖对象是公开日志已经收录的版本功能，而不是下载并运行了每一个历史安装包。日志并不保证列尽商业插件、所有参数和细节；涉及 WordPress 核心、古腾堡、hooks、插件 ABI 的兼容行为不属于本非 WordPress 项目的原样兼容目标。

本轮在既有差异表基础上补充 Google/GitHub/Microsoft、易支付按需查单、永久会员、整车金额上限、配置快照与徽章。并未把 QQ/微信/抖音、短信、访客购买、完整物流售后、升级补差、Stripe/PayPal、自动原路退款或代付标成已完成。

## 协议与平台主文档

| 官方或服务商资料 | 用途与边界 |
| --- | --- |
| https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/authorizing-oauth-apps | GitHub.com OAuth 授权码与 S256 PKCE；不承诺所有 Enterprise Server 版本 |
| https://developers.google.com/identity/protocols/oauth2/web-server | Google 服务端授权码流程；本实现请求基础身份，不按邮箱合并账户 |
| https://developers.google.com/identity/openid-connect/openid-connect | Google 用户信息稳定 subject 标识；本实现调用受保护的用户信息端点，不将未验签 ID token 当身份 |
| https://learn.microsoft.com/en-us/graph/auth-v2-user | Microsoft 授权、令牌与 Graph 用户信息；使用完整 User.Read scope，应用需配置适当账户类型 |
| https://epay.lyew.com/doc/v1_legacy_api.html | 经典 V1 单订单查询 act=order、商户及订单绑定、status 0/1；不覆盖所有同名平台与 V2 协议 |
| https://www.php.net/supported-versions.php | 区分上游维护状态与本项目同功能源码目标 |
| https://developer.mozilla.org/en-US/docs/Web/API/Web_Animations_API | 可取消的有限动画、结束状态和渐进增强 |

技术实现没有下载或复制上述平台的 SDK；共用原生 PHP HTTPS 传输及既有业务服务。搜索结果中出现的声称“官方开源”的第三方代码仓库未获官网身份交叉证明，未据其声明改变原代码的授权判断，也没有把该仓库代码加入交付。

## 当前剩余差异

完整范围见 `FEATURE_MATRIX.md`。其他登录驱动、短信/WebAuthn、会员补差、圈主经营分成、复杂版主策略、自动徽章发放、访客购物、运费与退换货、支付直连/退款/代付、大型媒体、专业搜索、自动化整站备份与主机面板证书 API 等仍有缺口。

## 权利与数据边界

完整包不含原主题代码/素材、原始分卷、旧站数据库或凭据；使用独立品牌、数据结构与视觉表达，不冒充原厂。功能参考与独立编写不自动等于零法律风险。实际运营者仍应核实内容授权、品牌使用、隐私、支付与消费者权益等适用要求。