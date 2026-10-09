<?php

declare(strict_types=1);

namespace app\model;

use think\admin\Model;

/**
 * 会员收藏模型
 * @class MemberFavorite
 */
class MemberFavorite extends Model
{
    protected $name = 'member_favorite';
    protected $autoWriteTimestamp = false;
}
