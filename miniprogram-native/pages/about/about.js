const { getSetting } = require('../../common/api');
Page({
  data: { setting: { follow_image: '', follow_text: '' }, showFollow: false },
  onLoad() { this.load(); },
  async load() { try { this.setData({ setting: await getSetting() || this.data.setting }); } catch (_) {} },
  follow() { this.setData({ showFollow: true }); },
  close() { this.setData({ showFollow: false }); },
  preview() { if (this.data.setting.follow_image) wx.previewImage({ urls: [this.data.setting.follow_image] }); }
});
