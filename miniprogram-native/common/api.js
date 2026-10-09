const BASE_URL = 'https://jc.52okp.com';
const TOKEN_KEY = 'member_token';

function getToken() { try { return wx.getStorageSync(TOKEN_KEY) || ''; } catch (_) { return ''; } }
function setToken(token) { try { wx.setStorageSync(TOKEN_KEY, token || ''); } catch (_) {} }
function toast(message) { wx.showToast({ title: message, icon: 'none' }); }

function request(path, options = {}) {
  return new Promise((resolve, reject) => {
    wx.request({
      url: BASE_URL + path, method: options.method || 'GET', data: options.data || {}, timeout: 15000,
      header: { 'content-type': 'application/json', 'Member-Token': getToken() },
      success(res) {
        const body = res.data || {};
        if (body.code === 1) return resolve(body.data);
        const error = { code: body.code === 401 ? 401 : 0, msg: body.msg || '请求失败' };
        if (error.code !== 401 && !options.silent) toast(error.msg);
        reject(error);
      },
      fail(error) { if (!options.silent) toast('网络异常，请稍后重试'); reject(error); }
    });
  });
}

let loginTask = null;
function memberLogin() {
  if (loginTask) return loginTask;
  loginTask = new Promise(resolve => {
    wx.login({
      success(result) {
        if (!result.code) return resolve(null);
        request('/api/member/login', { method: 'POST', data: { code: result.code }, silent: true })
          .then(data => { if (data && data.token) setToken(data.token); resolve(data); })
          .catch(() => resolve(null));
      }, fail() { resolve(null); }
    });
  }).finally(() => { loginTask = null; });
  return loginTask;
}

async function memberRequest(path, options = {}) {
  try { return await request(path, options); }
  catch (error) {
    if (error.code !== 401) throw error;
    setToken('');
    if (!await memberLogin()) throw error;
    return request(path, options);
  }
}

function query(params = {}) {
  const entries = Object.keys(params).filter(key => params[key] !== '' && params[key] != null);
  return entries.length ? '?' + entries.map(key => encodeURIComponent(key) + '=' + encodeURIComponent(params[key])).join('&') : '';
}
function shareImageUrl(url) {
  if (!url) return '/static/favicon.png';
  if (url.startsWith('//')) url = 'https:' + url;
  return /\.webp(\?|$)/i.test(url) ? '/static/favicon.png' : url;
}
function uploadAvatarOnce(filePath) {
  return new Promise((resolve, reject) => {
    wx.uploadFile({ url: BASE_URL + '/api/member/upload', filePath, name: 'file',
      header: { 'Member-Token': getToken() },
      success(res) {
        try {
          const body = JSON.parse(res.data);
          body.code === 1 ? resolve(body.data) : reject({ code: body.code, msg: body.msg });
        } catch (error) { reject(error); }
      }, fail: reject
    });
  });
}
async function uploadAvatar(filePath) {
  try { return await uploadAvatarOnce(filePath); }
  catch (error) {
    if (error.code !== 401) throw error;
    setToken('');
    if (!await memberLogin()) throw error;
    return uploadAvatarOnce(filePath);
  }
}

module.exports = {
  BASE_URL, getToken, setToken, memberLogin, shareImageUrl, uploadAvatar,
  getHome: () => request('/api/home/index'),
  getCategories: () => request('/api/category/list'),
  getArticleList: params => request('/api/article/list' + query(params)),
  getArticleDetail: id => request('/api/article/detail?id=' + encodeURIComponent(id), { silent: true }),
  getSetting: () => request('/api/setting/index', { silent: true }),
  getMemberInfo: () => memberRequest('/api/member/info', { silent: true }),
  updateProfile: data => memberRequest('/api/member/profile', { method: 'POST', data }),
  toggleFavorite: article_id => memberRequest('/api/member/favorite', { method: 'POST', data: { article_id } }),
  getFavorites: () => memberRequest('/api/member/favorites'),
  getHistory: () => memberRequest('/api/member/history')
};
