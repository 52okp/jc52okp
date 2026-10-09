<template>
  <view class="page">
    <view class="list">
      <view v-for="item in list" :key="item.id" class="card glass-solid" @click="goDetail(item.id)">
        <view class="cover" :class="{ 'cover-default': !item.cover }">
          <image v-if="item.cover" :src="item.cover" mode="aspectFill" class="cover-img" />
          <text v-else class="cover-text">SD</text>
        </view>
        <view class="body">
          <view class="title">{{ item.title }}</view>
          <view class="summary">{{ item.summary }}</view>
          <view class="muted read">阅读 {{ item.num_read }}</view>
        </view>
      </view>
    </view>
    <view v-if="!loading && list.length === 0" class="empty">还没有收藏，去首页逛逛吧～</view>
    <view v-if="loading" class="empty">加载中…</view>
  </view>
</template>

<script>
import { getFavorites, memberLogin } from '@/common/api.js';
export default {
  data() { return { list: [], loading: true }; },
  onShow() { this.load(); },
  onPullDownRefresh() { this.load().finally(() => uni.stopPullDownRefresh()); },
  methods: {
    async load() {
      this.loading = true;
      try { this.list = await getFavorites() || []; }
      catch (e) { if (e && e.code === 401) { await memberLogin(); try { this.list = await getFavorites() || []; } catch (e2) {} } }
      finally { this.loading = false; }
    },
    goDetail(id) { uni.navigateTo({ url: '/pages/detail/detail?id=' + id }); }
  }
};
</script>

<style>
.page { padding: 28rpx; }
.card { display: flex; border-radius: 28rpx; padding: 22rpx; margin-bottom: 22rpx; }
.cover { width: 160rpx; height: 160rpx; border-radius: 22rpx; overflow: hidden; flex-shrink: 0; margin-right: 24rpx; }
.cover-img { width: 100%; height: 100%; }
.cover-default { background: linear-gradient(135deg, #2f7bff, #79b4ff); display: flex; align-items: center; justify-content: center; }
.cover-text { color: #fff; font-size: 40rpx; font-weight: 700; }
.body { flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: space-between; }
.title { font-size: 32rpx; font-weight: 600; }
.summary { font-size: 26rpx; color: #6b6b78; margin: 8rpx 0; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.read { font-size: 24rpx; }
.muted { color: #9a9aa8; }
.empty { text-align: center; color: #9a9aa8; font-size: 26rpx; padding: 100rpx 0; }
</style>
