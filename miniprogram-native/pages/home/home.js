const { BASE_URL, getHome, getArticleList, shareImageUrl } = require('../../common/api');
const { topInset, backendImageUrl } = require('../../common/ui');
const FALLBACK_BANNER = { id: 0, title: '', backgroundImage: '', characterImage: '', articleId: 0 };
const withImageUrls = articles => (articles || []).map(article => ({ ...article, cover: backendImageUrl(article.cover, BASE_URL) }));
Page({
  data: { banners: [FALLBACK_BANNER], activeBanner: FALLBACK_BANNER, defaultBackground: '', bannerIndex: 0, keyword: '', articleMode: 'recommend', articles: [], recommendArticles: [], latestArticles: [], latestPage: 0, latestTotal: 0, latestLoaded: false, latestLoading: false, loading: false, refreshing: false, brandContracted: false, headerHidden: false, topInset: topInset() },
  onShow() {
    this.pageVisible = true;
    const tabBar = this.getTabBar && this.getTabBar();
    if (tabBar && tabBar.data.selected !== 0) tabBar.setData({ selected: 0 });
    if (this.data.loading) return;
    if (!this.lastHomeLoadedAt || Date.now() - this.lastHomeLoadedAt > 60000) this.loadHome();
    else this.startBannerTimer();
    if (this.data.articleMode === 'latest' && (!this.lastLatestLoadedAt || Date.now() - this.lastLatestLoadedAt > 60000)) this.loadLatest(1);
  },
  onHide() { this.pageVisible = false; this.stopBannerTimer(); this.setData({ brandContracted: false }); },
  onUnload() { this.pageVisible = false; this.stopBannerTimer(); this.loadVersion = (this.loadVersion || 0) + 1; this.latestRequestVersion = (this.latestRequestVersion || 0) + 1; },
  async onRefresh() {
    if (this.data.refreshing) return;
    this.stopBannerTimer();
    this.setData({ refreshing: true, brandContracted: true });
    try {
      await Promise.all([this.loadHome(), this.data.articleMode === 'latest' ? this.loadLatest(1, true) : Promise.resolve()]);
    }
    finally { this.setData({ refreshing: false }); }
  },
  onRefresherPulling(event) {
    const distance = Number(event.detail && event.detail.dy) || 0;
    if (distance > 14 && !this.data.brandContracted) this.setData({ brandContracted: true });
    else if (distance < 6 && this.data.brandContracted && !this.data.refreshing) this.setData({ brandContracted: false });
  },
  onRefresherRestore() {
    if (this.data.brandContracted) this.setData({ brandContracted: false });
  },
  onHomeScroll(event) {
    const hidden = Number(event.detail && event.detail.scrollTop) > 50;
    if (hidden !== this.data.headerHidden) this.setData({ headerHidden: hidden });
  },
  async loadHome() {
    const version = (this.loadVersion || 0) + 1;
    this.loadVersion = version;
    this.setData({ loading: true });
    try {
      const app = getApp();
      const pending = app && app.homePrefetch;
      if (pending) app.homePrefetch = null;
      const data = await (pending || getHome());
      if (version !== this.loadVersion) return;
      const defaultBackground = backendImageUrl(data.default_banner_background, BASE_URL);
      const banners = (data.banners || []).map(item => {
        return {
          id: item.id,
          title: String(item.title || '').trim(),
          backgroundImage: backendImageUrl(item.image, BASE_URL) || defaultBackground,
          characterImage: backendImageUrl(item.character_image, BASE_URL),
          articleId: Number(item.article_id) || 0
        };
      }).filter(item => item.backgroundImage || item.characterImage);
      const available = banners.length ? banners : [{ ...FALLBACK_BANNER, backgroundImage: defaultBackground }];
      const recommendArticles = withImageUrls(data.recommend);
      this.setData({
        banners: available,
        activeBanner: available[0],
        defaultBackground,
        bannerIndex: 0,
        recommendArticles,
        articles: this.data.articleMode === 'recommend' ? recommendArticles : this.data.latestArticles
      });
      this.lastHomeLoadedAt = Date.now();
      if (this.pageVisible) this.startBannerTimer();
    } catch (_) {
      // Retain the last visible articles if a background refresh fails.
    } finally {
      if (version === this.loadVersion) this.setData({ loading: false });
    }
  },
  selectArticleMode(event) {
    const mode = event.currentTarget.dataset.mode;
    if (mode === this.data.articleMode || (mode !== 'recommend' && mode !== 'latest')) return;
    this.setData({ articleMode: mode, articles: mode === 'recommend' ? this.data.recommendArticles : this.data.latestArticles });
    if (mode === 'latest' && !this.data.latestLoaded) this.loadLatest(1);
  },
  async loadLatest(page, force = false) {
    if (this.data.latestLoading && !force) return;
    const version = (this.latestRequestVersion || 0) + 1;
    this.latestRequestVersion = version;
    this.setData({ latestLoading: true });
    try {
      const data = await getArticleList({ page, limit: 10, order: 'latest' });
      if (version !== this.latestRequestVersion) return;
      const pageArticles = withImageUrls(data.list);
      const latestArticles = page === 1 ? pageArticles : this.data.latestArticles.concat(pageArticles);
      this.setData({
        latestArticles,
        latestPage: page,
        latestTotal: data.total || 0,
        latestLoaded: true,
        ...(this.data.articleMode === 'latest' ? { articles: latestArticles } : {})
      });
      this.lastLatestLoadedAt = Date.now();
    } catch (_) {
      // Keep the last successfully loaded articles when the request fails.
    } finally {
      if (version === this.latestRequestVersion) this.setData({ latestLoading: false });
    }
  },
  loadMoreArticles() {
    if (this.data.articleMode !== 'latest' || !this.data.latestLoaded || this.data.latestLoading) return;
    if (this.data.latestArticles.length < this.data.latestTotal) this.loadLatest(this.data.latestPage + 1);
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
