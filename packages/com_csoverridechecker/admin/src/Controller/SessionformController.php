<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Administrator\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;

final class SessionformController extends BaseController
{
    protected $default_view = 'sessionform';

    public function add(): void
    {
        $this->setRedirect(Route::_('index.php?option=com_csoverridechecker&view=sessionform', false));
    }

    public function cancel(): void
    {
        $this->setRedirect(Route::_('index.php?option=com_csoverridechecker&view=sessions', false));
    }
}
