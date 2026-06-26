<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Administrator\Controller;

defined('_JEXEC') or die;

use Cybersalt\Component\Csoverridechecker\Administrator\Helper\PermissionHelper;
use Cybersalt\Component\Csoverridechecker\Administrator\Helper\SessionsHelper;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Throwable;

final class SessionsController extends BaseController
{
    protected $default_view = 'sessions';

    public function save(): void
    {
        $this->checkToken();
        PermissionHelper::requireWrite();

        /** @var CMSApplication $app */
        $app  = $this->app;
        $data = (array) $app->getInput()->post->get('jform', [], 'array');

        try {
            $id = SessionsHelper::create(
                $data['name'] ?? null,
                $data['summary'] ?? null,
                $data['report_markdown'] ?? null,
                SessionsHelper::SOURCE_PASTE
            );

            $app->enqueueMessage(Text::sprintf('COM_CSOVERRIDECHECKER_SESSIONS_SAVED', $id), 'success');
            $this->setRedirect(Route::_('index.php?option=com_csoverridechecker&view=session&id=' . $id, false));
        } catch (Throwable $e) {
            $app->enqueueMessage(Text::sprintf('COM_CSOVERRIDECHECKER_SESSIONS_SAVE_ERROR', $e->getMessage()), 'error');
            $this->setRedirect(Route::_('index.php?option=com_csoverridechecker&view=sessionform', false));
        }
    }

    public function delete(): void
    {
        $this->checkToken();
        PermissionHelper::requireWrite();

        /** @var CMSApplication $app */
        $app = $this->app;
        $ids = (array) $app->getInput()->get('cid', [], 'array');

        try {
            $count = SessionsHelper::delete(array_map('intval', $ids));
            $app->enqueueMessage(Text::sprintf('COM_CSOVERRIDECHECKER_SESSIONS_DELETED', $count), 'success');
        } catch (Throwable $e) {
            $app->enqueueMessage(Text::sprintf('COM_CSOVERRIDECHECKER_SESSIONS_DELETE_ERROR', $e->getMessage()), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_csoverridechecker&view=sessions', false));
    }

    /**
     * Bundle the user-selected sessions into a single zip and stream it
     * back. One <name>.md per session inside the archive. Falls through
     * to the existing `session.download` task when only one row is
     * checked, to avoid wrapping a single file in a zip needlessly.
     */
    public function downloadSelected(): void
    {
        $this->checkToken();
        PermissionHelper::requireView();

        /** @var CMSApplication $app */
        $app = $this->app;
        $ids = (array) $app->getInput()->get('cid', [], 'array');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));

        if (empty($ids)) {
            $app->enqueueMessage(Text::_('COM_CSOVERRIDECHECKER_SESSIONS_DOWNLOAD_NONE'), 'warning');
            $this->setRedirect(Route::_('index.php?option=com_csoverridechecker&view=sessions', false));
            return;
        }

        if (!class_exists('ZipArchive')) {
            $app->enqueueMessage(Text::_('COM_CSOVERRIDECHECKER_SESSIONS_DOWNLOAD_ZIP_MISSING'), 'error');
            $this->setRedirect(Route::_('index.php?option=com_csoverridechecker&view=sessions', false));
            return;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'csti-sessions-');
        if ($tmpFile === false) {
            $app->enqueueMessage(Text::_('COM_CSOVERRIDECHECKER_SESSIONS_DOWNLOAD_TMP_FAIL'), 'error');
            $this->setRedirect(Route::_('index.php?option=com_csoverridechecker&view=sessions', false));
            return;
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpFile, \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpFile);
            $app->enqueueMessage(Text::_('COM_CSOVERRIDECHECKER_SESSIONS_DOWNLOAD_ZIP_OPEN_FAIL'), 'error');
            $this->setRedirect(Route::_('index.php?option=com_csoverridechecker&view=sessions', false));
            return;
        }

        $found = 0;
        $usedNames = [];
        foreach ($ids as $id) {
            $session = SessionsHelper::find($id);
            if ($session === null) {
                continue;
            }

            $contents = (string) ($session->report_markdown ?? '');
            $rawName  = (string) ($session->name ?? ('session-' . $id));
            $safeName = preg_replace('/[^A-Za-z0-9._-]/', '-', $rawName);
            if ($safeName === '' || $safeName === '.' || $safeName === '..') {
                $safeName = 'session-' . $id;
            }
            $base = 'csoverridechecker-' . $safeName;

            // Disambiguate same-named sessions inside the archive.
            $entryName = $base . '.md';
            $i         = 2;
            while (isset($usedNames[$entryName])) {
                $entryName = $base . '-' . $i . '.md';
                $i++;
            }
            $usedNames[$entryName] = true;

            $zip->addFromString($entryName, $contents);
            $found++;
        }

        $zip->close();

        if ($found === 0) {
            @unlink($tmpFile);
            $app->enqueueMessage(Text::_('COM_CSOVERRIDECHECKER_SESSIONS_DOWNLOAD_NOT_FOUND'), 'warning');
            $this->setRedirect(Route::_('index.php?option=com_csoverridechecker&view=sessions', false));
            return;
        }

        $bytes = (string) @file_get_contents($tmpFile);
        @unlink($tmpFile);

        $stamp    = gmdate('Ymd-His');
        $filename = 'csoverridechecker-sessions-' . $stamp . '.zip';

        $app->setHeader('Content-Type', 'application/zip', true);
        $app->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"', true);
        $app->setHeader('Content-Length', (string) strlen($bytes), true);
        $app->sendHeaders();
        echo $bytes;
        $app->close();
    }
}
