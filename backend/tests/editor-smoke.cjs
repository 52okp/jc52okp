const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '../public/static');
const server = http.createServer((req, res) => {
    if (req.url === '/favicon.ico') { res.statusCode=204; return res.end(); }
    if (req.url === '/') {
        res.setHeader('Content-Type', 'text/html; charset=utf-8');
        return res.end(`<link rel="stylesheet" href="/plugs/ckeditor5/ckeditor.css"><textarea id="editor"><p>原始内容</p></textarea>
<script src="/plugs/require/require.js"></script><script>
require.config({baseUrl:'/', paths:{jquery:'plugs/jquery/jquery.min','ckeditor5-core':'plugs/ckeditor5/core.umd','ckeditor5-zh':'plugs/ckeditor5/zh-cn.umd'}});
define('upload',[],function(){});
window.AdminUploadAdapter = function(){ this.event=function(){}; this.upload=function(files,done){done('https://example.invalid/image.png');}; };
require(['${process.env.EDITOR_ENTRY || 'plugs/ckeditor5/ckeditor'}'], function(){createEditor('#editor').then(function(editor){window.auditEditor=editor;}).catch(function(e){window.failure=String(e);});}, function(e){window.failure=String(e);});
</script>`);
    }
    const file = path.resolve(root, '.' + decodeURIComponent(req.url.split('?')[0]));
    if (!file.startsWith(root + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { res.statusCode=404; return res.end(); }
    res.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : 'application/javascript');
    fs.createReadStream(file).pipe(res);
});
(async()=>{
    await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
    let browser;
    try {
        browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe'});
        const page=await browser.newPage();
        const errors=[];
        page.on('pageerror',e=>errors.push(String(e)));
        page.on('console',m=>{ if(m.type()==='error') errors.push(m.text()); });
        await page.goto('http://127.0.0.1:'+server.address().port);
        try { await page.waitForFunction(()=>window.auditEditor || window.failure,{},{timeout:20000}); }
        catch(e){throw new Error(e.message+' '+errors.join('\n'));}
        assert.equal(await page.evaluate(()=>window.failure),undefined);
        const state=await page.evaluate(async()=>{
            const editor = window.auditEditor;
            editor.setData('<p><strong>审查修复</strong><img src="https://example.invalid/a.png"></p><table><tr><td>表格</td></tr></table>');
            const saved=document.querySelector('#editor').value;
            const adapter=editor.plugins.get('FileRepository').createUploadAdapter({file:Promise.resolve(new File(['x'],'x.png',{type:'image/png'}))});
            const uploaded=await adapter.upload();
            const source=editor.plugins.get('SourceEditing'); source.isSourceEditingMode=true; source.isSourceEditingMode=false;
            return {version:CKEDITOR_VERSION,saved,uploaded,data:editor.getData(),buttons:editor.ui.componentFactory.has('uploadVideo')};
        });
        assert.equal(state.version,'48.5.2');
        assert.match(state.saved,/审查修复/); assert.match(state.data,/<table/);
        assert.equal(state.uploaded.default,'https://example.invalid/image.png'); assert.equal(state.buttons,true);
        assert.equal(errors.filter(x=>!x.includes('ERR_NAME_NOT_RESOLVED')).length,0,errors.join('\n'));
        await page.evaluate(()=>window.auditEditor.destroy());
        console.log('Editor browser smoke passed: init, content, table, source mode, upload adapter, destroy');
    } finally {if(browser)await browser.close();server.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
