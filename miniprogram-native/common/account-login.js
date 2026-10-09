const ACCOUNT_API = 'https://open.52okp.com/realms/52okp/wechat';
const ACCOUNT_POLICY_PAGE = 'pages/account-policy/index';

function parseAccountTicket(options = {}) {
  let ticket = '';
  try { ticket = decodeURIComponent(options.scene || options.ticket || ''); }
  catch (_) { throw new Error('登录码无效，请在原网页刷新后重新扫码'); }
  if (!/^[a-f0-9]{32}$/.test(ticket)) throw new Error('登录码无效，请在原网页刷新后重新扫码');
  return ticket;
}
function accountRequest(path, data, method = 'GET') {
  return new Promise((resolve, reject) => {
    wx.request({ url: ACCOUNT_API + path, method, data, timeout: 15000,
      header: { 'content-type': 'application/json' },
      success(response) {
        const body = response.data;
        if (response.statusCode < 200 || response.statusCode >= 300) {
          const error = new Error(body && typeof body.message === 'string' ? body.message : '请求失败，请稍后重试');
          error.status = response.statusCode;
          return reject(error);
        }
        if (!body || typeof body !== 'object' || Array.isArray(body)) return reject(new Error('登录服务返回异常，请返回原网页查看'));
        resolve(body);
      }, fail() { reject(new Error('网络连接失败，请检查网络后重试')); }
    });
  });
}
async function getAccountRequest(ticket) {
  parseAccountTicket({ ticket });
  const result = await accountRequest('/request/' + ticket);
  if (typeof result.platform !== 'string' || !result.platform.trim() ||
      !['LOGIN', 'LINK'].includes(result.intent) ||
      !['WAITING', 'CONFIRMED', 'CANCELLED', 'CONSUMED', 'EXPIRED'].includes(result.state) ||
      !Number.isFinite(result.expiresAt) || result.expiresAt <= 0) {
    throw new Error('登录请求信息不完整，请返回原网页重新扫码');
  }
  return result;
}
async function submitAccountDecision(ticket, decision, accepted) {
  parseAccountTicket({ ticket });
  if (!['confirm', 'cancel'].includes(decision)) throw new Error('登录操作无效');
  if (decision === 'confirm' && accepted !== true) throw new Error('请先阅读并同意账号说明');
  const login = await new Promise((resolve, reject) => {
    wx.login({ timeout: 10000, success: resolve, fail() { reject(new Error('微信登录凭证获取失败，请重新点击')); } });
  });
  if (!login || !login.code) throw new Error('微信登录凭证获取失败，请重新点击');
  const result = await accountRequest('/confirm', { ticket, code: login.code, decision, accepted: accepted === true }, 'POST');
  if (typeof result.message !== 'string' || !result.message.trim()) throw new Error('未收到有效确认结果，请返回原网页查看');
  return result;
}
module.exports = { ACCOUNT_API, ACCOUNT_POLICY_PAGE, parseAccountTicket, getAccountRequest, submitAccountDecision };
