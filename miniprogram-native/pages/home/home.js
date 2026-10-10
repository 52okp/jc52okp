const { BASE_URL, getHome, getArticleList, shareImageUrl } = require('../../common/api');
const { topInset, backendImageUrl, quickCategories } = require('../../common/ui');
Page({
  data: { banners: [], categories: [], quickCategories: [], recommend: [], heroImage: '', heroArticleId: 0, keyword: '', loading: true, topInset: topInset(), sectionTitle: '推荐教程' },
  onShow() { this.load(); },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  async load() {
    this.setData({ loading: true });
    try {
      const data = await getHome();
      const banners = data.banners || [];
      const heroBanner = banners.find(item => backendImageUrl(item.image, BASE_URL));
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
        sectionTitle, heroImage: heroBanner ? backendImageUrl(heroBanner.image, BASE_URL) : '',
        heroArticleId: heroBanner ? Number(heroBanner.article_id) || 0 : 0 });
    } catch (_) {} finally { this.setData({ loading: false }); }
  },
  searchInput(event) { this.setData({ keyword: event.detail.value }); },
  iconError(event) {
    const index = Number(event.currentTarget.dataset.index);
    if (this.data.quickCategories[index]) this.setData({ [`quickCategories[${index}].icon`]: '' });
  },
  heroError() { this.setData({ heroImage: '', heroArticleId: 0 }); },
  search() { wx.navigateTo({ url: '/pages/list/list?title=' + encodeURIComponent('搜索结果') + '&keyword=' + encodeURIComponent(this.data.keyword) }); },
  openHero() { if (this.data.heroArticleId > 0) this.openArticle({ detail: { id: this.data.heroArticleId } }); else this.openMore(); },
  openCategory(event) { const item = this.data.quickCategories[event.currentTarget.dataset.index]; if (item) wx.navigateTo({ url: '/pages/list/list?category_id=' + item.id + '&title=' + encodeURIComponent(item.name) }); },
  openMore() { wx.switchTab({ url: '/pages/category/category' }); },
  openArticle(event) { wx.navigateTo({ url: '/pages/detail/detail?id=' + event.detail.id }); },
  onShareAppMessage() { return { title: '52okpAI教程站 · Stable Diffusion 教程 / 模型 / 工具', path: '/pages/home/home', imageUrl: shareImageUrl(this.data.heroImage) }; },
  onShareTimeline() { return { title: '52okpAI教程站 · Stable Diffusion 教程 / 模型 / 工具', imageUrl: shareImageUrl(this.data.heroImage) }; }
});
