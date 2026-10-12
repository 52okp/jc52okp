const { getMemberInfo, updateProfile, uploadAvatar } = require('../../common/api');
const { topInset } = require('../../common/ui');
const profileInset = topInset() + 34;
const collapseDistance = 140;

function profileLayout(progress) {
  const clamp = value => Math.max(0, Math.min(1, value));
  const smoothstep = value => { const t = clamp(value); return t * t * (3 - 2 * t); };
  const height = Math.round(260 - 182 * progress);
  const avatarSize = Math.round(144 - 116 * progress);
  const avatarTop = Math.round(2 - 64 * progress);
  const black = smoothstep((progress - 0.38) / 0.5);
  const exit = 1 - smoothstep((progress - 0.86) / 0.14);
  const tailHeight = Math.round(18 * black);
  const tailHalfWidth = Math.round(6 * black);
  const nameOffset = Math.round(50 * (1 - progress));
  return {
    profileStyle: `top:${profileInset}px;height:${height}rpx;`,
    avatarStyle: `top:${avatarTop}rpx;width:${avatarSize}rpx;height:${avatarSize}rpx;margin-left:${-Math.round(avatarSize / 2)}rpx;background:rgba(0,0,0,${black.toFixed(2)});opacity:${exit.toFixed(2)};`,
    avatarImageStyle: `opacity:${(1 - black).toFixed(2)};`,
    gooTailStyle: `top:${avatarTop - tailHeight + 4}rpx;border-left:${tailHalfWidth}rpx solid transparent;border-right:${tailHalfWidth}rpx solid transparent;border-bottom:${tailHeight}rpx solid #000;opacity:${(black * exit).toFixed(2)};`,
    profileNameStyle: `top:${Math.round(158 - 139 * progress)}rpx;left:${(50 - 48 * progress).toFixed(1)}%;transform:translateX(${nameOffset ? '-' : ''}${nameOffset}%);font-size:${Math.round(35 - 5 * progress)}rpx;`,
    profileIdStyle: `top:${Math.round(205 - 105 * progress)}rpx;opacity:${(1 - smoothstep(progress / 0.55)).toFixed(2)};`
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
    if (Math.abs(progress - this.data.profileProgress) < 0.01) return;
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
