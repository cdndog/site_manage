## 目标

网站名为：{{SITE_NAME}}

归档页面：{{ARCHIVE_PAGE}}

归档时间:  YYYYMMDDHHMMSS , 如： {{ARCHIVE_DATE}}

可用工具: scripts/* 下载归档数据（文章，图片，媒体文件等）的脚本，可参考调整后使用

网站因服务器故障无法恢复数据，需要从archive恢复数据，镜像入口是：{{ARCHIVE_PAGE}}，数据（文章内容+图片）同步到 content/ 下对应的目录，并完成文字与图片的本地化存储 ，css样式也需要下载还原为真实的网站UI

## 步骤

0、需要从 {{ARCHIVE_PAGE}} 中恢复所有的文章数据(图片，CSS样式，媒体文件)，并按严格遵循归档页面的布局，UI，CSS样式为基准，生成hugo主题样式（header,footer,section,sidebar,single,taxonomy,list...）组件，实现1:1还原网站。

- 0.1 从 {{ARCHIVE_PAGE}} 获取页面，并分析生成网站架构与配置参数，保存于hugo.toml文件，用于新网站的配置
```
baseURL = 'https://{{ARCHIVE_DOMAIN}}/'
title = "{{SITE_NAME}}"
theme = '<themename>'

[params]
  subtitle = "<site description>"
  noimagealt = "<no image path and name>"
  site_logo = "<site logo image path and name>"
  favicon = "<site favicon logo image path and name>"
  beian = "<网站备案号>"
  ...

  [params.author]
    email = "<author email>"
    name = "<author name>"

[taxonomies]
  category = "categories"
  tag = "tags"

[module]
  [[module.mounts]]
    source = "assets"
    target = "assets"
  [[module.mounts]]
    source = "static"
    target = "assets"

[outputs]
  home = ['html', 'rss']
  section = ['html', 'rss']
  taxonomy = ['html']
  term = ['html']

```
- 0.2 提取 {{ARCHIVE_PAGE}} 归档的图片，CSS样式，媒体文件，生成网站UI与首页
  网站UI使用Tailwind技术重构，一比一像素级高度还原网站的样式，保证页面可用（css样式丢失时，分析网站的内容与排版，使用Tailwindcss重构，实现网站功能恢复并上线）
  使用图片占位符替换没有归档的图片、媒体文件等

- 0.3 解析 {{ARCHIVE_PAGE}} 的归档文件：识别并抽取归档网站中的分类目录（categories）输出其层级关系、名称、slug、数量、父子关系及对应文章列表。

- 0.4 0.4 解析 {{ARCHIVE_PAGE}} 的文章内容结构：识别文章详情页的可抽取字段，用于生成结构化元数据，包括标题、目录（categories）、封面图、标签（tags）、作者、发布时间、摘要以及正文内容。


1、需要从 {{ARCHIVE_PAGE}} 中恢复所有的文章数据(图片，CSS样式，媒体文件)。

数据处理逻辑：获取目录链接 -> 访问目录 -> 获取文章链接 -> 访问文章链接 -> 恢复文章内容+图片+元数据

- 1.1 (首页中的图片需要优先下载，缺失的文件从源地址下载失败，可以通过从archive中的归档文件下载恢复)
- 1.2 CSS样式，媒体文件 优先下载首页中使用的css和媒体文件
- 1.3 首页的文章要优先下载到 content/目录下，如果文章缺失没有归档备份时，使用首页配置 title + url 生成占位文章
  如：
  <a href="/article/post-001.html">Example Tiltle</a> -> content/article/post-001.html.md

```
  +++
  title = "Example Tiltle"
  description = "Example Tiltle"
  oldurl = "/article/post-001.html"
  slug = "/article/post-001.html"
  thumbnail = "/images/xxx.jpg"
  date = ""
  tages = []
  categories = []
  +++
```

  1.4、增加网址跳转配置 从archive的归档 https://web.archive.org/web/{{ARCHIVE_DATE}}/{{ARCHIVE_DOMAIN}} 获取 全站的目录与文章链接（格式与参数方式）

  原始的地址：/Product_Detail.asp?id=262 -> hugo 配置跳转  /product/262/

  如 ：

```
    [params.redirects]
    from = '/Product_Detail.asp'
    to = '/product/'
    param = 'id'
```


2、从归档页面 https://web.archive.org/web/{{ARCHIVE_DATE}}/http://{{ARCHIVE_DOMAIN}}/ 之中检查有无rss文件，通过rss获取所有的文章链接，目录结构，下载所有的文章并恢复到 content/目录下

rss文件示例：https://web.archive.org/web/{{ARCHIVE_DATE}}/http://{{ARCHIVE_DOMAIN}}/sitemap.xml

文章示例 https://web.archive.org/web/{{ARCHIVE_DATE}}/http://{{ARCHIVE_DOMAIN}}/article/post-001.html

单篇文章输出格式示例：

```
  +++
  title = "Example Tiltle"
  description = "Example Tiltle"
  oldurl = "/article/post-001.html"
  slug = "/article/post-001.html"
  thumbnail = "/images/xxx.jpg"
  date = ""
  tages = []
  categories = []
  +++
```

3、检查首页各分类目录下的文章显示不正常的问题，并从归档备份中下载所需要的图片、文字，并完成修复

https://web.archive.org/web/*/http://{{ARCHIVE_DOMAIN}}/{目录}/*
如
https://web.archive.org/web/*/http://{{ARCHIVE_DOMAIN}}/article/*

4、检查网站LOGO 与 Alt 文字导致的格式错乱的问题 (首页中的图片需要优先下载，缺失的文件从源地址下载失败，可以通过从archive中的归档文件下载恢复)

5、使用 Tailwind（原生的配色与UI） 重构网站主题，保证UI样式一致，同时完成移动端的适配（使用/usr/local/bin/tailwindcss 生成css本地文件）
- 5.1 使用archive.org中备份的css为基线进行重构，通过分析 @hugo.toml 检查首页的页面调用逻辑，修复 template 的布局，保证与archive.org 上备份的首页内容布局保持一致
- 5.2 首页的内容与UI元素布局，参考并按 {{ARCHIVE_PAGE}} 保存的 css文件 、图片、ICON 和 html 布局进行重构
- 5.3 内容页的内容与UI元素布局，参考并按https://web.archive.org/上的内容页归档样式进行一比一还原重构
- 5.4 检查CSS样式中使用的图片，如缺失的文件从源地址下载失败，可以通过从archive中的归档文件下载恢复
- 5.5 检查导航栏的配色BUG，导致视觉上的错误
- 5.6 检查分页导航的排版及UI样式错误的BUG

6、检查 archive {{ARCHIVE_PAGE}} 上全量数据，没有恢复的文章，全部恢复之
  数据处理逻辑：获取目录链接 -> 访问目录 -> 获取文章链接 -> 访问文章链接 -> 恢复文章内容 + 图片 + 元数据
  - 6.1 获取archive.org全量的URL，（基于上面的{归档日期}，早于{归档日期}之前的所有数据都需要获取）
  - 6.2 基于archive.org全量的URL，批量下载恢复数据到 content/下的对应目录，
  - 6.3 使用程序化工具获取 {{ARCHIVE_PAGE}} 页面上的所有链接或目录
     通过文章链接并恢复文件，对于目录链接，通过目录链接抓取目录下的文章链接，遍历所有文章链接恢复文章内容
   链接需要自动补全
   如:
   示例：/web/<YYYYMMDDHHMMSS>/http://*.html -> https://web.archive.org/web/<YYYYMMDDHHMMSS>/http://*.html

7、检查 content/ 逐一检查每一篇文章的内容，优化排版格式（按上文内容做好分段，避免超长段落导致的阅读障碍），保持文章内容(无乱码、无转义字符【如\r】无异常排版)对用户阅读友好，符合SEO优化的要求
- 7.1 要求手动检查一篇一篇文章(清理与文章不相关的文字与html代码)，完成检查后再复核，不要使用程序代码批量替换或是处理，避免误操作
- 7.2 修复文章排版（超长段落切分通过断句修复）
- 7.3 文章正文为空时，需要按标题名撰写一篇短文作为正方内容
- 7.4 文章的配图如果缺失可以通过google image 查找最匹配的图片使用之

8、在hugo.toml增加配置项 app_download_data = 'https://datahub.pages.dev/okx/zh/cta_landing_promotion_data_zh.json'，再通过JS调用服JSON数据在网站的侧边增加一列下载引导按钮

- 8.1 先确认获取https://datahub.pages.dev/okx/zh/cta_landing_promotion_data_zh.json的数据结构，再进行代码的编写，（设定图片的大小避免尺寸过大导致的排版错乱）
- 8.2 检查下载元素不显示的BUG并修复之，检查网站有使用侧边栏时，在侧边栏中增加组件显示下载元素（设定图片的大小避免尺寸过大导致的排版错乱）

9、增加404页面的处理，并显示 app_download_data = 'https://datahub.pages.dev/okx/zh/cta_landing_promotion_data_zh.json'的数据,检查页面是否加载了下载组件显示，下载链接
   要求水平排放，设定图片的大小避免尺寸过大导致的排版错乱

10、 使用hugo检查全站配置与文章有无报错，并修复之

11、检查网站是否有在使用flash，如果使用flash时，引入并使用ruffle支持flash的播放，参考文档：https://github.com/ruffle-rs/ruffle/
- 11.1 ruffle实现flash文件的自动播放

12、@hugo.toml增加 author="欧交易所"，并在所有文章中引用这个作者名为署名作者

13、检查所有文章中缺失图片的，在前端图片显示为【无图片】保持界面的友好性

14、检查全站将所有的联系方式（电话，QQ、邮箱等）使用 *** 屏蔽部分信息，避免个人隐私暴露(备案号从archive中恢复真实的备案号，不需要脱敏处理), 并统一配置化管理于@hugo.toml

15、SEO审计，完成全站SEO优化

16、 完成所有工作后，提交github版本控制
