const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

test('cached tab bars keep their own selection while switching pages', () => {
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
  global.Component = value => { definition = value; };
  global.wx = { switchTab(options) { navigations.push(options.url); } };
  delete require.cache[require.resolve('../custom-tab-bar/index')];
  require('../custom-tab-bar/index');
  function mountTabBar(route) {
    global.getCurrentPages = () => [{ route }];
    const tabBar = {
      ...definition.methods,
      data: { ...definition.data },
      setData(values) { Object.assign(this.data, values); }
    };
    assert.equal(tabBar.data.selected, -1);
    definition.lifetimes.attached.call(tabBar);
    return tabBar;
  }

  const home = mountTabBar('pages/home/home');
  const my = mountTabBar('pages/my/my');
  home.switchTab({ currentTarget: { dataset: { index: 2 } } });
  assert.equal(home.data.selected, 0);
  my.switchTab({ currentTarget: { dataset: { index: 0 } } });
  assert.equal(my.data.selected, 2);
  assert.equal(home.data.selected, 0);
  const category = mountTabBar('pages/category/category');
  home.switchTab({ currentTarget: { dataset: { index: 1 } } });
  assert.equal(home.data.selected, 0);
  assert.equal(category.data.selected, 1);
  category.switchTab({ currentTarget: { dataset: { index: 1 } } });
  assert.deepEqual(navigations, [
    '/pages/my/my', '/pages/home/home', '/pages/category/category'
  ]);
});

test('onShow corrects stale tab selection only when necessary', () => {
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
      data: { selected: (selected + 1) % 3 },
      updates: 0,
      setData(values) { this.updates++; Object.assign(this.data, values); }
    };
    const page = {
      data: { loading: true },
      lastMemberLoadedAt: Date.now(),
      getTabBar: () => tabBar
    };
    pageDefinition.onShow.call(page);
    assert.equal(tabBar.data.selected, selected);
    assert.equal(tabBar.updates, 1);
    pageDefinition.onShow.call(page);
    assert.equal(tabBar.updates, 1);
  }
});
