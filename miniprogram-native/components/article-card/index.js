Component({
  properties: {
    article: { type: Object, value: {}, observer(value) {
      const article = value || {};
      const count = Number(article.num_read) || 0;
      const tagLabel = (article.tagList && article.tagList[0]) || String(article.tags || '').split(/[,，]/)[0] || '';
      this.setData({
        dateLabel: String(article.update_at || article.create_at || '').slice(0, 10).replace(/-/g, '/'),
        viewsLabel: count >= 10000 ? (count / 10000).toFixed(1) + 'w' : count >= 1000 ? (count / 1000).toFixed(1) + 'k' : String(count),
        tagLabel
      });
    } },
    compact: { type: Boolean, value: false }
  },
  data: { dateLabel: '', viewsLabel: '0', tagLabel: '' },
  methods: { open() { this.triggerEvent('open', { id: this.data.article.id }); } }
});
