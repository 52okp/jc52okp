# SD 教程小程序 Demo

Stable Diffusion 教程类微信小程序，包含「教程 / 工具下载 / 关于」三个模块。

## 如何运行

1. 下载安装 **微信开发者工具**：https://developers.weixin.qq.com/miniprogram/dev/devtools/download.html
2. 打开微信开发者工具 → 新建项目 → **导入项目** → 选择本文件夹。
3. AppID 这一步：
   - 有自己的小程序 AppID 就填上；
   - 没有就点 **「测试号 / 不使用 AppID（游客模式）」** 也能直接预览。
4. 进去就能看到三个底部 Tab，点教程、点复制链接都能用。

## 目录结构

```
app.json            全局配置（页面、底部 tabBar）
app.js / app.wxss   全局逻辑 / 样式
data/content.js     ★ 你主要改这里：教程内容 + 工具网盘链接
pages/
  tutorials/        教程列表
  detail/           教程详情
  tools/            工具下载（多网盘链接 + 点击复制）
  about/            关于页
```

## 怎么加 / 改内容（不用懂代码）

打开 `data/content.js`：

- **加教程**：在 `tutorials` 数组里照着已有的格式复制一段。
  - `content` 里每一段的 `type` 可填：`heading`(小标题) / `text`(正文) / `tip`(黄色提示框) / `image`(图片，填 url)。
- **加工具/网盘**：在 `tools` 数组里加一项，`links` 里可以放任意多个网盘：
  ```js
  { type: '百度网盘', url: '链接', code: '提取码' }
  ```
  没有提取码就把 `code` 留空 `''`。

## 关于网盘链接「点击复制」

小程序**不能直接跳转打开外部网址/网盘**（微信平台规则）。
本 demo 采用官方推荐、合规的方式：用 `wx.setClipboardData` 复制链接 +
弹窗提示用户去浏览器/网盘 App 粘贴打开。这种方式不会触发违规。

## 后端恢复后怎么接接口

1. 打开 `app.js`，把 `useRemote` 改成 `true`，填好 `apiBase`。
2. 在各页面的 `onLoad` 里，把 `require` 本地数据改成 `wx.request` 请求接口，
   数据结构跟 `data/content.js` 保持一致即可，页面 UI 无需改动。
