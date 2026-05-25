<?php

/**
 * @package     Cstemplateintegrity
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Lightweight action log. Records administrative events to
 * #__cstemplateintegrity_actions so a future investigator can reconstruct
 * what happened on a site after a Claude review session.
 *
 * Action ids are strings; the recommended set is documented in the
 * class constants. Callers may use other values; the column accepts
 * any varchar(64).
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cstemplateintegrity\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Throwable;

final class ActionLogHelper
{
    public const ACTION_INSTALL          = 'install';
    public const ACTION_UPDATE           = 'update';
    public const ACTION_UNINSTALL        = 'uninstall';
    public const ACTION_RESCAN           = 'rescan';
    public const ACTION_MARK_REVIEWED    = 'mark_reviewed';
    public const ACTION_SESSION_CREATED  = 'session_created';
    public const ACTION_SESSION_DELETED  = 'session_deleted';
    public const ACTION_BACKUP_CREATED   = 'backup_created';
    public const ACTION_BACKUP_RESTORED  = 'backup_restored';
    public const ACTION_BACKUP_PURGED    = 'backup_purged';
    public const ACTION_FIX_APPLIED      = 'fix_applied';
    public const ACTION_OVERRIDE_DISMISSED = 'override_dismissed';
    public const ACTION_DISCLAIMER_ACKNOWLEDGED = 'disclaimer_acknowledged';
    public const ACTION_AUTO_SCAN_RUN    = 'auto_scan_run';

    /**
     * @param  array<string,mixed>  $details  arbitrary metadata; JSON-encoded into the row
     */
    public static function log(string $action, array $details = [], ?int $sessionId = null): void
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $row = (object) [
                'session_id' => $sessionId,
                'action'     => $action,
                'details'    => empty($details) ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'user_id'    => self::currentUserId(),
                'created_at' => Factory::getDate()->toSql(),
            ];

            $db->insertObject('#__cstemplateintegrity_actions', $row);
        } catch (Throwable $e) {
            // Logging must never crash the parent operation. Swallow.
        }
    }

    /**
     * Count how many times the current user has logged a given action
     * within the last $secondsAgo seconds. Used by the per-hour scan
     * cap on `runScan` to prevent a write-tier user (or a CSRF-coerced
     * admin who somehow passes the form-token check) from rapid-firing
     * the Anthropic API.
     *
     * Returns 0 on database error or when the current user can't be
     * resolved — this is a soft cap, not an authentication gate.
     */
    public static function countActionsByCurrentUserSince(string $action, int $secondsAgo): int
    {
        try {
            $userId = self::currentUserId();
            if ($userId <= 0) {
                return 0;
            }

            $secondsAgo = max(1, $secondsAgo);
            $cutoff = Factory::getDate('-' . $secondsAgo . ' seconds')->toSql();

            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__cstemplateintegrity_actions'))
                ->where($db->quoteName('action')     . ' = :action')
                ->where($db->quoteName('user_id')    . ' = :uid')
                ->where($db->quoteName('created_at') . ' >= :cutoff')
                ->bind(':action', $action)
                ->bind(':uid',    $userId, ParameterType::INTEGER)
                ->bind(':cutoff', $cutoff);

            return (int) $db->setQuery($query)->loadResult();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private static function currentUserId(): int
    {
        try {
            $app  = Factory::getApplication();
            $user = $app->getIdentity();
            return $user ? (int) $user->id : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }
}
