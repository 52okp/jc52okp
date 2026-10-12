const { getMemberInfo, updateProfile, uploadAvatar } = require('../../common/api');
const { topInset } = require('../../common/ui');
Page({
  data: { member: {}, avatar: '/static/favicon.png', nicknameInput: '', showNick: false, saving: false, topInset: topInset() + 34 },
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
