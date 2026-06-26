<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * @var \Cybersalt\Component\Csoverridechecker\Administrator\View\Session\HtmlView $this
 */

declare(strict_types=1);

defined('_JEXEC') or die;

// First-run disclaimer modal (rendered only if the current admin
// user has not yet ticked "do not show again").
echo \Cybersalt\Component\Csoverridechecker\Administrator\Helper\DisclaimerHelper::renderModalIfNeeded();

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$session = $this->session;
?>

<div class="container-fluid csoverridechecker-dashboard">
    <p class="mb-3">
        <a href="<?php echo $this->escape($this->backUrl); ?>" class="btn btn-info">
            <span class="icon-arrow-left" aria-hidden="true"></span>
            <?php echo Text::_($this->backLabelKey); ?>
        </a>
        <a href="<?php echo $this->escape($this->downloadUrl); ?>" class="btn btn-info">
            <span class="icon-download" aria-hidden="true"></span>
            <?php echo Text::_('COM_CSOVERRIDECHECKER_SESSION_DOWNLOAD'); ?>
        </a>
    </p>

    <div class="row">
        <div class="col-lg-9">
            <div class="card mb-3">
                <div class="card-body">
                    <h3 class="card-title">
                        <span class="icon-eye" aria-hidden="true"></span>
                        <?php echo $this->escape($session->name); ?>
                    </h3>
                    <p class="card-text">
                        <span class="badge bg-secondary"><?php echo $this->escape($session->source); ?></span>
                        <small class="text-body-secondary ms-2">
                            <?php echo HTMLHelper::_('date', $session->created_at, Text::_('DATE_FORMAT_LC2')); ?>
                        </small>
                    </p>
                    <?php if (!empty($session->summary)) : ?>
                        <p class="card-text"><strong><?php echo $this->escape($session->summary); ?></strong></p>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between align-items-center mt-4 mb-2">
                        <h4 class="mb-0"><?php echo Text::_('COM_CSOVERRIDECHECKER_SESSION_REPORT'); ?></h4>
                        <?php if (!empty($session->report_markdown)) : ?>
                            <button type="button" class="btn btn-sm btn-info" id="csoverridechecker-fullscreen-btn"
                                    data-enter-label="<?php echo $this->escape(Text::_('COM_CSOVERRIDECHECKER_SESSION_FULLSCREEN_ENTER')); ?>"
                                    data-exit-label="<?php echo $this->escape(Text::_('COM_CSOVERRIDECHECKER_SESSION_FULLSCREEN_EXIT')); ?>">
                                <span class="icon-expand" aria-hidden="true"></span>
                                <?php echo Text::_('COM_CSOVERRIDECHECKER_SESSION_FULLSCREEN_ENTER'); ?>
                            </button>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($session->report_markdown)) : ?>
                        <p class="text-body-secondary"><?php echo Text::_('COM_CSOVERRIDECHECKER_SESSION_REPORT_EMPTY'); ?></p>
                    <?php else : ?>
                        <pre class="csoverridechecker-codeblock csoverridechecker-report" id="csoverridechecker-report"><?php echo $this->escape($session->report_markdown); ?></pre>
                    <?php endif; ?>
                </div>
            </div>

            <?php
            // Chat-with-Claude card. Visible iff the user has saved an API
            // key. Renders any conversation turns BEYOND the original scan
            // (we already showed the scan output above as the report) and
            // gives the user a textarea to add the next turn.
            //
            // Skip the first 2 messages (the seeded scan prompt + assistant
            // report) so they don't duplicate the report block above. For
            // sessions that have NO seeded conversation (paste-in sessions
            // or older auto-scan sessions before the messages column
            // existed), render every available message.
            $allMessages    = $this->messages;
            $messagesToShow = (count($allMessages) > 2) ? array_slice($allMessages, 2) : [];
            ?>
            <div class="card mb-3 border-info">
                <div class="card-body">
                    <h3 class="card-title">
                        <span class="icon-comments" aria-hidden="true"></span>
                        <?php echo Text::_('COM_CSOVERRIDECHECKER_SESSION_CHAT_TITLE'); ?>
                    </h3>

                    <?php if (!$this->hasApiKey) : ?>
                        <p class="text-body-secondary mb-0"><?php echo Text::_('COM_CSOVERRIDECHECKER_SESSION_CHAT_NOKEY'); ?></p>
                    <?php else : ?>
                        <p class="card-text"><?php echo Text::_('COM_CSOVERRIDECHECKER_SESSION_CHAT_INTRO'); ?></p>

                        <?php if (!empty($messagesToShow)) : ?>
                            <div class="csti-chat-history">
                                <?php foreach ($messagesToShow as $msg) :
                                    $role    = (string) ($msg['role'] ?? '');
                                    $content = $msg['content'] ?? '';

                                    // Tool-result blocks (sent as role=user with array content) — render
                                    // as small footnotes rather than full bubbles.
                                    if ($role === 'user' && is_array($content)) {
                                        $isToolResults = false;
                                        foreach ($content as $b) {
                                            if (is_array($b) && ($b['type'] ?? '') === 'tool_result') {
                                                $isToolResults = true;
                                                break;
                                            }
                                        }
                                        if ($isToolResults) {
                                            // Skip — we surface tool_use names from the assistant
                                            // turn instead, which is more useful for the user.
                                            continue;
                                        }
                                    }

                                    // Pull text + any tool_use block names from the content.
                                    $text     = '';
                                    $toolUses = [];
                                    if (is_string($content)) {
                                        $text = $content;
                                    } elseif (is_array($content)) {
                                        foreach ($content as $b) {
                                            if (is_array($b) && ($b['type'] ?? '') === 'text') {
                                                $text .= (string) ($b['text'] ?? '');
                                            } elseif (is_array($b) && ($b['type'] ?? '') === 'tool_use') {
                                                $toolUses[] = (string) ($b['name'] ?? '?');
                                            }
                                        }
                                    }

                                    if ($text === '' && empty($toolUses)) {
                                        continue;
                                    }

                                    $bubbleClass = $role === 'user' ? 'is-user' : 'is-assistant';
                                ?>
                                    <div class="csti-chat-msg <?php echo $bubbleClass; ?>">
                                        <?php if ($text !== '') : ?>
                                            <div><?php echo nl2br($this->escape($text)); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($toolUses)) : ?>
                                            <div class="csti-chat-tools">
                                                <strong><?php echo Text::_('COM_CSOVERRIDECHECKER_SESSION_CHAT_CALLED'); ?></strong>
                                                <?php foreach ($toolUses as $t) : ?>
                                                    <code><?php echo $this->escape($t); ?>()</code>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <form action="<?php echo $this->escape($this->continueAction); ?>"
                              method="post"
                              data-csti-chat
                              data-loading-title="<?php echo $this->escape(Text::_('COM_CSOVERRIDECHECKER_SESSION_CHAT_LOADING_TITLE')); ?>"
                              data-loading-body="<?php echo $this->escape(Text::_('COM_CSOVERRIDECHECKER_SESSION_CHAT_LOADING_BODY')); ?>">
                            <?php echo HTMLHelper::_('form.token'); ?>
                            <input type="hidden" name="task" value="session.continueChat">
                            <input type="hidden" name="id"   value="<?php echo (int) $session->id; ?>">
                            <div class="mb-2">
                                <textarea name="message"
                                          class="form-control"
                                          rows="3"
                                          required
                                          placeholder="<?php echo $this->escape(Text::_('COM_CSOVERRIDECHECKER_SESSION_CHAT_PLACEHOLDER')); ?>"></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <span class="icon-share" aria-hidden="true"></span>
                                <?php echo Text::_('COM_CSOVERRIDECHECKER_SESSION_CHAT_SEND'); ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-3">
            <div class="card mb-3">
                <div class="card-body">
                    <h4 class="card-title"><?php echo Text::_('COM_CSOVERRIDECHECKER_SESSION_ACTIONS'); ?></h4>
                    <?php if (empty($this->actions)) : ?>
                        <p class="text-body-secondary"><?php echo Text::_('COM_CSOVERRIDECHECKER_SESSION_ACTIONS_EMPTY'); ?></p>
                    <?php else : ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($this->actions as $action) : ?>
                                <li class="mb-2">
                                    <code><?php echo $this->escape($action->action); ?></code>
                                    <br>
                                    <small class="text-body-secondary">
                                        <?php echo HTMLHelper::_('date', $action->created_at, Text::_('DATE_FORMAT_LC5')); ?>
                                    </small>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
