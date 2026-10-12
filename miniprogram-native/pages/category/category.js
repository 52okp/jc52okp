const { getCategories, getArticleList } = require('../../common/api');
const { topInset } = require('../../common/ui');

function orderCategories(items) {
  const ordered = items.slice();
  const isTools = item => /工具下载/.test(item.name || '');
  if (!ordered.some(isTools)) return ordered;
  const moved = [];
  [/stable\s*diffusion/i, /comfy\s*ui/i].forEach(pattern => {
    const index = ordered.findIndex(item => pattern.test(item.name || ''));
    if (index >= 0) moved.push(...ordered.splice(index, 1));
  });
  ordered.splice(ordered.findIndex(isTools), 0, ...moved);
  return ordered;
}

function labelArticles(items, categories) {
  const names = {};
  categories.forEach(item => { names[item.id] = item.name; });
  return (items || []).map(item => ({ ...item, categoryName: names[item.category_id] || '' }));
}

Page({
  data: { categories: [], categoryId: 0, categoryName: '', list: [], page: 1, total: 0, loading: false, initializing: true, topInset: topInset() },
  onLoad() { this.init(); },
  onShow() {
    const tabBar = this.getTabBar && this.getTabBar();
    if (tabBar && tabBar.data.selected !== 1) tabBar.setData({ selected: 1 });
  },
  async init() {
    const version = this.requestVersion || 0;
    this.setData({ loading: true });
    const categoriesRequest = getCategories().then(items => orderCategories(items || [])).catch(() => []);
    const articlesRequest = getArticleList({ page: 1, limit: 10, order: 'latest' }).catch(() => null);
    const [categories, data] = await Promise.all([categoriesRequest, articlesRequest]);
    this.setData({ categories, initializing: false });
    if (version !== (this.requestVersion || 0)) return;
    this.setData({ list: labelArticles(data && data.list, categories), total: data && data.total || 0, loading: false });
  },
  selectAll() { return this.selectCategory(0, ''); },
  select(event) {
    const item = this.data.categories[event.currentTarget.dataset.index];
    if (!item) return;
    return this.selectCategory(item.id, item.name);
  },
  selectCategory(categoryId, categoryName) {
    this.requestVersion = (this.requestVersion || 0) + 1;
    this.setData({ categoryId, categoryName, list: [], page: 1, total: 0, loading: false });
    return this.load();
  },
  async load() {
    if (this.data.loading) return;
    const categoryId = this.data.categoryId;
    const page = this.data.page;
    const version = this.requestVersion;
    this.setData({ loading: true });
    try {
      const params = { page, limit: 10, order: 'latest' };
      if (categoryId > 0) params.category_id = categoryId;
      const data = await getArticleList(params);
      if (this.data.categoryId !== categoryId || this.requestVersion !== version) return;
      const articles = labelArticles(data.list, this.data.categories);
      this.setData({ list: page === 1 ? articles : this.data.list.concat(articles), total: data.total || 0 });
    } catch (_) {} finally { if (this.requestVersion === version) this.setData({ loading: false }); }
  },
  more() { if (!this.data.loading && this.data.list.length < this.data.total) this.setData({ page: this.data.page + 1 }, () => this.load()); },
  openArticle(event) { wx.navigateTo({ url: '/pages/detail/detail?id=' + event.detail.id }); }
});
