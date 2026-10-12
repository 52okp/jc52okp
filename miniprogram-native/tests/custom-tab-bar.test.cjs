const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

test('floating tab bar keeps the three existing routes and switches to the selected tab', async () => {
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
  global.getCurrentPages = () => [{ route: 'pages/category/category' }];
  global.wx = { switchTab(options) { navigations.push(options.url); } };
  delete require.cache[require.resolve('../custom-tab-bar/index')];
  require('../custom-tab-bar/index');
  const tabBar = {
    ...definition.methods,
    data: { ...definition.data },
    setData(values) { Object.assign(this.data, values); }
  };
  definition.lifetimes.attached.call(tabBar);
  assert.equal(tabBar.data.selected, 1);
  tabBar.switchTab({ currentTarget: { dataset: { index: 1 } } });
  assert.deepEqual(navigations, []);
  tabBar.switchTab({ currentTarget: { dataset: { index: 2 } } });
  assert.equal(tabBar.data.selected, 2);
  await new Promise(resolve => setTimeout(resolve, 220));
  assert.deepEqual(navigations, ['/pages/my/my']);
  definition.lifetimes.detached.call(tabBar);
});
