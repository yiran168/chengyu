# 澄屿 Chengyu 0.21.0

独立 PHP 内容、社区与数字资源平台，包含前台和可管理的后台。不需要 WordPress、运行期 Composer、Node、Redis 或常驻队列。PHP 7.4–8.5 使用同一套业务代码，支持原生 PDO MySQL / SQLite。

0.19 新增资源多版本与私有镜像、失效反馈闭环、组合筛选、22 种可视化区块、响应式轮播与按钮组，以及原创二次元站标和海岛插画。修复 Windows 安装 Cookie 路径、长文本分片上传类型识别、SKU 标价排序和布局布尔值校验问题。

保留文章/社区/圈子/问答/投票、会员、积分与余额、SKU 与卡密、实物订单、访客结算、售后退款、作者收益、课程、工单、私信、搜索、分片上传、快照恢复及后台动效实验室。功能的具体边界见 [功能矩阵](docs/FEATURE_MATRIX.md)，没有把未实现的第三方接口标成可用。

0.20 新增后台视觉素材库（79 种图标、6 个独立生成二次元图标、7 处页面素材）、6 张独立封面、年月归档、评论排序及主机限制检测。首页改为推荐阅读、分类标签和图文列表，支持服务端排序与翻页；无封面条目采用无图排版，资源显示真实价格和销量。后台可切回大图展示。修复上传回填、点击波纹撑大按钮、超过 50 条评论无法翻页及分类区块重复查询。操作见 [0.20 使用说明](docs/PLATFORM_020.md)。

0.21 新增快讯发布、时间线、订阅与站点地图；文章来源、版权模板、作者卡、上下篇、同分类相关阅读及右图/纯文字排版均可后台管理。新增独立二次元快讯图标，视觉库现有 80 个符号。修复内容保存中的过期权限信任和文章封面开关影响商品图的问题。三套参考主题已再次阅读源码并留下逐文件记录：[本轮使用说明](docs/PLATFORM_021.md) / [三主题对照](docs/REFERENCE_021.md)。

## 新站安装

1. 解压完整包或 upload.zip，在自己的电脑打开 **DEPLOY_PREPARE.html**，生成本站独立口令，保存 key.php 和私密 INSTALL_KEY.txt。
2. 上传 `site/` 内的全部文件，或直接解压 upload.zip 到网站根目录；保留隐藏的 .htaccess 和 web.config。
3. 将生成的 key.php 上传，覆盖网站的 `install/key.php`。私密 INSTALL_KEY.txt 留在本地，绝不上传。
4. 准备空 MySQL 数据库，打开 `/install/`，填写数据库、口令与自己的管理员账号。SQLite 数据库必须在网站公开目录外。
5. 安装成功后访问 `/admin/`，核对站点配置并删除服务器 install 目录。

公开包不含通用安装密码或默认管理员。无面板新 VPS 也可使用 [部署脚本](deploy/install-vps.sh)，脚本会生成该站点的独立安装口令；已有面板使用面板创建 PHP 站点。

已有 0.18 / 0.19 / 0.20 站点按 [升级说明](docs/UPGRADE.md) 保留 config.php、原 secret、数据库和 storage，升级至结构 v13，不重新安装。

## 部署与验证

最低环境：64 位 PHP 7.4–8.5、PDO MySQL 或 SQLite、OpenSSL、Fileinfo、可写存储。MySQL 需要 InnoDB、utf8mb4 和建表/索引权限；网上收款与外部服务需要 HTTPS 及主机允许的出站网络。

PHP 七版本本地原生 SQLite 测试、Linux 原生 MySQL/SQLite 的 14 组 CI 和真实 HTTP 测试分别保存证据，详见 [测试报告](docs/TEST_REPORT.md)。免费主机的具体实例和 VPS 装机尚未实测，不能将语言兼容测试等同于服务商所有套餐均可用。

- [离线完整手册](START_HERE.html) / [安装与主机配置](docs/DEPLOYMENT.md)
- [本版功能使用](docs/PLATFORM_021.md) / [后台指南](docs/ADMIN_GUIDE.md)
- [本轮三主题源码分析](docs/REFERENCE_021.md) / [原创图像出处](docs/ARTWORK.md)
- [代码仓库](https://github.com/yiran168/chengyu) / [发布下载](https://github.com/yiran168/chengyu/releases)

代码按 MIT 许可发布。参考主题仅用于分析功能与交互，本工程不包含其专有代码、素材、字体、授权逻辑或 WordPress 运行时。没有承诺实现所有历史第三方扩展或零知识产权风险。
