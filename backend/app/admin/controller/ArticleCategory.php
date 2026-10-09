<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\model\ArticleCategory as CategoryModel;
use think\admin\Controller;
use think\admin\helper\QueryHelper;

/**
 * 文章分类管理
 * @class ArticleCategory
 */
class ArticleCategory extends Controller
{
    /**
     * 文章分类管理
     * @auth true
     * @menu true
     */
    public function index()
    {
        CategoryModel::mQuery()->layTable(function () {
            $this->title = '文章分类管理';
        }, function (QueryHelper $query) {
            $query->where(['is_deleted' => 0])->like('name')->equal('status');
        });
    }

    /**
     * 添加分类
     * @auth true
     */
    public function add()
    {
        CategoryModel::mForm('form');
    }

    /**
     * 编辑分类
     * @auth true
     */
    public function edit()
    {
        CategoryModel::mForm('form');
    }

    /**
     * 修改状态
     * @auth true
     */
    public function state()
    {
        CategoryModel::mSave($this->_vali([
            'status.in:0,1'  => '状态值范围异常！',
            'status.require' => '状态值不能为空！',
        ]));
    }

    /**
     * 删除分类
     * @auth true
     */
    public function remove()
    {
        CategoryModel::mDelete();
    }

    protected function _form_filter(array &$data)
    {
        if ($this->request->isPost()) {
            empty($data['name']) && $this->error('分类名称不能为空！');
        }
    }
}
