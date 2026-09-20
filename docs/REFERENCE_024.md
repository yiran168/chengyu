# 四套主题源码再次审阅：2026-09-21

本次再次直接阅读用户指定的四个本地源码目录，并查询官方产品与说明页面。下面记录实际读取的文件、可借鉴的行为和本项目实现；不是“完整审计每个文件”，也不代表全部历史付费模块均已实现。参考源码只读，不执行，不随本项目分发。文件哈希清单见 [审阅记录](evidence/024/reference-files.json)。

## 子比 Zibll 8.9

目录：`zibll8.9开心版/zibll`。

- `inc/functions/zib-header.php`：头部布局、菜单图标和徽标、图文卡片与分栏子菜单，分项入场延迟。
- `inc/functions/zib-posts-list.php`：文字、单图、多图列表，左右缩略图及标题/摘要/元信息的结构。
- `inc/widgets/widget-user.php`：游客与已登录用户卡、作者信息与榜单入口。
- `css/main.css`：菜单短距离位移、图片轻微缩放及性能模式，普通过渡约 0.2–0.3 秒。

其自然感来自内容密度与顺序：标题先于装饰，摘要和元信息降一级，图像尺寸稳定，菜单与卡片只做短距离反馈。本版沿用已有内容列表及三级导航，补充由真实分类内容驱动的预览，继续共用站点动效配置，不复制专有 CSS、素材或授权实现。

## RiPro V5 的 Shane 子主题

目录：`RiProV5子主题/Shane`。用户提供的是子主题；目录中没有 RiPro 父主题，不能把子主题阅读说成已经审计父主题全部交易代码。

- `template-parts/header/menu.php`、`action-hover.php`：导航、账户操作与公告入口；菜单使用 WordPress transient。
- `inc/gadgets/home/home-slideer.php`：轮播、桌面/移动素材与分类快捷入口。
- `template-parts/loop/entry-meta-part.php`：时间、互动数据、会员与资源价格标签。
- `template-parts/single/post-content-new.php`：详情/FAQ切换和正文区。
- `assets/css/shane_home.css`：会员按钮及移动隐藏规则，缓动和投影使用。

采用“操作贴近内容、元信息紧凑、移动端有单独安排”的设计原则。FAQ在本项目改为独立问题/答案字段，避免靠分隔符录入多行答案。源代码中固定增加点赞和浏览数量的做法没有采用；统计仍来自真实数据。菜单也没有照搬跨用户 HTML 缓存，避免访问条件变化后继续显示旧内容。

## JustNews 6.16.7

目录：`Justnews主题6.16.7开心版+问答社区+用户中心高级版插件/justnews`；相邻问答和用户中心插件作为范围记录，本轮重点是主题的栏目与导航。

- `header.php`、`templates/loop-list.php`：页头操作及简洁标题列表。
- `modules/category-posts.php`、`modules/main-list.php`：栏目标题、子分类入口、不同排序与列表排版、加载更多。
- `widgets/post-tabs.php`：有限数量的侧栏分组及排序。
- `themer/functions/nav-walker.php`：多级图文导航，图标/图片和菜单形式配置。

其界面并不依靠每个区块的大口号：统一栏头、稳定间距、可扫读标题、次要日期和少量明确按钮构成主要层次。澄屿保留内容优先排版；本版去掉固定英文副标题，开放后台设置，并让分类链接、排序、预览共用一个导航编辑表单。

## B2 PRO 5.4.2

目录：`7B2 PRO主题5.4.2 WordPress主题`。

- `Modules/Templates/Menu.php`：四种菜单呈现，图标、图像和分类文章预览查询。
- `Modules/Settings/Menu.php`：菜单颜色、图片和类型字段，多个菜单位置共用设置结构。
- `Modules/Templates/Modules/Posts.php`：图片比例、可选元信息、作者与付费标记。
- `TempParts/Document/item-normal.php`、`Modules/Settings/Document.php`：文档条目、分类路径、更新时间、配置与权限。
- `Modules/Templates/Widgets/Author.php`：作者卡的公开信息和操作入口。
- `Assets/fontend/style.css`：菜单间距、标题悬停和紧凑元信息。

B2的分类内容菜单是当前最明确的缺口：澄屿原来只能手动设置图文子菜单。本版独立实现文章/社区/商品分类预览，复用内容发布与分类权限过滤，封面批量校验，单菜单最多 6 条、全站最多 8 个，重复配置在当前请求内复用，适合资源有限的共享主机。正文、提取码、私有资源地址不进入查询结果。

## 设计选择与仍存在的差距

| 观察到的优点 | 本项目处理 | 当前边界 |
| --- | --- | --- |
| 栏目有结构、标题易扫读 | 保留列表/网格/多图排版，增加真实分类菜单 | 不自动搬入主题演示文章或假统计 |
| 小图标帮助辨认功能 | 独立生成书本、客服耳机图标，接入现有后台素材库 | 图标与插画不从参考主题截取 |
| 编辑内容符合运营习惯 | FAQ逐条输入，旧格式保留；修复工作台实际交互 | 22类组件，不是全部 WordPress 小工具 |
| 克制动效服务于操作 | 复用统一曲线，菜单边界适配、键盘操作、减少动态效果 | 不保证所有低端设备固定帧率 |
| 文档、交易、社区形成体系 | 继续保留课程、社区、售后和资源权限服务 | 专门的文档中心目录/搜索尚未等价实现；外部支付、短信、物流等需真实服务联调 |

完整差距清单见 [功能矩阵](FEATURE_MATRIX.md)。本轮没有声称复刻所有商业主题版本、全部第三方插件、直播/转码、任意画布或多商家系统。复用的是需求与交互思路，PHP、CSS、模板和图像由本项目独立实现。

## 网上核对的资料

- [子比主题配置说明](https://www.zibll.com/zibll_word/theme_config)：菜单与外观配置。
- [子比模块说明](https://www.zibll.com/1816.html)及[主题介绍](https://www.zibll.com/28.html)：内容布局与组件范围。
- [RiPro V5 官方介绍](https://ritheme.com/theme/ripro-v5.html)：产品能力与更新记录，不能替代未提供的父主题代码检查。
- [JustNews 文档](https://www.wpcom.cn/docs/justnews)及[页头配置](https://www.wpcom.cn/docs/themer/header.html)。
- [B2 官方站](https://7b2.com/)：产品入口；具体菜单与文档行为以本次实际源码为依据。
- [蓝队云免费主机常见问题](https://www.landui.com/help/show-12693.html)：Windows环境、不能自行升级环境和数据库地址等限制。与泛化营销页冲突时，不据营销页保证当前账户能力。

萌哒云没有取得足以确认所有当前套餐限制的官方技术文档；以用户提供的安装检测结果作为该站的已有信息，绝不据其他套餐推断。详见 [主机资料](HOSTS_AND_SOURCES.md)。
