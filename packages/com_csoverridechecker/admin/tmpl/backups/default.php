<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * @var \Cybersalt\Component\Csoverridechecker\Administrator\View\Backups\HtmlView $this
 */

declare(strict_types=1);

defined('_JEXEC') or die;

echo \Cybersalt\Component\Csoverridechecker\Administrator\Helper\DisclaimerHelper::renderModalIfNeeded();

use Cybersalt\Component\Csoverridechecker\Administrator\Helper\BackupDescriber;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

$filter   = $this->filter;
$baseView = 'index.php?option=com_csoverridechecker&view=backups';

$sortLink = static function (string $column, string $label) use ($filter, $baseView): string {
    $isActive = $filter['sort'] === $column;
    $nextDir  = ($isActive && $filter['dir'] === 'asc') ? 'desc' : 'asc';
    $arrow    = $isActive
        ? ($filter['dir'] === 'desc' ? ' <span class="icon-caret-down" aria-hidden="true"></span>' : ' <span class="icon-caret-up" aria-hidden="true"></span>')
        : '';
    $cls = 'csti-sort-link' . ($isActive ? ' csti-sort-active' : '');
    // Route::_() default returns &amp;-encoded; htmlspecialchars on
    // top would double-encode to &amp;amp; and break the query
    // (browser decodes to &amp;view=, PHP parses query key as
    // amp;view). Pass false so encoding happens exactly once below.
    $url = Route::_(
        $baseView
        . '&sort=' . urlencode($column)
        . '&dir=' . urlencode($nextDir)
        . '&search=' . urlencode($filter['search'])
        . '&session_id=' . urlencode($filter['session_id'])
        . '&limit=' . (int) $filter['limit'],
        false
    );
    return '<a class="' . $cls . '" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . $arrow . '</a>';
};

$clearUrl = Route::_($baseView, false);

// adminForm action — preserve the current filter state in the URL so the
// toolbar's Delete posts with the same view we're already showing. Uri's
// toString() captures every query param Joomla already saw on this request.
$adminFormAction = Uri::getInstance()->toString();

// Per-row restore form post target. Sits inside the outer adminForm but
// each restore button submits its OWN form (declared via `form` attr below)
// so a click here doesn't accidentally trigger the toolbar Delete.
$restoreAction = Route::_($baseView, false);
?>

<form action="<?php echo $this->escape($adminFormAction); ?>" method="post" name="adminForm" id="adminForm">
<div class="container-fluid">
    <p class="text-body-secondary mb-3"><?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUPS_DESCRIPTION'); ?></p>
    <p class="text-body-secondary mb-3"><?php echo Text::_('COM_CSOVERRIDECHECKER_BACKUPS_STORAGE_NOTE'); ?></p>

    <div class="js-stools clearfix mb-3">
        <div class="js-stools-container-bar d-flex flex-wrap gap-2 align-items-center mb-2">
            <div class="btn-group" role="group">
                <input type="search" class="form-control" name="search"
                       value="<?php echo $this->escape($filter['search']); ?>"
                       placeholder="<?php echo $this->escape(Text::_('JSEARCH_FILTER')); ?>"
                       style="max-width: 240px"
                       form="csti-backups-filter-form">
                <button type="submit" form="csti-backups-filter-form" class="btn btn-primary"
                        title="<?php echo $this->escape(Text::_('JSEARCH_FILTER_SUBMIT')); ?>">
                    <span class="icon-search" aria-hidden="true"></span>
                </button>
            </div>

            <button type="button" class="btn btn-primary"
                    data-bs-toggle="collapse" data-bs-target="#csti-backups-filterbar"
                    aria-expanded="<?php echo $this->hasActiveFilter ? 'true' : 'false'; ?>">
                <?php echo $this->escape(Text::_('COM_CSOVERRIDECHECKER_FILTER_OPTIONS')); ?>
                <span class="icon-caret-down" aria-hidden="true"></span>
            </button>

            <a class="btn btn-danger" href="<?php echo $this->escape($clearUrl); ?>"
               title="<?php echo $this->escape(Text::_('JSEARCH_FILTER_CLEAR')); ?>">
                <?php echo $this->escape(Text::_('JSEARCH_FILTER_CLEAR')); ?>
            </a>

            <div class="js-stools-field-list ms-auto">
                <select name="limit" class="form-select" data-csti-choices
                        style="max-width: 110px"
                        onchange="document.getElementById('csti-backups-filter-form').submit()"
                        form="csti-backups-filter-form"
                        aria-label="<?php echo $this->escape(Text::_('JGLOBAL_LIST_LIMIT')); ?>">
                    <?php foreach ($this->limitOptions as $lim) : ?>
                        <option value="<?php echo (int) $lim; ?>" <?php echo (int) $filter['limit'] === (int) $lim ? 'selected' : ''; ?>><?php echo (int) $lim; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div id="csti-backups-filterbar"
             class="js-stools-container-list collapse<?php echo $this->hasActiveFilter ? ' show' : ''; ?>">
            <div class="d-flex flex-wrap gap-2 py-2">
                <div class="js-stools-field-filter">
                    <select name="session_id" class="form-select" data-csti-choices
                            style="min-width: 220px"
                            onchange="document.getElementById('csti-backups-filter-form').submit()"
                            form="csti-backups-filter-form"
                            aria-label="<?php echo $this->escape(Text::_('COM_CSOVERRIDECHECKER_BACKUPS_COL_SESSION')); ?>">
                        <option value=""><?php echo $this->escape(Text::_('COM_CSOVERRIDECHECKER_FILTER_ANY_SESSION')); ?></option>
                        <?php foreach ($this->sessions as $s) : ?>
                            <option value="<?php echo $this->escape((string) $s['id']); ?>" <?php echo $filter['session_id'] === (string) $s['id'] ? 'selected' : ''; ?>>
                                <?php echo $this->escape($s['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($this->items)) : ?>
        <div class="alert alert-info">
            <?php if ($this->hasActiveFilter) : ?>
                <?php echo $this->escape(Text::_('COM_CSOVERRIDECHECKER_FILTER_NO_MATCHES')); ?>
            <?php else : ?>
                <?php echo $this->escape(Text::_('COM_CSOVERRIDECHECKER_BACKUPS_EMPTY')); ?>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <table class="table">
            <thead>
                <tr>
                    <th class="w-1"><input type="checkbox" name="checkall-toggle" value="" onclick="Joomla.checkAll(this)"></th>
                    <th><?php echo $sortLink('saved', Text::_('COM_CSOVERRIDECHECKER_BACKUPS_COL_TIME')); ?></th>
                    <th><?php echo $sortLink('file', Text::_('COM_CSOVERRIDECHECKER_BACKUPS_COL_FILE')); ?></th>
                    <th><?php echo $sortLink('size', Text::_('COM_CSOVERRIDECHECKER_BACKUPS_COL_SIZE')); ?></th>
                    <th><?php echo $this->escape(Text::_('COM_CSOVERRIDECHECKER_BACKUPS_COL_HASH')); ?></th>
                    <th><?php echo $sortLink('session', Text::_('COM_CSOVERRIDECHECKER_BACKUPS_COL_SESSION')); ?></th>
                    <th><?php echo $this->escape(Text::_('COM_CSOVERRIDECHECKER_BACKUPS_COL_ACTIONS')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($this->items as $i => $row) : ?>
                    <?php
                    $viewUrl     = Route::_('index.php?option=com_csoverridechecker&view=backup&id=' . (int) $row->id, false);
                    $downloadUrl = Route::_('index.php?option=com_csoverridechecker&task=backups.download&id=' . (int) $row->id . '&' . Session::getFormToken() . '=1', false);
                    ?>
                    <tr>
                        <td><input type="checkbox" id="cb<?php echo $i; ?>" name="cid[]" value="<?php echo (int) $row->id; ?>" onclick="Joomla.isChecked(this.checked);"></td>
                        <td class="csti-when-cell">
                            <small class="d-block"><?php echo HTMLHelper::_('date', $row->created_at, 'Y-m-d'); ?></small>
                            <small class="text-body-secondary"><?php echo HTMLHelper::_('date', $row->created_at, 'H:i:s'); ?></small>
                        </td>
                        <td>
                            <a href="<?php echo $this->escape($viewUrl); ?>"><small><code><?php echo $this->escape($row->file_path); ?></code></small></a>
                            <br><small class="text-body-secondary"><?php echo BackupDescriber::describe((string) $row->file_path); ?></small>
                        </td>
                        <td><small><?php echo number_format((int) $row->file_size); ?> B</small></td>
                        <td><small class="text-body-secondary"><?php echo $this->escape(substr($row->file_hash, 0, 12)); ?>&hellip;</small></td>
                        <td>
                            <?php if ($row->session_id) : ?>
                                <a href="<?php echo $this->escape(Route::_('index.php?option=com_csoverridechecker&view=session&id=' . (int) $row->session_id . '&from=backups', false)); ?>">
                                    #<?php echo (int) $row->session_id; ?>
                                </a>
                            <?php else : ?>
                                <span class="text-body-secondary">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td class="csti-row-actions">
                            <a href="<?php echo $this->escape($viewUrl); ?>" class="btn btn-sm btn-info" title="<?php echo $this->escape(Text::_('COM_CSOVERRIDECHECKER_BACKUPS_VIEW')); ?>">
                                <span class="icon-eye" aria-hidden="true"></span>
                            </a>
                            <a href="<?php echo $this->escape($downloadUrl); ?>" class="btn btn-sm btn-info" title="<?php echo $this->escape(Text::_('COM_CSOVERRIDECHECKER_BACKUPS_COL_DOWNLOAD')); ?>">
                                <span class="icon-download" aria-hidden="true"></span>
                            </a>
                            <?php if ($this->canWrite) : ?>
                                <button type="submit"
                                        form="csti-restore-form-<?php echo (int) $row->id; ?>"
                                        class="btn btn-sm btn-success csti-restore-btn"
                                        title="<?php echo $this->escape(Text::sprintf('COM_CSOVERRIDECHECKER_BACKUPS_RESTORE_BUTTON_TITLE', $row->file_path)); ?>"
                                        data-csti-restore-path="<?php echo $this->escape($row->file_path); ?>">
                                    <span class="icon-loop" aria-hidden="true"></span>
                                </button>
                            <?php endif; ?>
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

<?php
// Filter form lives outside the adminForm so submitting it (Enter in
// search, dropdown change, button click) navigates with a clean URL
// instead of POSTing to the Delete handler. The adminForm above still
// owns checkbox state and the toolbar Delete.
?>
<form method="get" action="<?php echo $this->escape(Route::_($baseView)); ?>" class="js-stools-form" id="csti-backups-filter-form">
    <input type="hidden" name="option" value="com_csoverridechecker">
    <input type="hidden" name="view" value="backups">
    <input type="hidden" name="sort" value="<?php echo $this->escape($filter['sort']); ?>">
    <input type="hidden" name="dir" value="<?php echo $this->escape($filter['dir']); ?>">
</form>

<?php if ($this->canWrite) : ?>
    <?php foreach ($this->items as $row) : ?>
        <form action="<?php echo $this->escape($restoreAction); ?>" method="post"
              id="csti-restore-form-<?php echo (int) $row->id; ?>"
              class="d-none">
            <?php echo HTMLHelper::_('form.token'); ?>
            <input type="hidden" name="task" value="backups.restore">
            <input type="hidden" name="id" value="<?php echo (int) $row->id; ?>">
        </form>
    <?php endforeach; ?>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Choices !== 'undefined') {
        document.querySelectorAll('select[data-csti-choices]').forEach(function (el) {
            if (el._cstiChoices) return;
            try {
                el._cstiChoices = new Choices(el, {
                    shouldSort: false,
                    itemSelectText: '',
                    searchEnabled: !el.multiple ? false : true
                });
            } catch (e) { /* noop */ }
        });
    }

    // Restore-button confirm. Each button submits its own hidden form,
    // so we intercept the submit and abort if the user backs out.
    document.querySelectorAll('.csti-restore-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            var path = btn.getAttribute('data-csti-restore-path') || 'this file';
            var msg  = <?php echo json_encode((string) Text::_('COM_CSOVERRIDECHECKER_BACKUPS_RESTORE_BUTTON_CONFIRM'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            if (!window.confirm(msg.replace('%s', path))) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
    });
});
</script>
