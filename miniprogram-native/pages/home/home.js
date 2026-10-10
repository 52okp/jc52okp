const { BASE_URL, getHome, getArticleList, shareImageUrl } = require('../../common/api');
const { topInset, quickCategories } = require('../../common/ui');
Page({
  data: { banners: [], categories: [], quickCategories: [], recommend: [], heroImage: '', keyword: '', loading: true, topInset: topInset(), sectionTitle: '推荐教程' },
  onShow() { this.load(); },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  async load() {
    this.setData({ loading: true });
    try {
      const data = await getHome();
      const banners = data.banners || [];
      const categories = data.categories || [];
      let recommend = data.recommend || [];
      let sectionTitle = '推荐教程';
      if (!recommend.length) {
        try {
          const latest = await getArticleList({ page: 1, limit: 6 });
          recommend = latest.list || [];
          sectionTitle = '最新教程';
        } catch (_) {}
      }
      this.setData({ banners, categories, quickCategories: quickCategories(categories, BASE_URL), recommend,
        sectionTitle, heroImage: banners[0] ? banners[0].image : '' });
    } catch (_) {} finally { this.setData({ loading: false }); }
  },
  searchInput(event) { this.setData({ keyword: event.detail.value }); },
  iconError(event) {
    const index = Number(event.currentTarget.dataset.index);
    if (this.data.quickCategories[index]) this.setData({ [`quickCategories[${index}].icon`]: '' });
  },
  search() { wx.navigateTo({ url: '/pages/list/list?title=' + encodeURIComponent('搜索结果') + '&keyword=' + encodeURIComponent(this.data.keyword) }); },
  openHero() { const first = this.data.banners[0]; if (first && first.article_id > 0) this.openArticle({ detail: { id: first.article_id } }); else this.openMore(); },
  openCategory(event) { const item = this.data.quickCategories[event.currentTarget.dataset.index]; if (item) wx.navigateTo({ url: '/pages/list/list?category_id=' + item.id + '&title=' + encodeURIComponent(item.name) }); },
  openMore() { wx.switchTab({ url: '/pages/category/category' }); },
  openArticle(event) { wx.navigateTo({ url: '/pages/detail/detail?id=' + event.detail.id }); },
  onShareAppMessage() { return { title: '52okpAI教程站 · Stable Diffusion 教程 / 模型 / 工具', path: '/pages/home/home', imageUrl: shareImageUrl(this.data.heroImage) }; },
  onShareTimeline() { return { title: '52okpAI教程站 · Stable Diffusion 教程 / 模型 / 工具', imageUrl: shareImageUrl(this.data.heroImage) }; }
});
