<?php

/**
 * @package     Cstemplateintegrity
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Package-level installer for pkg_cstemplateintegrity. Auto-enables the
 * webservices plugin on install so the component's /v1/cstemplateintegrity/*
 * routes work immediately — otherwise Joomla would install the plugin
 * disabled and the endpoint would 404 until the admin flipped it on
 * manually.
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;

final class Pkg_CstemplateintegrityInstallerScript
{
    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        // Joomla also calls postflight() on uninstall. Skip everything but
        // install/update so we don't auto-enable a plugin that's about to
        // be removed and don't render an "installed, click here" card on
        // an uninstall.
        if (!\in_array($type, ['install', 'update', 'discover_install'], true)) {
            return true;
        }

        if ($type === 'install' || $type === 'update' || $type === 'discover_install') {
            $this->enableChildPlugin('webservices');
            $this->enableChildPlugin('task');
            $this->seedPurgeTask();
        }

        $this->showPostInstallMessage($type);

        return true;
    }

    public function uninstall(InstallerAdapter $adapter): bool
    {
        return true;
    }

    /**
     * Auto-enable a child plugin (webservices or task) on install/update.
     * Joomla installs third-party plugins disabled by default — without
     * this, plg_webservices_cstemplateintegrity 404s its API routes and
     * plg_task_cstemplateintegrity never appears in the Scheduled Tasks
     * type dropdown until the admin manually flips them on.
     */
    private function enableChildPlugin(string $folder): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        try {
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('cstemplateintegrity'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote($folder));

            $db->setQuery($query)->execute();
        } catch (\Throwable $e) {
            Log::add(
                sprintf('Could not auto-enable plg_%s_cstemplateintegrity: %s', $folder, $e->getMessage()),
                Log::WARNING,
                'pkg_cstemplateintegrity'
            );
        }
    }

    /**
     * Seed a default Scheduled Task instance for the purge-backups
     * routine, so the user has a ready-made task to enable instead of
     * having to build it from scratch.
     *
     * Defaults: retention 30 days, keep most-recent 5 regardless of age,
     * dry-run off. Daily interval (24h). State = 0 (unpublished) — the
     * task is wired up but won't fire until the admin enables it
     * explicitly from System → Scheduled Tasks. Seeding it as published
     * would mean the very first daily tick could delete pre-existing
     * backup rows older than 30 days without the admin having reviewed
     * the config, which is too aggressive on install.
     *
     * Idempotent: if a task of this type already exists (re-install,
     * update, or the admin already created one by hand), skip silently
     * so we never create duplicates.
     */
    private function seedPurgeTask(): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        try {
            $taskType = 'cstemplateintegrity.purgeBackups';

            $check = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__scheduler_tasks'))
                ->where($db->quoteName('type') . ' = :type')
                ->bind(':type', $taskType, \Joomla\Database\ParameterType::STRING);
            $existing = (int) $db->setQuery($check)->loadResult();
            if ($existing > 0) {
                return;
            }

            $now = \Joomla\CMS\Factory::getDate()->toSql();
            $row = (object) [
                'title'           => 'cs-template-integrity: Purge old file backups',
                'type'            => $taskType,
                'execution_rules' => json_encode([
                    'rule-type'      => 'interval-hours',
                    'interval-hours' => 24,
                    'exec-day'       => '*',
                    'exec-time'      => '00:00',
                ]),
                'cron_rules'      => json_encode([
                    'type' => 'interval',
                    'exp'  => 'PT24H',
                ]),
                'state'           => 0,
                'last_exit_code'  => -1,
                'times_executed'  => 0,
                'times_failed'    => 0,
                'priority'        => 0,
                'ordering'        => 0,
                'note'            => 'Seeded by pkg_cstemplateintegrity. Review the Parameters tab and the schedule, then publish to enable.',
                'params'          => json_encode([
                    'retention_days' => 30,
                    'min_keep'       => 5,
                    'dry_run'        => 0,
                ]),
                'created'         => $now,
                'created_by'      => $this->currentUserId(),
            ];

            $db->insertObject('#__scheduler_tasks', $row, 'id');
        } catch (\Throwable $e) {
            // Failure here is non-fatal — the user can still build the
            // task by hand from System → Scheduled Tasks → New. Log so
            // the next admin to look at the system log can see what
            // happened.
            Log::add(
                'Could not seed default purge-backups scheduled task: ' . $e->getMessage(),
                Log::WARNING,
                'pkg_cstemplateintegrity'
            );
        }
    }

    private function currentUserId(): int
    {
        try {
            $user = \Joomla\CMS\Factory::getApplication()->getIdentity();
            return $user ? (int) $user->id : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function showPostInstallMessage(string $type): void
    {
        $messageKey = $type === 'update'
            ? 'PKG_CSTEMPLATEINTEGRITY_POSTINSTALL_UPDATED'
            : 'PKG_CSTEMPLATEINTEGRITY_POSTINSTALL_INSTALLED';
        $url = 'index.php?option=com_cstemplateintegrity&view=dashboard';

        // Translated language strings are echoed escaped — see the
        // matching note in com_cstemplateintegrity/script.php.
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        echo '<div class="card mb-3" style="margin: 20px 0;">'
            . '<div class="card-body">'
            . '<h3 class="card-title">' . $h(Text::_('PKG_CSTEMPLATEINTEGRITY')) . '</h3>'
            . '<p class="card-text">' . $h(Text::_($messageKey)) . '</p>'
            . '<a href="' . $h($url) . '" class="btn btn-primary text-white">'
            . '<span class="icon-dashboard" aria-hidden="true"></span> '
            . $h(Text::_('PKG_CSTEMPLATEINTEGRITY_POSTINSTALL_OPEN'))
            . '</a>'
            . '</div></div>';
    }
}
