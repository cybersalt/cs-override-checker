<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Write-side operations against the override tracker. Read-only
 * listings live in the OverridesModel (admin) and the API model.
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

final class OverridesHelper
{
    /**
     * Apply a fix to an override file by writing new contents to it.
     *
     * Always takes a fresh backup of the live file's current state
     * BEFORE overwriting, so the operation is reversible. Refuses to
     * write outside JPATH_ROOT.
     *
     * @return array{
     *     override_id: int,
     *     path: string,
     *     pre_fix_backup_id: int,
     *     bytes_written: int
     * }
     */
    public static function applyFix(int $overrideId, string $newContents, ?int $sessionId = null): array
    {
        if ($newContents === '') {
            throw new \RuntimeException('Refusing to apply an empty fix; provide new contents.');
        }

        // Hard byte cap on the patched contents. BackupsHelper's MAX_SIZE
        // capped the *current* state being snapshotted, not the new
        // bytes being written — without this, a write-tier user could
        // POST multi-GB JSON to /apply-fix and have it land on disk.
        PathSafetyHelper::assertSizeAllowed($newContents);

        $row = self::loadOverride($overrideId);
        if ($row === null) {
            throw new \RuntimeException(sprintf('Override #%d not found.', $overrideId));
        }

        $clientId = (int) $row->client_id;
        $template = (string) $row->template;
        $hashId   = (string) $row->hash_id;

        $absolute = PathResolver::overridePath($template, $hashId, $clientId);
        if ($absolute === null) {
            throw new \RuntimeException('Could not resolve the override file path from this row.');
        }

        // Two complementary checks:
        //   1. assertWithinRoot — realpath containment, defends against
        //      symlink/.. escape from JPATH_ROOT.
        //   2. assertOverrideWriteAllowed — positive allow-list, refuses
        //      anything outside templates/<tpl>/html/ regardless of
        //      extension. So a hostile #__template_overrides row that
        //      decoded to e.g. /html/../../../components/com_foo/bar.css
        //      is refused, even though .css would have passed the v2.1
        //      extension-only check.
        $safety   = PathSafetyHelper::assertWithinRoot($absolute);
        PathSafetyHelper::assertOverrideWriteAllowed($absolute);
        $rootReal = $safety['rootReal'];

        if (!is_file($absolute)) {
            throw new \RuntimeException('Override file does not exist on disk; nothing to fix.');
        }

        $relativePath = ltrim(substr($absolute, strlen($rootReal)), '/\\');

        // Pre-fix backup of current contents — keeps the operation reversible.
        $currentContents   = (string) @file_get_contents($absolute);
        $preFixBackupId    = BackupsHelper::createFromContents($relativePath, $currentContents, $sessionId);

        $written = file_put_contents($absolute, $newContents);
        if ($written === false) {
            throw new \RuntimeException(sprintf('Could not write to %s. Check filesystem permissions.', $relativePath));
        }

        PathSafetyHelper::invalidateOpcacheIfPhp($absolute);

        ActionLogHelper::log(
            ActionLogHelper::ACTION_FIX_APPLIED,
            [
                'override_id'       => $overrideId,
                'path'              => $relativePath,
                'pre_fix_backup_id' => $preFixBackupId,
                'bytes_written'     => $written,
            ],
            $sessionId
        );

        return [
            'override_id'        => $overrideId,
            'path'               => $relativePath,
            'pre_fix_backup_id'  => $preFixBackupId,
            'bytes_written'      => (int) $written,
        ];
    }

    /**
     * Delete a single override-tracker row. Equivalent to clicking
     * "Dismiss" on one row in Joomla's Templates admin.
     */
    public static function dismissOne(int $overrideId): bool
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__template_overrides'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $overrideId, ParameterType::INTEGER);

        $db->setQuery($query)->execute();
        $affected = (int) $db->getAffectedRows();

        if ($affected > 0) {
            ActionLogHelper::log(
                ActionLogHelper::ACTION_OVERRIDE_DISMISSED,
                ['override_id' => $overrideId]
            );
        }

        return $affected > 0;
    }

    private static function loadOverride(int $id): ?\stdClass
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'template', 'hash_id', 'client_id']))
            ->from($db->quoteName('#__template_overrides'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $row = $db->setQuery($query)->loadObject();
        return $row ?: null;
    }
}
