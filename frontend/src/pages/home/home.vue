<template>
  <view class="page">
    <!-- 顶部 Hero（头图由后台「首页头图」控制） -->
    <view class="hero" @click="onHero">
      <image v-if="heroImage" :src="heroImage" mode="aspectFill" class="hero-bg" />
      <view class="hero-mask"></view>
      <view class="hero-inner">
        <view class="hero-title">stable DiffusionAI教程</view>
        <view class="hero-sub">Stable Diffusion 教程 · 模型 · 工具</view>
      </view>
    </view>

    <!-- 磨砂搜索栏（上移叠在 Hero 上） -->
    <view class="search-wrap">
      <view class="search glass">
        <text class="search-ico">🔍</text>
        <input class="search-input" v-model="keyword" placeholder="搜索教程 / 模型 / 工具" confirm-type="search" @confirm="goSearch" />
        <view class="search-btn" @click="goSearch">搜索</view>
      </view>
    </view>

    <!-- 分类入口 -->
    <view class="cats glass" v-if="categories.length">
      <view class="cat" v-for="(c, i) in categories" :key="c.id" @click="goCategory(c)">
        <view class="cat-icon" :class="'cc' + (i % 4)">
          <image v-if="c.icon" :src="c.icon" mode="aspectFill" class="cat-icon-img" />
          <text v-else class="cat-emoji">{{ c.name.substring(0, 1) }}</text>
        </view>
        <text class="cat-name">{{ c.name }}</text>
      </view>
    </view>

    <!-- 推荐文章 -->
    <view class="section-title">
      <text class="bar"></text><text>推荐教程</text>
    </view>
    <view class="list">
      <view v-for="item in recommend" :key="item.id" class="card glass-solid" @click="goDetail(item.id)">
        <view class="cover" :class="{ 'cover-default': !item.cover }">
          <image v-if="item.cover" :src="item.cover" mode="aspectFill" class="cover-img" />
          <text v-else class="cover-text">SD</text>
        </view>
        <view class="body">
          <view class="title">{{ item.title }}</view>
          <view class="summary">{{ item.summary }}</view>
          <view class="meta">
            <text v-for="t in item.tagList" :key="t" class="g-tag">{{ t }}</text>
            <text class="muted read">阅读 {{ item.num_read }}</text>
          </view>
        </view>
      </view>
      <view v-if="!loading && recommend.length === 0" class="empty">暂无推荐</view>
    </view>
  </view>
</template>

<script>
import { getHome, shareImageUrl } from '@/common/api.js';
export default {
  data() {
    return {
      banners: [], categories: [], recommend: [], loading: true, keyword: ''
    };
  },
  computed: {
    // 首页头图：取后台「首页头图」列表里排最前的一张
    heroImage() { return this.banners.length ? this.banners[0].image : ''; }
  },
  onLoad() { this.load(); },
  onPullDownRefresh() { this.load().finally(() => uni.stopPullDownRefresh()); },
  // 转发给朋友：指定标题/路径/封面图，避免默认截图导致封面发黑
  onShareAppMessage() {
    return {
      title: '52okpAI教程站 · Stable Diffusion 教程 / 模型 / 工具',
      path: '/pages/home/home',
      imageUrl: shareImageUrl(this.heroImage)
    };
  },
  // 分享到朋友圈
  onShareTimeline() {
    return {
      title: '52okpAI教程站 · Stable Diffusion 教程 / 模型 / 工具',
      query: '',
      imageUrl: shareImageUrl(this.heroImage)
    };
  },
  methods: {
    async load() {
      this.loading = true;
      try {
        const d = await getHome();
        this.banners = d.banners || [];
        this.categories = d.categories || [];
        this.recommend = d.recommend || [];
      } catch (e) {} finally { this.loading = false; }
    },
    goSearch() {
      uni.navigateTo({ url: '/pages/list/list?title=' + encodeURIComponent('搜索结果') + '&keyword=' + encodeURIComponent(this.keyword || '') });
    },
    onHero() {
      const b = this.banners[0];
      if (b && b.article_id > 0) uni.navigateTo({ url: '/pages/detail/detail?id=' + b.article_id });
    },
    goCategory(c) { uni.navigateTo({ url: '/pages/list/list?category_id=' + c.id + '&title=' + encodeURIComponent(c.name) }); },
    goDetail(id) { uni.navigateTo({ url: '/pages/detail/detail?id=' + id }); }
  }
};
</script>

<style>
.page { padding-bottom: 40rpx; }

/* Hero */
.hero { position: relative; height: 420rpx; overflow: hidden; }
.hero-bg { position: absolute; inset: 0; width: 100%; height: 100%; filter: saturate(1.1); }
.hero-mask {
  position: absolute; inset: 0;
  background: linear-gradient(180deg, rgba(20,60,140,0.22) 0%, rgba(47,123,255,0.12) 40%, rgba(234,243,255,0.92) 100%);
}
.hero-inner { position: absolute; left: 40rpx; bottom: 120rpx; }
.hero-title { font-size: 52rpx; font-weight: 800; color: #fff; text-shadow: 0 2rpx 12rpx rgba(0,0,0,0.25); }
.hero-sub { font-size: 26rpx; color: #fff; opacity: .92; margin-top: 8rpx; text-shadow: 0 2rpx 8rpx rgba(0,0,0,0.2); }
/* 无封面时给 hero 一个渐变底 */
.hero { background: linear-gradient(135deg, #2f7bff 0%, #4f93ff 50%, #7db4ff 100%); }

/* 搜索栏：上移叠在 hero 底部 */
.search-wrap { margin-top: -70rpx; padding: 0 28rpx; position: relative; z-index: 2; }
.search {
  display: flex; align-items: center; border-radius: 999rpx; padding: 12rpx 12rpx 12rpx 28rpx; height: 80rpx;
}
.search-ico { font-size: 30rpx; margin-right: 14rpx; opacity: .7; }
.search-input { flex: 1; height: 60rpx; font-size: 28rpx; }
.search-btn { background: linear-gradient(135deg,#2f7bff,#5b9bff); color: #fff; font-size: 26rpx; padding: 14rpx 34rpx; border-radius: 999rpx; }

/* 轮播 */

/* 分类入口 */
.cats { display: flex; flex-wrap: wrap; border-radius: 28rpx; padding: 30rpx 8rpx 16rpx; margin: 28rpx; }
.cat { width: 25%; display: flex; flex-direction: column; align-items: center; margin-bottom: 18rpx; }
.cat-icon { width: 100rpx; height: 100rpx; border-radius: 28rpx; overflow: hidden; display: flex; align-items: center; justify-content: center; margin-bottom: 12rpx; box-shadow: 0 6rpx 16rpx rgba(47,123,255,0.22); }
.cat-icon.cc0 { background: linear-gradient(135deg,#2f7bff,#5b9bff); }
.cat-icon.cc1 { background: linear-gradient(135deg,#19c0e8,#2f7bff); }
.cat-icon.cc2 { background: linear-gradient(135deg,#4f93ff,#7db4ff); }
.cat-icon.cc3 { background: linear-gradient(135deg,#5161ff,#2f8bff); }
.cat-emoji { color: #fff; font-size: 44rpx; font-weight: 800; }
.cat-icon-img { width: 100%; height: 100%; }
.cat-name { font-size: 24rpx; color: #4a4a5a; }

/* 区块标题 */
.section-title { display: flex; align-items: center; font-size: 32rpx; font-weight: 800; margin: 18rpx 36rpx 18rpx; color: #2b2b3a; }
.section-title .bar { width: 8rpx; height: 32rpx; background: linear-gradient(#2f7bff,#5b9bff); border-radius: 4rpx; margin-right: 14rpx; }

/* 列表卡片 */
.list { padding: 0 28rpx; }
.card { display: flex; border-radius: 28rpx; padding: 22rpx; margin-bottom: 22rpx; }
.cover { width: 160rpx; height: 160rpx; border-radius: 22rpx; overflow: hidden; flex-shrink: 0; margin-right: 24rpx; }
.cover-img { width: 100%; height: 100%; }
.cover-default { background: linear-gradient(135deg, #2f7bff, #79b4ff); display: flex; align-items: center; justify-content: center; }
.cover-text { color: #fff; font-size: 44rpx; font-weight: 800; }
.body { flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: space-between; }
.title { font-size: 32rpx; font-weight: 700; color: #2b2b3a; }
.summary { font-size: 26rpx; color: #6b6b7a; margin: 8rpx 0; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.meta { display: flex; align-items: center; }
.read { margin-left: auto; font-size: 24rpx; }
.empty { text-align: center; color: #8a8a9a; padding: 60rpx 0; }
</style>
