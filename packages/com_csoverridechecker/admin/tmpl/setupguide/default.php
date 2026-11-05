<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * @var \Cybersalt\Component\Csoverridechecker\Administrator\View\Setupguide\HtmlView $this
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/**
 * Per-assistant setup notes.
 *
 * Grouped by capability rather than by brand: product tiers and feature
 * names churn constantly, so the guide leads with what an assistant has
 * to be able to DO and treats named products as examples underneath.
 */
$families = [
    [
        'id'    => 'agents',
        'icon'  => 'icon-terminal',
        'badge' => 'success',
        'badge_text' => 'COM_CSOVERRIDECHECKER_SETUPGUIDE_BADGE_BEST',
        'heading' => 'COM_CSOVERRIDECHECKER_SETUPGUIDE_AGENTS_HEADING',
        'examples' => 'COM_CSOVERRIDECHECKER_SETUPGUIDE_AGENTS_EXAMPLES',
        'body'  => 'COM_CSOVERRIDECHECKER_SETUPGUIDE_AGENTS_BODY',
        'steps' => [
            'COM_CSOVERRIDECHECKER_SETUPGUIDE_AGENTS_STEP1',
            'COM_CSOVERRIDECHECKER_SETUPGUIDE_AGENTS_STEP2',
            'COM_CSOVERRIDECHECKER_SETUPGUIDE_AGENTS_STEP3',
        ],
    ],
    [
        'id'    => 'browser',
        'icon'  => 'icon-comments',
        'badge' => 'info',
        'badge_text' => 'COM_CSOVERRIDECHECKER_SETUPGUIDE_BADGE_COMMON',
        'heading' => 'COM_CSOVERRIDECHECKER_SETUPGUIDE_BROWSER_HEADING',
        'examples' => 'COM_CSOVERRIDECHECKER_SETUPGUIDE_BROWSER_EXAMPLES',
        'body'  => 'COM_CSOVERRIDECHECKER_SETUPGUIDE_BROWSER_BODY',
        'steps' => [
            'COM_CSOVERRIDECHECKER_SETUPGUIDE_BROWSER_STEP1',
            'COM_CSOVERRIDECHECKER_SETUPGUIDE_BROWSER_STEP2',
            'COM_CSOVERRIDECHECKER_SETUPGUIDE_BROWSER_STEP3',
        ],
        'warn' => 'COM_CSOVERRIDECHECKER_SETUPGUIDE_BROWSER_WARN',
    ],
    [
        'id'    => 'custom',
        'icon'  => 'icon-cog',
        'badge' => 'info',
        'badge_text' => 'COM_CSOVERRIDECHECKER_SETUPGUIDE_BADGE_MULTISITE',
        'heading' => 'COM_CSOVERRIDECHECKER_SETUPGUIDE_CUSTOM_HEADING',
        'examples' => 'COM_CSOVERRIDECHECKER_SETUPGUIDE_CUSTOM_EXAMPLES',
        'body'  => 'COM_CSOVERRIDECHECKER_SETUPGUIDE_CUSTOM_BODY',
        'steps' => [
            'COM_CSOVERRIDECHECKER_SETUPGUIDE_CUSTOM_STEP1',
            'COM_CSOVERRIDECHECKER_SETUPGUIDE_CUSTOM_STEP2',
            'COM_CSOVERRIDECHECKER_SETUPGUIDE_CUSTOM_STEP3',
        ],
    ],
    [
        'id'    => 'local',
        'icon'  => 'icon-server',
        'badge' => 'warning',
        'badge_text' => 'COM_CSOVERRIDECHECKER_SETUPGUIDE_BADGE_CAUTION',
        'heading' => 'COM_CSOVERRIDECHECKER_SETUPGUIDE_LOCAL_HEADING',
        'examples' => 'COM_CSOVERRIDECHECKER_SETUPGUIDE_LOCAL_EXAMPLES',
        'body'  => 'COM_CSOVERRIDECHECKER_SETUPGUIDE_LOCAL_BODY',
        'steps' => [
            'COM_CSOVERRIDECHECKER_SETUPGUIDE_LOCAL_STEP1',
            'COM_CSOVERRIDECHECKER_SETUPGUIDE_LOCAL_STEP2',
        ],
        'warn' => 'COM_CSOVERRIDECHECKER_SETUPGUIDE_LOCAL_WARN',
    ],
    [
        'id'    => 'mcp',
        'icon'  => 'icon-link',
        'badge' => 'info',
        'badge_text' => 'COM_CSOVERRIDECHECKER_SETUPGUIDE_BADGE_ADVANCED',
        'heading' => 'COM_CSOVERRIDECHECKER_SETUPGUIDE_MCP_HEADING',
        'examples' => 'COM_CSOVERRIDECHECKER_SETUPGUIDE_MCP_EXAMPLES',
        'body'  => 'COM_CSOVERRIDECHECKER_SETUPGUIDE_MCP_BODY',
        'steps' => [],
    ],
];
?>

<div class="container-fluid csoverridechecker-setupguide">

    <div class="card mb-3 border-info">
        <div class="card-body">
            <h3 class="card-title">
                <span class="icon-lightbulb" aria-hidden="true"></span>
                <?php echo Text::_('COM_CSOVERRIDECHECKER_SETUPGUIDE_INTRO_HEADING'); ?>
            </h3>
            <p class="card-text"><?php echo Text::_('COM_CSOVERRIDECHECKER_SETUPGUIDE_INTRO_BODY'); ?></p>
        </div>
    </div>

    <!-- The single requirement, stated before any product names. -->
    <div class="card mb-3 border-secondary">
        <div class="card-body">
            <h4 class="card-title"><?php echo Text::_('COM_CSOVERRIDECHECKER_SETUPGUIDE_REQ_HEADING'); ?></h4>
            <p class="card-text"><?php echo Text::_('COM_CSOVERRIDECHECKER_SETUPGUIDE_REQ_INTRO'); ?></p>
            <ul class="mb-2">
                <li><?php echo Text::_('COM_CSOVERRIDECHECKER_SETUPGUIDE_REQ_ITEM1'); ?></li>
                <li><?php echo Text::_('COM_CSOVERRIDECHECKER_SETUPGUIDE_REQ_ITEM2'); ?></li>
                <li><?php echo Text::_('COM_CSOVERRIDECHECKER_SETUPGUIDE_REQ_ITEM3'); ?></li>
            </ul>
            <p class="card-text mb-0">
                <small class="text-body-secondary">
                    <?php echo Text::_('COM_CSOVERRIDECHECKER_SETUPGUIDE_REQ_OUTRO'); ?>
                </small>
            </p>
        </div>
    </div>

    <?php if (!$this->hasJoomlaToken) : ?>
        <div class="alert alert-warning" role="alert">
            <span class="icon-warning me-2" aria-hidden="true"></span>
            <strong><?php echo Text::_('COM_CSOVERRIDECHECKER_SETUPGUIDE_NOTOKEN_TITLE'); ?></strong>
            <?php echo Text::_('COM_CSOVERRIDECHECKER_SETUPGUIDE_NOTOKEN_BODY'); ?>
            <a href="<?php echo $this->escape($this->optionsUrl); ?>" class="btn btn-sm btn-primary ms-2">
                <?php echo Text::_('JTOOLBAR_OPTIONS'); ?>
            </a>
        </div>
    <?php endif; ?>

    <!-- Per-family setup notes. -->
    <?php foreach ($families as $f) : ?>
        <div class="card mb-3" id="csti-guide-<?php echo $this->escape($f['id']); ?>">
            <div class="card-body">
                <h4 class="card-title d-flex align-items-center gap-2 flex-wrap">
                    <span class="<?php echo $this->escape($f['icon']); ?>" aria-hidden="true"></span>
                    <?php echo Text::_($f['heading']); ?>
                    <span class="badge bg-<?php echo $this->escape($f['badge']); ?>">
                        <?php echo Text::_($f['badge_text']); ?>
                    </span>
                </h4>

                <p class="text-body-secondary mb-2">
                    <small><?php echo Text::_($f['examples']); ?></small>
                </p>

                <p class="card-text"><?php echo Text::_($f['body']); ?></p>

                <?php if (!empty($f['steps'])) : ?>
                    <ol class="mb-2">
                        <?php foreach ($f['steps'] as $step) : ?>
                            <li class="mb-1"><?php echo Text::_($step); ?></li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>

                <?php if (!empty($f['warn'])) : ?>
                    <div class="alert alert-warning py-2 mb-0" role="alert">
                        <small><?php echo Text::_($f['warn']); ?></small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Method 2 is the one genuinely vendor-locked path; say so plainly. -->
    <div class="card mb-3 border-warning">
        <div class="card-body">
            <h4 class="card-title">
                <span class="icon-rocket" aria-hidden="true"></span>
                <?php echo Text::_('COM_CSOVERRIDECHECKER_SETUPGUIDE_ANTHROPIC_HEADING'); ?>
            </h4>
            <p class="card-text"><?php echo Text::_('COM_CSOVERRIDECHECKER_SETUPGUIDE_ANTHROPIC_BODY'); ?></p>
            <p class="card-text mb-0">
                <span class="badge bg-<?php echo $this->hasApiKey ? 'success' : 'secondary'; ?>">
                    <?php echo $this->hasApiKey
                        ? Text::sprintf('COM_CSOVERRIDECHECKER_SETUPGUIDE_ANTHROPIC_ACTIVE', $this->escape($this->aiProviderLabel))
                        : Text::_('COM_CSOVERRIDECHECKER_SETUPGUIDE_ANTHROPIC_INACTIVE'); ?>
                </span>
            </p>
        </div>
    </div>

    <!-- Raw endpoint reference, for anyone wiring their own client. -->
    <div class="card mb-3 border-secondary">
        <div class="card-body">
            <h4 class="card-title"><?php echo Text::_('COM_CSOVERRIDECHECKER_SETUPGUIDE_ENDPOINT_HEADING'); ?></h4>
            <p class="card-text"><?php echo Text::_('COM_CSOVERRIDECHECKER_SETUPGUIDE_ENDPOINT_BODY'); ?></p>
            <pre class="csoverridechecker-codeblock mb-2"><code><?php
                echo $this->escape($this->apiBase) . "\n\n"
                   . "X-Joomla-Token: &lt;your Joomla API token&gt;\n"
                   . 'Accept: application/vnd.api+json';
            ?></code></pre>
            <div class="alert alert-warning py-2 mb-0" role="alert">
                <small><?php echo Text::_('COM_CSOVERRIDECHECKER_SETUPGUIDE_ENDPOINT_BEARER_WARN'); ?></small>
            </div>
        </div>
    </div>

    <div class="card mb-0 border-info">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <strong><?php echo Text::_('COM_CSOVERRIDECHECKER_SETUPGUIDE_DOCS_HEADING'); ?></strong>
                <div><small class="text-body-secondary"><?php echo Text::_('COM_CSOVERRIDECHECKER_SETUPGUIDE_DOCS_BODY'); ?></small></div>
            </div>
            <a href="<?php echo $this->escape($this->docsUrl); ?>"
               class="btn btn-primary" target="_blank" rel="noopener noreferrer">
                <span class="icon-book" aria-hidden="true"></span>
                <?php echo Text::_('COM_CSOVERRIDECHECKER_SETUPGUIDE_DOCS_BUTTON'); ?>
            </a>
        </div>
    </div>

</div>
