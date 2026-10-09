<?php

declare(strict_types=1);

namespace app\model;

use think\admin\Model;

/**
 * 轮播图模型
 * @class Banner
 */
class Banner extends Model
{
    protected $name = 'banner';
    protected $autoWriteTimestamp = false;
    protected $oplogType = '轮播图管理';
    protected $oplogName = '轮播图';

    public function getCreateAtAttr($value)
    {
        return $value ? date('Y-m-d H:i', strtotime($value)) : '';
    }
}
