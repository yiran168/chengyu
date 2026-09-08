"""Build the offline manual from the maintained Markdown chapters.
Developer dependency: markdown-it-py, beautifulsoup4. Not used at runtime.
"""
from pathlib import Path
from markdown_it import MarkdownIt
from html import escape
from bs4 import BeautifulSoup
root = Path(__file__).resolve().parents[1]
docs = root / "docs"

manual_parts = [
    ("README.md", "先读这里"),
    ("docs/DEPLOYMENT.md", "新站安装与部署"),
    ("docs/UPGRADE.md", "保留数据升级"),
    ("docs/ADMIN_GUIDE.md", "后台运营与外观"),
    ("docs/MOTION_GUIDE.md", "动效与曲线"),
    ("docs/EMAIL_COMMUNITY.md", "邮箱、问答与投票"),
    ("docs/NEW_MODULES.md", "既有扩展模块"),
    ("docs/COMPATIBILITY.md", "环境兼容性"),
    ("docs/PAYMENTS.md", "支付与资产"),
    ("docs/SECURITY.md", "安全与备份"),
    ("docs/FEATURE_MATRIX.md", "功能与缺漏"),
    ("docs/TEST_REPORT.md", "实际测试证据"),
    ("docs/REFERENCE_AUDIT.md", "参考源码与历史"),
    ("docs/HOSTS_AND_SOURCES.md", "主机与资料"),
    ("docs/ARCHITECTURE.md", "架构与扩展"),
    ("docs/CHANGELOG.md", "版本变更"),
    ("docs/COMMUNITY_COMMERCE.md", "圈子、悬赏与评价"),
    ("tests/README.md", "开发者测试"),
]
manual_parts.append(("docs/CREATOR_AND_DISCOVERY.md", "\u4f5c\u8005\u6536\u76ca\u4e0e\u9605\u8bfb\u53d1\u73b0"))
manual_parts.append(("docs/SECURITY_PRICING.md", "账户安全、定价与自检"))
manual_parts.append(("docs/CONNECTIONS_AND_OPERATIONS.md", "\u8fde\u63a5\u4e0e\u8fd0\u8425"))
manual_parts.extend([['docs/COMMERCE_016.md', '交易、售后与会员补差'], ['docs/MEDIA_LANGUAGE_BUILDER.md', '媒体、语言与页面编排'], ['docs/EN_QUICKSTART.md', 'English quick start']])
manual_parts.extend([
    ('docs/PLATFORM_017.md','0.17 新工作台操作'),
    ('docs/STATEMENT_FORMAT.md','账单标准与差异处理'),
    ('docs/ACCEPTANCE_018.md','真实主机验收清单'),
    ('docs/HISTORICAL_FEATURES.md','历史功能族对照'),
])
manual_parts.extend([('docs/PLATFORM_018.md',"0.18 \u641c\u7d22\u4e0e\u591a\u5305\u88f9"),('docs/BACKUP_RECOVERY.md',"\u52a0\u5bc6\u5907\u4efd\u4e0e\u5b89\u5168\u6062\u590d")])
renderer = MarkdownIt("commonmark", {"html": False}).enable("table")
nav = []
articles = []
for n, (rel, title) in enumerate(manual_parts, 1):
    section_id = f"chapter-{n}"
    nav.append(f'<a href="#{section_id}"><span>{n:02d}</span>{escape(title)}</a>')
    rendered = renderer.render((root / rel).read_text())
    parsed = BeautifulSoup(rendered, "html.parser")
    for link in parsed.select("a[href]"):
        href = link["href"]
        if not href.startswith(("#", "/", "http:", "https:", "mailto:", "tel:")):
            link["href"] = str(Path(rel).parent / href)
    for heading in parsed.select("h1,h2,h3,h4,h5"):
        heading.name = "h" + str(min(6, int(heading.name[1:])+1))
    for table in parsed.select("table"):
        wrapper = parsed.new_tag("div", attrs={"class":"table-scroll"})
        table.wrap(wrapper)
    articles.append(
        f'<article id="{section_id}" class="chapter"><p class="eyebrow">CHAPTER {n:02d}'
        f' <a href="{escape(rel)}">分篇源文件</a></p>{parsed}</article>'
    )

previews = [
    ("home-desktop.png", "首页 · 桌面"),
    ("home-mobile.png", "首页 · 手机"),
    ("home-dusk-dark.png", "暮色 · 深色"),
    ("home-paper.png", "纸笺主题"),
    ("home-graphite.png", "石墨主题"),
    ("home-citrus.png", "柑橘主题"),
    ("admin-desktop.png", "后台 · 桌面"),
    ("admin-mobile.png", "后台 · 手机"),
    ("motion-studio-desktop.png", "动效实验室 · 桌面"),
    ("motion-studio-mobile.png", "动效实验室 · 手机"),
    ("poll-desktop.png", "社区投票"),
    ("poll-results.png", "投票结果"),
    ("question-desktop.png", "问答与采纳"),
    ("question-mobile.png", "问答 · 手机"),
    ("threads-admin.png", "问答投票管理"),
    ("settings-desktop.png", "外观设置"),
    ("layout-studio-desktop.png", "布局工作台"),
    ("layout-studio-mobile.png", "布局工作台 · 手机"),
    ("collections-desktop.png", "专题"),
    ("tasks-desktop.png", "任务中心"),
    ("variants-desktop.png", "规格管理"),
    ("composed-articles-desktop.png", "自定义文章布局"),
    ('circles-desktop.png', '圈子发现 · 桌面'),
    ('circles-mobile.png', '圈子发现 · 手机'),
    ('circle-desktop.png', '圈子详情 · 桌面'),
    ('circle-mobile.png', '圈子详情 · 手机'),
    ('bounty-desktop.png', '积分悬赏 · 桌面'),
    ('bounty-mobile.png', '积分悬赏 · 手机'),
    ('multi-poll-desktop.png', '多选投票'),
    ('product-reviews-desktop.png', '真实购买评价'),
    ('admin-circles-desktop.png', '圈子成员管理'),
    ('admin-bounties-desktop.png', '悬赏管理'),
    ('admin-reviews-desktop.png', '评价审核'),
    ('image-lightbox-desktop.png', '图片灯箱'),
]
previews.extend([
    ("creator-studio-desktop.png","\u521b\u4f5c\u4e2d\u5fc3 \u00b7 \u684c\u9762"),
    ("creator-studio-mobile.png","\u521b\u4f5c\u4e2d\u5fc3 \u00b7 \u624b\u673a"),
    ("creator-admin-desktop.png","\u4f5c\u8005\u6536\u76ca\u540e\u53f0"),
    ("history-desktop.png","\u9605\u8bfb\u5386\u53f2 \u00b7 \u684c\u9762"),
    ("history-mobile.png","\u9605\u8bfb\u5386\u53f2 \u00b7 \u624b\u673a"),
    ("following-desktop.png","\u5173\u6ce8\u52a8\u6001"),
    ("search-palette-desktop.png","\u641c\u7d22\u4ea4\u4e92 \u00b7 \u684c\u9762"),
    ("search-palette-mobile.png","\u641c\u7d22\u4ea4\u4e92 \u00b7 \u624b\u673a"),
])
previews.extend([
    ("security-desktop.png","安全中心 · 桌面"),
    ("security-mobile.png","安全中心 · 手机"),
    ("pricing-studio-desktop.png","定价工作台 · 桌面"),
    ("pricing-studio-mobile.png","定价工作台 · 手机"),
    ("pricing-product-desktop.png","优惠商品 · 桌面"),
    ("pricing-product-mobile.png","优惠商品 · 手机"),
    ("reading-focus-desktop.png","专注阅读"),
])
previews.extend([('integrations-desktop.png', '连接中心'), ('integrations-mobile.png', '连接中心 / 手机'), ('configuration-desktop.png', '配置快照'), ('configuration-mobile.png', '配置快照 / 手机'), ('badges-admin-desktop.png', '徽章管理'), ('badges-admin-mobile.png', '徽章管理 / 手机'), ('badges-desktop.png', '徽章展馆'), ('badges-mobile.png', '徽章展馆 / 手机'), ('connections-security-desktop.png', '账号连接'), ('connections-security-mobile.png', '账号连接 / 手机'), ('lifetime-membership-desktop.png', '永久会员'), ('lifetime-membership-mobile.png', '永久会员 / 手机'), ('connected-login-desktop.png', '社交登录'), ('connected-login-mobile.png', '社交登录 / 手机')])
previews.extend([(p.name,p.stem.replace('-', ' / ')) for p in sorted((docs/'previews').glob('*.png')) if p.name not in {name for name,_ in previews}])
missing_previews = [name for name,_ in previews if not (docs/"previews"/name).exists()]
print("Preview filename check:", missing_previews)
if missing_previews:
    print([p.name for p in (docs/"previews").glob("*.png")])
else:
    gallery = "".join(
        f'<a class="preview" href="docs/previews/{escape(name)}">'
        f'<img loading="lazy" src="docs/previews/{escape(name)}" alt="{escape(title)}">'
        f'<span>{escape(title)}</span></a>' for name,title in previews
    )
    css = """
:root{--ink:#183b3e;--muted:#5b7274;--accent:#177f79;--line:#dce8e7;--paper:#fff;--bg:#f3f8f7}
*{box-sizing:border-box}html{scroll-behavior:smooth;scroll-padding-top:28px}
body{overflow-wrap:anywhere;margin:0;background:var(--bg);color:var(--ink);font:16px/1.8 system-ui,-apple-system,"Segoe UI","Microsoft YaHei",sans-serif}
a{color:var(--accent);text-underline-offset:4px;overflow-wrap:anywhere}
.hero{padding:54px max(24px,calc((100vw - 1240px)/2)) 42px;background:linear-gradient(125deg,#143e41,#17645e);color:#fff}
.brand{letter-spacing:.15em;font-size:12px;text-transform:uppercase;opacity:.85}
.hero h1{font-size:clamp(30px,4vw,46px);line-height:1.25;letter-spacing:-.03em;margin:20px 0 12px}
.hero p{max-width:850px;opacity:.9;margin:12px 0}
.badges{display:flex;gap:10px;flex-wrap:wrap;margin-top:24px}.badges span{border:1px solid #ffffff40;background:#ffffff10;border-radius:999px;padding:5px 13px;font-size:13px}
.shell{max-width:1288px;margin:32px auto;display:grid;grid-template-columns:226px minmax(0,1fr);gap:28px;padding:0 24px}
.toc{align-self:start;position:sticky;top:20px;background:#fff;border:1px solid var(--line);border-radius:18px;padding:20px;max-height:calc(100vh - 40px);overflow:auto}
.toc summary{font-weight:750;cursor:pointer;margin-bottom:12px}.toc a{display:block;text-decoration:none;padding:7px 0;font-size:14px;color:var(--ink)}.toc span{display:inline-block;width:27px;color:var(--accent);font-size:11px}
main{min-width:0}.chapter,.overview{background:var(--paper);border:1px solid var(--line);border-radius:22px;padding:34px 38px;margin-bottom:24px}
.eyebrow{font-size:11px;letter-spacing:.14em;color:var(--muted);margin:0 0 18px}.eyebrow a{float:right;letter-spacing:0}
h2{font-size:27px;line-height:1.4;margin:14px 0 20px;letter-spacing:-.025em}h3{font-size:20px;line-height:1.5;margin:30px 0 12px}h4{font-size:18px}
p{margin:12px 0}li{margin:7px 0}strong{font-weight:700}
code{font-size:.91em;background:#edf3f2;border:1px solid #e1eae8;padding:1px 5px;border-radius:5px;overflow-wrap:anywhere}
pre{white-space:pre-wrap;overflow-wrap:anywhere;padding:18px;border-radius:12px;background:#eff5f3;border:1px solid var(--line);line-height:1.7}
pre code{border:0;padding:0;background:transparent;word-break:normal}
.table-scroll{max-width:100%;overflow:auto;border:1px solid var(--line);border-radius:12px;margin:18px 0}
table{width:100%;border-collapse:collapse;font-size:14px;line-height:1.75;min-width:460px}
th,td{text-align:left;vertical-align:top;padding:12px 14px;border-bottom:1px solid var(--line);overflow-wrap:anywhere}
th{background:#eef6f4;font-weight:700}tr:last-child td{border-bottom:0}
blockquote{margin:20px 0;padding:10px 20px;border-left:3px solid var(--accent);background:#f4f9f7}
.note{border-left:4px solid #cb9b5c;background:#fcf7ef;padding:14px 18px;border-radius:0 10px 10px 0}
.quicklinks{display:flex;gap:8px;flex-wrap:wrap}.quicklinks a{text-decoration:none;background:#e8f3f0;padding:8px 14px;border-radius:8px;font-size:14px}
.gallery{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:15px}
.preview{display:block;text-decoration:none;border:1px solid var(--line);border-radius:13px;overflow:hidden;background:#fafcfb}
.preview img{display:block;width:100%;height:210px;object-fit:cover;object-position:top;border-bottom:1px solid var(--line)}
.preview span{display:block;padding:9px 12px;font-size:13px}
footer{max-width:1240px;margin:25px auto 50px;padding:0 24px;font-size:13px;color:var(--muted)}
@media(max-width:900px){.shell{grid-template-columns:1fr;gap:18px}.toc{position:static;max-height:260px}.toc nav{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 16px}.chapter,.overview{padding:25px}.hero{padding:36px 24px}}
@media(max-width:500px){.shell{padding:0 12px;margin-top:16px}.chapter,.overview{padding:22px 18px;border-radius:16px}.gallery{grid-template-columns:repeat(2,minmax(0,1fr))}.preview img{height:180px}h2{font-size:23px}.toc{padding:16px}.hero{padding:30px 20px}.eyebrow a{float:none;margin-left:12px}}
@media(prefers-reduced-motion:reduce){html{scroll-behavior:auto}}
@media print{body{background:#fff;color:#111;font-size:11pt}.hero{background:#fff;color:#111;padding:0}.toc,.quicklinks,.gallery,.eyebrow a{display:none}.shell{display:block;padding:0}.chapter,.overview{border:0;padding:0;break-before:page}.overview{break-before:auto}.table-scroll{overflow:visible}table{min-width:0}a{color:inherit}.badges span{border-color:#aaa}}
"""
    manual = f"""<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>澄屿 0.18.0 · 部署与使用手册</title><style>{css}</style></head>
<body><header class="hero"><div class="brand">CHENGYU / INDEPENDENT PHP SITE</div>
<h1>澄屿 · 部署与使用手册</h1><p>源码、安装、升级、前后台操作与测试证据，一份可离线阅读的交付说明。</p>
<div class="badges"><span>版本 0.18.0</span><span>更新于 2026-09-07</span><span>非 WordPress</span><span>{len(manual_parts)} 篇说明 · {len(previews)} 张实页预览</span></div></header>
<div class="shell"><aside class="toc"><details open><summary>阅读导航</summary><nav>{''.join(nav)}<a href="#previews"><span>＋</span>页面预览</a></nav></details></aside>
<main><section class="overview"><p class="eyebrow">BEFORE YOU DEPLOY</p><h2>新站与旧站，走不同的安装路径。</h2>
<p>新站只上传 <code>site/</code>；已有 0.9.0–0.17.0 先备份，保留配置密钥、数据库与存储，再升级。根目录部署的后台入口为 <code>/admin</code>。</p>
<p class="note"><strong>不要上传完整交付目录或 INSTALL_KEY.txt。</strong> 本包含真实业务代码，但不等于全部历史子比模块已完成，也没有把未测试的 PHP 版本、原生数据库或真实商户标成通过。</p>
<div class="quicklinks"><a href="#chapter-29">0.18 新功能</a><a href="#chapter-27">上线验收</a><a href="#chapter-28">历史对照</a><a href="#chapter-22">交易</a><a href="#chapter-23">媒体</a><a href="#chapter-2">新站安装</a><a href="#chapter-3">旧站升级</a><a href="#chapter-5">动效与曲线</a><a href="#chapter-21">连接与运营</a><a href="#chapter-20">安全与定价</a><a href="#chapter-11">缺漏清单</a><a href="#chapter-12">测试证据</a></div></section>
{''.join(articles)}
<article id="previews" class="chapter"><p class="eyebrow">ACTUAL APPLICATION PREVIEWS</p><h2>真实页面预览</h2><p>由本项目临时站点生成 HTML 并在 Chromium 渲染。点击查看原图，截图来自原创示例数据，不是生产数据或概念海报。</p><div class="gallery">{gallery}</div></article>
</main></div><footer>澄屿 0.18.0 · 本说明与分篇 Markdown 同步生成。离线使用时保持 docs/previews 相对目录不变。代码许可见 LICENSE。</footer></body></html>"""
    (root/"START_HERE.html").write_text(manual)
    print("Offline manual created:", (root/"START_HERE.html").stat().st_size, "bytes")
