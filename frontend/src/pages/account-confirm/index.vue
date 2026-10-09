<template>
  <view class="account-page">
    <view class="brand">52okp <text>统一账号</text></view>
    <view v-if="state === 'waiting'" class="account-card glass-solid">
      <view class="badge">{{ isBinding ? '微信绑定请求' : '应用登录请求' }}</view>
      <view class="title">{{ isBinding ? '确认绑定微信' : '确认登录' }}</view>
      <view class="description">{{ isBinding ? '正在为已验证的 52okp 账号绑定当前微信，请核对申请应用。' : '以下应用正在请求使用你的 52okp 账号登录，请确认是你本人操作。' }}</view>
      <view class="platform">{{ platform }}</view>
      <view class="expires">请求剩余有效时间 {{ remaining }} 秒</view>
      <checkbox-group @change="agree">
        <view class="agreement">
          <label class="agreement-check"><checkbox value="accepted" :checked="accepted" :disabled="busy" color="#2f7bff" /><text>我已阅读并同意</text></label>
          <text class="policy-link" @click="showPolicy">账号使用与隐私说明</text>
        </view>
      </checkbox-group>
      <view v-if="error" class="error" role="alert">{{ error }}</view>
      <button class="primary" :loading="busy && pendingDecision === 'confirm'" :disabled="busy" @click="submit('confirm')">{{ isBinding ? '确认绑定' : '确认登录' }}</button>
      <button class="secondary" :loading="busy && pendingDecision === 'cancel'" :disabled="busy" @click="submit('cancel')">取消</button>
      <view class="tip">如非本人发起，请取消本次请求。</view>
    </view>
    <view v-else class="account-card glass-solid result">
      <view class="symbol">{{ state === 'success' ? '✓' : state === 'cancelled' ? '×' : '◎' }}</view>
      <view class="title">{{ resultTitle }}</view>
      <view class="description">{{ resultDescription }}</view>
      <button v-if="state === 'error' && ticket && retryable" class="secondary" :disabled="busy" @click="loadRequest">重新查询</button>
      <button v-if="state !== 'loading'" class="primary" @click="goHome">返回教程首页</button>
    </view>
    <view class="footer">统一身份 · 各应用分别登录</view>
  </view>
</template>

<script>
import {
  ACCOUNT_POLICY_PAGE, parseAccountTicket, getAccountRequest, submitAccountDecision
} from '@/common/account-login.js';

export default {
  data() {
    return {
      ticket: '', platform: '', intent: 'LOGIN', state: 'loading',
      error: '', accepted: false, busy: false, pendingDecision: '',
      remaining: 0, expiresAt: 0, retryable: false
    };
  },
  computed: {
    isBinding() { return this.intent === 'LINK'; },
    resultTitle() {
      return {
        loading: '正在核验请求', success: this.isBinding ? '已确认绑定' : '已确认登录',
        cancelled: '已取消', done: '请求已处理', expired: '登录请求已过期', error: '请求暂不可用'
      }[this.state] || '请求不可用';
    },
    resultDescription() {
      if (this.state === 'success') return '请返回原网页继续操作，最终结果以原网页显示为准。';
      if (this.state === 'cancelled') return '本次请求已取消。';
      if (this.state === 'expired') return '请在原网页刷新二维码后重新扫码。';
      if (this.state === 'done') return '本次请求已经处理，请返回原网页查看结果。';
      if (this.state === 'loading') return '正在获取申请应用和请求有效期…';
      return this.error || '请返回原网页重新发起登录。';
    }
  },
  onLoad(options) {
    this._accountDisposed = false;
    this._accountRequestVersion = 0;
    try { this.ticket = parseAccountTicket(options); }
    catch (error) { this.state = 'error'; this.error = error.message; return; }
    // #ifndef MP-WEIXIN
    this.state = 'error';
    this.error = '请使用微信扫描原网页的小程序码，在微信小程序中完成确认。';
    return;
    // #endif
    // #ifdef MP-WEIXIN
    this.loadRequest();
    // #endif
  },
  onShow() {
    if (this.state === 'waiting') { this.tick(); this.startTimer(); }
  },
  onHide() { this.stopTimer(); },
  onUnload() { this._accountDisposed = true; this._accountRequestVersion += 1; this.stopTimer(); },
  methods: {
    async loadRequest() {
      if (this.busy || !this.ticket) return;
      const version = ++this._accountRequestVersion;
      this.stopTimer();
      this.state = 'loading';
      this.error = '';
      this.accepted = false;
      this.retryable = false;
      this.busy = true;
      try {
        const result = await getAccountRequest(this.ticket);
        if (this._accountDisposed || version !== this._accountRequestVersion) return;
        this.platform = result.platform;
        this.intent = result.intent;
        this.expiresAt = result.expiresAt;
        if (result.state === 'CANCELLED') this.state = 'cancelled';
        else if (result.state === 'EXPIRED') this.state = 'expired';
        else if (result.state !== 'WAITING') this.state = 'done';
        else { this.state = 'waiting'; this.tick(); this.startTimer(); }
      } catch (error) {
        if (this._accountDisposed || version !== this._accountRequestVersion) return;
        this.state = error.status === 410 ? 'expired' : 'error';
        this.error = error.message || '请求失败，请稍后重试';
        this.retryable = ![400, 404, 409, 410].includes(error.status);
      } finally {
        if (!this._accountDisposed && version === this._accountRequestVersion) this.busy = false;
      }
    },
    startTimer() {
      this.stopTimer();
      if (this.state === 'waiting') this._accountTimer = setInterval(() => this.tick(), 1000);
    },
    stopTimer() { clearInterval(this._accountTimer); this._accountTimer = null; },
    tick() {
      this.remaining = Math.max(0, Math.ceil((this.expiresAt - Date.now()) / 1000));
      if (!this.remaining && this.state === 'waiting') { this.state = 'expired'; this.stopTimer(); }
    },
    agree(event) { if (!this.busy) this.accepted = (event.detail.value || []).includes('accepted'); },
    showPolicy() { uni.navigateTo({ url: '/' + ACCOUNT_POLICY_PAGE }); },
    async submit(decision) {
      if (this.busy || this.state !== 'waiting') return;
      this.tick();
      if (this.state !== 'waiting') return;
      if (decision === 'confirm' && !this.accepted) {
        uni.showToast({ title: '请先阅读并同意账号说明', icon: 'none' }); return;
      }
      const version = this._accountRequestVersion;
      this.busy = true;
      this.pendingDecision = decision;
      this.error = '';
      try {
        await submitAccountDecision(this.ticket, decision, this.accepted);
        if (this._accountDisposed || version !== this._accountRequestVersion) return;
        this.state = decision === 'confirm' ? 'success' : 'cancelled';
        this.stopTimer();
      } catch (error) {
        if (this._accountDisposed || version !== this._accountRequestVersion) return;
        if ([404, 410].includes(error.status)) { this.state = 'expired'; this.stopTimer(); }
        else if (error.status === 409) { this.state = 'done'; this.stopTimer(); }
        else this.error = (error.message || '验证失败，请稍后重试') + '。若已点击确认，请先返回原网页查看结果。';
      } finally {
        if (!this._accountDisposed && version === this._accountRequestVersion) {
          this.busy = false; this.pendingDecision = '';
        }
      }
    },
    goHome() { uni.switchTab({ url: '/pages/home/home' }); }
  }
};
</script>

<style scoped>
.account-page { min-height: 100vh; box-sizing: border-box; padding: 64rpx 36rpx 48rpx; font-family: "PingFang SC", "Microsoft YaHei", sans-serif; }
.brand { font-size: 44rpx; font-weight: 800; color: #1f2a3a; margin-bottom: 44rpx; }
.brand text { font-size: 24rpx; font-weight: 400; color: #7b8ca6; margin-left: 18rpx; }
.account-card { border-radius: 30rpx; padding: 44rpx 34rpx; }
.badge { font-size: 24rpx; font-weight: 600; color: #2f7bff; }
.title { font-size: 40rpx; line-height: 1.4; font-weight: 800; color: #1f2a3a; margin: 22rpx 0; }
.description { font-size: 28rpx; color: #687b95; line-height: 1.8; }
.platform { padding: 30rpx 24rpx; background: rgba(47,123,255,.09); color: #2468d8; border-radius: 18rpx; margin: 32rpx 0 18rpx; font-size: 34rpx; font-weight: 700; word-break: break-all; }
.expires { color: #7b8ca6; font-size: 24rpx; margin-bottom: 30rpx; }
.agreement { display: flex; align-items: center; flex-wrap: wrap; font-size: 25rpx; line-height: 1.9; color: #687b95; }
.agreement-check { display: flex; align-items: center; }
.agreement checkbox { transform: scale(.8); margin-left: -10rpx; }
.policy-link { color: #2468d8; padding: 8rpx 0; }
.primary, .secondary { font-size: 30rpx; border-radius: 18rpx; margin-top: 26rpx; }
.primary { background: linear-gradient(135deg,#2f7bff,#5b9bff); color: white; }
.secondary { background: #edf3ff; color: #557293; }
.primary::after, .secondary::after { border: none; }
.primary[disabled], .secondary[disabled] { opacity: .55; }
.tip, .footer { color: #7b8ca6; font-size: 24rpx; text-align: center; line-height: 1.8; margin-top: 30rpx; }
.error { color: #b44936; font-size: 26rpx; line-height: 1.7; margin-top: 20rpx; word-break: break-all; }
.result { text-align: center; padding-top: 62rpx; padding-bottom: 54rpx; }
.symbol { color: #2f7bff; font-size: 64rpx; }
.footer { margin-top: 44rpx; }
</style>