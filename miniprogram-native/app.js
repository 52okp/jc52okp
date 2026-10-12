const { memberLogin, getToken, getHome } = require('./common/api');
App({
  onLaunch(options = {}) {
    const path = options.path || '';
    if (path === 'pages/account-confirm/index' || path === 'pages/account-policy/index') return;
    if (!path || path === 'pages/home/home') {
      this.homePrefetch = getHome();
      this.homePrefetch.catch(() => {});
    }
    if (!getToken()) memberLogin();
  }
});
