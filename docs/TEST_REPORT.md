# 0.23.2 实际测试记录

2026-09-20，数据库结构 v15。本次改动为取消 Fileinfo 依赖并加强统一文件内容识别与安装提示。使用隔离测试库、临时网站和项目内临时目录，未连接用户生产数据库。

| 检查 | 实际结果 | 证据 |
| --- | --- | --- |
| Windows 原生 PDO SQLite | PHP 7.4—8.5，分别启用和关闭 Fileinfo，共 14 组，每组 673/673，stderr 为空 | evidence/0232/matrix.json、php-*.json |
| Linux 原生 MySQL / SQLite | 七版本 × 两数据库 × 两种扩展状态，共 28 组，每组 673/673；14 个 CI 作业全部成功 | evidence/0232/ci-verified.json、native-*.json |
| PHP 8.2.33，无 Fileinfo/GD/ZipArchive 的 HTTP | 1069/1069；实际服务器报告 finfo 类及扩展均不存在 | evidence/0232/http-8.2-absent.json |
| PHP 8.2.33，开启 Fileinfo 的 HTTP | 1068/1068；少一项“扩展关闭”专用断言，功能路径相同 | evidence/0232/http-8.2-present.json |
| PHP 7.4.33，无 Fileinfo 的 HTTP | 1069/1069；实际服务器报告 finfo 类及扩展均不存在 | evidence/0232/http-7.4-absent.json |
| PHP / JS 语法 | PHP 7.4 与 8.5 各 313 文件通过，JS 20 文件通过 | evidence/0232/syntax.json |
| 动画曲线不变量 | 42/42 | evidence/0232/spring.json |

CI 测试提交 `45319be5b629180734c218c80f4d212ed76cf171`：[实际运行](https://github.com/yiran168/chengyu/actions/runs/35455455248)。下载并核验全部 28 份结果，检查具体 PHP 版本、原生 PDO 驱动、扩展/类的真实状态及通过数，没有以模拟关闭函数或旧版 CI 代替。后续发布提交仅调整文档、包清单和 HTTP 测试脚本对旧 PDO 数字字符串的处理，PHP 业务文件保持一致。

新增 49 项核心回归覆盖全部九类允许文件、错误后缀与客户端 MIME、不完整文件、Unicode 文本边界、大 ID3 标签及尾标、ZIP64、带伪造结束标记的 ZIP 注释、PNG 空 IDAT、受保护文件偏移、图像尺寸限制、随机/截断输入，以及对象存储的 ETag、Content-Range、类型冲突和拒绝后无媒体记录。原有多进程余额、订单和上传幂等测试继续执行。

新增 HTTP 检查使用真实 PHP 服务和 multipart 请求，验证无扩展安装按钮与建库、缺少安装验证文件时的对应提示、上传后的真实 MIME/原始字节/私有权限、大 PNG 分片合并与后台自检。运行时探针只写入隔离临时站点，取证后立即删除，不加入生产上传包。

测试期间发现 UTF-16 LE 字节序标记可能落入 MPEG 同步标记分支，已先修复再重跑全部核心矩阵。PHP 7.4 的额外 HTTP 首轮在旧测试脚本使用 PDO 数字字符串作为 Python range 步长时中断；将两个步长显式转为整数后重新执行全套通过。这不是业务代码绕过错误。

本版没有重新执行完整视觉交互套件。193 张预览与 200/200 完整浏览器记录来自 0.23.0；新安装/上传行为以本次实际 HTTP 结果为证。发布手册重新生成并检查，见 manual-checks.json。历史 0.23.1 报告存入 history/0.23.1。

文件类型识别不是完整解码或病毒扫描。MP3/MP4 的小型核心测试素材用于结构识别，不能据此宣称完成真实编码器的全面播放兼容测试。具体支持范围与有界读取限制见 FILE_TYPES.md。无扩展不等于无其他运行条件：PDO、OpenSSL、写权限、空间和出站网络仍按主机配置。

实际萌哒云/蓝队云实例、VPS 装机、外部支付、SMTP 和 S3 服务未联调；S3 为协议测试。没有声称已替用户部署成功。发布包、覆盖补丁和 SHA-256 另由打包工具校验。
