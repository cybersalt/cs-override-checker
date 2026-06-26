<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Api\View\Backups;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

final class JsonapiView extends BaseApiView
{
    protected $fieldsToRenderList = [
        'id',
        'session_id',
        'file_path',
        'file_hash',
        'file_size',
        'created_by',
        'created_at',
    ];

    protected $fieldsToRenderItem = [
        'id',
        'session_id',
        'file_path',
        'file_hash',
        'file_size',
        'contents_b64',
        'created_by',
        'created_at',
    ];
}
