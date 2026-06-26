<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Re-builds Joomla's `#__template_overrides` tracker by walking every
 * enabled template's html/ folder and inserting a row for any
 * override file that doesn't already have one. Inverse of the admin
 * UI's "Dismiss" action, which deletes rows.
 *
 * Used as a testing utility: when a site owner has run "Dismiss All"
 * on the override badges, this brings the test corpus back without
 * needing to wait for the next Joomla core update to repopulate.
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Administrator\Helper;

defined('_JEXEC') or die;

use FilesystemIterator;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class RescanHelper
{
    /**
     * Walk every enabled template's html/ folder and insert
     * missing #__template_overrides rows for the files found.
     *
     * @param  list<int>|null  $extensionIdFilter  Optional whitelist of
     *     `#__extensions.extension_id` values. When non-null, only those
     *     templates are rescanned (others are skipped). When null, every
     *     enabled template is rescanned (the original "reset all" behavior).
     *     Empty list throws — callers must pass null for "all," not [].
     *
     * @return array{inserted: int, scanned: int, templates: int}
     */
    public static function rebuildOverrideTracker(?array $extensionIdFilter = null): array
    {
        if ($extensionIdFilter !== null && empty($extensionIdFilter)) {
            throw new \RuntimeException('No templates selected for rescan.');
        }

        $db        = Factory::getContainer()->get(DatabaseInterface::class);
        $templates = self::listTemplates($db);

        if ($extensionIdFilter !== null) {
            $allowed   = array_map('intval', $extensionIdFilter);
            $templates = array_values(array_filter(
                $templates,
                static fn ($t) => in_array((int) $t->extension_id, $allowed, true)
            ));
        }

        $now = Factory::getDate()->toSql();

        $stats = ['inserted' => 0, 'scanned' => 0, 'templates' => count($templates)];

        $logStats = function () use (&$stats): void {
            ActionLogHelper::log(ActionLogHelper::ACTION_RESCAN, $stats);
        };

        foreach ($templates as $template) {
            $clientId = (int) $template->client_id;
            $name     = (string) $template->element;
            $extId    = (int) $template->extension_id;

            $root    = $clientId === 1 ? JPATH_ADMINISTRATOR : JPATH_SITE;
            $htmlDir = $root . '/templates/' . $name . '/html';

            if (!is_dir($htmlDir)) {
                continue;
            }

            foreach (self::walkFiles($htmlDir) as $absolutePath) {
                $stats['scanned']++;

                $relative = '/html/' . str_replace('\\', '/', substr($absolutePath, strlen($htmlDir) + 1));
                $hashId   = base64_encode($relative);

                if (self::overrideExists($db, $name, $hashId, $clientId)) {
                    continue;
                }

                $row = (object) [
                    'template'      => $name,
                    'hash_id'       => $hashId,
                    'extension_id'  => $extId,
                    'state'         => 0,
                    'action'        => 'Joomla Update',
                    'client_id'     => $clientId,
                    'created_date'  => $now,
                    'modified_date' => $now,
                ];

                $db->insertObject('#__template_overrides', $row);
                $stats['inserted']++;
            }
        }

        $logStats();

        return $stats;
    }

    /**
     * Enumerate enabled templates for the per-template rescan picker.
     *
     * Returns one entry per enabled template that has an html/ override
     * directory on disk. Templates whose html/ folder is missing are
     * skipped — they have no overrides for us to rescan, and showing
     * them in the picker would just confuse the user.
     *
     * @return list<array{
     *     extension_id: int,
     *     element: string,
     *     client_id: int,
     *     client_label: string,
     *     html_dir: string,
     *     existing_overrides: int
     * }>
     */
    public static function listTemplatesWithOverrideDirs(): array
    {
        $db        = Factory::getContainer()->get(DatabaseInterface::class);
        $templates = self::listTemplates($db);

        $result = [];

        foreach ($templates as $template) {
            $clientId = (int) $template->client_id;
            $name     = (string) $template->element;
            $extId    = (int) $template->extension_id;

            $root    = $clientId === 1 ? JPATH_ADMINISTRATOR : JPATH_SITE;
            $htmlDir = $root . '/templates/' . $name . '/html';

            if (!is_dir($htmlDir)) {
                continue;
            }

            $result[] = [
                'extension_id'       => $extId,
                'element'            => $name,
                'client_id'          => $clientId,
                'client_label'       => $clientId === 1 ? 'Administrator' : 'Site',
                'html_dir'           => $htmlDir,
                'existing_overrides' => self::countOverridesForTemplate($db, $name, $clientId),
            ];
        }

        return $result;
    }

    private static function countOverridesForTemplate(DatabaseInterface $db, string $template, int $clientId): int
    {
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__template_overrides'))
            ->where($db->quoteName('template') . ' = :template')
            ->where($db->quoteName('client_id') . ' = :client_id')
            ->bind(':template',  $template)
            ->bind(':client_id', $clientId, ParameterType::INTEGER);

        return (int) $db->setQuery($query)->loadResult();
    }

    /**
     * @return list<\stdClass>
     */
    private static function listTemplates(DatabaseInterface $db): array
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName(['extension_id', 'element', 'client_id']))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('template'))
            ->where($db->quoteName('enabled') . ' = 1');

        return $db->setQuery($query)->loadObjectList() ?: [];
    }

    /**
     * @return iterable<string>
     */
    private static function walkFiles(string $dir): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $entry) {
            if ($entry->isFile()) {
                yield $entry->getPathname();
            }
        }
    }

    private static function overrideExists(DatabaseInterface $db, string $template, string $hashId, int $clientId): bool
    {
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__template_overrides'))
            ->where($db->quoteName('template') . ' = :template')
            ->where($db->quoteName('hash_id') . ' = :hash_id')
            ->where($db->quoteName('client_id') . ' = :client_id')
            ->bind(':template', $template)
            ->bind(':hash_id', $hashId)
            ->bind(':client_id', $clientId, ParameterType::INTEGER);

        return (int) $db->setQuery($query)->loadResult() > 0;
    }
}
