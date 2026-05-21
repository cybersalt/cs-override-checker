<?php

/**
 * @package     Cstemplateintegrity
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * @var \Cybersalt\Component\Cstemplateintegrity\Administrator\View\Actions\HtmlView $this
 */

declare(strict_types=1);

defined('_JEXEC') or die;

echo \Cybersalt\Component\Cstemplateintegrity\Administrator\Helper\DisclaimerHelper::renderModalIfNeeded();

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$filter   = $this->filter;
$baseView = 'index.php?option=com_cstemplateintegrity&view=actions';

// Sort-link helper. Mirrors the JOOMLA5-LIST-FILTERS-GUIDE pattern: clicking
// a header sorts ascending on first click and flips on subsequent clicks.
// Re-encodes every active filter into the URL so sort doesn't reset state.
$sortLink = static function (string $column, string $label) use ($filter, $baseView): string {
    $isActive = $filter['sort'] === $column;
    $nextDir  = ($isActive && $filter['dir'] === 'asc') ? 'desc' : 'asc';
    $arrow    = $isActive
        ? ($filter['dir'] === 'desc' ? ' <span class="icon-caret-down" aria-hidden="true"></span>' : ' <span class="icon-caret-up" aria-hidden="true"></span>')
        : '';
    $cls = 'csti-sort-link' . ($isActive ? ' csti-sort-active' : '');
    // Route::_() defaults to xhtml=true (returns &amp;). Calling
    // htmlspecialchars on top of that double-encodes to &amp;amp;,
    // and the browser sees query keys like amp;view / amp;sort —
    // with no view= present, Joomla bounces to the dashboard. Pass
    // false here so Route::_ returns raw ampersands; htmlspecialchars
    // below encodes them exactly once.
    $url = Route::_(
        $baseView
        . '&sort=' . urlencode($column)
        . '&dir=' . urlencode($nextDir)
        . '&search=' . urlencode($filter['search'])
        . '&action_type=' . urlencode($filter['action_type'])
        . '&session_id=' . urlencode($filter['session_id'])
        . '&limit=' . (int) $filter['limit'],
        false
    );
    return '<a class="' . $cls . '" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . $arrow . '</a>';
};

$clearUrl = Route::_($baseView, false);
?>

<form method="get" action="<?php echo $this->escape(Route::_($baseView)); ?>" class="js-stools-form" id="csti-actions-filter-form">
    <input type="hidden" name="option" value="com_cstemplateintegrity">
    <input type="hidden" name="view" value="actions">
    <input type="hidden" name="sort" value="<?php echo $this->escape($filter['sort']); ?>">
    <input type="hidden" name="dir" value="<?php echo $this->escape($filter['dir']); ?>">

    <div class="container-fluid">
        <p class="text-body-secondary mb-3"><?php echo Text::_('COM_CSTEMPLATEINTEGRITY_ACTIONS_DESCRIPTION'); ?></p>
        <p class="text-body-secondary mb-3"><?php echo Text::_('COM_CSTEMPLATEINTEGRITY_ACTIONS_VS_SESSIONS'); ?></p>

        <div class="js-stools clearfix mb-3">
            <div class="js-stools-container-bar d-flex flex-wrap gap-2 align-items-center mb-2">
                <div class="btn-group" role="group">
                    <input type="search" class="form-control" name="search"
                           value="<?php echo $this->escape($filter['search']); ?>"
                           placeholder="<?php echo $this->escape(Text::_('JSEARCH_FILTER')); ?>"
                           style="max-width: 240px">
                    <button type="submit" class="btn btn-primary" title="<?php echo $this->escape(Text::_('JSEARCH_FILTER_SUBMIT')); ?>">
                        <span class="icon-search" aria-hidden="true"></span>
                    </button>
                </div>

                <button type="button" class="btn btn-primary"
                        data-bs-toggle="collapse" data-bs-target="#csti-actions-filterbar"
                        aria-expanded="<?php echo $this->hasActiveFilter ? 'true' : 'false'; ?>">
                    <?php echo $this->escape(Text::_('COM_CSTEMPLATEINTEGRITY_FILTER_OPTIONS')); ?>
                    <span class="icon-caret-down" aria-hidden="true"></span>
                </button>

                <a class="btn btn-danger" href="<?php echo $this->escape($clearUrl); ?>"
                   title="<?php echo $this->escape(Text::_('JSEARCH_FILTER_CLEAR')); ?>">
                    <?php echo $this->escape(Text::_('JSEARCH_FILTER_CLEAR')); ?>
                </a>

                <div class="js-stools-field-list ms-auto">
                    <select name="limit" class="form-select" data-csti-choices
                            style="max-width: 110px"
                            onchange="this.form.submit()"
                            aria-label="<?php echo $this->escape(Text::_('JGLOBAL_LIST_LIMIT')); ?>">
                        <?php foreach ($this->limitOptions as $lim) : ?>
                            <option value="<?php echo (int) $lim; ?>" <?php echo (int) $filter['limit'] === (int) $lim ? 'selected' : ''; ?>><?php echo (int) $lim; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div id="csti-actions-filterbar"
                 class="js-stools-container-list collapse<?php echo $this->hasActiveFilter ? ' show' : ''; ?>">
                <div class="d-flex flex-wrap gap-2 py-2">
                    <div class="js-stools-field-filter">
                        <select name="action_type" class="form-select" data-csti-choices
                                style="min-width: 240px"
                                onchange="this.form.submit()"
                                aria-label="<?php echo $this->escape(Text::_('COM_CSTEMPLATEINTEGRITY_ACTIONS_COL_ACTION')); ?>">
                            <option value=""><?php echo $this->escape(Text::_('COM_CSTEMPLATEINTEGRITY_FILTER_ANY_ACTION')); ?></option>
                            <?php foreach ($this->actionTypes as $a) : ?>
                                <option value="<?php echo $this->escape($a); ?>" <?php echo $filter['action_type'] === $a ? 'selected' : ''; ?>>
                                    <?php echo $this->escape($a); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="js-stools-field-filter">
                        <select name="session_id" class="form-select" data-csti-choices
                                style="min-width: 220px"
                                onchange="this.form.submit()"
                                aria-label="<?php echo $this->escape(Text::_('COM_CSTEMPLATEINTEGRITY_ACTIONS_COL_SESSION')); ?>">
                            <option value=""><?php echo $this->escape(Text::_('COM_CSTEMPLATEINTEGRITY_FILTER_ANY_SESSION')); ?></option>
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
                    <?php echo $this->escape(Text::_('COM_CSTEMPLATEINTEGRITY_FILTER_NO_MATCHES')); ?>
                <?php else : ?>
                    <?php echo $this->escape(Text::_('COM_CSTEMPLATEINTEGRITY_ACTIONS_EMPTY')); ?>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <table class="table">
                <thead>
                    <tr>
                        <th><?php echo $sortLink('time', Text::_('COM_CSTEMPLATEINTEGRITY_ACTIONS_COL_TIME')); ?></th>
                        <th><?php echo $sortLink('action', Text::_('COM_CSTEMPLATEINTEGRITY_ACTIONS_COL_ACTION')); ?></th>
                        <th><?php echo $sortLink('session', Text::_('COM_CSTEMPLATEINTEGRITY_ACTIONS_COL_SESSION')); ?></th>
                        <th><?php echo $this->escape(Text::_('COM_CSTEMPLATEINTEGRITY_ACTIONS_COL_DETAILS')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->items as $row) : ?>
                        <tr>
                            <td class="csti-when-cell">
                                <small class="d-block"><?php echo HTMLHelper::_('date', $row->created_at, 'Y-m-d'); ?></small>
                                <small class="text-body-secondary"><?php echo HTMLHelper::_('date', $row->created_at, 'H:i:s'); ?></small>
                            </td>
                            <td><code><?php echo $this->escape($row->action); ?></code></td>
                            <td>
                                <?php if ($row->session_id) : ?>
                                    <a href="<?php echo $this->escape(Route::_('index.php?option=com_cstemplateintegrity&view=session&id=' . (int) $row->session_id . '&from=actions', false)); ?>">
                                        #<?php echo (int) $row->session_id; ?>
                                    </a>
                                <?php else : ?>
                                    <span class="text-body-secondary">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row->details)) : ?>
                                    <small><code class="cstemplateintegrity-detail-code"><?php echo $this->escape($row->details); ?></code></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Choices === 'undefined') return;
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
});
</script>
