<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * @var \Cybersalt\Component\Csoverridechecker\Administrator\View\Sessions\HtmlView $this
 */

declare(strict_types=1);

defined('_JEXEC') or die;

// First-run disclaimer modal (rendered only if the current admin
// user has not yet ticked "do not show again").
echo \Cybersalt\Component\Csoverridechecker\Administrator\Helper\DisclaimerHelper::renderModalIfNeeded();

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

$listAction = Route::_('index.php?option=com_csoverridechecker&view=sessions', false);

/**
 * Build a sort-link header. Clicking flips the direction if the column
 * is already the sort column; otherwise sets the column with DESC as
 * the default first click.
 */
$sortHeader = function (string $column, string $labelKey) use ($listAction): string {
    /** @var \Cybersalt\Component\Csoverridechecker\Administrator\View\Sessions\HtmlView $tpl */
    $tpl    = $this;
    $isCurrent = $tpl->listOrder === $column;
    $nextDir   = ($isCurrent && $tpl->listDir === 'DESC') ? 'ASC' : 'DESC';
    $arrow     = '';
    if ($isCurrent) {
        $arrow = $tpl->listDir === 'DESC'
            ? ' <span class="icon-arrow-down" aria-hidden="true"></span>'
            : ' <span class="icon-arrow-up"   aria-hidden="true"></span>';
    }
    $url = Route::_(
        'index.php?option=com_csoverridechecker&view=sessions&order=' . $column . '&dir=' . $nextDir,
        false
    );
    return '<a href="' . $tpl->escape($url) . '">' . Text::_($labelKey) . $arrow . '</a>';
};
?>

<form action="<?php echo $this->escape(Uri::getInstance()->toString()); ?>" method="post" name="adminForm" id="adminForm">
    <div class="container-fluid">
        <p class="text-body-secondary mb-3">
            <?php echo Text::_('COM_CSOVERRIDECHECKER_SESSIONS_INTRO'); ?>
        </p>

        <?php if (empty($this->items)) : ?>
            <div class="alert alert-info">
                <?php echo Text::_('COM_CSOVERRIDECHECKER_SESSIONS_EMPTY'); ?>
            </div>
        <?php else : ?>
            <table class="table">
                <thead>
                    <tr>
                        <th class="w-1"><input type="checkbox" name="checkall-toggle" value=""
                                onclick="Joomla.checkAll(this)"></th>
                        <th><?php echo $sortHeader('name', 'COM_CSOVERRIDECHECKER_SESSIONS_COL_NAME'); ?></th>
                        <th><?php echo $sortHeader('source', 'COM_CSOVERRIDECHECKER_SESSIONS_COL_SOURCE'); ?></th>
                        <th><?php echo Text::_('COM_CSOVERRIDECHECKER_SESSIONS_COL_SUMMARY'); ?></th>
                        <th><?php echo $sortHeader('created_at', 'COM_CSOVERRIDECHECKER_SESSIONS_COL_CREATED'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->items as $i => $row) : ?>
                        <?php $url = Route::_('index.php?option=com_csoverridechecker&view=session&id=' . (int) $row->id, false); ?>
                        <tr>
                            <td><input type="checkbox" id="cb<?php echo $i; ?>" name="cid[]" value="<?php echo (int) $row->id; ?>"
                                    onclick="Joomla.isChecked(this.checked);"></td>
                            <td>
                                <a href="<?php echo $this->escape($url); ?>">
                                    <?php echo $this->escape($row->name); ?>
                                </a>
                            </td>
                            <td>
                                <span class="badge bg-secondary"><?php echo $this->escape($row->source); ?></span>
                            </td>
                            <td>
                                <small class="text-body-secondary">
                                    <?php echo $this->escape($row->summary !== '' ? $row->summary : Text::_('COM_CSOVERRIDECHECKER_SESSIONS_NO_SUMMARY')); ?>
                                </small>
                            </td>
                            <td>
                                <small><?php echo HTMLHelper::_('date', $row->created_at, Text::_('DATE_FORMAT_LC5')); ?></small>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <input type="hidden" name="task" value="">
    <input type="hidden" name="boxchecked" value="0">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
