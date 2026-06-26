<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Centralised authorisation checks for the API + admin controllers.
 *
 * Every endpoint that returns or mutates csoverridechecker data must call
 * either requireView() or requireWrite() before doing any work. The
 * helper resolves the current Joomla identity (token-authenticated for
 * API requests, session-authenticated for admin requests) and asserts
 * an ACL action defined in admin/access.xml.
 *
 * Super Users always pass. Anyone else must have either core.manage on
 * com_csoverridechecker OR the matching csoverridechecker.view / csoverridechecker.write
 * action explicitly granted to a group they belong to.
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

final class PermissionHelper
{
    public const COMPONENT = 'com_csoverridechecker';

    public const ACTION_VIEW  = 'csoverridechecker.view';
    public const ACTION_WRITE = 'csoverridechecker.write';

    /**
     * Throw if the current user cannot read csoverridechecker data.
     *
     * @throws \RuntimeException with a 403 marker for the controller to translate.
     */
    public static function requireView(): User
    {
        return self::requireAny([self::ACTION_VIEW, 'core.manage', 'core.admin']);
    }

    /**
     * Throw if the current user cannot mutate csoverridechecker-managed state
     * (apply fixes, restore backups, dismiss overrides, create sessions).
     *
     * @throws \RuntimeException with a 403 marker for the controller to translate.
     */
    public static function requireWrite(): User
    {
        return self::requireAny([self::ACTION_WRITE, 'core.manage', 'core.admin']);
    }

    /**
     * Non-throwing variant of requireWrite() — returns true if the
     * current user holds the write tier. Use this when the caller
     * needs to *conditionally* render write-only content (e.g. a saved
     * Joomla API token in the dashboard's prompt) rather than gate the
     * whole page. View-tier users get a placeholder; write-tier users
     * get the real value.
     */
    public static function hasWrite(): bool
    {
        $user = self::currentUser();
        if ($user === null || $user->guest) {
            return false;
        }

        foreach ([self::ACTION_WRITE, 'core.manage', 'core.admin'] as $action) {
            if ($user->authorise($action, self::COMPONENT)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $actions
     */
    private static function requireAny(array $actions): User
    {
        $user = self::currentUser();

        if ($user === null || $user->guest) {
            throw new \RuntimeException('AUTH_REQUIRED', 401);
        }

        foreach ($actions as $action) {
            if ($user->authorise($action, self::COMPONENT)) {
                return $user;
            }
        }

        throw new \RuntimeException('FORBIDDEN', 403);
    }

    private static function currentUser(): ?User
    {
        try {
            $app  = Factory::getApplication();
            $user = $app->getIdentity();
            if ($user instanceof User) {
                return $user;
            }
        } catch (\Throwable $e) {
            // fall through
        }

        try {
            return Factory::getUser();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
