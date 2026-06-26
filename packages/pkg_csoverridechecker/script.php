<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Package-level installer for pkg_csoverridechecker. Three jobs on install /
 * update:
 *   1. auto-enable the webservices + task plugins (Joomla installs third-
 *      party plugins disabled by default, which leaves the API routes
 *      404'ing and the scheduled task absent from the type dropdown until
 *      the admin flips them on by hand),
 *   2. migrate from the legacy pkg_cstemplateintegrity install if present
 *      (v2.5.0 rename — see migrateLegacyData() below),
 *   3. seed a default purge-backups scheduled task (state=0 so the admin
 *      reviews + publishes it).
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;

final class Pkg_CsoverridecheckerInstallerScript
{
    /**
     * Set to true by migrateLegacyData() when we actually copied data from
     * a pkg_cstemplateintegrity install. showPostInstallMessage() reads this
     * to decide whether to render the "rename complete, please uninstall the
     * old package" card or the plain "installed" card.
     */
    private bool $migrationPerformed = false;

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        // Joomla also calls postflight() on uninstall. Skip everything but
        // install/update so we don't auto-enable a plugin that's about to
        // be removed and don't render an "installed, click here" card on
        // an uninstall.
        if (!\in_array($type, ['install', 'update', 'discover_install'], true)) {
            return true;
        }

        $this->enableChildPlugin('webservices');
        $this->enableChildPlugin('task');

        // Migration runs only on the very first install of the new package
        // on top of a legacy pkg_cstemplateintegrity install. Subsequent
        // updates of pkg_csoverridechecker are no-ops here.
        $this->migrationPerformed = $this->migrateLegacyData($type);

        $this->seedPurgeTask();

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
     * this, plg_webservices_csoverridechecker 404s its API routes and
     * plg_task_csoverridechecker never appears in the Scheduled Tasks
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
                ->where($db->quoteName('element') . ' = ' . $db->quote('csoverridechecker'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote($folder));

            $db->setQuery($query)->execute();
        } catch (\Throwable $e) {
            Log::add(
                sprintf('Could not auto-enable plg_%s_csoverridechecker: %s', $folder, $e->getMessage()),
                Log::WARNING,
                'pkg_csoverridechecker'
            );
        }
    }

    /**
     * v2.5.0 rename migration: copy settings and data from the legacy
     * pkg_cstemplateintegrity install if present.
     *
     * Background: v2.4.x shipped as pkg_cstemplateintegrity. JED's reserved-
     * words policy bars "Template" in extension names, so v2.5.0 renamed
     * every internal identifier to pkg_csoverridechecker. To Joomla, that
     * looks like a brand new extension, so installing v2.5.0 on top of
     * v2.4.x produces two side-by-side installs with the new one starting
     * empty. This method bridges the two: copies extension params (so the
     * admin keeps their API key, model selection, etc.), copies the three
     * data tables (sessions / actions / backups), and rewrites scheduler
     * task type from "cstemplateintegrity.*" to "csoverridechecker.*" so
     * the user's existing schedule continues to run on the new plugin.
     *
     * Idempotent: only runs on install (not update), only runs if legacy
     * extension is present, skips entirely if the new tables already
     * contain data (e.g. user re-installs the new package twice).
     *
     * @return bool true if migration actually ran, false otherwise — caller
     *              uses this to switch the postinstall message.
     */
    private function migrateLegacyData(string $type): bool
    {
        if (!\in_array($type, ['install', 'discover_install'], true)) {
            return false;
        }

        if (!$this->legacyInstallExists()) {
            return false;
        }

        // If the new tables already have data, migration must already have
        // run — don't double-import.
        if ($this->newTablesHaveData()) {
            return false;
        }

        try {
            $this->copyExtensionParams();
            $this->copyTableData();
            $this->rewriteSchedulerTaskTypes();
            $this->relabelLegacyPackage();
            return true;
        } catch (\Throwable $e) {
            Log::add(
                'Legacy data migration failed: ' . $e->getMessage(),
                Log::WARNING,
                'pkg_csoverridechecker'
            );
            return false;
        }
    }

    private function legacyInstallExists(): bool
    {
        return $this->getLegacyPackageExtensionId() !== null;
    }

    /**
     * Look up the extension_id of the legacy pkg_cstemplateintegrity row.
     * Used in two places: legacyInstallExists() for the gate-check, and
     * renderMigrationCard() to build a filter URL that targets just the
     * legacy package row in the Extensions Manager (filter_search=id:N
     * — see comment in renderMigrationCard for why the name-based filter
     * doesn't work here).
     */
    private function getLegacyPackageExtensionId(): ?int
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        try {
            $query = $db->getQuery(true)
                ->select($db->quoteName('extension_id'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('package'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('pkg_cstemplateintegrity'));

            $id = $db->setQuery($query)->loadResult();
            return $id !== null ? (int) $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Returns true if any of the new component's tables already has rows.
     * Used to short-circuit migration on a re-install so we never duplicate
     * the user's data.
     */
    private function newTablesHaveData(): bool
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $tables = [
            '#__csoverridechecker_sessions',
            '#__csoverridechecker_actions',
            '#__csoverridechecker_backups',
        ];

        foreach ($tables as $t) {
            try {
                $q = $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName($t));
                if (((int) $db->setQuery($q)->loadResult()) > 0) {
                    return true;
                }
            } catch (\Throwable $e) {
                // Table doesn't exist yet — that's a "no data" case, keep
                // checking siblings.
                continue;
            }
        }

        return false;
    }

    /**
     * Copy params from old extension rows to new ones. Component params
     * hold the user's Anthropic API key, model selection, cost caps, etc.
     * Plugin params hold the webservices and task plugin settings.
     */
    private function copyExtensionParams(): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $mappings = [
            ['type' => 'component', 'old' => 'com_cstemplateintegrity', 'new' => 'com_csoverridechecker'],
            ['type' => 'plugin', 'folder' => 'webservices', 'old' => 'cstemplateintegrity', 'new' => 'csoverridechecker'],
            ['type' => 'plugin', 'folder' => 'task',        'old' => 'cstemplateintegrity', 'new' => 'csoverridechecker'],
        ];

        foreach ($mappings as $m) {
            try {
                $select = $db->getQuery(true)
                    ->select($db->quoteName('params'))
                    ->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('type') . ' = ' . $db->quote($m['type']))
                    ->where($db->quoteName('element') . ' = ' . $db->quote($m['old']));
                if (isset($m['folder'])) {
                    $select->where($db->quoteName('folder') . ' = ' . $db->quote($m['folder']));
                }
                $oldParams = $db->setQuery($select)->loadResult();

                if ($oldParams === null || $oldParams === '' || $oldParams === '{}') {
                    continue;
                }

                $update = $db->getQuery(true)
                    ->update($db->quoteName('#__extensions'))
                    ->set($db->quoteName('params') . ' = ' . $db->quote($oldParams))
                    ->where($db->quoteName('type') . ' = ' . $db->quote($m['type']))
                    ->where($db->quoteName('element') . ' = ' . $db->quote($m['new']));
                if (isset($m['folder'])) {
                    $update->where($db->quoteName('folder') . ' = ' . $db->quote($m['folder']));
                }
                $db->setQuery($update)->execute();
            } catch (\Throwable $e) {
                Log::add(
                    'Could not migrate params for ' . $m['old'] . ': ' . $e->getMessage(),
                    Log::WARNING,
                    'pkg_csoverridechecker'
                );
            }
        }
    }

    /**
     * Copy data from #__cstemplateintegrity_{sessions,actions,backups}
     * to #__csoverridechecker_{sessions,actions,backups}. The new tables
     * have the same schema as the old (the rename script substitution
     * touched the SQL file but not the column definitions), so a plain
     * INSERT … SELECT * works.
     */
    private function copyTableData(): void
    {
        $db     = Factory::getContainer()->get(DatabaseInterface::class);
        $prefix = $db->getPrefix();

        foreach (['sessions', 'actions', 'backups'] as $suffix) {
            $oldName = $prefix . 'cstemplateintegrity_' . $suffix;
            $newName = $prefix . 'csoverridechecker_'  . $suffix;

            try {
                $sql = 'INSERT INTO ' . $db->quoteName($newName)
                    . ' SELECT * FROM ' . $db->quoteName($oldName);
                $db->setQuery($sql)->execute();
            } catch (\Throwable $e) {
                Log::add(
                    'Could not migrate table ' . $suffix . ': ' . $e->getMessage(),
                    Log::WARNING,
                    'pkg_csoverridechecker'
                );
            }
        }
    }

    /**
     * Relabel the legacy package's display name in #__extensions so it
     * stands out in Extensions Manager. Two reasons:
     *
     *   1. Joomla 5/6's com_installer manage filter searches against
     *      #__extensions.name only (the e.name column, LIKE %x%) — there
     *      is no id:N shortcut and no element-column search. After the
     *      v2.4.4 rename, both the legacy and new packages have the same
     *      display name "Cybersalt Override Checker", which makes them
     *      indistinguishable in the manage list and unfilterable by name.
     *      Restoring the old "Cybersalt Template Integrity" name (with a
     *      "(safe to uninstall)" annotation) gives the row a unique,
     *      user-recognisable label AND a filter-targetable substring —
     *      filter[search]=Template Integrity isolates exactly this row,
     *      and the name is what the user installed in the first place
     *      so they immediately know what they're removing.
     *   2. Users who skip the migration card and open Manage by hand
     *      see at a glance which row is the one to remove.
     *
     * Idempotent: if the rename has already happened (re-run of the
     * installer), we re-apply the same string, which is a no-op.
     */
    private function relabelLegacyPackage(): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        try {
            $legacyName = 'Cybersalt Template Integrity (safe to uninstall)';
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('name') . ' = ' . $db->quote($legacyName))
                ->where($db->quoteName('type') . ' = ' . $db->quote('package'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('pkg_cstemplateintegrity'));
            $db->setQuery($query)->execute();
        } catch (\Throwable $e) {
            Log::add(
                'Could not relabel legacy package: ' . $e->getMessage(),
                Log::WARNING,
                'pkg_csoverridechecker'
            );
        }
    }

    /**
     * Rewrite #__scheduler_tasks rows where type='cstemplateintegrity.*'
     * to type='csoverridechecker.*'. After this, the user's existing
     * scheduled task continues to run, but against the new task plugin.
     * Without this rewrite, the old rows would be orphaned (still in the
     * table but no plugin would handle them) once the legacy package is
     * uninstalled.
     */
    private function rewriteSchedulerTaskTypes(): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        try {
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__scheduler_tasks'))
                ->set($db->quoteName('type') . ' = ' . $db->quote('csoverridechecker.purgeBackups'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('cstemplateintegrity.purgeBackups'));
            $db->setQuery($query)->execute();
        } catch (\Throwable $e) {
            Log::add(
                'Could not rewrite scheduler task types: ' . $e->getMessage(),
                Log::WARNING,
                'pkg_csoverridechecker'
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
     * update, the admin already created one by hand, or migration just
     * rewrote a legacy task row to use the new type), skip silently so
     * we never create duplicates.
     */
    private function seedPurgeTask(): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        try {
            $taskType = 'csoverridechecker.purgeBackups';

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
                'title'           => 'cs-override-checker: Purge old file backups',
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
                'note'            => 'Seeded by pkg_csoverridechecker. Review the Parameters tab and the schedule, then publish to enable.',
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
                'pkg_csoverridechecker'
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
        // Translated language strings are echoed escaped — see the
        // matching note in com_csoverridechecker/script.php.
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Show the migration card whenever a legacy pkg_cstemplateintegrity
        // install is still present on the site, not only on the install run
        // that actually copied the data. Otherwise a user who re-runs the
        // installer (e.g. to test, or to apply a v2.5.x point release)
        // before they've uninstalled the legacy package would see the plain
        // "updated" card and never get the prompt to clean up the leftover.
        if ($this->migrationPerformed || $this->legacyInstallExists()) {
            // Make sure the legacy row carries the "(LEGACY — safe to
            // uninstall)" label even on subsequent installs/updates, where
            // migrateLegacyData() short-circuited because the data copy
            // already ran. Idempotent — same UPDATE every time.
            $this->relabelLegacyPackage();
            $this->renderMigrationCard($h);
            return;
        }

        $messageKey = $type === 'update'
            ? 'PKG_CSOVERRIDECHECKER_POSTINSTALL_UPDATED'
            : 'PKG_CSOVERRIDECHECKER_POSTINSTALL_INSTALLED';
        $url = 'index.php?option=com_csoverridechecker&view=dashboard';

        echo '<div class="card mb-3" style="margin: 20px 0;">'
            . '<div class="card-body">'
            . '<h3 class="card-title">' . $h(Text::_('PKG_CSOVERRIDECHECKER')) . '</h3>'
            . '<p class="card-text">' . $h(Text::_($messageKey)) . '</p>'
            . '<a href="' . $h($url) . '" class="btn btn-primary text-white">'
            . '<span class="icon-dashboard" aria-hidden="true"></span> '
            . $h(Text::_('PKG_CSOVERRIDECHECKER_POSTINSTALL_OPEN'))
            . '</a>'
            . '</div></div>';
    }

    /**
     * Rendered instead of the normal "installed!" card when migration ran.
     * Explains the rename and gives a one-click link to the Extensions
     * Manager filtered to the old package, so the admin can uninstall it.
     */
    private function renderMigrationCard(\Closure $h): void
    {
        $dashboardUrl = 'index.php?option=com_csoverridechecker&view=dashboard';

        // Filter the manage list to a single row: the legacy package.
        //
        // The search clause matches "Template Integrity" — present in the
        // legacy package's display name AND in every child extension's
        // translated_name (Joomla's manage view searches both e.name and
        // the language-file translation, so children inherit the match
        // via their pre-v2.4.4 language strings still on disk). On its
        // own the search returns four rows: pkg + com + 2 plugins.
        //
        // The type=package clause narrows to just the parent package.
        // Uninstalling the parent cascades to all three children
        // automatically, so a one-row list is what the user needs.
        //
        // URL params must use the bracket form filter[…]=… (not the older
        // filter_search=…). Joomla 5.4+ dropped the flat form for
        // com_installer's manage view; verified against Joomla 5.4.6 on
        // 2026-06-26. Browsers URL-encode the brackets on click.
        $uninstallUrl = 'index.php?option=com_installer&view=manage'
            . '&filter[search]=Template+Integrity'
            . '&filter[type]=package';

        echo '<div class="card mb-3 border-warning" style="margin: 20px 0;">'
            . '<div class="card-body">'
            . '<h3 class="card-title">' . $h(Text::_('PKG_CSOVERRIDECHECKER_POSTINSTALL_MIGRATED_TITLE')) . '</h3>'
            . '<p class="card-text">' . $h(Text::_('PKG_CSOVERRIDECHECKER_POSTINSTALL_MIGRATED_BODY')) . '</p>'
            // text-dark forces black on the yellow btn-warning background.
            // Without it, Joomla's dark-mode admin theme inherits white
            // text from the surrounding card, producing white-on-yellow
            // which is unreadable.
            . '<a href="' . $h($uninstallUrl) . '" class="btn btn-warning text-dark">'
            . '<span class="icon-remove" aria-hidden="true"></span> '
            . $h(Text::_('PKG_CSOVERRIDECHECKER_POSTINSTALL_MIGRATED_UNINSTALL'))
            . '</a> '
            . '<a href="' . $h($dashboardUrl) . '" class="btn btn-primary text-white">'
            . '<span class="icon-dashboard" aria-hidden="true"></span> '
            . $h(Text::_('PKG_CSOVERRIDECHECKER_POSTINSTALL_OPEN'))
            . '</a>'
            . '</div></div>';
    }
}
