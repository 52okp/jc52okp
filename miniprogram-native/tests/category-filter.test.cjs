const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

function mount() {
  let definition;
  const requests = [];
  const categories = [{ id: 2, name: '基础入门' }, { id: 7, name: 'ComfyUI' }];
  global.Page = value => { definition = value; };
  global.wx = {
    getSystemInfoSync: () => ({ statusBarHeight: 24 }),
    getStorageSync: () => '',
    request(options) {
      requests.push(options.url);
      if (options.url.includes('/api/category/list')) {
        options.success({ data: { code: 1, data: categories } });
      } else {
        const url = new URL(options.url);
        const selected = url.searchParams.get('category_id') === '7';
        const nextPage = url.searchParams.get('page') === '2';
        const data = selected
          ? { list: [{ id: 8, category_id: 7, title: 'ComfyUI 教程' }], total: 1 }
          : nextPage
            ? { list: [{ id: 9, category_id: 7, title: '另一篇教程' }], total: 3 }
            : { list: [{ id: 3, category_id: 2, title: '入门教程' }, { id: 8, category_id: 7, title: 'ComfyUI 教程' }], total: 3 };
        options.success({ data: { code: 1, data } });
      }
    },
    navigateTo() {},
    showToast() {}
  };
  delete require.cache[require.resolve('../pages/category/category')];
  require('../pages/category/category');
  const page = {
    ...definition,
    data: { ...definition.data },
    setData(values, callback) { Object.assign(this.data, values); if (callback) callback(); }
  };
  return { page, requests };
}

test('all chip loads real articles without category filter and category chips keep backend IDs', async () => {
  const { page, requests } = mount();
  await page.init();
  assert.equal(page.data.categoryId, 0);
  assert.equal(page.data.list.length, 2);
  assert.equal(page.data.list[0].categoryName, '基础入门');
  assert.equal(page.data.list[1].categoryName, 'ComfyUI');
  assert.doesNotMatch(requests[1], /category_id=/);

  await page.select({ currentTarget: { dataset: { index: 1 } } });
  assert.equal(page.data.categoryId, 7);
  assert.equal(page.data.list[0].id, 8);
  assert.match(requests[2], /category_id=7/);

  await page.selectAll();
  page.more();
  await new Promise(resolve => setImmediate(resolve));
  assert.match(requests.at(-1), /page=2/);
  assert.deepEqual(page.data.list.map(article => article.id), [3, 8, 9]);

  const markup = fs.readFileSync(path.join(__dirname, '../pages/category/category.wxml'), 'utf8');
  assert.match(markup, /bindtap="selectAll">全部/);
  assert.doesNotMatch(markup, /scroll-x class="category-tabs"/);
});
