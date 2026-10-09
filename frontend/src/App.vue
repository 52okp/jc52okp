<script>
import { memberLogin, getToken } from '@/common/api.js';
import { isAccountEntry } from '@/common/account-login.js';

export default {
  onLaunch: function (options = {}) {
    // 扫码确认使用中枢身份，不在此时触发教程会员静默登录。
    if (isAccountEntry(options.path)) return;
    if (!getToken()) memberLogin();
  },
  onShow: function () {},
  onHide: function () {}
};
</script>

<style>
/* ===== 全局：蓝色渐变背景 + 玻璃卡片（不依赖 backdrop-filter，所有机型一致） ===== */
page {
  background: linear-gradient(165deg, #cfe2ff 0%, #e3eeff 32%, #eaf3ff 58%, #f1f6ff 100%);
  background-attachment: fixed;
  min-height: 100vh;
  color: #1f2a3a;
  font-size: 28rpx;
}

/* 玻璃卡片：半透明白 + 描边 + 阴影（够实，磨砂感稳定） */
.glass {
  background: rgba(255, 255, 255, 0.62);
  backdrop-filter: blur(18px);
  -webkit-backdrop-filter: blur(18px);
  border: 1rpx solid rgba(255, 255, 255, 0.9);
  box-shadow: 0 10rpx 30rpx rgba(47, 123, 255, 0.12);
}
.glass-solid {
  background: rgba(255, 255, 255, 0.82);
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  border: 1rpx solid rgba(255, 255, 255, 0.95);
  box-shadow: 0 8rpx 24rpx rgba(47, 123, 255, 0.1);
}

.muted { color: #8a93a6; }

.g-tag {
  font-size: 22rpx; padding: 4rpx 16rpx; border-radius: 999rpx;
  background: rgba(47, 123, 255, 0.12); color: #2f7bff; margin-right: 12rpx;
}
</style>
