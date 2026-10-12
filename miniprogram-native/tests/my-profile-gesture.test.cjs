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

  assert.match(page.data.profileStyle, /height:260rpx/);
  assert.match(page.data.avatarStyle, /width:144rpx/);
  assert.match(page.data.avatarImageStyle, /opacity:1\.00/);
  assert.match(page.data.profileNameStyle, /top:158rpx;left:50\.0%;transform:translateX\(-50%\)/);
  page.profileTouchStart(touch(100, 300));
  page.profileTouchMove(touch(100, 225));
  assert.ok(page.data.profileProgress > 0.5 && page.data.profileProgress < 1);
  assert.match(page.data.profileNameStyle, /left:2[0-9]\.[0-9]%/);
  page.profileTouchEnd();
  assert.equal(page.data.profileProgress, 1);
  assert.match(page.data.profileStyle, /height:78rpx/);
  assert.match(page.data.avatarStyle, /width:28rpx/);
  assert.match(page.data.avatarStyle, /opacity:0\.00/);
  assert.match(page.data.gooTailStyle, /opacity:0\.00/);
  assert.match(page.data.profileNameStyle, /top:19rpx;left:2\.0%;transform:translateX\(0%\)/);

  page.profileTouchStart(touch(100, 200));
  page.profileTouchMove(touch(100, 295));
  page.profileTouchEnd();
  assert.equal(page.data.profileProgress, 0);
  assert.match(page.data.profileStyle, /height:260rpx/);

  page.profileTouchStart(touch(100, 300));
  page.profileTouchMove(touch(210, 290));
  page.profileTouchEnd();
  assert.equal(page.data.profileProgress, 0);

  page.profileTouchStart(touch(100, 300));
  page.profileTouchMove(touch(100, 196));
  assert.ok(page.data.profileProgress > 0.7 && page.data.profileProgress < 0.8);
  assert.match(page.data.gooTailStyle, /opacity:0\.[1-9]/);
  assert.match(page.data.avatarImageStyle, /opacity:0\.[0-9]/);
  page.profileTouchEnd();

  const markup = fs.readFileSync(path.join(__dirname, '../pages/my/my.wxml'), 'utf8');
  assert.equal((markup.match(/member\.nickname \|\| '微信用户'/g) || []).length, 1);
  assert.ok(!markup.includes('我的收藏'));
  for (const route of ['history/history', 'about/about']) {
    assert.ok(markup.includes(`/pages/${route}`));
  }
});
