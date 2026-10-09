const { memberLogin, getToken } = require('./common/api');
App({
  onLaunch(options = {}) {
    const path = options.path || '';
    if (path === 'pages/account-confirm/index' || path === 'pages/account-policy/index') return;
    if (!getToken()) memberLogin();
  }
});
