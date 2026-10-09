<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\model\Banner as BannerModel;
use think\admin\Controller;
use think\admin\helper\QueryHelper;

/**
 * 轮播图管理
 * @class Banner
 */
class Banner extends Controller
{
    /**
     * 轮播图管理
     * @auth true
     * @menu true
     */
    public function index()
    {
        BannerModel::mQuery()->layTable(function () {
            $this->title = '首页头图管理';
        }, function (QueryHelper $query) {
            $query->where(['is_deleted' => 0])->like('title')->equal('status');
        });
    }

    /**
     * 添加轮播图
     * @auth true
     */
    public function add()
    {
        BannerModel::mForm('form');
    }

    /**
     * 编辑轮播图
     * @auth true
     */
    public function edit()
    {
        BannerModel::mForm('form');
    }

    /**
     * 修改状态
     * @auth true
     */
    public function state()
    {
        BannerModel::mSave($this->_vali([
            'status.in:0,1'  => '状态值范围异常！',
            'status.require' => '状态值不能为空！',
        ]));
    }

    /**
     * 删除轮播图
     * @auth true
     */
    public function remove()
    {
        BannerModel::mDelete();
    }

    protected function _form_filter(array &$data)
    {
        if ($this->request->isPost()) {
            empty($data['image']) && $this->error('请上传轮播图片！');
            if (empty($data['article_id'])) $data['article_id'] = 0;
        }
    }
}
