<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\model\Member as MemberModel;
use think\admin\Controller;
use think\admin\helper\QueryHelper;

/**
 * 会员列表
 * @class Member
 */
class Member extends Controller
{
    /**
     * 会员列表
     * @auth true
     * @menu true
     */
    public function index()
    {
        MemberModel::mQuery()->layTable(function () {
            $this->title = '会员列表';
        }, function (QueryHelper $query) {
            $query->like('nickname,openid')->equal('status')->dateBetween('create_at');
        });
    }

    /**
     * 修改会员状态
     * @auth true
     */
    public function state()
    {
        MemberModel::mSave($this->_vali([
            'status.in:0,1'  => '状态值范围异常！',
            'status.require' => '状态值不能为空！',
        ]));
    }
}
