// ============================================================
//  接口配置 & 请求封装
//  本地联调时把 BASE_URL 改成 http://127.0.0.1:8800
// ============================================================

const BASE_URL = 'https://ai.600868.xyz';

const TOKEN_KEY = 'member_token';
export function getToken() {
  try { return uni.getStorageSync(TOKEN_KEY) || ''; } catch (e) { return ''; }
}
export function setToken(t) {
  try { uni.setStorageSync(TOKEN_KEY, t || ''); } catch (e) {}
}

function request(path, options = {}) {
  return new Promise((resolve, reject) => {
    uni.request({
      url: BASE_URL + path,
      method: options.method || 'GET',
      data: options.data || {},
      header: {
        'content-type': 'application/json',
        'Member-Token': getToken(),
        ...(options.header || {})
      },
      success(res) {
        const body = res.data || {};
        if (body.code === 1) {
          resolve(body.data);
        } else if (body.code === 401) {
          // 未登录：交给调用方处理，不弹提示
          reject({ code: 401, msg: body.msg || '请先登录' });
        } else {
          if (!options.silent) uni.showToast({ title: body.msg || '请求失败', icon: 'none' });
          reject({ code: 0, msg: body.msg || '请求失败' });
        }
      },
      fail(err) {
        if (!options.silent) uni.showToast({ title: '网络异常，请稍后重试', icon: 'none' });
        reject(err);
      }
    });
  });
}

// 处理分享卡片封面图：① 协议相对 // 补成 https:// ② 微信分享只支持 PNG/JPG，webp 会白底，回退本地图
export function shareImageUrl(url) {
  if (!url) return '/static/favicon.png';
  if (url.indexOf('//') === 0) url = 'https:' + url;
  if (/\.webp(\?|$)/i.test(url)) return '/static/favicon.png';
  return url;
}

function qs(params = {}) {
  const s = Object.keys(params)
    .filter((k) => params[k] !== '' && params[k] != null)
    .map((k) => `${k}=${encodeURIComponent(params[k])}`)
    .join('&');
  return s ? '?' + s : '';
}

// ---------- 公共 ----------
export function getHome() { return request('/api/home/index'); }
export function getCategories() { return request('/api/category/list'); }
export function getArticleList(params = {}) { return request('/api/article/list' + qs(params)); }
export function getArticleDetail(id) { return request('/api/article/detail?id=' + id, { silent: true }); }
export function getSetting() { return request('/api/setting/index', { silent: true }); }

// ---------- 会员 ----------
// 静默登录：wx.login 拿 code -> 后端换 openid -> 建会员 -> 存 token
export function memberLogin() {
  return new Promise((resolve) => {
    uni.login({
      success: (r) => {
        if (!r.code) return resolve(null);
        request('/api/member/login', { method: 'POST', data: { code: r.code }, silent: true })
          .then((d) => { if (d && d.token) setToken(d.token); resolve(d); })
          .catch(() => resolve(null));
      },
      fail: () => resolve(null)
    });
  });
}
export function getMemberInfo() { return request('/api/member/info', { silent: true }); }
// 上传头像（chooseAvatar 得到的临时文件路径 -> 上传到服务器存储）
export function uploadAvatar(filePath) {
  return new Promise((resolve, reject) => {
    uni.uploadFile({
      url: BASE_URL + '/api/member/upload',
      filePath, name: 'file',
      header: { 'Member-Token': getToken() },
      success: (res) => {
        try {
          const b = JSON.parse(res.data);
          if (b.code === 1) resolve(b.data); else reject(b);
        } catch (e) { reject(e); }
      },
      fail: reject
    });
  });
}
export function updateProfile(data) { return request('/api/member/profile', { method: 'POST', data }); }
export function toggleFavorite(article_id) { return request('/api/member/favorite', { method: 'POST', data: { article_id } }); }
export function getFavorites() { return request('/api/member/favorites'); }
export function getHistory() { return request('/api/member/history'); }

export default { BASE_URL, getToken, setToken };
