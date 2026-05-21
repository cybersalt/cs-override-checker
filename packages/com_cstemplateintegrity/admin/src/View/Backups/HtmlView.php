<?php

/**
 * @package     Cstemplateintegrity
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cstemplateintegrity\Administrator\View\Backups;

defined('_JEXEC') or die;

use Cybersalt\Component\Cstemplateintegrity\Administrator\Helper\BackupsHelper;
use Cybersalt\Component\Cstemplateintegrity\Administrator\Helper\PermissionHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

final class HtmlView extends BaseHtmlView
{
    /** @var list<\stdClass> */
    public array $items = [];

    /** @var array{search: string, session_id: string, sort: string, dir: string, limit: int} */
    public array $filter = [
        'search'     => '',
        'session_id' => '',
        'sort'       => 'saved',
        'dir'        => 'desc',
        'limit'      => 100,
    ];

    /** @var list<int> */
    public array $limitOptions = BackupsHelper::LIMIT_OPTIONS;

    /** @var list<array{id: int|string, label: string}> */
    public array $sessions = [];

    public bool $hasActiveFilter = false;

    public bool $canWrite = false;

    public function display($tpl = null): void
    {
        PermissionHelper::requireView();

        $input = Factory::getApplication()->getInput();

        $this->filter['search']     = trim((string) $input->getString('search', ''));
        $this->filter['session_id'] = trim((string) $input->getString('session_id', ''));
        $this->filter['sort']       = (string) $input->getCmd('sort', 'saved');
        $this->filter['dir']        = strtolower((string) $input->getCmd('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $this->filter['limit']      = (int) $input->getInt('limit', 100);

        if (!in_array($this->filter['limit'], $this->limitOptions, true)) {
            $this->filter['limit'] = 100;
        }

        $this->hasActiveFilter = $this->filter['search'] !== ''
            || $this->filter['session_id'] !== '';

        $this->canWrite = PermissionHelper::hasWrite();
        $this->sessions = BackupsHelper::distinctSessions();

        $this->items = BackupsHelper::listFiltered(
            [
                'search'     => $this->filter['search'],
                'session_id' => $this->filter['session_id'],
            ],
            $this->filter['sort'],
            $this->filter['dir'],
            $this->filter['limit']
        );

        $wa = $this->document->getWebAssetManager();
        $wa->useScript('bootstrap.collapse');
        $wa->useScript('choicesjs');
        $wa->useStyle('choicesjs');

        HTMLHelper::_('stylesheet', 'com_cstemplateintegrity/dashboard.css', ['relative' => true, 'version' => 'auto']);

        $this->addToolbar();
        parent::display($tpl);
    }

    private function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_CSTEMPLATEINTEGRITY_BACKUPS_TITLE'), 'archive');
        ToolbarHelper::deleteList('', 'backups.delete', 'JTOOLBAR_DELETE');
    }
}
