const { getMemberInfo, updateProfile, uploadAvatar } = require('../../common/api');
const { topInset } = require('../../common/ui');
const profileInset = topInset() + 34;
const collapseDistance = 90;

function profileLayout(progress) {
  const height = Math.round(224 - 96 * progress);
  const avatarSize = Math.round(132 - 56 * progress);
  return {
    profileStyle: `top:${profileInset}px;height:${height}rpx;border-radius:${Math.round(26 - 6 * progress)}rpx;`,
    avatarStyle: `width:${avatarSize}rpx;height:${avatarSize}rpx;flex-basis:${avatarSize}rpx;margin-right:${Math.round(24 - 6 * progress)}rpx;`,
    profileNameStyle: `font-size:${Math.round(35 - 5 * progress)}rpx;`
  };
}

Page({
  data: { member: {}, avatar: '/static/favicon.png', nicknameInput: '', showNick: false, saving: false, topInset: profileInset, profileProgress: 0, profileSettling: false, ...profileLayout(0) },
  onShow() {
    const tabBar = this.getTabBar && this.getTabBar();
    if (tabBar && tabBar.data.selected !== 2) tabBar.setData({ selected: 2 });
    if (!this.lastMemberLoadedAt || Date.now() - this.lastMemberLoadedAt > 60000) this.load();
  },
  async load() {
    if (this.memberLoading) return;
    this.memberLoading = true;
    try {
      const member = await getMemberInfo() || {};
      this.setData({ member, avatar: member.avatar || '/static/favicon.png' });
      this.lastMemberLoadedAt = Date.now();
    } catch (_) {}
    finally { this.memberLoading = false; }
  },
  profileTouchStart(event) {
    const touch = event.touches && event.touches[0];
    if (!touch || this.data.showNick) return;
    this.profileTouch = { x: touch.pageX, y: touch.pageY, progress: this.data.profileProgress, moved: false };
    if (this.data.profileSettling) this.setData({ profileSettling: false });
  },
  profileTouchMove(event) {
    const touch = event.touches && event.touches[0];
    const gesture = this.profileTouch;
    if (!touch || !gesture || this.data.showNick) return;
    const dx = touch.pageX - gesture.x;
    const dy = touch.pageY - gesture.y;
    if (Math.abs(dy) < 8 || Math.abs(dy) < Math.abs(dx) * 1.2) return;
    gesture.moved = true;
    const progress = Math.max(0, Math.min(1, gesture.progress - dy / collapseDistance));
    if (Math.abs(progress - this.data.profileProgress) < 0.03) return;
    this.setData({ profileProgress: progress, ...profileLayout(progress) });
  },
  profileTouchEnd() {
    const gesture = this.profileTouch;
    this.profileTouch = null;
    if (!gesture || !gesture.moved) return;
    const progress = this.data.profileProgress >= 0.5 ? 1 : 0;
    this.setData({ profileProgress: progress, profileSettling: true, ...profileLayout(progress) });
  },
  chooseAvatar(event) {
    const filePath = event.detail.avatarUrl;
    if (!filePath) return;
    this.setData({ avatar: filePath });
    wx.showLoading({ title: '上传中', mask: true });
    uploadAvatar(filePath).then(data => { this.setData({ avatar: data.avatar, 'member.avatar': data.avatar }); wx.showToast({ title: '头像已更新' }); })
      .catch(error => wx.showToast({ title: error.msg || '上传失败', icon: 'none' }))
      .finally(() => wx.hideLoading());
  },
  openNick() { this.setData({ showNick: true, nicknameInput: this.data.member.nickname || '' }); },
  closeNick() { this.setData({ showNick: false }); },
  nickInput(event) { this.setData({ nicknameInput: event.detail.value }); },
  async saveNick() {
    if (this.data.saving) return;
    const nickname = this.data.nicknameInput.trim();
    if (!nickname) return wx.showToast({ title: '昵称不能为空', icon: 'none' });
    this.setData({ saving: true });
    try { const member = await updateProfile({ nickname }); this.setData({ member, showNick: false }); wx.showToast({ title: '已保存' }); }
    catch (_) { wx.showToast({ title: '保存失败', icon: 'none' }); }
    finally { this.setData({ saving: false }); }
  },
  go(event) { wx.navigateTo({ url: event.currentTarget.dataset.url }); }
});
