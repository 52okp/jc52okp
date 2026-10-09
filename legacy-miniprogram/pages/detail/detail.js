const { tutorials } = require('../../data/content.js');

Page({
  data: {
    tutorial: null
  },
  onLoad(options) {
    const id = options.id;
    const tutorial = tutorials.find(t => t.id === id) || null;
    this.setData({ tutorial });
    if (tutorial) {
      wx.setNavigationBarTitle({ title: tutorial.title });
    }
  },
  previewImage(e) {
    const url = e.currentTarget.dataset.url;
    if (url) {
      wx.previewImage({ urls: [url] });
    }
  }
});
