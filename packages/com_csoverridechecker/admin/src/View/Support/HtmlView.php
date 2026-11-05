<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Administrator\View\Support;

defined('_JEXEC') or die;

use Cybersalt\Component\Csoverridechecker\Administrator\Helper\AiClientFactory;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Uri\Uri;

/**
 * Built-in support area.
 *
 * Required of every Cybersalt extension by the wishlist ("every extension
 * exposes a Get help link visible from the main admin view").
 *
 * This extension has no Pro tier, so unlike the MCP-for-Joomla equivalent
 * there is no licence banner and no paid-priority path — just the feedback
 * channels, an honest statement about response times, and a pre-built
 * diagnostics block the user can copy into a report so we can skip the
 * back-and-forth about versions.
 */
final class HtmlView extends BaseHtmlView
{
    public string $componentVersion = '';

    public string $joomlaVersion = '';

    public string $phpVersion = '';

    public string $siteUrl = '';

    public bool $hasApiKey = false;

    public bool $hasJoomlaToken = false;

    /** Pre-formatted block the user can paste into an issue report. */
    public string $diagnosticsBlock = '';

    public string $issuesUrl = 'https://github.com/cybersalt/cs-override-checker/issues';

    public string $repoUrl = 'https://github.com/cybersalt/cs-override-checker';

    public string $docsUrl = 'https://docs.cybersalt.com/extensions/override-checker';

    public string $websiteUrl = 'https://www.cybersalt.com/';

    public function display($tpl = null): void
    {
        // Consistent with every other view in this component: Joomla's
        // dispatcher checks core.manage, requireView() enforces this
        // component's own csoverridechecker.view action.
        \Cybersalt\Component\Csoverridechecker\Administrator\Helper\PermissionHelper::requireView();

        $params = ComponentHelper::getParams('com_csoverridechecker');

        $this->componentVersion = $this->resolveVersion();
        $this->joomlaVersion    = JVERSION;
        $this->phpVersion       = PHP_VERSION;
        $this->siteUrl          = rtrim(Uri::root(), '/');

        $provider             = AiClientFactory::currentProvider();
        $this->hasApiKey      = AiClientFactory::hasKey($provider);
        $this->hasJoomlaToken = trim((string) $params->get('joomla_api_token', '')) !== '';

        // Deliberately contains no secrets - only presence flags, never the
        // keys themselves, so the block is safe to paste into a public issue.
        $this->diagnosticsBlock = implode("\n", [
            'Extension:      Cybersalt Override Checker ' . $this->componentVersion,
            'Joomla:         ' . $this->joomlaVersion,
            'PHP:            ' . $this->phpVersion,
            'Site URL:       ' . $this->siteUrl,
            'AI provider:    ' . AiClientFactory::labelFor($provider),
            'Provider key:   ' . ($this->hasApiKey ? 'saved' : 'not saved'),
            'Joomla token:   ' . ($this->hasJoomlaToken ? 'saved in Options' : 'not saved'),
        ]);

        HTMLHelper::_('stylesheet', 'com_csoverridechecker/dashboard.css', ['relative' => true, 'version' => 'auto']);
        HTMLHelper::_('script', 'com_csoverridechecker/dashboard.js', ['relative' => true, 'version' => 'auto', 'defer' => true]);

        $this->addToolbar();

        parent::display($tpl);
    }

    private function resolveVersion(): string
    {
        $db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);

        $query = $db->getQuery(true)
            ->select($db->quoteName('manifest_cache'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_csoverridechecker'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'));

        $db->setQuery($query);
        $cache = (string) $db->loadResult();

        if ($cache !== '') {
            $decoded = json_decode($cache, true);

            if (\is_array($decoded) && !empty($decoded['version'])) {
                return (string) $decoded['version'];
            }
        }

        return '?';
    }

    private function addToolbar(): void
    {
        ToolbarHelper::title(
            Text::_('COM_CSOVERRIDECHECKER_SUPPORT_TITLE'),
            'help'
        );

        $toolbar = $this->getDocument()->getToolbar();

        $toolbar->linkButton('dashboard', 'COM_CSOVERRIDECHECKER_TOOLBAR_DASHBOARD')
            ->url(Route::_('index.php?option=com_csoverridechecker&view=dashboard', false))
            ->icon('icon-home');

        $toolbar->linkButton('setupguide', 'COM_CSOVERRIDECHECKER_TOOLBAR_SETUPGUIDE')
            ->url(Route::_('index.php?option=com_csoverridechecker&view=setupguide', false))
            ->icon('icon-lightbulb');

        ToolbarHelper::preferences('com_csoverridechecker');
    }
}
