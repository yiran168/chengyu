# 0.23.3 实际测试记录

2026-09-20，结构 v15。此次再次检查无 Fileinfo 的识别方式，修复 JPEG 大附加信息在不同入口的差异、ZIP 注释标记与空 ZIP64 的误拒绝，并补充中文旧编码及真实图片变体的覆盖。

| 检查 | 实际结果 | 证据 |
| --- | --- | --- |
| Windows 原生 PDO SQLite | PHP 7.4—8.5 × Fileinfo 开启/关闭，14 组，每组 692/692，stderr 为空 | evidence/0233/matrix.json、php-*.json |
| Linux 原生 MySQL / SQLite | 七版本 × 两数据库 × 两种扩展状态，28 组各 692/692；14 个 CI 作业成功 | evidence/0233/ci-verified.json、native-*.json |
| PHP 8.2.33，无 Fileinfo/GD/ZipArchive | 1102/1102 实际 HTTP 检查 | evidence/0233/http-8.2-absent.json |
| PHP 7.4.33，无 Fileinfo | 1102/1102 实际 HTTP 检查 | evidence/0233/http-7.4-absent.json |
| PHP 8.2.33，开启 Fileinfo | 1101/1101；少一项扩展关闭专用断言 | evidence/0233/http-8.2-present.json |
| PHP / JS 语法 | PHP 7.4 和 8.5 各 314 文件，JS 20 文件全部通过 | evidence/0233/syntax.json |
| 动画曲线不变量 | 42/42 | evidence/0233/spring.json |

CI 对应 `47bc84c2dcbbe20958804cb9316bcb3ccaaf7525`：[实际执行](https://github.com/yiran168/chengyu/actions/runs/35489457973)。逐份下载 28 个结果，核对 PHP、原生 PDO、Fileinfo/finfo 实际状态和通过数。后续发布提交只更新说明、证据、清单与包元数据，业务代码与此提交一致。

## 先复现，再修复

从 Git 标签 v0.23.2 提取旧识别器，在关闭 Fileinfo 的相同运行时与新实现对照。旧版对带特殊注释的合法 ZIP、PK 开头普通文本和 GBK 文本返回未知类型；300 KiB 附加信息后的 JPEG 尺寸在有界前缀中不可见。新实现通过，证据为 evidence/0233/reproduction.json。该对照是回归说明，不重复计入核心通过总数。

新加 19 项核心检查和 33 项 HTTP 检查：

- JPEG 按段读取 SOF，类型和尺寸共用最多 256 KiB 前缀、额外 256 KiB/32 次的读取预算；普通、受保护文件、分片和 S3 均使用同一结果。尺寸超限、标记畸形、读取中途失败或 ETag 改变时拒绝，并检查未产生媒体记录。
- ZIP 继续查找不成立的注释候选，验证空 ZIP64 和损坏定位记录；PK/RIFF/ID3 字母开头的普通文本不因短前缀误拒绝。
- 编码器实际生成并独立解码的渐进式/CMYK JPEG、透明/无损/动画 WebP、调色板 PNG，均通过识别、尺寸、媒体写入和 HTTP 字节验证。
- GBK/GB18030 保持原始字节和私有权限，普通会员不能借文本冒充图片。显式 UTF-8 BOM 不合法、截断序列、非法四字节区间、脚本和 SVG/HTML 仍拒绝。无 BOM 的 C0 AF 同时是合法 GBK，因此旧“非法 UTF-8”反例现在带明确 BOM，避免把编码歧义当作校验成功。
- 对截断图片前缀及 500 次 JPEG 字节变异执行警告转异常检查，无 PHP 警告或越界读取。既有账户、权限、余额、交易、备份、幂等和并发测试继续执行。

真实 HTTP 服务的临时探针记录扩展状态后删除，生产包不包含探针。测试仅使用隔离数据库与测试账号，不访问用户生产数据库。安装安全口令流程、可用安装按钮和附件授权下载仍在总套件中执行。

## 验证范围

文件类型检测不能替代完整解码或病毒扫描；文本编码没有唯一可判定性，GBK/GB18030 检查字节结构而非完整字形映射，下载不会自动转码。S3 测试为协议夹具；实际免费主机/VPS、外部支付、SMTP 和云存储未联调。读取限制及格式范围见 FILE_TYPES.md。

本版未重跑完整视觉交互套件；193 张预览和完整浏览器 200/200 记录沿用 0.23.0，并注明来源。本次手册重新生成并检查，见 manual-checks.json。旧版报告存入 history/0.23.2。

包校验和累计补丁的双基线覆盖模拟另见 Release 的 package-verification.json 与 SHA256SUMS.txt。补丁不含 app/config.php、install/key.php、数据库或 storage，不需要重新安装或生成口令。
