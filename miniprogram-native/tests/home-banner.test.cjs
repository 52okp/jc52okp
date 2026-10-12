const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

function mount(home) {
  let definition;
  const navigations = [];
  global.Page = value => { definition = value; };
  global.wx = {
    getSystemInfoSync: () => ({ statusBarHeight: 24 }),
    getStorageSync: () => '',
    request(options) { options.success({ data: { code: 1, data: home } }); },
    navigateTo(options) { navigations.push(options.url); },
    showToast() {}
  };
  delete require.cache[require.resolve('../pages/home/home')];
  require('../pages/home/home');
  const page = {
    ...definition,
    data: JSON.parse(JSON.stringify(definition.data)),
    setData(values) {
      for (const [key, value] of Object.entries(values)) {
        const match = /^banners\[(\d+)\]\.(\w+)$/.exec(key);
        if (match) this.data.banners[Number(match[1])][match[2]] = value;
        else if (key.startsWith('activeBanner.')) this.data.activeBanner[key.slice('activeBanner.'.length)] = value;
        else this.data[key] = value;
      }
    }
  };
  return { page, navigations };
}

test('backend banners switch inside a fixed hero and keep their own article targets', async () => {
  const { page, navigations } = mount({
    banners: [
      { id: 10, title: 'ComfyUI 从零开始', image: '/upload/first.webp', character_image: '/upload/first.png', article_id: 31 },
      { id: 11, title: '  ', image: 'https://cdn.example.com/second.jpg', character_image: '', article_id: 32 }
    ],
    default_banner_background: '/static/hero/background.webp',
    recommend: [{ id: 7, title: '真实推荐' }]
  });
  await page.loadHome();
  assert.equal(page.data.banners.length, 2);
  assert.equal(page.data.activeBanner.title, 'ComfyUI 从零开始');
  assert.equal(page.data.banners[0].backgroundImage, 'https://jc.52okp.com/upload/first.webp');
  assert.equal(page.data.banners[0].characterImage, 'https://jc.52okp.com/upload/first.png');
  assert.equal(page.data.banners[1].characterImage, '');
  assert.equal(page.data.banners[1].backgroundImage, 'https://cdn.example.com/second.jpg');
  assert.deepEqual(page.data.articles.map(article => article.id), [7]);
  page.heroTouchStart({ touches: [{ pageX: 300, pageY: 180 }] });
  page.heroTouchEnd({ changedTouches: [{ pageX: 110, pageY: 185 }] });
  assert.equal(page.data.bannerIndex, 1);
  assert.equal(page.data.activeBanner.articleId, 32);
  assert.equal(page.data.activeBanner.title, '');
  page.openHero();
  assert.deepEqual(navigations, [], 'a swipe must not also open an article');
  page.lastSwipeAt = 0;
  page.openHero();
  assert.deepEqual(navigations, ['/pages/detail/detail?id=32']);
  page.heroTouchStart({ touches: [{ pageX: 160, pageY: 180 }] });
  page.heroTouchEnd({ changedTouches: [{ pageX: 165, pageY: 300 }] });
  assert.equal(page.data.bannerIndex, 1, 'vertical scrolling must not switch the banner');
});

test('empty and failed banner images use only the backend default background', async () => {
  const { page } = mount({ banners: [], default_banner_background: '/static/hero/background.webp', recommend: [] });
  await page.loadHome();
  assert.equal(page.data.banners.length, 1);
  assert.equal(page.data.banners[0].articleId, 0);
  assert.equal(page.data.banners[0].backgroundImage, 'https://jc.52okp.com/static/hero/background.webp');
  assert.equal(page.data.banners[0].characterImage, '');
  const clearedBackground = mount({
    banners: [{ id: 2, image: '', character_image: '/upload/person.webp', article_id: 0 }],
    default_banner_background: '/static/hero/background.webp',
    recommend: []
  }).page;
  await clearedBackground.loadHome();
  assert.equal(clearedBackground.data.banners[0].backgroundImage, 'https://jc.52okp.com/static/hero/background.webp');
  assert.equal(clearedBackground.data.banners[0].characterImage, 'https://jc.52okp.com/upload/person.webp');
  const withCharacter = mount({ banners: [{ id: 1, image: '/a.jpg', character_image: '/bad.png', article_id: 0 }], default_banner_background: '/static/hero/background.webp', recommend: [] }).page;
  await withCharacter.loadHome();
  withCharacter.characterError({ currentTarget: { dataset: { index: 0 } } });
  assert.equal(withCharacter.data.banners[0].characterImage, '');
  assert.equal(withCharacter.data.activeBanner.characterImage, '');
  withCharacter.heroError();
  assert.equal(withCharacter.data.activeBanner.backgroundImage, 'https://jc.52okp.com/static/hero/background.webp');
});

test('hero images are served by the backend instead of bundled locally', () => {
  const miniRoot = path.resolve(__dirname, '..');
  assert.equal(fs.existsSync(path.join(miniRoot, 'static/hero')), false);
  const markup = fs.readFileSync(path.join(miniRoot, 'pages/home/home.wxml'), 'utf8');
  assert.doesNotMatch(markup, /\/static\/hero\//);
  assert.match(markup, /wx:if="\{\{activeBanner.characterImage\}\}"/);
  assert.match(markup, /class="hero-character"[^>]*webp="\{\{true\}\}"/);
  assert.match(markup, /wx:if="\{\{activeBanner.title\}\}"/);
  assert.match(markup, /wx:else class="hero-headline">让 AI 更简单<text>更好用<\/text>/);
});

test('automatic rotation stops when the page is hidden', async () => {
  const { page } = mount({ banners: [
    { id: 1, image: '/a.jpg', character_image: '', article_id: 0 },
    { id: 2, image: '/b.jpg', character_image: '', article_id: 0 }
  ], recommend: [] });
  page.pageVisible = true;
  await page.loadHome();
  assert.ok(page.bannerTimer);
  page.onHide();
  assert.equal(page.bannerTimer, null);
});
