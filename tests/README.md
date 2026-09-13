# 开发者测试与复现

这些文件只供隔离开发环境使用，**不要上传到 Web 根目录，不要对生产数据运行测试**。生产应用不需要 Python、Node、Playwright、FFI、proc_open 或测试服务器。

## 环境

PHP CLI 与正常生产扩展；核心测试优先使用原生 pdo_sqlite。没有此驱动时会尝试测试专用 FFI/libsqlite3 适配，只用于验证真实 SQLite 事务，不等于原生 PDO 测试。FFI 可能需要 `php -d ffi.enable=1`，不得因此要求免费主机启用 FFI。

HTTP 测试需要 Python 3、requests、beautifulsoup4。浏览器测试另外需要 playwright、Pillow 及可用 Chromium，脚本可用 `CY_CHROMIUM` 环境变量指定可执行文件，默认 `/usr/bin/chromium`。Node 用于脚本语法检查与独立弹簧算法测试，不参与生产运行。

在自己的隔离开发环境按工具官方文档安装依赖。例如：

```bash
python -m pip install requests beautifulsoup4 playwright pillow
```

程序运行本身没有这一步。

## 核心回归

在完整包根目录：

```bash
php tests/run.php > core-results.json
```

返回码非零表示有失败，应同时阅读 stderr 与 JSON 明细。默认创建随机临时目录/数据库，用后清理；包含多进程竞争余额、最后一件 SKU 和同一账号投票、圈子加入与悬赏发奖的测试，不要屏蔽 proc_open 后仍宣称该部分已测。

原生 MySQL 模式仅用于自己专门的测试服务器：

```bash
export CY_TEST_MYSQL_HOST=127.0.0.1
export CY_TEST_MYSQL_PORT=3306
export CY_TEST_MYSQL_USER=your_test_user
export CY_TEST_MYSQL_PASSWORD=your_test_password
php tests/run.php > mysql-results.json
```

测试会新建随机命名的 `chengyu_test_*` 数据库，结束时删除；账号需要创建/删除该测试库的权限。**不要使用生产地址或生产管理账号**。脚本不接受指定已有数据库名，也不读取生产 `app/config.php`，但仍应隔离凭据和网络。不要把真实密码写入源码或提交仓库。

## 本地 HTTP

```bash
python tests/http_smoke.py > http-results.json
```

复制干净 `site/` 到临时目录，动态选择本地端口，走安装器并使用完整包根目录安装口令创建临时管理员，再运行实际 HTTP 操作。源目录若有已安装的 config.php 会拒绝。此服务器的目录保护由专用测试路由提供，不代表 Apache/Nginx 已验证。

0.11 的 `http_motion_community.py` 会启动 `smtp_fixture.py` 的本地 TLS SMTP 夹具，覆盖验证码注册和改绑。开发机需可运行 OpenSSL CLI 生成临时自签证书，测试在临时 PHP 进程信任该证书；生产代码仍校验证书，不放宽外部 TLS。夹具不会向互联网发信。不要将测试信任证书、临时站点或邮件日志上传。

## 浏览器与截图

```bash
python tests/run_visual.py > visual-results.json
```

自动准备临时站点与随机管理员密码、种入原创示例数据、运行 PHP 服务器，结束后删除临时数据。正常输出为 JSON；截图默认写到 `docs/previews/`，可用 `CY_VISUAL_OUT` 指定其他目录。会覆盖同名预览图，不覆盖网站业务数据。

底层 `visual_snapshots.py` 从本地 HTTP 获取真实 HTML，内联源码里的 CSS/JS/SVG，再用 Chromium set_content；它不是公网浏览器导航测试。直接调用该底层脚本需要自行提供隔离站点地址、管理员环境变量，推荐使用上面的自动临时站点入口，避免遗留测试账号。

## 实际浏览器 URL 导航

```bash
python tests/run_live_browser.py > live-browser-results.json
```

这是一项独立入口，尝试在浏览器直接访问临时站点 URL，执行真实表单、Cookie 和资源加载。当前交付环境的访问策略阻止本地 URL 导航，结果为 `ERR_BLOCKED_BY_ADMINISTRATOR`，不是测试通过；失败证据已经保存。只能在允许此类访问的隔离开发环境运行，不应绕过安全策略，也不能把 `run_visual.py` 的 set_content 结果当作它的替代通过证据。

## 静态检查与 CI

```bash
find site tests tools -name '*.php' -print0 | xargs -0 -n 1 php -l
node --check site/assets/app.js
node --check site/assets/boot.js
node --check site/assets/studio.js
node --check site/assets/motion.js
node --check site/assets/motion-studio.js
node --check site/assets/features.js
node --check site/assets/spring.js
node --check site/assets/interactions.js
node --check site/assets/discovery.js
node --check site/assets/feedback.js
node --check site/assets/refinement.js
node --check site/assets/connections.js
node tests/spring.test.js
bash -n deploy/install-vps.sh
```

`.github/workflows/php.yml` 包含 7.4/8.0/8.1/8.2/8.3/8.4/8.5 与原生 SQLite/MySQL 组合，MySQL 使用隔离容器。CI 文件中的密码只是临时容器夹具，不是站点默认管理员密码；切勿把它用到生产。

本次交付已实测的结果和缺少的原生矩阵证据，以 `docs/TEST_REPORT.md` 为准。代码修改后应重新保存新的证据，不直接沿用旧 JSON 的通过数量。

## 重建离线说明书

Markdown 是说明书的维护源；修改后在开发环境安装 `markdown-it-py` 与 `beautifulsoup4`，运行：

```bash
python tools/build_manual.py
```

它会重建根目录 START_HERE.html，引用 docs/previews 中的实际截图。该工具不需要上传到网站，不会修改站点业务代码或数据库。发布前应重新生成文件清单和 ZIP 校验值。

## 0.12 扩展文件

`reliability_012.php` 保存作者编辑等错误回归；`community_commerce_012.php` 验证圈子/悬赏/多选/邀请码/评价；`concurrency_012.php` 与 `community_worker.php` 验证多进程幂等。主核心入口自动包含它们，不必对每个片段单独调用。

`http_012.py` 由 HTTP 主套件加载；`visual_012.py` 由浏览器主套件加载。片段依赖主套件的临时站点上下文，不能当作独立服务器直接执行。`seed_012.php` 只创建原创测试内容，绝对不要运行在正式数据库上。

`node tests/spring.test.js` 输出 JSON 并以失败退出码报告；测试解析求解器而非截图外观，修改物理参数/算法时应与 DOM 套件一起重跑。CI 增加前端语法和该算法测试，但本次未执行远程 CI。


## 0.13 扩展文件

`reliability_013.php` 保留三个先失败后修复的边界回归；`creator_discovery_013.php` 验证收益计算、资格、冻结/退款/负债、作者定价保护、历史隔离/容量和搜索过滤；`concurrency_013.php` 与 `creator_worker.php` 验证 8 进程结算同一收入只入账一次。

`http_013.py` 由 HTTP 主入口加载，覆盖作者批准、付费投稿、审核、真实购买、结算、提现驳回、退款、本人记录、搜索和新动效设置。HTTP 日志还检查 Parse error，不能只看状态码把模板错误算作正常。

`visual_013.py` 由离线浏览器入口加载。收益/历史等截图来自测试库实际业务操作；搜索 IME、乱序、关闭失效和 DOM 安全部分使用模拟 fetch，只测试浏览器交互，不替代 `http_013.py` 的真实服务端请求。搜索预览图使用隔离站点真实 HTTP 搜索返回的公开标题；时序测试使用的模拟响应仍不当成浏览器真实网络验证。

`seed_013.php` 只在隔离截图站点创建原创样例和模拟资金的真实订单数据，不包含真实营业额，不应写入生产。`CY_VISUAL_TRACE=1` 可以把每项进度输出到 stderr，stdout 保留 JSON，便于诊断长测试而不影响机器读取。

所有新增文件仍由统一入口运行，不要单独执行依赖测试上下文的片段；不要把产出中的临时站点、邮件、凭据或数据库留在公开目录。

## 0.14 扩展与原生驱动约束

`reliability_014.php` 保留三条修复回归；`security_pricing_014.php` 运行 RFC 向量、绑定/重放/恢复/会话、整数会员/活动价、券/上限/退款、回滚、自检和 v6 重启迁移测试。`concurrency_014.php` 与 `security_worker.php` 验证八进程争抢一枚恢复码/一个券名额，只成功一次。

`http_014.py` 在主套件临时站点实际提交表单、改变会话、核对一次性代码展示、设置价格/券、购买/改价/退款和下载诊断。`visual_014.py` 验证新界面、专注阅读、乱序报价、键盘重排/焦点/展开状态、0ms 与无脚本的非规格购买路径；模拟 fetch 仅用于时序测试，不声称它是浏览器真实网络。

`seed_014.php` 只建立原创测试商品和规则；预览中的价格与订单不是实际营业数据。所有片段由原统一入口加载，不单独执行。`CY_HTTP_TRACE=1` 可输出无凭据的步骤进度到 stderr，JSON 仍在 stdout。

CI 增加 EXPECTED_DRIVER 前置验证，缺少任务要求的原生驱动直接失败，不能使用 FFI 后算原生通过。核心测试在本地缺驱动时仍可显式用测试适配层，但输出会标明；14 组远程任务本次尚未执行。生产包不包含测试适配器。

`tools/recover-authenticator.php` 是实际站点所有者的本地应急工具，不是测试脚本；不要对生产用户为了测试而运行。普通语法检查包含该文件；安全服务的 CLI 恢复方法在核心套件中验证，完整生产命令需实际原生环境验收。


## 0.15 连接、运营与安全回归

`reliability_015.php` 保留三个先失败再修复的会话/密码/资料边界；`integrations_015.php` 验证三家身份协议、PKCE、账号归属、第二因素、HTTPS 响应解析、主动查单、永久会员、整车上限、配置快照及徽章。

`concurrency_015.php` 与 `connection_worker.php` 启动独立进程，对同一充值单同时执行四次查单与四次通知，以及八次相同徽章授予。所有片段由 `run.php` 自动加载，不单独运行。

三家 OAuth 和新支付查询使用受控协议响应，不使用真实商户或真实应用。测试传输注入只允许 CLI / CLI-server；生产路径使用验证证书与地址的 HTTPS 连接，不能将夹具通过当成外部平台已联调。

`http_015.py` 验证新管理权限、密码证明、快照附件下载与 multipart 上传、修订冲突、永久套餐实际交易与整车原子回滚。HTTP 主入口在隔离站点中生成独立随机安装口令，公开源码不需要私密 INSTALL_KEY.txt。

`visual_015.py` 验证分组导航、快捷键、移动菜单、有限图标运动和配置/身份表单。set_content 会保留旧文档监听器，涉及媒体偏好反复切换的运动段落使用新浏览器页面上下文，避免将历史监听干扰当成新页面行为。

`seed_015.php` 创建明显的测试平台占位配置、原创徽章、设置快照和永久套餐，仅写入临时站点。启用占位按钮只为检查界面，HTTP 站点实际 OAuth 请求会因 HTTPS 要求拒绝，不存在演示成功登录后门。不会向互联网发送这些测试凭据。

## 0.16 回归

commerce_016.php/platform_016.php 覆盖交易、权限、未知结果、支付协议及媒体；http_016.py 覆盖真实本机 HTTP 下单/退款/访客、multipart 分片、翻译/规则/页面、HMAC 重放和分发的 CLI 工具。visual_016.py 用实际 PHP HTML 经 Chromium set_content 检查四种宽度、语言及原生表单，不是浏览器直接 URL 导航。

HTTP/截图脚手架关闭本机 cli-server OPcache，确保安装/配置变更下一请求可见；生产升级仍需自行清 OPcache。登录测试按 action=login 定位，减少动画测试等待浏览器偏好变化事件，不以固定 60ms 代替事件到达。原生 CI 驱动前置断言保留，不允许测试适配器伪装原生通过。当前计数以 TEST_REPORT.md 为准，evidence/history 只是历史。

## 0.17 regression additions

`platform_017.php` adds search/privacy, live course permissions, draft/history, shipment matching, report-only statement import and support ownership/version tests. `http_017.py` exercises the actual action and page routes. `visual_017.py` creates original course/ticket data, checks 320/390/768/1440px layouts, independent locales, templates, hover and reduced motion. It remains an offline browser suite, not a successful full browser navigation test.

Include `node --check site/assets/platform.js` and `node --check site/assets/media-workbench.js` in manual syntax runs. New screenshots and JSON counts must be regenerated from the actual final source, rather than copied from prior releases.


## 0.18.0 新增测试与实际证据

新增 `platform_018.php`、`backups_018.php`、`http_018.py`、`visual_018.py` 和原创 `seed_018.php`。它们接入原有完整测试入口，不替换旧测试。证据在 `../docs/evidence/018/`：core-final 558/558、http-final 804/804、visual-final 498/498、spring 42/42。backup-working 的 19 项属于 core-final 子集，不重复计数。

测试使用原生 PDO 不可用时的专用 FFI/真实 SQLite 适配；生产代码不使用该替代层。MySQL/原生PDO CI 14 组未执行。外部搜索、支付与物流的网络协议是夹具，不是真实服务联调。HTTP 新测试组清空测试数据库中的限流记录以隔离连续敏感操作，生产限制保持不变。

真实浏览器导航被环境 ERR_BLOCKED_BY_ADMINISTRATOR 阻断，离线 set_content 结果不能当成公网浏览器全链路。备份恢复专项确实在隔离测试目标重建数据和媒体，但没有 MySQL/真实主机恢复证据。详见当前 `../docs/TEST_REPORT.md`。
## 0.19 当前证据

最新结果以 ../docs/TEST_REPORT.md 为准。Windows 使用 `python tests/run_matrix.py RUNTIME OUT TMP`，由父进程在 PHP 完全退出后清理原生 SQLite 临时目录。HTTP 测试自行生成站点安装密钥。临时目录、报告和依赖应放在当前项目的私有工作目录；不得指向生产库。

## 0.22 导航与正文图片

`platform_022.php` 接入完整核心套件，新增15项服务检查，累计612项。`http_022.py` 和 `browser_022.py` 分别使用真实PHP请求和Chromium操作，覆盖父子菜单权限、循环/修订、上传插入、私有图片撤销、列表排版、Esc/外部关闭及无JS导航。升级脚本接受原始0.21站点，检查结构14、原导航、图片和交易数据保留。证据位于 `docs/evidence/022`。
