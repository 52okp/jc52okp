<template>
  <view class="page" v-if="article">
    <view class="content-wrap">
      <view class="head glass-solid">
        <view class="title">{{ article.title }}</view>
        <view class="meta">
          <text v-for="t in article.tagList" :key="t" class="g-tag">{{ t }}</text>
          <text class="muted">阅读 {{ article.num_read }} · {{ article.create_at }}</text>
        </view>
      </view>

      <view class="content glass-solid">
        <rich-text :nodes="article.content || ''" selectable user-select></rich-text>
      </view>

      <view class="downloads glass-solid" v-if="article.links && article.links.length">
        <view class="dl-title">📁 配套资料</view>
        <view class="dl-sub muted">由作者提供，自行前往网盘获取</view>
        <view v-for="(link, idx) in article.links" :key="idx" class="link-row">
          <view class="link-left">
            <text class="link-type">{{ link.type === '解压密码' ? '🔑 解压密码' : '资料链接' + (article.links.length > 1 ? ' ' + (idx + 1) : '') }}</text>
            <text v-if="link.code" class="link-code">访问码 {{ link.code }}</text>
          </view>
          <view class="copy-btn" @click="copyLink(link)">复制</view>
        </view>
        <view class="dl-tip muted">资料均来自网络或原作者，仅供学习交流；如有侵权请通过「关于我们」联系删除。</view>
      </view>
    </view>

    <view class="action-bar glass">
      <view class="fav-btn" :class="{ active: article.favorited }" @click="onFavorite">
        <text class="fav-icon">{{ article.favorited ? '★' : '☆' }}</text>
        <text>{{ article.favorited ? '已收藏' : '收藏' }}</text>
      </view>
    </view>
  </view>

  <view v-else class="empty">{{ loading ? '加载中…' : '内容不存在' }}</view>
</template>

<script>
import { getArticleDetail, toggleFavorite, memberLogin, shareImageUrl } from '@/common/api.js';
export default {
  data() { return { article: null, loading: true, id: 0 }; },
  onLoad(options) {
    this.id = options.id;
    if (!this.id) { this.loading = false; return; }
    this.load();
  },
  // 转发给朋友：用文章标题+封面，路径带 id 直达本篇
  onShareAppMessage() {
    return {
      title: (this.article && this.article.title) || '52okpAI教程站 · Stable Diffusion 教程',
      path: '/pages/detail/detail?id=' + this.id,
      imageUrl: shareImageUrl(this.article && this.article.cover)
    };
  },
  // 分享到朋友圈
  onShareTimeline() {
    return {
      title: (this.article && this.article.title) || '52okpAI教程站 · Stable Diffusion 教程',
      query: 'id=' + this.id,
      imageUrl: shareImageUrl(this.article && this.article.cover)
    };
  },
  methods: {
    async load() {
      this.loading = true;
      try {
        this.article = await getArticleDetail(this.id);
        if (this.article && this.article.title) uni.setNavigationBarTitle({ title: this.article.title });
      } catch (e) {} finally { this.loading = false; }
    },
    copyLink(link) {
      const isUnzip = link.type === '解压密码';
      const text = link.code ? `${link.url} 提取码: ${link.code}` : link.url;
      uni.setClipboardData({
        data: text,
        success: () => uni.showModal({
          title: '已复制',
          content: isUnzip ? '解压密码已复制，解压文件时粘贴使用即可。' : '资料地址已复制，可在浏览器或对应网页端粘贴打开。',
          showCancel: false, confirmText: '知道了'
        })
      });
    },
    async onFavorite() {
      try {
        const r = await toggleFavorite(this.id);
        this.article.favorited = r.favorited;
        uni.showToast({ title: r.favorited ? '已收藏' : '已取消', icon: 'none' });
      } catch (e) {
        if (e && e.code === 401) {
          await memberLogin();
          try { const r2 = await toggleFavorite(this.id); this.article.favorited = r2.favorited; uni.showToast({ title: r2.favorited ? '已收藏' : '已取消', icon: 'none' }); }
          catch (e2) { uni.showToast({ title: '请稍后重试', icon: 'none' }); }
        }
      }
    }
  }
};
</script>

<style>
.page { padding-bottom: 130rpx; }
.content-wrap { padding: 28rpx; }
.head { border-radius: 28rpx; padding: 30rpx 28rpx; }
.title { font-size: 42rpx; font-weight: 800; line-height: 1.4; color: #2b2b3a; }
.meta { display: flex; align-items: center; flex-wrap: wrap; margin-top: 16rpx; }
.muted { color: #8a8a9a; font-size: 24rpx; }
.content { border-radius: 28rpx; padding: 32rpx 28rpx; margin-top: 24rpx; font-size: 30rpx; line-height: 1.85; color: #3a3a48; }
.downloads { border-radius: 28rpx; padding: 28rpx; margin-top: 24rpx; }
.dl-title { font-size: 32rpx; font-weight: 800; margin-bottom: 4rpx; }
.dl-sub { font-size: 23rpx; margin-bottom: 12rpx; }
.link-row { display: flex; align-items: center; justify-content: space-between; padding: 22rpx 0; border-bottom: 1rpx solid rgba(160,150,210,0.18); }
.link-row:last-of-type { border-bottom: none; }
.link-left { display: flex; flex-direction: column; }
.link-type { font-size: 29rpx; font-weight: 600; color: #2b2b3a; }
.link-code { font-size: 24rpx; color: #2f7bff; margin-top: 6rpx; }
.copy-btn { background: linear-gradient(135deg,#2f7bff,#5b9bff); color: #fff; font-size: 26rpx; padding: 14rpx 32rpx; border-radius: 14rpx; }
.dl-tip { margin-top: 16rpx; }

.action-bar { position: fixed; left: 0; right: 0; bottom: 0; height: 110rpx; display: flex; align-items: center; padding: 0 28rpx; border-top: 1rpx solid rgba(255,255,255,0.5); }
.fav-btn { margin-left: auto; display: flex; align-items: center; gap: 8rpx; border: 1rpx solid #2f7bff; color: #2f7bff; border-radius: 999rpx; padding: 14rpx 44rpx; font-size: 28rpx; background: rgba(255,255,255,0.5); }
.fav-btn.active { background: linear-gradient(135deg,#2f7bff,#5b9bff); color: #fff; border-color: transparent; }
.fav-icon { font-size: 32rpx; }
.empty { text-align: center; color: #8a8a9a; font-size: 26rpx; padding: 200rpx 0; }
</style>
