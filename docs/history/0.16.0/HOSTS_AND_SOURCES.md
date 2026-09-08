# 主机条件与资料

核查 2026-09-06；没有登录你的蓝队云/萌哒云账号，也没有实际部署 VPS。当前会话工程与分卷包可读取，不把不可见项目记忆当已确认配置。

## 蓝队云免费虚拟主机

官方说明 https://notify.landui.com/help/show-12693 （页面日期 2025-03-06）：Windows 环境、不支持单独更换环境；FTP 而非 SSH；上传 wwwroot；需绑定自己的可用域名；数据库通过面板管理、连接 localhost/127.0.0.1；应遵守指定页脚鸣谢和续期要求。

该页没有证明具体套餐 PHP 小版本/64 位/PDO/OpenSSL/Fileinfo/HTTPS/出站 DNS 与 443/配额满足本程序条件。请用实际面板、工单和安装自检确认。IIS 规则随包提供但未实机验收；公网私有目录测试不可省略，不得删除保护文件绕过错误。

## 萌哒云“免费1”

本轮未取得可确认具体套餐当前配置的官方正文，撤回旧文档 PHP 8.2 的未复核断言。以实际面板为准，检查同样的 PHP、扩展、数据库、HTTPS、网络与空间条件，根目录位置以该主机面板为准。程序不能突破套餐限制。

## VPS

面板服务器创建 PHP 站点/数据库/证书后合并保护规则，不运行新机脚本覆盖宝塔/1Panel。无面板全新服务器提供 deploy/install-vps.sh、Nginx/Caddy 模板；本次仅检查脚本语法，未安装系统包或签证书。详见 DEPLOYMENT.md。

## 来源

https://www.zibll.com/375.html/comment-page-4
https://www.zibll.com/14960.html
https://www.zibll.com/zibll-pay-desc
https://www.php.net/supported-versions.php

支付资料见 PAYMENTS.md，云存储/翻译见 MEDIA_LANGUAGE_BUILDER.md。资料不是第三方对本工程的认证或源代码授权替代。
