<?php

declare(strict_types=1);
use think\migration\Migrator;

/** Add constraints only; never merge identities or delete legacy records. */
class MemberUniqueKeys extends Migrator
{
    private const KEYS = [
        'member' => ['openid'],
        'member_favorite' => ['member_id', 'article_id'],
        'member_history' => ['member_id', 'article_id'],
    ];
    public function up()
    {
        // Check all tables before any DDL. Operators must resolve ambiguous legacy identities themselves.
        foreach (self::KEYS as $table => $columns) {
            $fields = implode(', ', $columns);
            if ($this->fetchRow("SELECT COUNT(*) AS n FROM $table GROUP BY $fields HAVING COUNT(*) > 1 LIMIT 1")) {
                throw new RuntimeException("Duplicate business keys in $table. Back up and reconcile before retrying; no records were deleted.");
            }
        }
        foreach (self::KEYS as $name => $columns) {
            $table = $this->table($name);
            $index = 'uq_' . $name . '_business';
            if (!$table->hasIndexByName($index)) $table->addIndex($columns, ['unique' => true, 'name' => $index])->update();
        }
        $history = $this->table('member_history');
        if (!$history->hasIndexByName('idx_history_member_time')) {
            $history->addIndex(['member_id', 'update_at', 'id'], ['name' => 'idx_history_member_time'])->update();
        }
    }
    public function down()
    {
        foreach (self::KEYS as $name => $columns) {
            $table = $this->table($name);
            $index = 'uq_' . $name . '_business';
            if ($table->hasIndexByName($index)) $table->removeIndexByName($index)->update();
        }
        $history = $this->table('member_history');
        if ($history->hasIndexByName('idx_history_member_time')) $history->removeIndexByName('idx_history_member_time')->update();
    }
}
