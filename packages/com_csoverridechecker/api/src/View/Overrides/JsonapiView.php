<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Api\View\Overrides;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

final class JsonapiView extends BaseApiView
{
    protected $fieldsToRenderList = [
        'id',
        'template',
        'hash_id',
        'extension_id',
        'state',
        'action',
        'client_id',
        'created_date',
        'modified_date',
    ];
}
