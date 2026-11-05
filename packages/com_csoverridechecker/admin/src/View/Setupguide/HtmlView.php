<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Administrator\View\Setupguide;

defined('_JEXEC') or die;

use Cybersalt\Component\Csoverridechecker\Administrator\Helper\AiClientFactory;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Uri\Uri;

/**
 * "Which AI can I use?" setup guide.
 *
 * The review workflow is deliberately assistant-agnostic: everything the
 * extension exposes is a plain REST API behind a Joomla API token, so any
 * client that can issue an HTTPS request with a custom header can drive it.
 * This view exists so that fact is discoverable from inside the admin,
 * rather than only in the online documentation.
 */
final class HtmlView extends BaseHtmlView
{
    /** Base URL of this site's Override Checker API. */
    public string $apiBase = '';

    /** Whether a Joomla API token is saved in Options. */
    public bool $hasJoomlaToken = false;

    /** Whether the selected AI provider has a key saved (i.e. Method 2 is available). */
    public bool $hasApiKey = false;

    /** Display name of the AI provider selected in Options. */
    public string $aiProviderLabel = '';

    /** Link back to the dashboard where the prompt lives. */
    public string $dashboardUrl = '';

    /** Full-page com_config URL for this component's Options. */
    public string $optionsUrl = '';

    /** Online documentation URL. */
    public string $docsUrl = 'https://docs.cybersalt.com/extensions/override-checker';

    public function display($tpl = null): void
    {
        // Consistent with every other view in this component: Joomla's
        // dispatcher checks core.manage, requireView() enforces this
        // component's own csoverridechecker.view action.
        \Cybersalt\Component\Csoverridechecker\Administrator\Helper\PermissionHelper::requireView();

        $params = ComponentHelper::getParams('com_csoverridechecker');

        $this->apiBase = rtrim(Uri::root(), '/')
            . '/api/index.php/v1/csoverridechecker';

        $this->hasJoomlaToken = trim((string) $params->get('joomla_api_token', '')) !== '';
        $this->hasApiKey       = AiClientFactory::hasKey();
        $this->aiProviderLabel = AiClientFactory::labelFor();

        $this->dashboardUrl = Route::_('index.php?option=com_csoverridechecker&view=dashboard', false);

        $this->optionsUrl = Route::_(
            'index.php?option=com_config&view=component&component=com_csoverridechecker'
            . '&return=' . base64_encode('index.php?option=com_csoverridechecker&view=setupguide'),
            false
        );

        HTMLHelper::_('stylesheet', 'com_csoverridechecker/dashboard.css', ['relative' => true, 'version' => 'auto']);
        HTMLHelper::_('script', 'com_csoverridechecker/dashboard.js', ['relative' => true, 'version' => 'auto', 'defer' => true]);

        $this->addToolbar();

        parent::display($tpl);
    }

    private function addToolbar(): void
    {
        ToolbarHelper::title(
            Text::_('COM_CSOVERRIDECHECKER_SETUPGUIDE_TITLE'),
            'lightbulb'
        );

        $toolbar = $this->getDocument()->getToolbar();

        $toolbar->linkButton('dashboard', 'COM_CSOVERRIDECHECKER_TOOLBAR_DASHBOARD')
            ->url($this->dashboardUrl)
            ->icon('icon-home');

        $toolbar->linkButton('support', 'COM_CSOVERRIDECHECKER_TOOLBAR_SUPPORT')
            ->url(Route::_('index.php?option=com_csoverridechecker&view=support', false))
            ->icon('icon-help');

        ToolbarHelper::preferences('com_csoverridechecker');
    }
}
