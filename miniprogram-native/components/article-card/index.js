Component({
  properties: {
    article: { type: Object, value: {}, observer(value) {
      const article = value || {};
      const count = Number(article.num_read) || 0;
      const tagLabel = (article.tagList && article.tagList[0]) || String(article.tags || '').split(/[,，]/)[0] || '';
      this.setData({
        dateLabel: String(article.create_at || article.update_at || '').slice(0, 10).replace(/-/g, '/'),
        viewsLabel: count >= 10000 ? (count / 10000).toFixed(1) + 'w' : count >= 1000 ? (count / 1000).toFixed(1) + 'k' : String(count),
        hasViews: article.num_read !== undefined && article.num_read !== null && article.num_read !== '',
        coverFailed: false,
        tagLabel
      });
    } },
    compact: { type: Boolean, value: false },
    adaptive: { type: Boolean, value: false },
    categoryName: { type: String, value: '' }
  },
  data: { dateLabel: '', viewsLabel: '0', hasViews: false, coverFailed: false, tagLabel: '' },
  methods: {
    coverError() { this.setData({ coverFailed: true }); },
    open() { this.triggerEvent('open', { id: this.data.article.id }); }
  }
});
