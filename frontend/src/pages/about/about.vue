<template>
  <view class="page">
    <!-- 顶部 LOGO -->
    <view class="brand">
      <image class="logo" src="/static/favicon.png" mode="aspectFill" />
      <view class="app-name">52okpAI教程站</view>
    </view>

    <!-- 功能项 -->
    <view class="menu glass">
      <view class="menu-item" @click="showPrivacy = true">
        <text class="mi-icon">📄</text><text class="mi-text">隐私政策</text><text class="mi-arrow">›</text>
      </view>
      <!-- 官方客服会话 -->
      <button class="menu-item btn-reset" open-type="contact">
        <text class="mi-icon">💬</text><text class="mi-text">联系客服</text><text class="mi-arrow">›</text>
      </button>
      <view class="menu-item no-border" @click="showFollow = true">
        <text class="mi-icon">❤️</text><text class="mi-text">关注我们</text><text class="mi-arrow">›</text>
      </view>
    </view>

    <view class="ver">版本 v2.0.0</view>

    <!-- 隐私政策弹窗 -->
    <view v-if="showPrivacy" class="mask" @click="showPrivacy = false">
      <view class="sheet" @click.stop>
        <view class="sheet-title">隐私政策</view>
        <scroll-view scroll-y class="sheet-body">
          <text class="privacy-text">{{ setting.privacy_content || '暂无内容' }}</text>
        </scroll-view>
        <view class="sheet-close" @click="showPrivacy = false">关闭</view>
      </view>
    </view>

    <!-- 关注我们弹窗 -->
    <view v-if="showFollow" class="mask" @click="showFollow = false">
      <view class="follow-box" @click.stop>
        <view class="sheet-title">关注我们</view>
        <image v-if="setting.follow_image" :src="setting.follow_image" mode="widthFix" class="follow-img" @click="previewImg" />
        <view v-else class="follow-empty">暂未设置关注图</view>
        <text class="follow-text">{{ setting.follow_text }}</text>
        <view class="sheet-close" @click="showFollow = false">关闭</view>
      </view>
    </view>
  </view>
</template>

<script>
import { getSetting } from '@/common/api.js';
export default {
  data() {
    return { setting: { follow_image: '', follow_text: '', privacy_content: '' }, showPrivacy: false, showFollow: false };
  },
  onLoad() { this.load(); },
  methods: {
    async load() {
      try { this.setting = await getSetting() || this.setting; } catch (e) {}
    },
    previewImg() {
      if (this.setting.follow_image) uni.previewImage({ urls: [this.setting.follow_image] });
    }
  }
};
</script>

<style>
.page { padding: 40rpx 28rpx; }
.brand { display: flex; flex-direction: column; align-items: center; padding: 30rpx 0 40rpx; }
.logo { width: 140rpx; height: 140rpx; border-radius: 32rpx; box-shadow: 0 10rpx 30rpx rgba(47,123,255,0.25); }
.app-name { font-size: 34rpx; font-weight: 800; color: #1f2a3a; margin-top: 18rpx; }

.menu { border-radius: 28rpx; overflow: hidden; }
.menu-item { display: flex; align-items: center; width: 100%; padding: 34rpx 30rpx; border-bottom: 1rpx solid rgba(255,255,255,0.5); font-size: 30rpx; color: #1f2a3a; box-sizing: border-box; }
.menu-item.no-border, .menu-item:last-child { border-bottom: none; }
.mi-icon { width: 52rpx; }
.mi-text { flex: 1; text-align: left; }
.mi-arrow { color: #b0b8c8; font-size: 28rpx; }
/* 重置 button 默认样式，使其和普通行一致 */
.btn-reset { background: transparent; line-height: 1.4; border-radius: 0; text-align: left; }
.btn-reset::after { border: none; }

.ver { text-align: center; color: #9aa3b2; font-size: 22rpx; margin-top: 36rpx; }

/* 弹窗 */
.mask { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 99; }
.sheet { width: 620rpx; max-height: 76vh; background: #fff; border-radius: 28rpx; padding: 36rpx 30rpx 28rpx; display: flex; flex-direction: column; }
.sheet-title { font-size: 32rpx; font-weight: 800; text-align: center; color: #1f2a3a; }
.sheet-body { margin: 24rpx 0; max-height: 56vh; }
.privacy-text { font-size: 27rpx; color: #45506a; line-height: 1.8; white-space: pre-wrap; }
.sheet-close { height: 84rpx; line-height: 84rpx; text-align: center; border-radius: 16rpx; background: linear-gradient(135deg,#2f7bff,#5b9bff); color: #fff; font-size: 30rpx; margin-top: 12rpx; }

.follow-box { width: 600rpx; background: #fff; border-radius: 28rpx; padding: 36rpx 30rpx 28rpx; display: flex; flex-direction: column; align-items: center; }
.follow-img { width: 380rpx; border-radius: 16rpx; margin: 26rpx 0 16rpx; }
.follow-empty { color: #9aa3b2; font-size: 26rpx; padding: 50rpx 0; }
.follow-text { font-size: 27rpx; color: #45506a; line-height: 1.7; text-align: center; margin-bottom: 26rpx; white-space: pre-wrap; }
</style>
