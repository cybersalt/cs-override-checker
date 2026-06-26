<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Bulk-clears every row in `#__template_overrides`. Equivalent to
 * Joomla's own "Dismiss All" admin action. Used after a user has
 * reviewed flagged overrides (typically with Claude's help) and
 * accepts responsibility for them.
 *
 * Inverse of RescanHelper. The two together let an admin cycle the
 * tracker between empty and fully-populated states.
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

final class MarkReviewedHelper
{
    public static function clearAllOverrides(): int
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $countQuery = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__template_overrides'));
        $count = (int) $db->setQuery($countQuery)->loadResult();

        if ($count === 0) {
            return 0;
        }

        $deleteQuery = $db->getQuery(true)
            ->delete($db->quoteName('#__template_overrides'));
        $db->setQuery($deleteQuery)->execute();

        ActionLogHelper::log(ActionLogHelper::ACTION_MARK_REVIEWED, ['cleared' => $count]);

        return $count;
    }
}
