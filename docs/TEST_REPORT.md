# 0.20.0 实际测试记录

2026-09-12，数据库结构 v12。本地测试使用隔离数据，不接触用户的生产站点。

| 检查 | 结果 | 证据 |
| --- | --- | --- |
| Windows 原生 PDO SQLite | PHP 7.4.33 / 8.0.30 / 8.1.34 / 8.2.33 / 8.3.33 / 8.4.25 / 8.5.10 各 587/587，stderr 为空 | evidence/020/native-matrix.json、php-*.json |
| 真实 PHP HTTP 请求 | 882/882 | evidence/020/http-final.json |
| Chromium 实页、表单和动效 | 91/91，实页截图 14 张 | evidence/020/browser-final.json、previews/020-* |
| PHP 语法 | 7.4 与 8.5 各 284 文件通过 | evidence/020/syntax.json |
| JavaScript 与弹簧数学 | 18 文件语法、42/42 不变量 | evidence/020/syntax.json、spring-final.json |
| 0.18 与 0.19 升级 | PHP 7.4、8.5 四组升级通过 | evidence/020/upgrade-from-018.json、upgrade-from-019.json |

浏览器验证 Cookie 登录、79 图标筛选、内置预览、上传 ID 回填、保存与恢复默认、四种宽度、首页分类和排序原生导航、归档筛选、后台曲线保存后前台生效、系统减少动态效果、按钮波纹尺寸、投票、问答、编辑器和无 JavaScript 阅读。Windows Chrome 150 使用软件渲染避免宿主遮挡检测停帧；页面动画和真实点击未被关闭。新安装截图未混入扩展回归测试的虚构订单或填充文章。

修复按钮波纹撑大点击区域、上传字段无法回填、跨页面快照导致帧冻结；页面跳转改为 CSS 入场，日夜切换保留同页原生转场。相应 HTTP 检查验证外部动效脚本和不启用跨页面快照。TOTP 重放测试复用真正的激活码，避免跨 30 秒窗口时把新码误判为重放。

升级演练保留余额、订单、付费权限与受保护正文；0.19 加密资源链接仍可读取；旧站上传封面不被替换。备份白名单包含新表。

Linux PHP 7.4–8.5 × 原生 MySQL/SQLite 的 14 组 CI 全部通过，提交 `77d68cbea52eba7db928e4647d05de7e6140b3d5`。[实际运行](https://github.com/yiran168/chengyu/actions/runs/34684329635)，证据见 `evidence/020/ci-verified.json`。

两家免费虚拟主机、用户 VPS、真实支付/邮件/对象存储账号尚未线上部署联调。安装/后台提供实际运行检测。PHP 七版本使用同一业务路径；部署仍取决于该实例的扩展、权限和配额。功能矩阵中的未实现模块不计为已完成。
