# 0.23.1 实际测试记录

2026-09-19，数据库结构 v15。本版仅修复凭据保存与不存在文章的编辑入口。测试使用隔离数据库、测试账号和项目内临时目录。

| 检查 | 结果 | 证据 |
| --- | --- | --- |
| Windows 原生 PDO SQLite | PHP 7.4—8.5 七个版本各 624/624，stderr 为空 | evidence/0231/native-matrix.json、php-*.json |
| Linux 原生 MySQL / SQLite | 14/14 组，各 624/624，逐份核对真实版本和 PDO 驱动 | evidence/0231/ci-verified.json、native-drivers.json、native-*.json |
| 实际 PHP HTTP / TLS SMTP | 1024/1024 | evidence/0231/http-final.json |
| PHP / JS 语法 | PHP 7.4 与 8.5 各 311 个文件通过，JS 20 个通过 | evidence/0231/syntax.json |
| 动画曲线不变量 | 42/42 | evidence/0231/spring-final.json |

CI 验证提交 `eb167346a8cf2d533d04cfa1cadf742f5c8df0de`：[实际运行](https://github.com/yiran168/chengyu/actions/runs/35439745843)。下载并核对全部 14 份制品，每份都通过 624 项；没有以旧版 CI 替代本版结果。后续发布提交仅更新文档、打包清单和发布元数据，PHP/JS 业务代码保持一致。

新增测试先在旧代码上复现两类错误，再确认修复后通过。核心测试覆盖加密密钥重载、首尾空白、空值保留、明确清除及非法值拒绝；HTTP 检查真实 SMTP AUTH LOGIN 认证与发送、留空再次保存，以及会员/管理员访问缺失编辑目标的 404、正常新建和既有文章权限。参见[本轮检查报告](CODE_AUDIT-2026-09-19.md)。

界面未作改版。本包保留 0.23.0 的 193 张历史预览和完整浏览器 200/200 记录，明确属于上一版，本轮没有冒充重新执行完整交互套件。本轮重新生成并检查发布手册，结果见 manual-checks.json。0.23.0 的详细历史测试报告见 history/0.23.0/TEST_REPORT.md。

从 0.23.0 升级保持结构 v15，不增加迁移。既有迁移链仍在核心/HTTP 测试中执行，但本轮没有另做全部历史生产库升级演练。实际带空白密码的 MySQL 安装认证、真实免费主机/VPS、外部 SMTP/支付/对象存储实例未联调；不能将语言与隔离数据库测试等同于所有实例均已部署通过。

包校验结果和 SHA-256 由打包工具独立生成，见 Release 的 package-verification.json 及 SHA256SUMS.txt。
