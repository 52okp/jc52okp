const tabs = [
  { pagePath: '/pages/home/home', text: '首页', iconPath: '/static/tabbar/home-outline.png' },
  { pagePath: '/pages/category/category', text: '分类', iconPath: '/static/tabbar/category-outline.png' },
  { pagePath: '/pages/my/my', text: '我的', iconPath: '/static/tabbar/my-outline.png' }
];

Component({
  data: { selected: -1, entering: false, tabs },
  lifetimes: {
    attached() {
      if (typeof getCurrentPages !== 'function') return;
      const pages = getCurrentPages();
      const current = pages[pages.length - 1];
      const index = current && tabs.findIndex(tab => tab.pagePath.slice(1) === current.route);
      if (index >= 0) this.setData({ selected: index });
    }
  },
  pageLifetimes: {
    show() { this.setData({ entering: true }); },
    hide() { this.setData({ entering: false }); }
  },
  methods: {
    switchTab(event) {
      const index = Number(event.currentTarget.dataset.index);
      if (!Number.isInteger(index) || !tabs[index]) return;
      if (index === this.data.selected) return;
      // Tab pages are cached. Leave this page's selection in place so it is
      // already correct on the first frame when the user returns.
      wx.switchTab({ url: tabs[index].pagePath });
    }
  }
});
