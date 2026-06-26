<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * @var \Cybersalt\Component\Csoverridechecker\Administrator\View\Backup\HtmlView $this
 */

declare(strict_types=1);

defined('_JEXEC') or die;

// First-run disclaimer modal (rendered only if the current admin
// user has not yet ticked "do not show again").
echo \Cybersalt\Component\Csoverridechecker\Administrator\Helper\DisclaimerHelper::renderModalIfNeeded();

use Cybersalt\Component\Csoverridechecker\Administrator\Helper\BackupDescriber;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$b = $this->backup;
?>

<div class="container-fluid csoverridechecker-dashboard">
    <p class="mb-3">
        <a href="<?php echo $this->escape($this->backUrl); ?>" class="btn btn-info">
            <span class="icon-arrow-left" aria-hidden="true"></span>
            <?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_BACK_TO_LIST'); ?>
        </a>
        <a href="<?php echo $this->escape($this->downloadUrl); ?>" class="btn btn-info">
            <span class="icon-download" aria-hidden="true"></span>
            <?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_DOWNLOAD'); ?>
        </a>
    </p>

    <div class="row">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-body">
                    <h3 class="card-title">
                        <span class="icon-archive" aria-hidden="true"></span>
                        <?php echo Text::sprintf('COM_CSOVERRIDECHECKER_BACKUP_TITLE', '#' . (int) $b->id); ?>
                    </h3>
                    <table class="table table-sm mb-0">
                        <tbody>
                            <tr>
                                <th class="w-25"><?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_LABEL_DESCRIPTION'); ?></th>
                                <td><?php echo BackupDescriber::describe((string) $b->file_path); ?></td>
                            </tr>
                            <tr>
                                <th><?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_LABEL_PATH'); ?></th>
                                <td><code><?php echo $this->escape($b->file_path); ?></code></td>
                            </tr>
                            <tr>
                                <th><?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_LABEL_ABSOLUTE'); ?></th>
                                <td><small class="text-body-secondary"><code><?php echo $this->escape($this->absolutePath); ?></code></small></td>
                            </tr>
                            <tr>
                                <th><?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_LABEL_SIZE'); ?></th>
                                <td><?php echo number_format((int) $b->file_size); ?> bytes</td>
                            </tr>
                            <tr>
                                <th><?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_LABEL_HASH'); ?></th>
                                <td><small><code><?php echo $this->escape($b->file_hash); ?></code></small></td>
                            </tr>
                            <tr>
                                <th><?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_LABEL_CREATED'); ?></th>
                                <td><?php echo HTMLHelper::_('date', $b->created_at, Text::_('DATE_FORMAT_LC2')); ?></td>
                            </tr>
                            <tr>
                                <th><?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_LABEL_SESSION'); ?></th>
                                <td>
                                    <?php if ($b->session_id) : ?>
                                        <a href="<?php echo $this->escape(\Joomla\CMS\Router\Route::_('index.php?option=com_csoverridechecker&view=session&id=' . (int) $b->session_id . '&from=backups', false)); ?>">
                                            #<?php echo (int) $b->session_id; ?>
                                        </a>
                                    <?php else : ?>
                                        <span class="text-body-secondary"><?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_NO_SESSION'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_LABEL_FILE_STATE'); ?></th>
                                <td>
                                    <?php if ($this->fileExists) : ?>
                                        <span class="badge bg-success"><?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_FILE_PRESENT'); ?></span>
                                    <?php else : ?>
                                        <span class="badge bg-warning text-dark"><?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_FILE_MISSING'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-body">
                    <h4 class="card-title"><?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_CONTENTS_HEADING'); ?></h4>
                    <p class="text-body-secondary mb-2"><?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_CONTENTS_HELP'); ?></p>
                    <pre class="csoverridechecker-codeblock csoverridechecker-backup-contents"><code id="csoverridechecker-backup-contents-code" class="language-<?php echo $this->escape($this->highlightLanguage); ?>"><?php echo $this->escape($this->contents); ?></code></pre>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3 border-success">
                <div class="card-body">
                    <h4 class="card-title">
                        <span class="icon-checkmark" aria-hidden="true"></span>
                        <?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_RESTORE_TITLE'); ?>
                    </h4>
                    <p class="card-text"><?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_RESTORE_INTRO'); ?></p>
                    <p class="card-text">
                        <small class="text-body-secondary"><?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_RESTORE_NOTE'); ?></small>
                    </p>
                    <button type="button" class="btn btn-success"
                            data-bs-toggle="modal"
                            data-bs-target="#csoverridechecker-restore-modal">
                        <span class="icon-loop" aria-hidden="true"></span>
                        <?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_RESTORE_BUTTON'); ?>
                    </button>
                </div>
            </div>

            <div class="card mb-3 border-info">
                <div class="card-body">
                    <h4 class="card-title">
                        <span class="icon-download" aria-hidden="true"></span>
                        <?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_MANUAL_TITLE'); ?>
                    </h4>
                    <p class="card-text"><?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_MANUAL_INTRO'); ?></p>
                    <ol class="mb-2">
                        <li><?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_MANUAL_STEP1'); ?></li>
                        <li><?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_MANUAL_STEP2'); ?></li>
                        <li><?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_MANUAL_STEP3'); ?></li>
                    </ol>
                    <p class="card-text mb-1">
                        <strong><?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_MANUAL_DESTINATION'); ?></strong>
                    </p>
                    <p class="card-text">
                        <small><code><?php echo $this->escape($this->absolutePath); ?></code></small>
                    </p>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-body">
                    <h4 class="card-title"><?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_STORAGE_TITLE'); ?></h4>
                    <p class="card-text"><?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_STORAGE_INTRO'); ?></p>
                    <p class="card-text mb-0"><small class="text-body-secondary"><?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_STORAGE_NOTE'); ?></small></p>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="csoverridechecker-restore-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <span class="icon-warning" aria-hidden="true"></span>
                    <?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_RESTORE_MODAL_TITLE'); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo $this->escape(Text::_('JCANCEL')); ?>"></button>
            </div>
            <div class="modal-body">
                <p><?php echo Text::sprintf('COM_CSOVERRIDECHECKER_BACKUP_RESTORE_MODAL_BODY', '<code>' . $this->escape($b->file_path) . '</code>'); ?></p>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="csoverridechecker-restore-confirm-check">
                    <label class="form-check-label" for="csoverridechecker-restore-confirm-check">
                        <strong><?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_RESTORE_MODAL_AGREE'); ?></strong>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <?php echo Text::_('JCANCEL'); ?>
                </button>
                <form action="<?php echo $this->escape($this->restoreAction); ?>" method="post" class="d-inline">
                    <?php echo HTMLHelper::_('form.token'); ?>
                    <input type="hidden" name="task" value="backups.restore">
                    <input type="hidden" name="id" value="<?php echo (int) $b->id; ?>">
                    <input type="hidden" name="return" value="backup">
                    <button type="submit"
                            class="btn btn-success"
                            id="csoverridechecker-restore-confirm-btn"
                            disabled>
                        <span class="icon-loop" aria-hidden="true"></span>
                        <?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUP_RESTORE_MODAL_CONFIRM'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
