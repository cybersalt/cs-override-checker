<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Pre-change file-snapshot helper. Stores a copy of a file's
 * contents in #__csoverridechecker_backups before a change is made, so
 * that "what was here before Claude rewrote it?" is answerable.
 *
 * v0.6: store + list. Restore-from-backup is intentionally deferred
 * because restoring arbitrary template / layout / plugin files is
 * destructive enough to deserve its own design pass.
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

// resolved at call site via use Joomla\Database\ParameterType when needed

final class BackupsHelper
{
    /**
     * Cap on a single backup row's contents. Aligned with
     * PathSafetyHelper::MAX_WRITE_SIZE (4 MB) so apply_fix can both
     * snapshot the current contents AND write the new contents on a
     * legitimately large override file. v2.1 capped at 1 MB which
     * silently failed apply_fix on real-world overrides bigger than
     * that.
     */
    public const MAX_SIZE = 4194304;

    public static function createFromContents(
        string $filePath,
        string $contents,
        ?int $sessionId = null,
        ?int $createdBy = null
    ): int {
        $size = strlen($contents);
        if ($size > self::MAX_SIZE) {
            throw new \RuntimeException(sprintf('Backup contents exceed the %d-byte cap.', self::MAX_SIZE));
        }

        $filePath = mb_substr($filePath, 0, 500);
        $hash     = hash('sha256', $contents);

        // Dedupe: if we already have a backup of this exact path+contents,
        // return its id instead of inserting a copy. Caller's audit-log
        // entry (e.g., fix_applied) will reference the existing backup,
        // which is still semantically correct — that snapshot already
        // captured this state.
        $existingId = self::findExistingByPathAndHash($filePath, $hash);
        if ($existingId !== null) {
            return $existingId;
        }

        $db  = Factory::getContainer()->get(DatabaseInterface::class);
        $now = Factory::getDate()->toSql();

        $row = (object) [
            'session_id'   => $sessionId,
            'file_path'    => $filePath,
            'file_hash'    => $hash,
            'file_size'    => $size,
            'contents_b64' => base64_encode($contents),
            'created_by'   => $createdBy ?? self::currentUserId(),
            'created_at'   => $now,
        ];

        $db->insertObject('#__csoverridechecker_backups', $row, 'id');

        $insertedId = (int) $row->id;

        ActionLogHelper::log(
            ActionLogHelper::ACTION_BACKUP_CREATED,
            ['id' => $insertedId, 'file_path' => $row->file_path, 'size' => $size, 'sha256' => $row->file_hash],
            $sessionId
        );

        return $insertedId;
    }

    private static function findExistingByPathAndHash(string $filePath, string $hash): ?int
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__csoverridechecker_backups'))
            ->where($db->quoteName('file_path') . ' = :path')
            ->where($db->quoteName('file_hash') . ' = :hash')
            ->bind(':path', $filePath)
            ->bind(':hash', $hash)
            ->order($db->quoteName('id') . ' ASC');

        $db->setQuery($query, 0, 1);
        $existingId = $db->loadResult();

        return $existingId !== null ? (int) $existingId : null;
    }

    /**
     * Delete backup rows older than $days, with optional safety floor
     * and dry-run. Drives the scheduled-task plugin
     * (plg_task_csoverridechecker) — see
     * TASKS_MAP['csoverridechecker.purgeBackups'].
     *
     * Parameters:
     *   $days     — retention window in days. Floored at 1: a zero
     *               or negative value would delete every row including
     *               the one we just created, which is never what the
     *               user wants and would silently break a restore flow
     *               that depends on a fresh pre-change snapshot.
     *   $minKeep  — keep this many most-recent rows regardless of age.
     *               A safety floor: if a user sets retention=30 days
     *               on a table whose 5 rows are all 6 months old,
     *               purging all of them at once kills their roll-back
     *               window. min_keep=5 says "never delete the 5
     *               newest". Default 0 = no floor.
     *   $dryRun   — if true, compute what WOULD be deleted but make
     *               no DB changes. Used to verify a new task config
     *               before turning it loose on real data.
     *
     * Logs ACTION_BACKUP_PURGED with the full result struct so the
     * Action log surfaces exactly what the cron job did.
     *
     * @return array{
     *   deleted: int, would_delete: int, kept_by_floor: int,
     *   retention_days: int, min_keep: int, cutoff: string, dry_run: bool
     * }
     */
    public static function purgeOlderThan(int $days, int $minKeep = 0, bool $dryRun = false): array
    {
        $days    = max(1, $days);
        $minKeep = max(0, $minKeep);

        $db     = Factory::getContainer()->get(DatabaseInterface::class);
        $cutoff = Factory::getDate('now')->sub(new \DateInterval('P' . $days . 'D'))->toSql();

        // Build the "always keep" set: the $minKeep most-recent rows
        // by created_at, with id as a tie-breaker so two rows in the
        // same second can't both fall out of the floor.
        $keptIds = [];
        if ($minKeep > 0) {
            $keepQ = $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__csoverridechecker_backups'))
                ->order($db->quoteName('created_at') . ' DESC')
                ->order($db->quoteName('id') . ' DESC');
            $db->setQuery($keepQ, 0, $minKeep);
            $keptIds = array_values(array_map('intval', $db->loadColumn() ?: []));
        }

        // Count what would be deleted (age-eligible AND not in floor).
        $countQ = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__csoverridechecker_backups'))
            ->where($db->quoteName('created_at') . ' < :cutoff')
            ->bind(':cutoff', $cutoff);
        if (!empty($keptIds)) {
            $countQ->whereNotIn($db->quoteName('id'), $keptIds);
        }
        $candidateCount = (int) ($db->setQuery($countQ)->loadResult() ?? 0);

        $result = [
            'deleted'        => 0,
            'would_delete'   => $candidateCount,
            'kept_by_floor'  => count($keptIds),
            'retention_days' => $days,
            'min_keep'       => $minKeep,
            'cutoff'         => $cutoff,
            'dry_run'        => $dryRun,
        ];

        if ($dryRun || $candidateCount === 0) {
            ActionLogHelper::log(ActionLogHelper::ACTION_BACKUP_PURGED, $result);
            return $result;
        }

        $deleteQ = $db->getQuery(true)
            ->delete($db->quoteName('#__csoverridechecker_backups'))
            ->where($db->quoteName('created_at') . ' < :cutoff')
            ->bind(':cutoff', $cutoff);
        if (!empty($keptIds)) {
            $deleteQ->whereNotIn($db->quoteName('id'), $keptIds);
        }
        $db->setQuery($deleteQ)->execute();

        $result['deleted'] = $candidateCount;
        ActionLogHelper::log(ActionLogHelper::ACTION_BACKUP_PURGED, $result);
        return $result;
    }

    /**
     * Delete a single backup row.
     */
    public static function delete(int $id): bool
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__csoverridechecker_backups'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $db->setQuery($query)->execute();
        return $db->getAffectedRows() > 0;
    }

    /**
     * Sortable column whitelist — keys are the URL-facing sort tokens,
     * values are the DB column names. Anything outside this list is
     * silently rejected by listFiltered() and falls back to created_at.
     *
     * @var array<string, string>
     */
    private const SORT_COLUMNS = [
        'saved'   => 'created_at',
        'session' => 'session_id',
        'file'    => 'file_path',
        'size'    => 'file_size',
    ];

    public const LIMIT_OPTIONS = [20, 50, 100, 200, 500];

    /**
     * @return list<\stdClass>
     */
    public static function listRecent(int $limit = 100): array
    {
        return self::listFiltered([], 'saved', 'desc', $limit);
    }

    /**
     * @param array{search?: string, session_id?: int|string} $filter
     * @return list<\stdClass>
     */
    public static function listFiltered(array $filter, string $sort = 'saved', string $dir = 'desc', int $limit = 100): array
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = self::buildBaseQuery($db, $filter);

        $column    = self::SORT_COLUMNS[$sort] ?? 'created_at';
        $direction = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $query->order($db->quoteName($column) . ' ' . $direction);
        // Secondary key keeps identical-timestamp rows deterministic.
        $query->order($db->quoteName('id') . ' ' . $direction);

        $limit = max(1, min(500, $limit));
        $db->setQuery($query, 0, $limit);

        return $db->loadObjectList() ?: [];
    }

    /**
     * Distinct session ids referenced from the backups table, joined
     * with the sessions table for the display name. Drives the
     * session-id filter dropdown so users can pick rather than type.
     *
     * @return list<array{id: int|string, label: string}>
     */
    public static function distinctSessions(): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $db->getQuery(true)
            ->select([
                'DISTINCT ' . $db->quoteName('b.session_id', 'id'),
                $db->quoteName('s.name', 'name'),
            ])
            ->from($db->quoteName('#__csoverridechecker_backups', 'b'))
            ->leftJoin(
                $db->quoteName('#__csoverridechecker_sessions', 's')
                . ' ON ' . $db->quoteName('s.id') . ' = ' . $db->quoteName('b.session_id')
            )
            ->where($db->quoteName('b.session_id') . ' IS NOT NULL')
            ->order($db->quoteName('b.session_id') . ' DESC');

        $rows = $db->setQuery($query)->loadObjectList() ?: [];

        $out = [];
        foreach ($rows as $row) {
            $id    = (int) $row->id;
            $name  = trim((string) ($row->name ?? ''));
            $label = $name !== '' ? '#' . $id . ' — ' . $name : '#' . $id;
            $out[] = ['id' => $id, 'label' => $label];
        }

        $orphanQuery = $db->getQuery(true)
            ->select('1')
            ->from($db->quoteName('#__csoverridechecker_backups'))
            ->where($db->quoteName('session_id') . ' IS NULL');

        if ($db->setQuery($orphanQuery, 0, 1)->loadResult() !== null) {
            array_unshift($out, ['id' => 'none', 'label' => '(no session)']);
        }

        return $out;
    }

    /**
     * @param array{search?: string, session_id?: int|string} $filter
     */
    private static function buildBaseQuery(DatabaseInterface $db, array $filter)
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'session_id', 'file_path', 'file_hash', 'file_size', 'created_by', 'created_at']))
            ->from($db->quoteName('#__csoverridechecker_backups'));

        $search = trim((string) ($filter['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where($db->quoteName('file_path') . ' LIKE :search');
            $query->bind(':search', $like);
        }

        $sessionFilter = (string) ($filter['session_id'] ?? '');
        if ($sessionFilter === 'none') {
            $query->where($db->quoteName('session_id') . ' IS NULL');
        } elseif ($sessionFilter !== '' && (int) $sessionFilter > 0) {
            $sid = (int) $sessionFilter;
            $query->where($db->quoteName('session_id') . ' = :sid');
            $query->bind(':sid', $sid, ParameterType::INTEGER);
        }

        return $query;
    }

    public static function find(int $id): ?\stdClass
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__csoverridechecker_backups'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $row = $db->setQuery($query)->loadObject();
        return $row ?: null;
    }

    public static function decodeContents(\stdClass $row): string
    {
        return base64_decode((string) ($row->contents_b64 ?? ''), true) ?: '';
    }

    /**
     * Restore a stored backup to its original file path.
     *
     * Before overwriting the live file, this method takes a fresh
     * backup of its CURRENT contents — so the restore operation is
     * itself reversible. Refuses to write outside of JPATH_ROOT and
     * refuses to write a PHP-executable file outside of a template
     * override path.
     *
     * @return array{backup_id: int, restored_path: string, pre_restore_backup_id: ?int, bytes_written: int}
     */
    public static function restore(int $id): array
    {
        $row = self::find($id);
        if ($row === null) {
            throw new \RuntimeException(sprintf('Backup #%d not found.', $id));
        }

        $relativePath = ltrim((string) $row->file_path, '/\\');
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            throw new \RuntimeException('Backup row has an invalid file_path.');
        }

        $absolute = JPATH_ROOT . '/' . $relativePath;

        // Two complementary checks:
        //   1. assertWithinRoot — realpath containment, defends against
        //      .. / symlink escape (separator-anchored str_starts_with
        //      so /var/www/joomla vs /var/www/joomla-bak doesn't bypass).
        //   2. assertOverrideWriteAllowed — positive allow-list, refuses
        //      writes outside templates/<tpl>/html/ regardless of
        //      extension. Closes the .htaccess / .user.ini / .css overwrite
        //      surface that survived v0.9.0's RCE hardening: archival
        //      backup rows from pre-v0.9.0 (or seeded via DB-level access)
        //      with arbitrary file_path values are now refused at restore.
        PathSafetyHelper::assertWithinRoot($absolute);
        PathSafetyHelper::assertOverrideWriteAllowed($absolute);

        $restoredContents = self::decodeContents($row);
        if ($restoredContents === '') {
            throw new \RuntimeException('Backup is empty; nothing to restore.');
        }

        // Cap on the bytes about to be written to disk. Belt-and-braces
        // — backup rows are already capped at MAX_SIZE on creation, but
        // pre-v2.2 rows were stored at 1 MB cap and the field type
        // (LONGTEXT) accepts up to ~4 GB. A row could in principle
        // contain more bytes than MAX_WRITE_SIZE; refuse before write.
        PathSafetyHelper::assertSizeAllowed($restoredContents);

        // Pre-restore safety backup of the current file state, so this
        // restore is itself reversible.
        $preRestoreBackupId = null;
        if (is_file($absolute)) {
            $currentContents    = (string) @file_get_contents($absolute);
            $preRestoreBackupId = self::createFromContents(
                $relativePath,
                $currentContents,
                (int) ($row->session_id ?? 0) ?: null
            );
        }

        $written = file_put_contents($absolute, $restoredContents);
        if ($written === false) {
            throw new \RuntimeException(sprintf('Could not write to %s. Check filesystem permissions.', $relativePath));
        }

        PathSafetyHelper::invalidateOpcacheIfPhp($absolute);

        ActionLogHelper::log(
            ActionLogHelper::ACTION_BACKUP_RESTORED,
            [
                'backup_id'             => $id,
                'restored_path'         => $relativePath,
                'pre_restore_backup_id' => $preRestoreBackupId,
                'bytes_written'         => $written,
            ],
            isset($row->session_id) ? (int) $row->session_id : null
        );

        return [
            'backup_id'             => $id,
            'restored_path'         => $relativePath,
            'pre_restore_backup_id' => $preRestoreBackupId,
            'bytes_written'         => (int) $written,
        ];
    }

    private static function currentUserId(): int
    {
        try {
            $app  = Factory::getApplication();
            $user = $app->getIdentity();
            return $user ? (int) $user->id : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
