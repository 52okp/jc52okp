App({
  globalData: {
    // 后端恢复后，把下面的 useRemote 改成 true，并填好 apiBase 即可切换到接口取数据
    useRemote: false,
    apiBase: 'https://your-domain.com/api'
  },
  onLaunch() {
    // 预留：启动时可在此做登录、拉取配置等
  }
});
