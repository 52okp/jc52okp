function topInset() {
  try {
    const system = wx.getSystemInfoSync();
    return (system.statusBarHeight || 24) + 14;
  } catch (_) {
    return 38;
  }
}

const QUICK_TYPES = [
  { test: /基础|入门|新手/, kind: 'start', glyph: '▣', hint: '快速上手', rank: 0 },
  { test: /进阶|高级/, kind: 'advance', glyph: '▥', hint: '深入学习', rank: 1 },
  { test: /工具|下载|资源/, kind: 'tools', glyph: '▤', hint: '实用工具', rank: 2 },
  { test: /github|开源/i, kind: 'github', glyph: '⌘', hint: '开源资源', rank: 3 },
  { test: /stable\s*diffusion|sd教程/i, kind: 'stable', glyph: 'S', hint: '绘画教程', rank: 4 },
  { test: /comfy/i, kind: 'comfy', glyph: 'C', hint: '工作流教程', rank: 5 }
];

function decorateCategory(category, index) {
  const match = QUICK_TYPES.find(type => type.test.test(category.name || ''));
  const visual = match || { kind: 'other', glyph: (category.name || 'AI').slice(0, 1), hint: '探索更多', rank: 100 + index };
  return { ...category, kind: visual.kind, glyph: visual.glyph, hint: visual.hint, rank: visual.rank };
}

function quickCategories(categories) {
  const decorated = (categories || []).map(decorateCategory);
  const selected = [];
  QUICK_TYPES.forEach(type => {
    const match = decorated.find(item => item.kind === type.kind && !selected.some(chosen => chosen.id === item.id));
    if (match) selected.push(match);
  });
  decorated.forEach(item => {
    if (selected.length < 6 && !selected.some(chosen => chosen.id === item.id)) selected.push(item);
  });
  return selected.slice(0, 6);
}

module.exports = { topInset, decorateCategory, quickCategories };
