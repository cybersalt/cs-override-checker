<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Api\View\Sessions;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

final class JsonapiView extends BaseApiView
{
    protected $fieldsToRenderList = [
        'id',
        'name',
        'summary',
        'source',
        'state',
        'created_by',
        'created_at',
    ];

    protected $fieldsToRenderItem = [
        'id',
        'name',
        'summary',
        'source',
        'state',
        'report_markdown',
        'created_by',
        'created_at',
        'modified_at',
    ];
}
