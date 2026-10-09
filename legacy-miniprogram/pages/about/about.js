Page({
  data: {
    // 客服微信号，可改成你自己的
    wechat: 'your_wechat_id'
  },
  copyWechat() {
    wx.setClipboardData({
      data: this.data.wechat,
      success() {
        wx.showToast({ title: '微信号已复制', icon: 'success' });
      }
    });
  }
});
