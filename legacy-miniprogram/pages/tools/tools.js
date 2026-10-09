const { tools } = require('../../data/content.js');

Page({
  data: {
    list: []
  },
  onLoad() {
    this.setData({ list: tools });
  },

  // 复制单条网盘链接（含提取码）
  copyLink(e) {
    const { url, code, type } = e.currentTarget.dataset;
    // 有提取码就一起复制，方便用户粘贴
    const text = code ? `${url} 提取码: ${code}` : url;
    wx.setClipboardData({
      data: text,
      success() {
        wx.showModal({
          title: '链接已复制',
          content: `${type}链接已复制到剪贴板。\n请打开浏览器或网盘 App 粘贴打开。`,
          showCancel: false,
          confirmText: '知道了'
        });
      },
      fail() {
        wx.showToast({ title: '复制失败，请重试', icon: 'none' });
      }
    });
  },

  // 单独复制提取码
  copyCode(e) {
    const code = e.currentTarget.dataset.code;
    if (!code) return;
    wx.setClipboardData({
      data: code,
      success() {
        wx.showToast({ title: '提取码已复制', icon: 'success' });
      }
    });
  }
});
