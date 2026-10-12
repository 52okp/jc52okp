const { BASE_URL, getHome, shareImageUrl } = require('../../common/api');
const { topInset, backendImageUrl } = require('../../common/ui');
const FALLBACK_BANNER = { id: 0, backgroundImage: '', characterImage: '', articleId: 0 };
Page({
  data: { banners: [FALLBACK_BANNER], activeBanner: FALLBACK_BANNER, defaultBackground: '', bannerIndex: 0, keyword: '', articles: [], loading: false, topInset: topInset() },
  onShow() { this.pageVisible = true; this.loadHome(); },
  onHide() { this.pageVisible = false; this.stopBannerTimer(); },
  onUnload() { this.pageVisible = false; this.stopBannerTimer(); this.loadVersion = (this.loadVersion || 0) + 1; },
  onPullDownRefresh() { this.loadHome().finally(() => wx.stopPullDownRefresh()); },
  async loadHome() {
    const version = (this.loadVersion || 0) + 1;
    this.loadVersion = version;
    this.setData({ loading: true });
    try {
      const data = await getHome();
      if (version !== this.loadVersion) return;
      const defaultBackground = backendImageUrl(data.default_banner_background, BASE_URL);
      const banners = (data.banners || []).map(item => {
        return {
          id: item.id,
          backgroundImage: backendImageUrl(item.image, BASE_URL) || defaultBackground,
          characterImage: backendImageUrl(item.character_image, BASE_URL),
          articleId: Number(item.article_id) || 0
        };
      }).filter(item => item.backgroundImage || item.characterImage);
      const available = banners.length ? banners : [{ ...FALLBACK_BANNER, backgroundImage: defaultBackground }];
      this.setData({
        banners: available,
        activeBanner: available[0],
        defaultBackground,
        bannerIndex: 0,
        articles: data.recommend || []
      });
      if (this.pageVisible) this.startBannerTimer();
    } catch (_) {
      if (version === this.loadVersion) this.setData({ articles: [] });
    } finally {
      if (version === this.loadVersion) this.setData({ loading: false });
    }
  },
  searchInput(event) { this.setData({ keyword: event.detail.value }); },
  search() { wx.navigateTo({ url: '/pages/list/list?title=' + encodeURIComponent('搜索结果') + '&keyword=' + encodeURIComponent(this.data.keyword) }); },
  stopBannerTimer() {
    if (this.bannerTimer) clearInterval(this.bannerTimer);
    this.bannerTimer = null;
  },
  startBannerTimer() {
    this.stopBannerTimer();
    if (this.data.banners.length > 1) {
      this.bannerTimer = setInterval(() => this.switchBanner(this.data.bannerIndex + 1, false), 5000);
    }
  },
  switchBanner(index, restartTimer) {
    const count = this.data.banners.length;
    if (count < 2) return;
    const next = (index + count) % count;
    this.setData({ bannerIndex: next, activeBanner: this.data.banners[next] });
    if (restartTimer && this.pageVisible) this.startBannerTimer();
  },
  heroTouchStart(event) {
    const touch = event.touches && event.touches[0];
    if (touch) {
      this.stopBannerTimer();
      this.touchStartX = touch.pageX;
      this.touchStartY = touch.pageY;
    }
  },
  heroTouchEnd(event) {
    const touch = event.changedTouches && event.changedTouches[0];
    if (!touch || this.touchStartX === undefined) {
      if (this.pageVisible) this.startBannerTimer();
      return;
    }
    const dx = touch.pageX - this.touchStartX;
    const dy = touch.pageY - this.touchStartY;
    this.touchStartX = undefined;
    if (this.data.banners.length > 1 && Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy) * 1.2) {
      this.lastSwipeAt = Date.now();
      this.switchBanner(this.data.bannerIndex + (dx < 0 ? 1 : -1), true);
    } else if (this.pageVisible) this.startBannerTimer();
  },
  heroTouchCancel() { this.touchStartX = undefined; if (this.pageVisible) this.startBannerTimer(); },
  selectBanner(event) {
    this.switchBanner(Number(event.currentTarget.dataset.index), true);
  },
  heroError() {
    const index = this.data.bannerIndex;
    const banner = this.data.banners[index];
    if (!banner) return;
    const fallback = banner.backgroundImage === this.data.defaultBackground ? '' : this.data.defaultBackground;
    this.setData({ [`banners[${index}].backgroundImage`]: fallback, 'activeBanner.backgroundImage': fallback });
  },
  characterError() {
    const index = this.data.bannerIndex;
    const banner = this.data.banners[index];
    if (banner && banner.characterImage) this.setData({ [`banners[${index}].characterImage`]: '', 'activeBanner.characterImage': '' });
  },
  openHero() {
    if (Date.now() - (this.lastSwipeAt || 0) < 450) return;
    const banner = this.data.activeBanner;
    if (banner && banner.articleId > 0) this.openArticle({ detail: { id: banner.articleId } });
  },
  openArticle(event) { wx.navigateTo({ url: '/pages/detail/detail?id=' + event.detail.id }); },
  onShareAppMessage() {
    const banner = this.data.activeBanner || FALLBACK_BANNER;
    return { title: '52okpAI教程站 · Stable Diffusion 教程 / 模型 / 工具', path: '/pages/home/home', imageUrl: shareImageUrl(banner.backgroundImage) };
  },
  onShareTimeline() {
    const banner = this.data.activeBanner || FALLBACK_BANNER;
    return { title: '52okpAI教程站 · Stable Diffusion 教程 / 模型 / 工具', imageUrl: shareImageUrl(banner.backgroundImage) };
  }
});
