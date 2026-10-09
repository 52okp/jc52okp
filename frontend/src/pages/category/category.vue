<template>
  <view class="wrap">
    <!-- 左侧分类栏 -->
    <scroll-view scroll-y class="side">
      <view
        v-for="c in categories" :key="c.id"
        class="side-item" :class="{ active: c.id === curId }"
        @click="selectCat(c)"
      >
        <view v-if="c.id === curId" class="side-bar"></view>
        <text>{{ c.name }}</text>
      </view>
      <view v-if="categories.length === 0" class="side-empty">暂无分类</view>
    </scroll-view>

    <!-- 右侧文章列表 -->
    <scroll-view scroll-y class="main">
      <view class="cat-head" v-if="curName">{{ curName }}</view>
      <view
        v-for="item in list" :key="item.id"
        class="card glass-solid" @click="goDetail(item.id)"
      >
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
      <view v-if="!loading && list.length === 0" class="empty">该分类暂无内容</view>
      <view v-if="loading" class="empty">加载中…</view>
    </scroll-view>
  </view>
</template>

<script>
import { getCategories, getArticleList } from '@/common/api.js';
export default {
  data() {
    return { categories: [], curId: 0, curName: '', list: [], loading: true };
  },
  onLoad() { this.init(); },
  methods: {
    async init() {
      try {
        this.categories = await getCategories() || [];
        if (this.categories.length) {
          this.selectCat(this.categories[0]);
        } else {
          this.loading = false;
        }
      } catch (e) { this.loading = false; }
    },
    async selectCat(c) {
      this.curId = c.id;
      this.curName = c.name;
      this.loading = true;
      this.list = [];
      try {
        const d = await getArticleList({ category_id: c.id, limit: 50 });
        this.list = d.list || [];
      } catch (e) {} finally { this.loading = false; }
    },
    goDetail(id) { uni.navigateTo({ url: '/pages/detail/detail?id=' + id }); }
  }
};
</script>

<style>
.wrap { display: flex; height: 100vh; }

/* 左侧分类栏 */
.side { width: 196rpx; height: 100vh; background: rgba(255,255,255,0.5); border-right: 1rpx solid rgba(255,255,255,0.7); }
.side-item { position: relative; padding: 38rpx 16rpx; text-align: center; font-size: 27rpx; color: #555f70; }
.side-item.active { background: rgba(255,255,255,0.92); color: #2f7bff; font-weight: 700; }
.side-bar { position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 8rpx; height: 38rpx; background: linear-gradient(#2f7bff,#5b9bff); border-radius: 0 4rpx 4rpx 0; }
.side-empty { padding: 60rpx 10rpx; text-align: center; color: #9aa3b2; font-size: 24rpx; }

/* 右侧列表 */
.main { flex: 1; height: 100vh; padding: 24rpx; box-sizing: border-box; }
.cat-head { font-size: 30rpx; font-weight: 800; color: #1f2a3a; margin: 4rpx 6rpx 20rpx; }
.card { display: flex; border-radius: 26rpx; padding: 20rpx; margin-bottom: 20rpx; }
.cover { width: 140rpx; height: 140rpx; border-radius: 20rpx; overflow: hidden; flex-shrink: 0; margin-right: 20rpx; }
.cover-img { width: 100%; height: 100%; }
.cover-default { background: linear-gradient(135deg, #2f7bff, #79b4ff); display: flex; align-items: center; justify-content: center; }
.cover-text { color: #fff; font-size: 40rpx; font-weight: 800; }
.body { flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: space-between; }
.title { font-size: 30rpx; font-weight: 700; color: #1f2a3a; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.summary { font-size: 25rpx; color: #6b6b7a; margin: 8rpx 0; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.meta { display: flex; align-items: center; }
.read { margin-left: auto; font-size: 23rpx; }
.muted { color: #8a93a6; }
.empty { text-align: center; color: #8a93a6; font-size: 26rpx; padding: 80rpx 0; }
</style>
