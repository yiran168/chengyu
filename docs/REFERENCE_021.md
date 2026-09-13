# 三套主题源码再次对照：0.21

阅读日期：2026-09-12–13。这里记录实际源码阅读与改动依据，不以产品宣传代替代码分析。参考目录只读；没有运行其中的安装、授权或加密代码，也没有向公开仓库复制这些实现和素材。

## 本轮读取范围

重新阅读共 30 个文件，逐文件路径、行段、整文件 SHA-256 见 [读取记录](evidence/021/reference-source-review.json)。9 月 13 日又并排重读了三套列表模板。以下是各套的具体入口。

| 参考 | 实际读取的文件与关注点 |
| --- | --- |
| 子比 8.9 本地包 | `index.php`、`inc/functions/zib-header.php`：页头与内容入口；`zib-posts-list.php`：统一列表、无图与多图判断、作者及统计；`zib-single.php` 与 `functions.php`：阅读页分区、上下篇与相关文章；`sidebar.php`、`user/page/user-center.php`：边栏位置与用户页分区；`inc/options/options-module.php`：模块配置。`zib-svg-icon.php` 只读到文件头，未据此声称完成图标注册器分析。 |
| RiPro V5 子主题 Shane | `template-parts/header/menu.php`、`header/action-hover.php`：导航、登录状态、搜索与操作菜单；`loop/item.php`、`entry-meta-part.php`：网格、叠图、列表、标题模式与价格元信息；`single/entry-copyright.php`、`post-content-new.php`：版权与内容/FAQ；`inc/method/admin-options.php`：展示开关；`footer/m-navbar.php`：手机图标和标签；`logo.css`、`shane_home.css`：装饰与悬停。 |
| JustNews 6.16.7 与附带插件 | `page-home.php`、`index.php`、`modules/main-list.php`：首页模块与列表；`templates/loop-list.php`、`loop-default.php`：缩略图、摘要、元信息；`single.php`：来源、版权与作者；`page-kuaixun.php`：日期和短消息；`header.php`、`sidebar.php`：导航层次及边栏；QAPress 的 `templates/list-item.php`：问题与回复层次；Member Pro 的 `templates/orders.php`：订单编号、状态与条目。 |

另外检查子比 `css/main.css`、JustNews `css/style.css`、QAPress `css/style.css` 中的列表、标题、缩略图和移动端选择器。大体使用 16–24px 的分级标题、统一内边距、克制阴影和小幅悬停；并非每个区块都有独立大标题、渐变背景和宣传语。这里提取的是设计原则，本项目没有照搬这些 CSS 值或选择器实现。

Shane 目录是子主题，没有 RiPro 父主题完整核心。其价格、会员和媒体调用可用于理解页面接口，不能据此声称已审计 RiPro 的全部支付、订单或授权代码。三个本地包也不等于最新官方版本。

## 从分析到本项目

| 观察与设计取舍 | 本项目对应实现 | 本轮状态 |
| --- | --- | --- |
| 子比以共享函数生成列表，各页面复用元信息与无图规则 | `Content::feed`、`Views/card.php` 与原有列表组件共享权限和字段，文章支持左图/右图/纯文字 | 已实现；未加入正文自动提取多图 |
| Shane 多种卡片形式保持价格、标题和分类的固定位置 | 保留三种列表视图、真实资源价格与销售数；新设置限定文章，商品图不被文章开关误伤 | 已实现；未复制全部四种主题模板 |
| JustNews 用来源、摘要、版权和作者构成完整阅读页 | `Editorial` 服务，来源/版权/作者/上下篇/相关内容独立模板，后台管理显示与文字 | 本轮新增 |
| 三套都把导航、内容和账户操作分区 | 保留紧凑页头、手机底栏、后台分组和检索；新快讯归入“内容与发布” | 已落地；任意三级高级菜单仍有差距 |
| JustNews 快讯以日期和时间组织短消息 | 原生快讯服务、后台编辑、时间过滤、独立详情、RSS、站点地图 | 本轮新增，带幂等与修订冲突处理 |
| 主题的图标服务于按钮含义，重点位置用品牌素材 | 统一 80 个逻辑符号目录，7 个独立生成二次元图标，后台同一处替换 | 新增快讯图标；未搬运字体图标库 |
| 子比相关内容与读者操作靠近正文末尾 | 作者卡、同分类上下篇、相关阅读位于正文后、评论前 | 本轮新增；共用发布与分类访问限制 |
| Shane 局部动画和 JustNews 轻量悬停不干扰阅读 | 阅读页弱化装饰，状态变化保持短促，减少动态效果与无 JS 阅读可用 | 保留动效实验室；未复制无限 Logo 扫光 |
| 子主题中发现固定加值的展示统计 | 页面使用数据库实际计数 | 不移入虚构点赞或阅读量 |

问答的图片排列、会员订单展示、全部小工具和全部历史扩展并没有因为读取源码就自动移植完成。当前问答、会员与订单继续使用本项目的现有模块，确切边界见 [功能矩阵](FEATURE_MATRIX.md)。

## 官方网页与历史版本

本轮取得 [子比更新记录](https://www.zibll.com/375.html)中的 V9.1（2026-09-01）、[RiPro 官方产品页](https://ritheme.com/theme/ripro-v5.html)中的 V10.3，以及 [JustNews 官方产品与更新记录](https://www.wpcom.cn/themes/justnews.html)中的 V6.25.1（2026-08-19）。9 月 13 日重复打开时子比超时、RiPro 返回 403，因此其版本记录沿用本轮前次成功检索，不把失败当成新验证结果。

JustNews 官方将模块首页、高级菜单、版权模板、快讯和列表多图/右图列为功能，QAPress 与高级用户中心则是可搭配的插件。对照 [官方演示站](https://demo.wpcom.cn/justnews)可见图文列表、摘要与细元信息构成主体。子比、RiPro 官方网页的实际浏览记录保留于上轮研究，本轮又补充 JustNews 实页，截图仅留本地分析，不随源码分发。

[RiPro 子主题文档](https://ritheme.com/document/1069.html)、[QAPress](https://www.wpcom.cn/plugins/qapress.html)、[用户中心高级版](https://www.wpcom.cn/plugins/wpcom-member-pro.html)用于确认产品关系。官方更新页只覆盖公开记录，不代表找齐所有非官方改版、隐藏功能或付费插件实现。

## 主机与实现边界

蓝队云官方免费主机说明为 Windows、FTP、无 SSH，数据库使用本机地址；安装目录权限、IIS 保护、上传和请求上限必须按实际实例检测。萌哒云的用户截图确认 PHP 7.4/8.0/8.1/8.2 选项及空间配额，但未取得能核对全部扩展的公开官方套餐说明。本项目保持无常驻进程依赖的请求式运行；详情及官方链接见 [主机配置来源](HOSTS_AND_SOURCES.md)。

借鉴通用布局与业务概念，再以独立代码和原创资产实现，能降低直接复制带来的风险；这不是“所有法律风险已经消除”的保证。公开包不含参考主题字节、商业截图、字体或授权绕过逻辑。
