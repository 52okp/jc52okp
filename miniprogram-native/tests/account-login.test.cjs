const test = require('node:test');
const assert = require('node:assert/strict');
const api = require('../common/account-login');
const ticket = '0123456789abcdef0123456789abcdef';
const waiting = () => ({ platform: '52okp 导航', intent: 'LOGIN', state: 'WAITING', expiresAt: Date.now() + 60000 });

function mount() {
  let definition;
  global.Page = value => { definition = value; };
  delete require.cache[require.resolve('../pages/account-confirm/index')];
  require('../pages/account-confirm/index');
  const page = { ...definition, data: JSON.parse(JSON.stringify(definition.data)), setData(value) {
    for (const [key, item] of Object.entries(value)) this.data[key] = item;
  } };
  return page;
}

test('ticket accepts only lowercase 32 character hex and scene wins', () => {
  assert.equal(api.parseAccountTicket({ scene: ticket, ticket: 'bad' }), ticket);
  for (const value of ['', ticket.toUpperCase(), '../confirm', '%ZZ', ticket + '/']) {
    assert.throws(() => api.parseAccountTicket({ scene: value }), /登录码无效/);
  }
});

test('lookup uses the fixed hub and sends no member token', async () => {
  let request;
  global.wx = { request(options) { request = options; options.success({ statusCode: 200, data: waiting() }); } };
  assert.equal((await api.getAccountRequest(ticket)).platform, '52okp 导航');
  assert.equal(request.url, api.ACCOUNT_API + '/request/' + ticket);
  assert.equal(request.header['Member-Token'], undefined);
});

test('confirm requires consent and each decision obtains a fresh code', async () => {
  let calls = 0;
  const submissions = [];
  global.wx = {
    login(options) { options.success({ code: 'fresh-' + ++calls }); },
    request(options) { submissions.push(options); options.success({ statusCode: 200, data: { message: '已处理' } }); }
  };
  await assert.rejects(api.submitAccountDecision(ticket, 'confirm', false), /同意/);
  assert.equal(calls, 0);
  await api.submitAccountDecision(ticket, 'confirm', true);
  await api.submitAccountDecision(ticket, 'cancel', false);
  assert.deepEqual(submissions.map(item => item.data), [
    { ticket, code: 'fresh-1', decision: 'confirm', accepted: true },
    { ticket, code: 'fresh-2', decision: 'cancel', accepted: false }
  ]);
  assert.equal(submissions[0].header['Member-Token'], undefined);
});

test('page loads request without confirming, then expires on return', async () => {
  let logins = 0;
  global.wx = {
    request(options) { options.success({ statusCode: 200, data: waiting() }); },
    login() { logins++; }, showToast() {}
  };
  const page = mount();
  page.onLoad({ ticket });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(page.data.state, 'waiting');
  assert.equal(page.data.accepted, false);
  assert.equal(logins, 0);
  page.onHide();
  page.setData({ expiresAt: Date.now() - 1000 });
  page.onShow();
  assert.equal(page.data.state, 'expired');
  page.onUnload();
});

test('a late lookup response is ignored after unloading', async () => {
  let complete;
  global.wx = { request(options) { complete = options.success; } };
  const page = mount();
  page.onLoad({ ticket });
  page.onUnload();
  complete({ statusCode: 200, data: waiting() });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(page.data.platform, '');
});
