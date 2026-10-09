<?php

declare(strict_types=1);

namespace app\model;

use think\admin\Model;

/**
 * 会员模型
 * @class Member
 */
class Member extends Model
{
    protected $name = 'member';
    protected $autoWriteTimestamp = false;
    protected $oplogType = '会员管理';
    protected $oplogName = '会员';

    public function getCreateAtAttr($value)
    {
        return $value ? date('Y-m-d H:i', strtotime($value)) : '';
    }

    public function getLoginAtAttr($value)
    {
        return $value ? date('Y-m-d H:i', strtotime($value)) : '';
    }
}
