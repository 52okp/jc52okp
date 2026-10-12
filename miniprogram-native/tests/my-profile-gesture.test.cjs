const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

test('My profile follows an upward swipe and settles into a compact header', () => {
  let definition;
  const source = fs.readFileSync(path.join(__dirname, '../pages/my/my.js'), 'utf8');
  vm.runInNewContext(source, {
    Page(value) { definition = value; },
    require(modulePath) { return modulePath.endsWith('/ui') ? { topInset: () => 38 } : {}; }
  });
  const page = {
    ...definition,
    data: { ...definition.data },
    setData(values) { Object.assign(this.data, values); }
  };
  const touch = (x, y) => ({ touches: [{ pageX: x, pageY: y }] });

  assert.match(page.data.profileStyle, /height:224rpx/);
  page.profileTouchStart(touch(100, 300));
  page.profileTouchMove(touch(100, 246));
  assert.ok(page.data.profileProgress > 0.5 && page.data.profileProgress < 1);
  page.profileTouchEnd();
  assert.equal(page.data.profileProgress, 1);
  assert.match(page.data.profileStyle, /height:128rpx/);
  assert.match(page.data.avatarStyle, /width:76rpx/);

  page.profileTouchStart(touch(100, 200));
  page.profileTouchMove(touch(100, 295));
  page.profileTouchEnd();
  assert.equal(page.data.profileProgress, 0);
  assert.match(page.data.profileStyle, /height:224rpx/);

  page.profileTouchStart(touch(100, 300));
  page.profileTouchMove(touch(210, 290));
  page.profileTouchEnd();
  assert.equal(page.data.profileProgress, 0);

  const markup = fs.readFileSync(path.join(__dirname, '../pages/my/my.wxml'), 'utf8');
  for (const route of ['favorites/favorites', 'history/history', 'about/about']) {
    assert.ok(markup.includes(`/pages/${route}`));
  }
});
