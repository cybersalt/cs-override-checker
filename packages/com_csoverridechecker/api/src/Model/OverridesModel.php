<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Api\Model;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\DatabaseQuery;
use Joomla\Database\ParameterType;

final class OverridesModel extends ListModel
{
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id',
                'template',
                'client_id',
                'state',
                'extension_id',
                'action',
                'modified_date',
            ];
        }

        parent::__construct($config, $factory);
    }

    protected function populateState($ordering = null, $direction = null): void
    {
        $app     = Factory::getApplication();
        $filters = (array) $app->getInput()->get('filter', [], 'array');

        if (isset($filters['template']) && $filters['template'] !== '') {
            $this->setState('filter.template', (string) $filters['template']);
        }

        if (isset($filters['client_id']) && $filters['client_id'] !== '') {
            $this->setState('filter.client_id', (int) $filters['client_id']);
        }

        if (isset($filters['state']) && $filters['state'] !== '') {
            $this->setState('filter.state', (int) $filters['state']);
        }

        if (isset($filters['extension_id']) && $filters['extension_id'] !== '') {
            $this->setState('filter.extension_id', (int) $filters['extension_id']);
        }

        parent::populateState($ordering, $direction);
    }

    protected function getListQuery(): DatabaseQuery
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'template', 'hash_id', 'extension_id', 'state', 'action', 'client_id', 'created_date', 'modified_date']))
            ->from($db->quoteName('#__template_overrides'));

        $template = $this->getState('filter.template');
        if ($template !== null && $template !== '') {
            $query->where($db->quoteName('template') . ' = :template')
                ->bind(':template', $template);
        }

        $clientId = $this->getState('filter.client_id');
        if ($clientId !== null && $clientId !== '') {
            $clientId = (int) $clientId;
            $query->where($db->quoteName('client_id') . ' = :client_id')
                ->bind(':client_id', $clientId, ParameterType::INTEGER);
        }

        $state = $this->getState('filter.state');
        if ($state !== null && $state !== '') {
            $state = (int) $state;
            $query->where($db->quoteName('state') . ' = :state')
                ->bind(':state', $state, ParameterType::INTEGER);
        }

        $extensionId = $this->getState('filter.extension_id');
        if ($extensionId !== null && $extensionId !== '') {
            $extensionId = (int) $extensionId;
            $query->where($db->quoteName('extension_id') . ' = :extension_id')
                ->bind(':extension_id', $extensionId, ParameterType::INTEGER);
        }

        $query->order($db->quoteName('modified_date') . ' DESC');

        return $query;
    }
}
