const { getHistory } = require('../../common/api');
Page({
  data: { list: [], loading: true },
  onShow() { this.load(); },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  async load() { this.setData({ loading: true }); try { this.setData({ list: await getHistory() || [] }); } catch (_) {} finally { this.setData({ loading: false }); } },
  openArticle(event) { wx.navigateTo({ url: '/pages/detail/detail?id=' + event.detail.id }); }
});
