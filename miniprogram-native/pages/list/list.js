const { getArticleList } = require('../../common/api');
Page({
  data: { keyword: '', categoryId: 0, list: [], page: 1, total: 0, loading: false, noMore: false },
  onLoad(options) {
    this.setData({ keyword: options.keyword ? decodeURIComponent(options.keyword) : '', categoryId: Number(options.category_id) || 0 });
    if (options.title) wx.setNavigationBarTitle({ title: decodeURIComponent(options.title) });
    this.load(true);
  },
  onPullDownRefresh() { this.load(true).finally(() => wx.stopPullDownRefresh()); },
  onReachBottom() { if (!this.data.noMore && !this.data.loading) this.setData({ page: this.data.page + 1 }, () => this.load(false)); },
  keywordInput(event) { this.setData({ keyword: event.detail.value }); },
  search() { this.requestVersion = (this.requestVersion || 0) + 1; this.setData({ loading: false }); this.load(true); },
  async load(reset) {
    if (this.data.loading) return;
    if (reset) this.setData({ page: 1, list: [], noMore: false });
    const page = this.data.page;
    const version = this.requestVersion || 0;
    this.setData({ loading: true });
    try {
      const data = await getArticleList({ page, limit: 10, keyword: this.data.keyword, category_id: this.data.categoryId });
      if ((this.requestVersion || 0) !== version) return;
      const list = page === 1 ? data.list || [] : this.data.list.concat(data.list || []);
      this.setData({ list, total: data.total || 0, noMore: list.length >= (data.total || 0) });
    } catch (_) {} finally { if ((this.requestVersion || 0) === version) this.setData({ loading: false }); }
  },
  openArticle(event) { wx.navigateTo({ url: '/pages/detail/detail?id=' + event.detail.id }); }
});
