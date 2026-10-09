const { getArticleDetail, toggleFavorite, shareImageUrl } = require('../../common/api');
Page({
  data: { id: 0, article: null, loading: true, favoriteBusy: false },
  onLoad(options) { const id = Number(options.id) || 0; this.setData({ id }); if (id) this.load(); else this.setData({ loading: false }); },
  async load() {
    this.setData({ loading: true });
    try {
      const article = await getArticleDetail(this.data.id);
      this.setData({ article });
      if (article && article.title) wx.setNavigationBarTitle({ title: article.title });
    } catch (_) {} finally { this.setData({ loading: false }); }
  },
  copyLink(event) {
    const link = this.data.article.links[event.currentTarget.dataset.index];
    if (!link) return;
    const text = link.code ? link.url + ' 提取码: ' + link.code : link.url;
    wx.setClipboardData({ data: text, success() { wx.showModal({ title: '已复制', content: link.type === '解压密码' ? '解压密码已复制。' : '资料地址已复制。', showCancel: false }); } });
  },
  async favorite() {
    if (this.data.favoriteBusy || !this.data.article) return;
    this.setData({ favoriteBusy: true });
    try {
      const data = await toggleFavorite(this.data.id);
      this.setData({ 'article.favorited': data.favorited });
      wx.showToast({ title: data.favorited ? '已收藏' : '已取消', icon: 'none' });
    } catch (_) { wx.showToast({ title: '请稍后重试', icon: 'none' }); }
    finally { this.setData({ favoriteBusy: false }); }
  },
  onShareAppMessage() { const article = this.data.article || {}; return { title: article.title || '52okpAI教程站', path: '/pages/detail/detail?id=' + this.data.id, imageUrl: shareImageUrl(article.cover) }; },
  onShareTimeline() { const article = this.data.article || {}; return { title: article.title || '52okpAI教程站', query: 'id=' + this.data.id, imageUrl: shareImageUrl(article.cover) }; }
});
