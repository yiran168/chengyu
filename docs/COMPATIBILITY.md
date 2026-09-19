# PHP 与部署兼容性

0.23.2 使用同一套 64 位 PHP 7.4–8.5 代码，功能不按小版本裁剪。需要原生 PDO MySQL 或 SQLite、OpenSSL，以及可写的私有存储。文件类型识别无需 Fileinfo、GD 或 ZipArchive；默认不依赖 curl、mbstring、FFI、Composer、Node、Redis 或常驻服务。

本地实际执行 PHP 7.4.33 / 8.0.30 / 8.1.34 / 8.2.33 / 8.3.33 / 8.4.25 / 8.5.10，全部使用原生 pdo_sqlite。Linux CI 为七个 PHP 小版本各运行原生 MySQL 8.0 与 SQLite 两组，共 14 组；准确提交、数量与状态见 TEST_REPORT.md，不把旧报告计入最新改动的通过数。

Windows 安装网址路径已改用专用 WebPath，避免 dirname() 产生反斜杠 Cookie 路径；媒体签名探测有固定字节边界，采用统一内置识别器，支持无 Fileinfo 的共享主机。兼容 7.4 不代表旧 PHP 运行时本身仍有官方安全维护。

MySQL 使用 InnoDB、utf8mb4、预处理查询与事务，需要数据库建表、加列和索引权限。SQLite 文件必须放在网站公开目录外；同一数据库并发写入仍受 SQLite 锁和共享主机配额约束，不承诺无限吞吐。

虚拟主机上传生产目录即可运行普通页面、会员、内容管理和本地资源；自动金融任务需要外部定时触发或手动后台执行。所有外部支付、邮件、云存储、物流和翻译仍需要真实账号、服务授权、允许的 DNS/HTTPS 出站和公网回调。

提供 Apache/LiteSpeed .htaccess、IIS web.config、Nginx/Caddy 模板及全新无面板 VPS 脚本。实际两家免费主机、生产 IIS/Apache/Nginx 与完整 VPS 装机没有接入测试；通过 PHP 语言测试不能保证任何套餐都有扩展、空间或网络权限。

0.23图片库复用现有存储，查询每页最多12条并增加公开/归属索引；无需GD或图像处理进程，不按PHP小版本关闭选择功能。
