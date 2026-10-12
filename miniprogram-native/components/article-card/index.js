Component({
  properties: {
    article: { type: Object, value: {}, observer(value) {
      const article = value || {};
      const tagLabel = (article.tagList && article.tagList[0]) || String(article.tags || '').split(/[,，]/)[0] || '';
      this.setData({
        dateLabel: String(article.create_at || article.update_at || '').slice(0, 10).replace(/-/g, '/'),
        coverFailed: false,
        tagLabel
      });
    } },
    compact: { type: Boolean, value: false },
    adaptive: { type: Boolean, value: false },
    categoryName: { type: String, value: '' }
  },
  data: { dateLabel: '', coverFailed: false, tagLabel: '' },
  methods: {
    coverError() { this.setData({ coverFailed: true }); },
    open() { this.triggerEvent('open', { id: this.data.article.id }); }
  }
});
