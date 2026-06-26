<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Administrator\View\Sessions;

defined('_JEXEC') or die;

use Cybersalt\Component\Csoverridechecker\Administrator\Helper\PermissionHelper;
use Cybersalt\Component\Csoverridechecker\Administrator\Helper\SessionsHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

final class HtmlView extends BaseHtmlView
{
    /** @var list<\stdClass> */
    public array $items = [];

    public string $listOrder = 'created_at';

    public string $listDir = 'DESC';

    public function display($tpl = null): void
    {
        PermissionHelper::requireView();

        // Sortable columns. Both `order` and `dir` are validated again
        // inside SessionsHelper::listRecent against an explicit
        // whitelist; this read just normalises the values for the
        // template's column-header link rendering.
        $input = Factory::getApplication()->getInput();
        $this->listOrder = (string) $input->getCmd('order', 'created_at');
        $this->listDir   = strtoupper((string) $input->getCmd('dir', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $this->items = SessionsHelper::listRecent(200, $this->listOrder, $this->listDir);
        $this->addToolbar();
        parent::display($tpl);
    }

    private function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_CSOVERRIDECHECKER_SESSIONS_TITLE'), 'list');
        ToolbarHelper::addNew('sessionform.add', Text::_('COM_CSOVERRIDECHECKER_SESSIONS_NEW'));
        ToolbarHelper::custom(
            'sessions.downloadSelected',
            'download',
            '',
            Text::_('COM_CSOVERRIDECHECKER_SESSIONS_DOWNLOAD_SELECTED'),
            true   // requires at least one row to be checked
        );
        ToolbarHelper::deleteList('', 'sessions.delete', 'JTOOLBAR_DELETE');
    }
}
