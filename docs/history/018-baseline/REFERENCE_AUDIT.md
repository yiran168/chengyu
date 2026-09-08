# 0.18 ICU分卷与官方历史核查记录

核查日期2026-09-07。本轮不是只复用前次报告：三个RAR分卷重新通过libarchive连续读取，主题文本在独立私有核查目录提取，未运行旧站PHP、导入原数据库或使用任何原站密钥。

| 项目 | 实际结果 |
| --- | --- |
| 输入 | www.taojinge.icu_20260522_175206.part1.rar / part2.rar / part3.rar |
| 归档遍历 | 13,652条目 |
| 主题文本索引 | 952个文件，包含主题目录内相关文本依赖；路径、长度、SHA-256在evidence/018/reference-index.json |
| 主题头 | Version7.8；这是上传副本自述，不证明未经改动的官方原版 |
| 定向阅读 | 8个实际文件，记录函数名、行号和摘要；不是952文件逐行审计 |
| 官方历史 | 早期V1–V5档案的可见正文、V6–V9后续日志，最新V9.1（2026-09-01）；缺失正文如实标注 |

## 读了哪些方向

参考搜索聚合/用户搜索、验证码入口、签到/连续奖励、用户等级、提现、会员永久和升级计算、组件类及菜单类。定向文件包含 `inc/functions/zib-search.php`、`action/captcha.php`、`inc/functions/user/user-checkin.php`、`inc/functions/user/user-level.php`、`inc/widgets/widget-class.php`、`zibpay/functions/zibpay-withdraw.php`、`zibpay/functions/zibpay-vip.php` 和 `inc/codestar-framework/classes/nav-menu-options.class.php`。

两条初始猜测路径未找到，没有将它们算作已审阅。实际成功记录在 `evidence/018/reference-reviewed.json`。只把路径、摘要和函数索引放入交付证据，不分发参考代码原文。

## 从核对得到的变化

已存在的等级、签到、会员补差和有条件导航不重复编写。针对官方近期能力与本工程缺口，新增Meilisearch服务端连接、IP/收件人六维验证码发送限制、多包裹履约视图，以及用户要求的可验证备份/恢复。不同代码入口继续复用本工程权限、CSRF、HTTP和账目服务。

参考主题依赖WordPress。澄屿继续采用独立PHP架构；没有移入主题/插件源码、图片、字体、原站数据库、用户资料或商户密钥，也不移除/伪造对方授权流程。生产文件与参考文本的整文件摘要交叉检查会写入 `evidence/018/reference-production-comparison.json`；该检查只能证明不存在整文件完全相同内容，不能代替专业授权或法律审查。

## 无法由本报告推出的结论

不意味着所有历史版本和第三方插件均已实现、不意味着每行代码经过安全审计、不意味着未完成的实时服务已联网验收。不能称为子比官方、官方授权版或作零法律风险承诺。上线内容、品牌、素材和商户资质仍由部署方依法取得。

官网来源与功能对照见 `HISTORICAL_FEATURES.md`，交付范围与仍缺模块见 `FEATURE_MATRIX.md`。
