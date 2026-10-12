const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

test('floating tab bar switches pages immediately and restores selection on failure', () => {
  const app = JSON.parse(fs.readFileSync(path.join(__dirname, '../app.json'), 'utf8'));
  assert.equal(app.tabBar.custom, true);
  assert.deepEqual(app.tabBar.list.map(item => item.pagePath), [
    'pages/home/home', 'pages/category/category', 'pages/my/my'
  ]);
  for (const item of app.tabBar.list) {
    assert.equal(item.iconPath, item.selectedIconPath);
    assert.ok(fs.existsSync(path.join(__dirname, '..', item.iconPath)));
  }

  let definition;
  const navigations = [];
  let pendingSwitch;
  global.Component = value => { definition = value; };
  global.getCurrentPages = () => [{ route: 'pages/category/category' }];
  global.wx = { switchTab(options) { navigations.push(options.url); pendingSwitch = options; } };
  delete require.cache[require.resolve('../custom-tab-bar/index')];
  require('../custom-tab-bar/index');
  const tabBar = {
    ...definition.methods,
    data: { ...definition.data },
    updates: [],
    setData(values, callback) {
      this.updates.push({ ...values, animatedBefore: this.data.animated });
      Object.assign(this.data, values);
      if (callback) callback();
    }
  };
  assert.equal(tabBar.data.selected, -1);
  assert.equal(tabBar.data.animated, false);
  definition.lifetimes.attached.call(tabBar);
  assert.equal(tabBar.data.selected, 1);
  assert.equal(tabBar.data.animated, false);
  tabBar.switchTab({ currentTarget: { dataset: { index: 1 } } });
  assert.deepEqual(navigations, []);
  tabBar.switchTab({ currentTarget: { dataset: { index: 2 } } });
  assert.equal(tabBar.data.selected, 2);
  assert.deepEqual(tabBar.updates.slice(-2), [
    { animated: true, animatedBefore: false },
    { selected: 2, animatedBefore: true }
  ]);
  assert.deepEqual(navigations, ['/pages/my/my']);
  pendingSwitch.fail();
  assert.equal(tabBar.data.selected, 1);

  for (const [route, selected] of [
    ['pages/home/home', 0], ['pages/category/category', 1], ['pages/my/my', 2]
  ]) {
    global.getCurrentPages = () => [{ route }];
    const destination = {
      data: { ...definition.data },
      updates: [],
      setData(values, callback) {
        this.updates.push({ ...values, animatedBefore: this.data.animated });
        Object.assign(this.data, values);
        if (callback) callback();
      }
    };
    definition.lifetimes.attached.call(destination);
    assert.equal(destination.data.selected, selected);
    assert.equal(destination.updates[0].animatedBefore, false);
    assert.equal(destination.data.animated, false);
  }
});

test('returning to an existing tab resets its indicator without replaying the transition', () => {
  for (const [pagePath, selected] of [
    ['home/home.js', 0], ['category/category.js', 1], ['my/my.js', 2]
  ]) {
    let pageDefinition;
    const source = fs.readFileSync(path.join(__dirname, '../pages', pagePath), 'utf8');
    vm.runInNewContext(source, {
      Page(value) { pageDefinition = value; },
      require(modulePath) {
        return modulePath.endsWith('/ui') ? { topInset: () => 0 } : {};
      }
    });
    const tabBar = {
      data: { selected: (selected + 1) % 3, animated: true },
      setData(values) { Object.assign(this.data, values); }
    };
    const page = {
      data: { loading: true },
      lastMemberLoadedAt: Date.now(),
      getTabBar: () => tabBar
    };
    pageDefinition.onShow.call(page);
    assert.equal(tabBar.data.selected, selected);
    assert.equal(tabBar.data.animated, false);
  }
});
