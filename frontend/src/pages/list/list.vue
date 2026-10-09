<template>
  <view class="page">
    <view class="search glass">
      <text class="search-ico">🔍</text>
      <input class="search-input" v-model="keyword" placeholder="搜索教程标题" confirm-type="search" @confirm="onSearch" />
      <view class="search-btn" @click="onSearch">搜索</view>
    </view>

    <view class="list">
      <view v-for="item in list" :key="item.id" class="card glass-solid" @click="goDetail(item.id)">
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
    </view>

    <view v-if="!loading && list.length === 0" class="empty">暂无内容</view>
    <view v-if="loading" class="empty">加载中…</view>
    <view v-if="noMore && list.length > 0" class="empty muted">没有更多了</view>
  </view>
</template>

<script>
import { getArticleList } from '@/common/api.js';
export default {
  data() {
    return { list: [], keyword: '', categoryId: 0, page: 1, limit: 10, total: 0, loading: false, noMore: false };
  },
  onLoad(options) {
    this.categoryId = parseInt(options.category_id || 0) || 0;
    if (options.keyword) this.keyword = decodeURIComponent(options.keyword);
    if (options.title) uni.setNavigationBarTitle({ title: decodeURIComponent(options.title) });
    this.load(true);
  },
  onPullDownRefresh() { this.load(true).finally(() => uni.stopPullDownRefresh()); },
  onReachBottom() { if (!this.noMore && !this.loading) { this.page += 1; this.load(false); } },
  methods: {
    async load(reset) {
      if (reset) { this.page = 1; this.noMore = false; }
      this.loading = true;
      try {
        const d = await getArticleList({ page: this.page, limit: this.limit, keyword: this.keyword, category_id: this.categoryId });
        const rows = d.list || [];
        this.total = d.total || 0;
        this.list = reset ? rows : this.list.concat(rows);
        if (this.list.length >= this.total) this.noMore = true;
      } catch (e) {} finally { this.loading = false; }
    },
    onSearch() { this.load(true); },
    goDetail(id) { uni.navigateTo({ url: '/pages/detail/detail?id=' + id }); }
  }
};
</script>

<style>
.page { padding: 28rpx; }
.search { display: flex; align-items: center; border-radius: 999rpx; padding: 12rpx 12rpx 12rpx 28rpx; margin-bottom: 26rpx; height: 80rpx; }
.search-ico { font-size: 28rpx; margin-right: 12rpx; opacity: .7; }
.search-input { flex: 1; height: 60rpx; font-size: 28rpx; }
.search-btn { background: linear-gradient(135deg,#2f7bff,#5b9bff); color: #fff; font-size: 26rpx; padding: 14rpx 34rpx; border-radius: 999rpx; }
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
.empty { text-align: center; color: #8a8a9a; font-size: 26rpx; padding: 60rpx 0; }
</style>
