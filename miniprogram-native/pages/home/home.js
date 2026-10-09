const { getHome, shareImageUrl } = require('../../common/api');
Page({
  data: { banners: [], categories: [], recommend: [], heroImage: '', keyword: '', loading: true },
  onLoad() { this.load(); },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  async load() {
    this.setData({ loading: true });
    try {
      const data = await getHome();
      const banners = data.banners || [];
      this.setData({ banners, categories: data.categories || [], recommend: data.recommend || [], heroImage: banners[0] ? banners[0].image : '' });
    } catch (_) {} finally { this.setData({ loading: false }); }
  },
  searchInput(event) { this.setData({ keyword: event.detail.value }); },
  search() { wx.navigateTo({ url: '/pages/list/list?title=' + encodeURIComponent('搜索结果') + '&keyword=' + encodeURIComponent(this.data.keyword) }); },
  openHero() { const first = this.data.banners[0]; if (first && first.article_id > 0) this.openArticle({ detail: { id: first.article_id } }); },
  openCategory(event) { const item = this.data.categories[event.currentTarget.dataset.index]; if (item) wx.navigateTo({ url: '/pages/list/list?category_id=' + item.id + '&title=' + encodeURIComponent(item.name) }); },
  openArticle(event) { wx.navigateTo({ url: '/pages/detail/detail?id=' + event.detail.id }); },
  onShareAppMessage() { return { title: '52okpAI教程站 · Stable Diffusion 教程 / 模型 / 工具', path: '/pages/home/home', imageUrl: shareImageUrl(this.data.heroImage) }; },
  onShareTimeline() { return { title: '52okpAI教程站 · Stable Diffusion 教程 / 模型 / 工具', imageUrl: shareImageUrl(this.data.heroImage) }; }
});
