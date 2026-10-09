<?php

declare(strict_types=1);

namespace app\model;

use think\admin\Model;

/**
 * 会员浏览历史模型
 * @class MemberHistory
 */
class MemberHistory extends Model
{
    protected $name = 'member_history';
    protected $autoWriteTimestamp = false;
}
