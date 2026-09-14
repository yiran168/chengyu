# 0.23 三套参考主题的素材配置再读

2026-09-14再次实际读取三套本地目录中的下列源代码，分析素材配置方式。只记录行为和结构，采用Chengyu自己的PHP服务、DOM组件和存储，不复制商业主题代码、素材或WordPress依赖。更广的导航、列表、多图、动效对照见[0.22源码分析](REFERENCE_022.md)。

| 来源 | 本轮实际读取范围 | 观察与实现取舍 |
| --- | --- | --- |
| Zibll | inc/functions/admin/admin-main.php 76–131行 | 分类添加/编辑图片都调用统一媒体环境，字段与预览一起呈现；Chengyu公开图片字段复用统一选择器 |
| RiPro子主题Shane | inc/gadgets/home/home-slideer.php 1–115行 | PC/WAP幻灯及CMS小图都把图片作为明确可配置字段；Chengyu图片选择跟随实际字段，不把同一插画自动铺满内容 |
| JustNews | themer/core/visual-editor.php 1–96行 | 可视化编辑器入口先做权限判断、统一加载媒体，再区分编辑与预览；Chengyu保留独立选图/保存动作并重检权限 |

逐文件SHA-256和读取范围见[evidence/023/reference-source-review.json](evidence/023/reference-source-review.json)。Shane目录是子主题，没有RiPro父主题完整代码，因此不把这些阅读写成审计了整个RiPro。没有执行参考主题中的授权、远程下载或解密逻辑。

本轮再次网上搜索并读取了[WPCOM页头设置](https://www.wpcom.cn/docs/themer/header.html)、[JustNews使用文档](https://www.wpcom.cn/docs/justnews)、[子比海报与图片处理说明](https://www.zibll.com/886.html)和[蓝队云免费主机FAQ](https://notify.landui.com/help/show-12693)。前两者明确提供Logo/菜单的图片选择与配置，子比说明按需加载图像相关功能；本轮吸收统一入口和按需加载的思路。RiPro官方检索未取得比0.22记录更多的可核对实现，不虚构新增版本的审计结果。没有穷尽全部历史插件或版本。

蓝队云官方FAQ仍说明Windows、FTP/wwwroot、不能升级运行环境、数据库本机连接；因此新增图片库不引入需安装的常驻服务或图像扩展。萌哒云继续依据用户截图记录SSD 2GB/MySQL 1GB和PHP7.4–8.2菜单，并未取得用户真实实例的扩展及目录保护证据。两家主机的账号实际安装与VPS仍未验证。
