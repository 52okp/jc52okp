const { tutorials } = require('../../data/content.js');

Page({
  data: {
    list: []
  },
  onLoad() {
    // Demo 阶段直接读本地数据；后端恢复后可在此改为 wx.request
    this.setData({ list: tutorials });
  },
  goDetail(e) {
    const id = e.currentTarget.dataset.id;
    wx.navigateTo({
      url: `/pages/detail/detail?id=${id}`
    });
  }
});
