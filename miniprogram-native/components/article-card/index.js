Component({ properties: { article: { type: Object, value: {} } }, methods: { open() { this.triggerEvent('open', { id: this.data.article.id }); } } });
