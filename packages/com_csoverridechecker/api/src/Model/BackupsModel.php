<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Api\Model;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\DatabaseQuery;

final class BackupsModel extends ListModel
{
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = ['id', 'session_id', 'created_at'];
        }

        parent::__construct($config, $factory);
    }

    protected function getListQuery(): DatabaseQuery
    {
        $db = $this->getDatabase();

        return $db->getQuery(true)
            ->select($db->quoteName(['id', 'session_id', 'file_path', 'file_hash', 'file_size', 'created_by', 'created_at']))
            ->from($db->quoteName('#__csoverridechecker_backups'))
            ->order($db->quoteName('created_at') . ' DESC');
    }
}
