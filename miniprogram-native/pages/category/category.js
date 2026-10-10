const { getCategories, getArticleList } = require('../../common/api');
const { topInset } = require('../../common/ui');
Page({
  data: { categories: [], categoryId: 0, categoryName: '', list: [], page: 1, total: 0, loading: false, initializing: true, topInset: topInset() },
  onLoad() { this.init(); },
  async init() {
    try {
      const categories = await getCategories() || [];
      this.setData({ categories, initializing: false });
      if (categories.length) {
        const featured = categories.findIndex(item => /comfy/i.test(item.name || ''));
        this.select({ currentTarget: { dataset: { index: featured >= 0 ? featured : 0 } } });
      }
    } catch (_) { this.setData({ initializing: false }); }
  },
  select(event) {
    const item = this.data.categories[event.currentTarget.dataset.index];
    if (!item) return;
    this.requestVersion = (this.requestVersion || 0) + 1;
    this.setData({ categoryId: item.id, categoryName: item.name, list: [], page: 1, total: 0, loading: false });
    this.load();
  },
  async load() {
    if (this.data.loading || !this.data.categoryId) return;
    const categoryId = this.data.categoryId;
    const page = this.data.page;
    const version = this.requestVersion;
    this.setData({ loading: true });
    try {
      const data = await getArticleList({ category_id: categoryId, page, limit: 10 });
      if (this.data.categoryId !== categoryId || this.requestVersion !== version) return;
      this.setData({ list: page === 1 ? data.list || [] : this.data.list.concat(data.list || []), total: data.total || 0 });
    } catch (_) {} finally { if (this.requestVersion === version) this.setData({ loading: false }); }
  },
  more() { if (!this.data.loading && this.data.list.length < this.data.total) this.setData({ page: this.data.page + 1 }, () => this.load()); },
  openSelected() { if (this.data.categoryId) wx.navigateTo({ url: '/pages/list/list?category_id=' + this.data.categoryId + '&title=' + encodeURIComponent(this.data.categoryName) }); },
  openArticle(event) { wx.navigateTo({ url: '/pages/detail/detail?id=' + event.detail.id }); }
});
