<?php

declare(strict_types=1);

use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Allow adaptive password hashes and make member token lookup efficient. */
class HardenAuthentication extends Migrator
{
    public function up()
    {
        $this->table('system_user')
            ->changeColumn('password', 'string', ['limit' => 255, 'default' => '', 'null' => true])
            ->update();

        $members = $this->table('member');
        if (!$members->hasIndex(['token'])) {
            $members->addIndex(['token'], ['name' => 'idx_member_token'])->update();
        }
    }

    public function down()
    {
        throw new IrreversibleMigrationException('密码哈希不可安全缩回 32 位');
    }
}
