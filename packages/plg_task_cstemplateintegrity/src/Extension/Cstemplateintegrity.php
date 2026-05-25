<?php

/**
 * @package     Cstemplateintegrity
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Scheduled-task plugin for cs-template-integrity. Registers one task
 * routine:
 *
 *   cstemplateintegrity.purgeBackups
 *     Deletes #__cstemplateintegrity_backups rows older than N days
 *     (configurable per-task, default 30). The "fix-applied" backups
 *     are intended as a short-window safety net for restoring a file
 *     after a Claude-applied patch; they accumulate forever otherwise.
 *
 * Mirrors the structure of Joomla core task plugins (plg_task_sitestatus,
 * plg_task_sessiongc) — TaskPluginTrait + TASKS_MAP + event subscribers
 * + a single method per routine.
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\Task\Cstemplateintegrity\Extension;

defined('_JEXEC') or die;

use Cybersalt\Component\Cstemplateintegrity\Administrator\Helper\BackupsHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\SubscriberInterface;

final class Cstemplateintegrity extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;

    /**
     * Routine catalogue. Maps the routine id (saved on the task row in
     * #__scheduler_tasks) to the language-string prefix, the task-params
     * form filename, and the method on this class that actually runs it.
     *
     * @var array<string, array{langConstPrefix: string, form?: string, method: string}>
     */
    private const TASKS_MAP = [
        'cstemplateintegrity.purgeBackups' => [
            'langConstPrefix' => 'PLG_TASK_CSTEMPLATEINTEGRITY_TASK_PURGE_BACKUPS',
            'form'            => 'purge_backups',
            'method'          => 'purgeBackups',
        ],
    ];

    /**
     * Required by CMSPlugin convention; ensures language files for
     * the task type's UI strings (title, description, field labels)
     * are loaded before the dropdown / form renders.
     */
    protected $autoloadLanguage = true;

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList'    => 'advertiseRoutines',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
            'onExecuteTask'        => 'standardRoutineHandler',
        ];
    }

    /**
     * Actual purge routine. The trait's standardRoutineHandler() calls
     * this when the scheduler fires a task whose routine id is
     * cstemplateintegrity.purgeBackups.
     *
     * Returns an integer Status code:
     *   Status::OK          — purge completed cleanly (zero or more rows deleted)
     *   Status::KNOCKOUT_OK — task ran but should not run again on this tick
     *
     * Errors bubble out as exceptions — the scheduler converts those
     * into Status::KNOCKOUT_FAIL and records them on the task row.
     */
    protected function purgeBackups(ExecuteTaskEvent $event): int
    {
        $params        = (object) ($event->getArgument('params') ?? new \stdClass());
        $retentionDays = max(1, (int) ($params->retention_days ?? 30));
        $minKeep       = max(0, (int) ($params->min_keep ?? 0));
        $dryRun        = (bool) (int) ($params->dry_run ?? 0);

        $this->logTask(
            sprintf(
                'cs-template-integrity: %spurging backups older than %d day(s); always keep %d most-recent',
                $dryRun ? '[DRY RUN] ' : '',
                $retentionDays,
                $minKeep
            )
        );

        $result = BackupsHelper::purgeOlderThan($retentionDays, $minKeep, $dryRun);

        $this->logTask(
            sprintf(
                'cs-template-integrity: %s — would delete %d, deleted %d, kept by floor %d (cutoff: %s)',
                $dryRun ? 'DRY RUN' : 'purge complete',
                $result['would_delete'],
                $result['deleted'],
                $result['kept_by_floor'],
                $result['cutoff']
            )
        );

        return Status::OK;
    }
}
