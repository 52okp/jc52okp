<?php

declare(strict_types=1);

namespace app\model;

use think\admin\Model;

/**
 * 教程文章模型
 * @class Article
 * @table article
 */
class Article extends Model
{
    // 数据表名（无前缀）
    protected $name = 'article';

    // 关闭自动时间戳（create_at 由数据库默认值写入）
    protected $autoWriteTimestamp = false;

    // links 字段自动 JSON 编解码：读出为数组，写入自动编码
    protected $json = ['links'];
    protected $jsonAssoc = true;

    // 操作日志
    protected $oplogType = '教程文章管理';
    protected $oplogName = '教程文章';

    /**
     * 格式化创建时间（列表展示用）
     */
    public function getCreateAtAttr($value)
    {
        return $value ? date('Y-m-d H:i', strtotime($value)) : '';
    }
}
