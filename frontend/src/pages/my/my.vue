<template>
  <view class="page">
    <view class="user-card">
      <button class="avatar-btn" open-type="chooseAvatar" @chooseavatar="onChooseAvatar">
        <image class="avatar" :src="member.avatar || defaultAvatar" mode="aspectFill" />
        <view class="avatar-edit">换</view>
      </button>
      <view class="user-info" @click="openNick">
        <view class="nick">{{ member.nickname || '微信用户' }}</view>
        <view class="hint">{{ member.nickname ? 'ID: ' + (member.id || '-') : '点击设置昵称' }}</view>
      </view>
    </view>

    <view class="menu glass">
      <view class="menu-item" @click="go('/pages/favorites/favorites')">
        <text class="mi-icon">⭐</text><text class="mi-text">我的收藏</text><text class="mi-arrow">›</text>
      </view>
      <view class="menu-item" @click="go('/pages/history/history')">
        <text class="mi-icon">🕘</text><text class="mi-text">浏览历史</text><text class="mi-arrow">›</text>
      </view>
    </view>

    <view class="menu glass">
      <view class="menu-item" @click="go('/pages/about/about')">
        <text class="mi-icon">ℹ️</text><text class="mi-text">关于我们</text><text class="mi-arrow">›</text>
      </view>
    </view>

    <!-- 昵称弹窗 -->
    <view v-if="showNick" class="mask" @click="showNick = false">
      <view class="nick-box" @click.stop>
        <view class="nick-title">设置昵称</view>
        <input class="nick-input" type="nickname" v-model="nickInput" placeholder="点击使用微信昵称或手动输入" />
        <view class="nick-btns">
          <view class="nb cancel" @click="showNick = false">取消</view>
          <view class="nb ok" @click="saveNick">保存</view>
        </view>
      </view>
    </view>
  </view>
</template>

<script>
import { memberLogin, getMemberInfo, getToken, updateProfile, uploadAvatar } from '@/common/api.js';
export default {
  data() {
    return { member: {}, defaultAvatar: '/static/favicon.png', showNick: false, nickInput: '' };
  },
  onShow() { this.ensureLogin(); },
  methods: {
    async ensureLogin() {
      if (!getToken()) await memberLogin();
      try { this.member = await getMemberInfo() || {}; } catch (e) {}
    },
    // 新版微信：选择头像
    onChooseAvatar(e) {
      const tmp = e.detail && e.detail.avatarUrl;
      if (!tmp) return;
      this.member = { ...this.member, avatar: tmp }; // 先本地预览
      uni.showLoading({ title: '上传中', mask: true });
      uploadAvatar(tmp).then((d) => {
        uni.hideLoading();
        this.member = { ...this.member, avatar: d.avatar };
        uni.showToast({ title: '头像已更新', icon: 'success' });
      }).catch(async (err) => {
        uni.hideLoading();
        if (err && err.code === 401) { await memberLogin(); }
        uni.showToast({ title: (err && err.msg) || '上传失败', icon: 'none' });
      });
    },
    openNick() { this.nickInput = this.member.nickname || ''; this.showNick = true; },
    async saveNick() {
      const name = (this.nickInput || '').trim();
      if (!name) { uni.showToast({ title: '昵称不能为空', icon: 'none' }); return; }
      try {
        this.member = await updateProfile({ nickname: name });
        this.showNick = false;
        uni.showToast({ title: '已保存', icon: 'success' });
      } catch (e) {
        if (e && e.code === 401) { await memberLogin(); }
        uni.showToast({ title: '保存失败', icon: 'none' });
      }
    },
    go(url) { uni.navigateTo({ url }); }
  }
};
</script>

<style>
.page { padding: 28rpx; }
.user-card {
  display: flex; align-items: center; border-radius: 32rpx; padding: 44rpx 36rpx; color: #fff;
  background: linear-gradient(135deg, #2f7bff 0%, #4f93ff 55%, #79b4ff 100%);
  box-shadow: 0 14rpx 36rpx rgba(47, 123, 255, 0.32);
}
.avatar-btn {
  position: relative; padding: 0; margin: 0 26rpx 0 0; width: 124rpx; height: 124rpx;
  min-height: 0; background: transparent; line-height: 0; font-size: 0; border-radius: 50%;
  display: flex; align-items: center; justify-content: center; box-sizing: border-box; overflow: visible;
}
.avatar-btn::after { border: none; }
.avatar { width: 124rpx; height: 124rpx; border-radius: 50%; background: #fff; border: 4rpx solid rgba(255,255,255,0.6); display: block; box-sizing: border-box; }
.avatar-edit { position: absolute; right: -4rpx; bottom: -4rpx; width: 44rpx; height: 44rpx; border-radius: 50%; background: rgba(0,0,0,0.45); color: #fff; font-size: 22rpx; line-height: 44rpx; text-align: center; }
.user-info { flex: 1; }
.nick { font-size: 38rpx; font-weight: 800; }
.hint { font-size: 24rpx; opacity: .9; margin-top: 10rpx; }

.menu { border-radius: 28rpx; margin-top: 26rpx; overflow: hidden; }
.menu-item { display: flex; align-items: center; padding: 32rpx 30rpx; border-bottom: 1rpx solid rgba(255,255,255,0.45); font-size: 30rpx; color: #1f2a3a; }
.menu-item:last-child { border-bottom: none; }
.mi-icon { width: 50rpx; }
.mi-text { flex: 1; }
.mi-arrow { color: #b0b8c8; font-size: 28rpx; }
.muted { color: #8a93a6; }

/* 昵称弹窗 */
.mask { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 99; }
.nick-box { width: 560rpx; background: #fff; border-radius: 28rpx; padding: 40rpx 32rpx; }
.nick-title { font-size: 32rpx; font-weight: 700; text-align: center; margin-bottom: 24rpx; color: #1f2a3a; }
.nick-input { height: 80rpx; border: 1rpx solid #e2e6ee; border-radius: 14rpx; padding: 0 22rpx; font-size: 28rpx; }
.nick-btns { display: flex; gap: 20rpx; margin-top: 28rpx; }
.nb { flex: 1; height: 80rpx; line-height: 80rpx; text-align: center; border-radius: 14rpx; font-size: 30rpx; }
.nb.cancel { background: #f1f3f8; color: #555; }
.nb.ok { background: linear-gradient(135deg,#2f7bff,#5b9bff); color: #fff; }
</style>
