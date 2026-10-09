<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\model\Article as ArticleModel;
use app\model\ArticleCategory;
use think\admin\Controller;
use think\admin\helper\QueryHelper;
use think\facade\Db;

/**
 * 教程文章管理
 * @class Article
 */
class Article extends Controller
{
    /**
     * 教程文章管理
     * @auth true
     * @menu true
     */
    public function index()
    {
        ArticleModel::mQuery()->layTable(function () {
            $this->title = '教程文章管理';
        }, function (QueryHelper $query) {
            $query->where(['is_deleted' => 0]);
            $query->like('title,tags')->equal('status')->dateBetween('create_at');
        });
    }

    /**
     * 添加文章
     * @auth true
     */
    public function add()
    {
        ArticleModel::mForm('form');
    }

    /**
     * 编辑文章
     * @auth true
     */
    public function edit()
    {
        ArticleModel::mForm('form');
    }

    /**
     * 解析上传的 Markdown 文件，返回可填入表单的字段
     * 支持可选 YAML frontmatter：title / tags / summary / category
     * 无 frontmatter 标题时，取正文第一个一级标题（# 标题）作为标题
     * @auth true
     */
    public function mdimport()
    {
        // 取文件内容（表单字段 file）或直接 POST 文本 text
        $md = '';
        $file = $this->request->file('file');
        if ($file) {
            $md = (string)file_get_contents($file->getRealPath());
        } else {
            $md = (string)$this->request->post('text', '');
        }
        // 去除 UTF-8 BOM，统一换行
        $md = preg_replace('/^\xEF\xBB\xBF/', '', $md);
        $md = str_replace(["\r\n", "\r"], "\n", $md);
        if (trim($md) === '') $this->error('未读取到 Markdown 内容！');

        $meta = ['title' => '', 'tags' => '', 'summary' => '', 'category' => ''];

        // 解析 frontmatter（文件开头 --- ... --- 之间的简单 key: value）
        if (preg_match('/^---\s*\n(.*?)\n---\s*\n?(.*)$/s', $md, $m)) {
            $body = $m[2];
            foreach (explode("\n", $m[1]) as $line) {
                if (preg_match('/^\s*([A-Za-z_]+)\s*:\s*(.*)$/', $line, $kv)) {
                    $key = strtolower(trim($kv[1]));
                    if (array_key_exists($key, $meta)) {
                        $meta[$key] = trim(trim($kv[2]), "\"'");
                    }
                }
            }
            $md = $body;
        }

        // 标题：frontmatter 没有就取正文第一个一级标题，并从正文移除该行
        if ($meta['title'] === '' && preg_match('/^[ \t]*#[ \t]+(.+?)[ \t]*$/m', $md, $hm)) {
            $meta['title'] = trim($hm[1]);
            $md = preg_replace('/^[ \t]*#[ \t]+.+?[ \t]*$\n?/m', '', $md, 1);
        }

        // Markdown -> HTML
        $parsedown = new \Parsedown();
        $parsedown->setBreaksEnabled(true);
        $html = $parsedown->text(trim($md));

        // 摘要兜底：取正文纯文本前 80 字
        if ($meta['summary'] === '' && $html !== '') {
            $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
            $meta['summary'] = mb_substr($text, 0, 80);
        }

        $this->success('解析成功', [
            'title'    => $meta['title'],
            'tags'     => $meta['tags'],
            'summary'  => $meta['summary'],
            'category' => $meta['category'],
            'content'  => $html,
        ]);
    }

    /**
     * 修改文章状态
     * @auth true
     */
    public function state()
    {
        ArticleModel::mSave($this->_vali([
            'status.in:0,1'   => '状态值范围异常！',
            'status.require'  => '状态值不能为空！',
        ]));
    }

    /**
     * 删除文章（软删除）
     * @auth true
     */
    public function remove()
    {
        // 表中存在 is_deleted 字段，mDelete 会自动软删除
        ArticleModel::mDelete();
    }

    /**
     * 表单数据处理
     */
    protected function _form_filter(array &$data)
    {
        if ($this->request->isPost()) {
            // 组装下载链接（并列数组 -> 结构化数组，交给模型 JSON 编码）
            $types = $this->request->post('link_type', []);
            $urls  = $this->request->post('link_url', []);
            $codes = $this->request->post('link_code', []);
            $links = [];
            foreach ((array)$urls as $i => $url) {
                $url = trim((string)$url);
                if ($url === '') continue;
                $links[] = [
                    'type' => trim((string)($types[$i] ?? '')),
                    'url'  => $url,
                    'code' => trim((string)($codes[$i] ?? '')),
                ];
            }
            $data['links'] = $links;
            // 清理非数据表字段，避免写库报错
            unset($data['link_type'], $data['link_url'], $data['link_code']);

            // 解压密码去除首尾空格（纯空格视为未填写）
            $data['unzip_code'] = trim((string)($data['unzip_code'] ?? ''));

            // 推荐为复选框，未勾选时补 0
            $data['is_recommend'] = isset($data['is_recommend']) ? 1 : 0;
            $data['category_id'] = intval($data['category_id'] ?? 0);

            $existingId = intval($data['id'] ?? 0);
            $existing = $existingId ? ArticleModel::mk()->where('id', $existingId)->findOrEmpty() : null;
            if ($existing && $existing->isExists() && $existing->source_type === 'wordpress') {
                // Source fields belong to WordPress. Local operations remain editable.
                unset($data['title'], $data['summary'], $data['content'], $data['cover'],
                    $data['tags'], $data['links'], $data['unzip_code'], $data['category_id'],
                    $data['source_type'], $data['source_state']);
            } else {
                empty($data['title']) && $this->error('文章标题不能为空！');
            }
        } else {
            $articleId = (int)($data['id'] ?? $this->request->get('id', 0));
            $this->wpSourceUrl = $articleId > 0
                ? (string)Db::name('wp_sync_post')->where('article_id', $articleId)->value('source_url')
                : '';
            // 编辑/新增表单：加载分类下拉
            $this->categories = ArticleCategory::mk()
                ->where(['is_deleted' => 0, 'status' => 1])
                ->order('sort desc,id desc')
                ->column('name', 'id');
        }
    }
}
