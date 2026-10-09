const { ACCOUNT_POLICY_PAGE, parseAccountTicket, getAccountRequest, submitAccountDecision } = require('../../common/account-login');

Page({
  data: {
    ticket: '', platform: '', intent: 'LOGIN', state: 'loading', accepted: false,
    busy: false, pendingDecision: '', remaining: 0, expiresAt: 0,
    error: '', retryable: false, title: '正在核验请求', description: '正在获取申请应用和请求有效期…'
  },
  onLoad(options) {
    this.disposed = false;
    this.requestVersion = 0;
    try { this.setData({ ticket: parseAccountTicket(options) }); }
    catch (error) { this.showState('error', error.message); return; }
    this.loadRequest();
  },
  onShow() { if (this.data.state === 'waiting') { this.tick(); this.startTimer(); } },
  onHide() { this.stopTimer(); },
  onUnload() { this.disposed = true; this.requestVersion++; this.stopTimer(); },
  showState(state, error = '') {
    const binding = this.data.intent === 'LINK';
    const title = {
      loading: '正在核验请求', success: binding ? '已确认绑定' : '已确认登录',
      cancelled: '已取消', done: '请求已处理', expired: '登录请求已过期', error: '请求暂不可用'
    }[state];
    const description = {
      loading: '正在获取申请应用和请求有效期…',
      success: '请返回原网页继续操作，最终结果以原网页显示为准。',
      cancelled: '本次请求已取消。', done: '本次请求已经处理，请返回原网页查看结果。',
      expired: '请在原网页刷新二维码后重新扫码。', error: error || '请返回原网页重新发起登录。'
    }[state];
    this.setData({ state, title, description, error });
  },
  async loadRequest() {
    if (this.data.busy || !this.data.ticket) return;
    const version = ++this.requestVersion;
    this.stopTimer();
    this.setData({ busy: true, accepted: false, retryable: false });
    this.showState('loading');
    try {
      const result = await getAccountRequest(this.data.ticket);
      if (this.disposed || version !== this.requestVersion) return;
      this.setData({ platform: result.platform, intent: result.intent, expiresAt: result.expiresAt });
      if (result.state === 'CANCELLED') this.showState('cancelled');
      else if (result.state === 'EXPIRED') this.showState('expired');
      else if (result.state !== 'WAITING') this.showState('done');
      else { this.showState('waiting'); this.tick(); this.startTimer(); }
    } catch (error) {
      if (this.disposed || version !== this.requestVersion) return;
      this.showState(error.status === 410 ? 'expired' : 'error', error.message || '请求失败，请稍后重试');
      this.setData({ retryable: ![400, 404, 409, 410].includes(error.status) });
    } finally {
      if (!this.disposed && version === this.requestVersion) this.setData({ busy: false });
    }
  },
  startTimer() { this.stopTimer(); if (this.data.state === 'waiting') this.timer = setInterval(() => this.tick(), 1000); },
  stopTimer() { if (this.timer) clearInterval(this.timer); this.timer = null; },
  tick() {
    const remaining = Math.max(0, Math.ceil((this.data.expiresAt - Date.now()) / 1000));
    this.setData({ remaining });
    if (!remaining && this.data.state === 'waiting') { this.showState('expired'); this.stopTimer(); }
  },
  agree(event) { if (!this.data.busy) this.setData({ accepted: (event.detail.value || []).includes('accepted') }); },
  showPolicy() { wx.navigateTo({ url: '/' + ACCOUNT_POLICY_PAGE }); },
  async submit(event) {
    const decision = event.currentTarget.dataset.decision;
    if (this.data.busy || this.data.state !== 'waiting') return;
    this.tick();
    if (this.data.state !== 'waiting') return;
    if (decision === 'confirm' && !this.data.accepted) {
      wx.showToast({ title: '请先阅读并同意账号说明', icon: 'none' }); return;
    }
    const version = this.requestVersion;
    this.setData({ busy: true, pendingDecision: decision, error: '' });
    try {
      await submitAccountDecision(this.data.ticket, decision, this.data.accepted);
      if (this.disposed || version !== this.requestVersion) return;
      this.showState(decision === 'confirm' ? 'success' : 'cancelled');
      this.stopTimer();
    } catch (error) {
      if (this.disposed || version !== this.requestVersion) return;
      if ([404, 410].includes(error.status)) { this.showState('expired'); this.stopTimer(); }
      else if (error.status === 409) { this.showState('done'); this.stopTimer(); }
      else this.setData({ error: (error.message || '验证失败，请稍后重试') + '。若已点击确认，请先返回原网页查看结果。' });
    } finally {
      if (!this.disposed && version === this.requestVersion) this.setData({ busy: false, pendingDecision: '' });
    }
  },
  goHome() { wx.switchTab({ url: '/pages/home/home' }); }
});
