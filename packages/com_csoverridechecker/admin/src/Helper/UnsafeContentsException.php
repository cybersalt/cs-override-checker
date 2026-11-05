<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Administrator\Helper;

defined('_JEXEC') or die;

/** New override contents were refused by OverrideContentGuard. Nothing was written. */
final class UnsafeContentsException extends \RuntimeException
{
}
