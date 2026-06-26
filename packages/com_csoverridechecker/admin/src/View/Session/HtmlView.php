<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Administrator\View\Session;

defined('_JEXEC') or die;

use Cybersalt\Component\Csoverridechecker\Administrator\Helper\ActionsHelper;
use Cybersalt\Component\Csoverridechecker\Administrator\Helper\PermissionHelper;
use Cybersalt\Component\Csoverridechecker\Administrator\Helper\SessionsHelper;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\GenericDataException;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\ToolbarHelper;

final class HtmlView extends BaseHtmlView
{
    public ?\stdClass $session = null;

    /** @var list<\stdClass> */
    public array $actions = [];

    public string $backUrl = '';

    public string $backLabelKey = 'COM_CSOVERRIDECHECKER_SESSION_BACK_TO_LIST';

    public string $downloadUrl = '';

    public string $continueAction = '';

    public bool $hasApiKey = false;

    /** @var array<int, array{role: string, content: mixed}> */
    public array $messages = [];

    public function display($tpl = null): void
    {
        PermissionHelper::requireView();

        $id = (int) Factory::getApplication()->getInput()->getInt('id', 0);
        if ($id <= 0) {
            throw new GenericDataException(Text::_('COM_CSOVERRIDECHECKER_SESSION_NOT_FOUND'), 404);
        }

        $this->session = SessionsHelper::find($id);
        if ($this->session === null) {
            throw new GenericDataException(Text::_('COM_CSOVERRIDECHECKER_SESSION_NOT_FOUND'), 404);
        }

        $this->actions        = ActionsHelper::listForSession($id);
        $this->downloadUrl    = Route::_('index.php?option=com_csoverridechecker&task=session.download&id=' . $id . '&' . Session::getFormToken() . '=1', false);
        $this->continueAction = Route::_('index.php?option=com_csoverridechecker', false);
        $this->hasApiKey      = trim((string) ComponentHelper::getParams('com_csoverridechecker')->get('anthropic_api_key', '')) !== '';
        $this->messages       = SessionsHelper::getMessages($this->session);

        // Back-button destination depends on where the user came from.
        // Pages that link to a session pass &from=<view> in the URL;
        // any unknown / missing value falls through to the sessions list.
        $from = (string) Factory::getApplication()->getInput()->getCmd('from', '');

        switch ($from) {
            case 'actions':
                $this->backUrl      = Route::_('index.php?option=com_csoverridechecker&view=actions', false);
                $this->backLabelKey = 'COM_CSOVERRIDECHECKER_SESSION_BACK_TO_ACTIONS';
                break;

            case 'backups':
                $this->backUrl      = Route::_('index.php?option=com_csoverridechecker&view=backups', false);
                $this->backLabelKey = 'COM_CSOVERRIDECHECKER_SESSION_BACK_TO_BACKUPS';
                break;

            case 'dashboard':
                $this->backUrl      = Route::_('index.php?option=com_csoverridechecker&view=dashboard', false);
                $this->backLabelKey = 'COM_CSOVERRIDECHECKER_SESSION_BACK_TO_DASHBOARD';
                break;

            default:
                $this->backUrl      = Route::_('index.php?option=com_csoverridechecker&view=sessions', false);
                $this->backLabelKey = 'COM_CSOVERRIDECHECKER_SESSION_BACK_TO_LIST';
                break;
        }

        HTMLHelper::_('stylesheet', 'com_csoverridechecker/dashboard.css', ['relative' => true, 'version' => 'auto']);
        HTMLHelper::_('script', 'com_csoverridechecker/dashboard.js', ['relative' => true, 'version' => 'auto', 'defer' => true]);

        ToolbarHelper::title(
            Text::sprintf('COM_CSOVERRIDECHECKER_SESSION_TITLE', $this->escape($this->session->name)),
            'eye'
        );

        parent::display($tpl);
    }
}
