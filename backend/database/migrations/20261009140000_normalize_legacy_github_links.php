<?php

declare(strict_types=1);

use think\migration\Migrator;

/** Makes existing scheme-less GitHub resource links usable without editing old articles. */
class NormalizeLegacyGithubLinks extends Migrator
{
    public function up()
    {
        foreach ($this->fetchAll('SELECT id, links FROM article WHERE is_deleted = 0 AND links IS NOT NULL') as $row) {
            $links = json_decode((string)$row['links'], true);
            if (!is_array($links)) continue;
            $changed = false;
            foreach ($links as &$link) {
                if (!is_array($link) || !is_string($link['url'] ?? null)) continue;
                $url = trim($link['url']);
                if (preg_match('~^github\.com/[A-Za-z0-9._/-]+$~i', $url)) {
                    $link['url'] = 'https://' . $url;
                    $changed = true;
                }
            }
            unset($link);
            if ($changed) {
                $this->execute('UPDATE article SET links = ? WHERE id = ?', [
                    json_encode($links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    (int)$row['id'],
                ]);
            }
        }
    }

    public function down()
    {
        throw new RuntimeException('Legacy URL normalization is intentionally not reversible without the database backup');
    }
}
