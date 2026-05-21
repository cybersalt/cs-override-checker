<?php

/**
 * @package     Cstemplateintegrity
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cstemplateintegrity\Administrator\View\Actions;

defined('_JEXEC') or die;

use Cybersalt\Component\Cstemplateintegrity\Administrator\Helper\ActionsHelper;
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

    /** @var array{search: string, action_type: string, session_id: string, sort: string, dir: string, limit: int} */
    public array $filter = [
        'search'      => '',
        'action_type' => '',
        'session_id'  => '',
        'sort'        => 'time',
        'dir'         => 'desc',
        'limit'       => 100,
    ];

    /** @var list<string> */
    public array $actionTypes = [];

    /** @var list<array{id: int|string, label: string}> */
    public array $sessions = [];

    /** @var list<int> */
    public array $limitOptions = ActionsHelper::LIMIT_OPTIONS;

    public bool $hasActiveFilter = false;

    public function display($tpl = null): void
    {
        // ACL gate. Joomla's outer core.manage check lets a user with
        // admin access on another component reach this view by URL —
        // requireView() enforces the granular cstemplateintegrity.view
        // action declared in admin/access.xml.
        PermissionHelper::requireView();

        $input = Factory::getApplication()->getInput();

        $this->filter['search']      = trim((string) $input->getString('search', ''));
        $this->filter['action_type'] = trim((string) $input->getString('action_type', ''));
        $this->filter['session_id']  = trim((string) $input->getString('session_id', ''));
        $this->filter['sort']        = (string) $input->getCmd('sort', 'time');
        $this->filter['dir']         = strtolower((string) $input->getCmd('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $this->filter['limit']       = (int) $input->getInt('limit', 100);

        if (!in_array($this->filter['limit'], $this->limitOptions, true)) {
            $this->filter['limit'] = 100;
        }

        $this->hasActiveFilter = $this->filter['search'] !== ''
            || $this->filter['action_type'] !== ''
            || $this->filter['session_id'] !== '';

        $this->actionTypes = ActionsHelper::distinctActionTypes();
        $this->sessions    = ActionsHelper::distinctSessions();

        $this->items = ActionsHelper::listFiltered(
            [
                'search'      => $this->filter['search'],
                'action_type' => $this->filter['action_type'],
                'session_id'  => $this->filter['session_id'],
            ],
            $this->filter['sort'],
            $this->filter['dir'],
            $this->filter['limit']
        );

        // Register the assets the js-stools filter bar needs — Bootstrap
        // collapse for the advanced filter row, and Choices.js to wrap
        // every <select> the same way the Joomla core admin does. Without
        // these the filter bar still works, but the selects render at
        // their default Bootstrap height and lose the native chip / pill
        // feel.
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
        ToolbarHelper::title(Text::_('COM_CSTEMPLATEINTEGRITY_ACTIONS_TITLE'), 'list-2');
    }
}
