<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;

final class Com_CsoverridecheckerInstallerScript
{
    private string $minimumJoomla = '5.0.0';
    private string $minimumPhp    = '8.1.0';

    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        if (version_compare(PHP_VERSION, $this->minimumPhp, '<')) {
            Log::add(
                Text::sprintf('COM_CSOVERRIDECHECKER_ERROR_PHP_VERSION', $this->minimumPhp, PHP_VERSION),
                Log::WARNING,
                'jerror'
            );
            return false;
        }

        if (version_compare(JVERSION, $this->minimumJoomla, '<')) {
            Log::add(
                Text::sprintf('COM_CSOVERRIDECHECKER_ERROR_JOOMLA_VERSION', $this->minimumJoomla, JVERSION),
                Log::WARNING,
                'jerror'
            );
            return false;
        }

        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        // Joomla also calls postflight() on uninstall. Without this guard
        // the admin gets the "installed, click to open dashboard" card
        // right after hitting Uninstall — at best confusing, at worst
        // linking to a route that no longer exists.
        if (!\in_array($type, ['install', 'update', 'discover_install'], true)) {
            return true;
        }

        // Idempotent schema upgrades. install.mysql.utf8.sql carries
        // the current schema for fresh installs; for existing rows,
        // adding columns has to happen here so we don't depend on the
        // version-comparison-driven <schemas> path running.
        try {
            $this->ensureSchemaUpToDate();
        } catch (\Throwable $e) {
            Log::add('csoverridechecker schema upgrade failed: ' . $e->getMessage(), Log::WARNING, 'jerror');
        }

        try {
            $this->showPostInstallMessage($type);
        } catch (\Throwable $e) {
            // Never block install on a postflight UI failure.
        }
        return true;
    }

    /**
     * Add columns we need that older installs are missing. Each
     * upgrade step is guarded by a "does the column already exist?"
     * check, so this is safe to run on every postflight regardless
     * of which version we're upgrading from.
     */
    private function ensureSchemaUpToDate(): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        // 1.0.5 (chat-with-Claude): #__csoverridechecker_sessions.messages
        $sessionsTable = $db->replacePrefix('#__csoverridechecker_sessions');
        $db->setQuery(
            'SHOW COLUMNS FROM ' . $db->quoteName($sessionsTable) . ' LIKE ' . $db->quote('messages')
        );
        if ($db->loadResult() === null) {
            $db->setQuery(
                'ALTER TABLE ' . $db->quoteName($sessionsTable)
                . ' ADD COLUMN ' . $db->quoteName('messages') . ' LONGTEXT NULL'
                . ' AFTER ' . $db->quoteName('report_markdown')
            );
            $db->execute();
        }
    }

    public function uninstall(InstallerAdapter $adapter): bool
    {
        return true;
    }

    protected function showPostInstallMessage(string $type): void
    {
        $messageKey = $type === 'update'
            ? 'COM_CSOVERRIDECHECKER_POSTINSTALL_UPDATED'
            : 'COM_CSOVERRIDECHECKER_POSTINSTALL_INSTALLED';
        $url = 'index.php?option=com_csoverridechecker&view=dashboard';

        // Translated language strings are echoed escaped — the
        // post-install message is rendered into Joomla's installer
        // output frame, and even strings we control today shouldn't
        // be templated as raw HTML, since a future translation file
        // could carry markup that breaks the layout.
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        echo '<div class="card mb-3" style="margin: 20px 0;">'
            . '<div class="card-body">'
            . '<h3 class="card-title">' . $h(Text::_('COM_CSOVERRIDECHECKER')) . '</h3>'
            . '<p class="card-text">' . $h(Text::_($messageKey)) . '</p>'
            . '<a href="' . $h($url) . '" class="btn btn-primary text-white">'
            . '<span class="icon-dashboard" aria-hidden="true"></span> '
            . $h(Text::_('COM_CSOVERRIDECHECKER_POSTINSTALL_OPEN'))
            . '</a>'
            . '</div></div>';
    }
}
