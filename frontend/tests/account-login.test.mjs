import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../src/common/account-login.js', import.meta.url), 'utf8');
const api = await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'));
const ticket = '0123456789abcdef0123456789abcdef';
const waiting = () => ({ platform: '52okp 导航', intent: 'LOGIN', state: 'WAITING', expiresAt: Date.now() + 300000 });
const response = (data, statusCode = 200) => ({ data, statusCode });

function pageComponent(relative, dependencies) {
  const file = fs.readFileSync(new URL(relative, import.meta.url), 'utf8');
  const script = file.match(/<script>([\s\S]*?)<\/script>/)[1]
    .replace(/import[\s\S]*?from ['"][^'"]+['"];?/g, '')
    .replace('export default', 'page =');
  const context = {
    ...dependencies, page: null, Date,
    setInterval: () => 1, clearInterval: () => {}
  };
  vm.runInNewContext(script, context);
  return context.page;
}
function mountedPage(dependencies = {}) {
  const page = pageComponent('../src/pages/account-confirm/index.vue', {
    ...api, uni: globalThis.uni, ...dependencies
  });
  const instance = { ...page.data(), _accountDisposed: false, _accountRequestVersion: 0 };
  for (const [name, method] of Object.entries(page.methods)) instance[name] = method.bind(instance);
  return { page, instance };
}

test('仅允许32位小写十六进制票据，拒绝地址、坏编码和路径注入', () => {
  assert.equal(api.parseAccountTicket({ scene: ticket, ticket: 'other' }), ticket);
  assert.equal(api.parseAccountTicket({ ticket }), ticket);
  for (const value of ['', '../confirm', 'https://other.example', '%ZZ', ticket.toUpperCase(), ticket + '/']) {
    assert.throws(() => api.parseAccountTicket({ scene: value }), /登录码无效/);
  }
});

test('扫码入口识别支持前导斜杠和查询参数，普通教程入口保留', () => {
  assert.equal(api.isAccountEntry('/pages/account-confirm/index?scene=' + ticket), true);
  assert.equal(api.isAccountEntry(api.ACCOUNT_POLICY_PAGE), true);
  assert.equal(api.isAccountEntry('pages/detail/detail'), false);
});

test('请求固定中枢，GET只查询申请应用且不发送教程token', async () => {
  let sent;
  globalThis.uni = { request(options) { sent = options; options.success(response(waiting())); } };
  const result = await api.getAccountRequest(ticket);
  assert.equal(result.platform, '52okp 导航');
  assert.equal(sent.url, api.ACCOUNT_API + '/request/' + ticket);
  assert.equal(sent.method, 'GET');
  assert.equal(sent.header['Member-Token'], undefined);
});

test('不完整或HTML响应不能作为有效登录请求', async () => {
  for (const value of ['<html>error</html>', {}, { ...waiting(), intent: 'ADMIN' }, { ...waiting(), expiresAt: 'later' }]) {
    globalThis.uni = { request(options) { options.success(response(value)); } };
    await assert.rejects(api.getAccountRequest(ticket), /异常|不完整/);
  }
});

test('HTTP错误携带状态，网络失败明确报告', async () => {
  globalThis.uni = { request(options) { options.success(response({ message: '请求已失效' }, 410)); } };
  await assert.rejects(api.getAccountRequest(ticket), (error) => error.status === 410 && error.message === '请求已失效');
  globalThis.uni = { request(options) { options.fail({ errMsg: 'timeout' }); } };
  await assert.rejects(api.getAccountRequest(ticket), /网络连接失败/);
});

test('未同意协议或无效操作不会调用微信或中枢', async () => {
  let calls = 0;
  globalThis.uni = { login() { calls++; }, request() { calls++; } };
  await assert.rejects(api.submitAccountDecision(ticket, 'confirm', false), /同意/);
  await assert.rejects(api.submitAccountDecision(ticket, 'approve', true), /无效/);
  assert.equal(calls, 0);
});

test('确认和取消分别获取新微信code，取消不强制同意协议', async () => {
  let count = 0;
  const requests = [];
  globalThis.uni = {
    login(options) { assert.equal(options.provider, 'weixin'); options.success({ code: 'fresh-' + ++count }); },
    request(options) { requests.push(options); options.success(response({ message: '已处理' })); }
  };
  await api.submitAccountDecision(ticket, 'confirm', true);
  await api.submitAccountDecision(ticket, 'cancel', false);
  assert.deepEqual(requests.map(r => r.data), [
    { ticket, code: 'fresh-1', decision: 'confirm', accepted: true },
    { ticket, code: 'fresh-2', decision: 'cancel', accepted: false }
  ]);
  assert.equal(requests[0].url, api.ACCOUNT_API + '/confirm');
  assert.equal(requests[0].method, 'POST');
  assert.equal(requests[0].data.openid, undefined);
});

test('微信失败或没有code时不提交确认', async () => {
  let requests = 0;
  globalThis.uni = { login(o) { o.success({}); }, request() { requests++; } };
  await assert.rejects(api.submitAccountDecision(ticket, 'confirm', true), /凭证获取失败/);
  globalThis.uni.login = o => o.fail();
  await assert.rejects(api.submitAccountDecision(ticket, 'confirm', true), /凭证获取失败/);
  assert.equal(requests, 0);
});

test('确认响应异常或网络超时不会当作成功，也不会自动重发', async () => {
  let requests = 0;
  globalThis.uni = {
    login(o) { o.success({ code: 'fresh' }); },
    request(o) { requests++; o.success(response({})); }
  };
  await assert.rejects(api.submitAccountDecision(ticket, 'confirm', true), /未收到有效/);
  globalThis.uni.request = o => { requests++; o.fail(); };
  await assert.rejects(api.submitAccountDecision(ticket, 'confirm', true), /网络连接失败/);
  assert.equal(requests, 2);
});

test('加载确认页只查询，显示应用名称并保持未确认', async () => {
  let submissions = 0;
  const { instance } = mountedPage({
    getAccountRequest: async () => waiting(),
    submitAccountDecision: async () => { submissions++; }
  });
  instance.ticket = ticket;
  await instance.loadRequest();
  assert.equal(instance.platform, '52okp 导航');
  assert.equal(instance.state, 'waiting');
  assert.equal(instance.accepted, false);
  assert.equal(submissions, 0);
});

test('确认页拒绝未同意协议及已过期请求', async () => {
  let submissions = 0, toasts = 0;
  globalThis.uni = { showToast() { toasts++; } };
  const { instance } = mountedPage({ submitAccountDecision: async () => { submissions++; } });
  Object.assign(instance, { ticket, state: 'waiting', expiresAt: Date.now() + 60000 });
  await instance.submit('confirm');
  assert.equal(toasts, 1);
  Object.assign(instance, { accepted: true, expiresAt: Date.now() - 1000 });
  await instance.submit('confirm');
  assert.equal(instance.state, 'expired');
  assert.equal(submissions, 0);
});

test('连续点击只提交一次，成功后按钮不能再次提交', async () => {
  let submissions = 0, complete;
  const { instance } = mountedPage({
    submitAccountDecision: () => { submissions++; return new Promise(resolve => { complete = resolve; }); }
  });
  Object.assign(instance, { ticket, state: 'waiting', accepted: true, expiresAt: Date.now() + 60000 });
  const first = instance.submit('confirm');
  await instance.submit('confirm');
  assert.equal(submissions, 1);
  complete({ message: '已确认' });
  await first;
  assert.equal(instance.state, 'success');
  await instance.submit('confirm');
  assert.equal(submissions, 1);
});

test('重复处理和过期的服务器结果转为终态', async () => {
  for (const [status, expected] of [[409, 'done'], [410, 'expired']]) {
    const { instance } = mountedPage({
      submitAccountDecision: async () => { const error = new Error('已失效'); error.status = status; throw error; }
    });
    Object.assign(instance, { ticket, state: 'waiting', accepted: true, expiresAt: Date.now() + 60000 });
    await instance.submit('confirm');
    assert.equal(instance.state, expected);
    assert.equal(instance.busy, false);
  }
});

test('网络超时不展示成功，提醒返回网页核对', async () => {
  const { instance } = mountedPage({ submitAccountDecision: async () => { throw new Error('网络连接失败'); } });
  Object.assign(instance, { ticket, state: 'waiting', accepted: true, expiresAt: Date.now() + 60000 });
  await instance.submit('confirm');
  assert.equal(instance.state, 'waiting');
  assert.match(instance.error, /返回原网页查看/);
});

test('页面离开后忽略延迟查询结果', async () => {
  let complete;
  const { page, instance } = mountedPage({
    getAccountRequest: () => new Promise(resolve => { complete = resolve; })
  });
  instance.ticket = ticket;
  const pending = instance.loadRequest();
  page.onUnload.call(instance);
  complete(waiting());
  await pending;
  assert.equal(instance.platform, '');
  assert.equal(instance.state, 'loading');
});

test('从账号说明返回时重新计算期限，过期请求不能继续确认', () => {
  const { page, instance } = mountedPage();
  Object.assign(instance, { state: 'waiting', expiresAt: Date.now() - 1000 });
  page.onShow.call(instance);
  assert.equal(instance.state, 'expired');
});

test('扫码冷启动跳过教程静默登录，教程入口仍正常登录', () => {
  let logins = 0;
  const app = pageComponent('../src/App.vue', {
    isAccountEntry: api.isAccountEntry, getToken: () => '', memberLogin: () => { logins++; }
  });
  app.onLaunch({ path: api.ACCOUNT_CONFIRM_PAGE });
  app.onLaunch({ path: api.ACCOUNT_POLICY_PAGE });
  assert.equal(logins, 0);
  app.onLaunch({ path: 'pages/home/home' });
  assert.equal(logins, 1);
});