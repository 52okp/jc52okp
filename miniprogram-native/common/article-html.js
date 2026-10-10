const tagStyles = {
  p: 'margin:0 0 16px;line-height:1.85;',
  h1: 'margin:28px 0 12px;font-size:23px;line-height:1.4;color:#152547;font-weight:700;',
  h2: 'margin:26px 0 12px;font-size:21px;line-height:1.4;color:#152547;font-weight:700;',
  h3: 'margin:22px 0 10px;font-size:18px;line-height:1.45;color:#1a3158;font-weight:700;',
  h4: 'margin:20px 0 8px;font-size:16px;line-height:1.5;color:#1a3158;font-weight:700;',
  ul: 'margin:12px 0 18px;padding-left:22px;line-height:1.85;',
  ol: 'margin:12px 0 18px;padding-left:22px;line-height:1.85;',
  li: 'margin:6px 0;',
  blockquote: 'display:block;margin:18px 0;padding:12px 16px;border-left:3px solid #6b98f7;background:#f3f7ff;color:#4c6185;line-height:1.8;',
  pre: 'display:block;margin:18px 0;padding:16px;border-radius:10px;background:#f1f5fb;color:#233657;white-space:pre-wrap;word-break:break-word;font-size:13px;line-height:1.65;',
  code: 'font-family:monospace;word-break:break-word;',
  a: 'color:#3474e8;text-decoration:underline;word-break:break-all;',
  table: 'width:100%;border-collapse:collapse;margin:18px 0;table-layout:fixed;',
  th: 'padding:10px;border:1px solid #e2e9f3;background:#f3f7ff;word-break:break-word;text-align:left;',
  td: 'padding:10px;border:1px solid #e2e9f3;word-break:break-word;vertical-align:top;',
  img: 'display:block;max-width:100%;height:auto;margin:18px auto;border-radius:10px;'
};

function addStyle(attributes, style) {
  if (/\bstyle\s*=\s*(["'])/i.test(attributes)) {
    return attributes.replace(/\bstyle\s*=\s*(["'])(.*?)\1/i, (_, quote, original) =>
      `style=${quote}${original.trim().replace(/;?$/, ';')}${style}${quote}`);
  }
  return `${attributes} style="${style}"`;
}

function mobileTable(html, themed) {
  return html.replace(/<table\b[^>]*>([\s\S]*?)<\/table>/gi, (table, body) => {
    if (/<table\b/i.test(body)) return table;
    const rows = Array.from(body.matchAll(/<tr\b[^>]*>([\s\S]*?)<\/tr>/gi));
    if (!rows.length) return table;
    const cells = rows.map(row => Array.from(row[1].matchAll(/<(td|th)\b([^>]*)>([\s\S]*?)<\/\1>/gi)));
    if (cells.some(row => row.length !== 2 || row.some(cell => /\b(?:colspan|rowspan)\b/i.test(cell[2])))) return table;
    const bodyRows = cells[0].every(cell => cell[1].toLowerCase() === 'th') ? cells.slice(1) : cells;
    const border = themed ? '#BBF7D0' : '#e6edf8';
    const label = themed ? '#059669' : '#243e68';
    const value = themed ? '#374151' : '#405575';
    return `<div style="display:block;margin:18px 0;border:1px solid ${border};border-radius:10px;overflow:hidden;">${bodyRows.map((row, index) =>
      `<div style="display:block;padding:12px 14px;${index ? `border-top:1px solid ${border};` : ''}">` +
      `<div style="display:block;margin-bottom:5px;font-weight:700;color:${label};">${row[0][3]}</div>` +
      `<div style="display:block;line-height:1.75;color:${value};">${row[1][3]}</div></div>`
    ).join('')}</div>`;
  });
}

function articleHtml(input) {
  if (input == null) return '';
  let html = String(input).trim();
  if (!html) return '';
  if (!/<[a-z][\s\S]*?>/i.test(html)) {
    html = html.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .split(/\n\s*\n/).map(paragraph => `<p>${paragraph.replace(/\n/g, '<br>')}</p>`).join('');
  }
  const themed = /data-wwds-theme\s*=\s*(["'])moyu-green\1/i.test(html);
  html = html.replace(/<(script|style|iframe|object|embed|form)\b[^>]*>[\s\S]*?<\/\1>/gi, '');
  html = mobileTable(html, themed);
  html = html.replace(/<([a-z][a-z0-9]*)\b([^>]*?)\s*(\/?)>/gi, (whole, name, attributes, slash) => {
    const tag = name.toLowerCase();
    const style = tagStyles[tag];
    if (!style) return whole;
    if (themed && tag !== 'img') return whole;
    if (tag === 'img') {
      const lazy = attributes.match(/\b(?:data-src|data-lazy-src|data-original)\s*=\s*(["'])(.*?)\1/i);
      if (lazy && (!/(?:^|\s)src\s*=\s*(["'])https?:\/\//i.test(attributes) || /(?:^|\s)src\s*=\s*(["'])data:/i.test(attributes))) {
        attributes = attributes.replace(/(^|\s)src\s*=\s*(["'])(.*?)\2/i, '$1');
        attributes += ` src="${lazy[2]}"`;
      }
      attributes = attributes.replace(/\s(?:width|height)\s*=\s*(?:["'][^"']*["']|\S+)/gi, '');
    }
    if (themed && /(?:^|\s)style\s*=\s*(["'])/i.test(attributes)) return `<${name}${attributes}${slash}>`;
    return `<${name}${addStyle(attributes, style)}${slash}>`;
  });
  return html;
}

module.exports = { articleHtml };
