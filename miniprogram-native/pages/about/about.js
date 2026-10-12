const { getSetting } = require('../../common/api');
Page({
  data: { followAccounts: [], followScrollable: false, showFollow: false },
  onLoad() { this.load(); },
  async load() {
    try {
      const setting = await getSetting() || {};
      const source = Array.isArray(setting.follow_accounts)
        ? setting.follow_accounts
        : (setting.follow_image || setting.follow_text)
          ? [{ title: '公众号', image: setting.follow_image || '', description: setting.follow_text || '' }]
          : [];
      const followAccounts = source.filter(item => item && typeof item === 'object').map(item => ({
        title: typeof item.title === 'string' && item.title.trim() ? item.title : '公众号',
        image: typeof item.image === 'string' ? item.image : '',
        description: typeof item.description === 'string' ? item.description : ''
      })).filter(item => item.image || item.description);
      this.setData({ followAccounts, followScrollable: followAccounts.length > 1 });
    } catch (_) {}
  },
  follow() { this.setData({ showFollow: true }); },
  close() { this.setData({ showFollow: false }); },
  preview(event) {
    const account = this.data.followAccounts[Number(event.currentTarget.dataset.index)];
    if (!account || !account.image) return;
    const urls = this.data.followAccounts.map(item => item.image).filter(Boolean);
    wx.previewImage({ current: account.image, urls });
  }
});
