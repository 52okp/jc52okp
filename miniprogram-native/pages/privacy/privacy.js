const { getSetting } = require('../../common/api');

Page({
  data: { content: '', loading: true, failed: false },
  onLoad() { this.load(); },
  async load() {
    this.setData({ loading: true, failed: false });
    try {
      const setting = await getSetting() || {};
      this.setData({ content: setting.privacy_content || '' });
    } catch (_) {
      this.setData({ failed: true });
    } finally {
      this.setData({ loading: false });
    }
  }
});
