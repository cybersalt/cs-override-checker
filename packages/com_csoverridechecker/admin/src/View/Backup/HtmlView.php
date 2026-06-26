<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Administrator\View\Backup;

defined('_JEXEC') or die;

use Cybersalt\Component\Csoverridechecker\Administrator\Helper\BackupsHelper;
use Cybersalt\Component\Csoverridechecker\Administrator\Helper\PermissionHelper;
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
    public ?\stdClass $backup = null;

    public string $contents = '';

    public string $absolutePath = '';

    public string $backUrl = '';

    public string $downloadUrl = '';

    public string $restoreAction = '';

    public bool $fileExists = false;

    public string $highlightLanguage = 'plaintext';

    public function display($tpl = null): void
    {
        PermissionHelper::requireView();

        $id = (int) Factory::getApplication()->getInput()->getInt('id', 0);
        if ($id <= 0) {
            throw new GenericDataException(Text::_('COM_CSOVERRIDECHECKER_BACKUP_NOT_FOUND'), 404);
        }

        $row = BackupsHelper::find($id);
        if ($row === null) {
            throw new GenericDataException(Text::_('COM_CSOVERRIDECHECKER_BACKUP_NOT_FOUND'), 404);
        }

        $this->backup            = $row;
        $this->contents          = BackupsHelper::decodeContents($row);
        $this->absolutePath      = JPATH_ROOT . '/' . ltrim((string) $row->file_path, '/\\');
        $this->fileExists        = is_file($this->absolutePath);
        $this->backUrl           = Route::_('index.php?option=com_csoverridechecker&view=backups', false);
        $this->downloadUrl       = Route::_('index.php?option=com_csoverridechecker&task=backups.download&id=' . $id . '&' . Session::getFormToken() . '=1', false);
        $this->restoreAction     = Route::_('index.php?option=com_csoverridechecker', false);
        $this->highlightLanguage = self::languageForExtension(pathinfo((string) $row->file_path, PATHINFO_EXTENSION));

        HTMLHelper::_('stylesheet', 'com_csoverridechecker/dashboard.css', ['relative' => true, 'version' => 'auto']);
        HTMLHelper::_('stylesheet', 'com_csoverridechecker/highlight-theme.css', ['relative' => true, 'version' => 'auto']);

        // Joomla's HTMLHelper::script $options array silently drops keys
        // it doesn't recognize, including `defer` — so script ordering
        // ends up implicit. Pass `defer` as an actual HTML attribute via
        // the fourth parameter ($attribs) so the tag is real-defer, and
        // dashboard.js's polling loop covers the rest.
        HTMLHelper::_(
            'script',
            'com_csoverridechecker/highlight.min.js',
            ['relative' => true, 'version' => 'auto'],
            ['defer' => true]
        );
        HTMLHelper::_(
            'script',
            'com_csoverridechecker/dashboard.js',
            ['relative' => true, 'version' => 'auto'],
            ['defer' => true]
        );

        // The "Restore now…" button uses a Bootstrap 5 modal. Joomla 5+
        // ships Bootstrap, but only loads modal/dropdown/etc. assets on
        // request — without this the data-bs-toggle attribute is just
        // dead HTML on this view.
        $this->getDocument()->getWebAssetManager()->useScript('bootstrap.modal');

        ToolbarHelper::title(
            Text::sprintf('COM_CSOVERRIDECHECKER_BACKUP_TITLE', '#' . (int) $row->id),
            'archive'
        );

        parent::display($tpl);
    }

    private static function languageForExtension(string $ext): string
    {
        return match (strtolower($ext)) {
            'php', 'phtml'   => 'php',
            'html', 'htm'    => 'xml',
            'xml', 'xhtml'   => 'xml',
            'js', 'mjs'      => 'javascript',
            'ts'             => 'typescript',
            'jsx', 'tsx'     => 'javascript',
            'css', 'scss',
            'sass', 'less'   => 'css',
            'json'           => 'json',
            'yaml', 'yml'    => 'yaml',
            'md', 'markdown' => 'markdown',
            'sh', 'bash'     => 'bash',
            'sql'            => 'sql',
            'ini'            => 'ini',
            default          => 'plaintext',
        };
    }
}
