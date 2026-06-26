<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

final class ActionsHelper
{
    /**
     * Sortable column whitelist — keys are the URL-facing sort tokens,
     * values are the DB column names. Anything outside this list is
     * silently rejected by listFiltered() and falls back to created_at.
     *
     * @var array<string, string>
     */
    private const SORT_COLUMNS = [
        'time'    => 'created_at',
        'session' => 'session_id',
        'action'  => 'action',
    ];

    public const LIMIT_OPTIONS = [20, 50, 100, 200, 500];

    /**
     * @return list<\stdClass>
     */
    public static function listRecent(int $limit = 200): array
    {
        return self::listFiltered([], 'time', 'desc', $limit);
    }

    /**
     * @param array{search?: string, action_type?: string, session_id?: int|string} $filter
     * @return list<\stdClass>
     */
    public static function listFiltered(array $filter, string $sort = 'time', string $dir = 'desc', int $limit = 100): array
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = self::buildBaseQuery($db, $filter);

        $column   = self::SORT_COLUMNS[$sort] ?? 'created_at';
        $direction = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $query->order($db->quoteName($column) . ' ' . $direction);

        // Secondary sort on id so rows with identical timestamps remain
        // deterministically ordered across page reloads.
        $query->order($db->quoteName('id') . ' ' . $direction);

        $limit = max(1, min(500, $limit));
        $db->setQuery($query, 0, $limit);

        return $db->loadObjectList() ?: [];
    }

    /**
     * Distinct action types currently present in the table, for the
     * filter dropdown. Sorted alphabetically.
     *
     * @return list<string>
     */
    public static function distinctActionTypes(): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $db->getQuery(true)
            ->select('DISTINCT ' . $db->quoteName('action'))
            ->from($db->quoteName('#__csoverridechecker_actions'))
            ->order($db->quoteName('action') . ' ASC');

        $rows = $db->setQuery($query)->loadColumn() ?: [];
        return array_values(array_filter(array_map('strval', $rows), static fn ($v) => $v !== ''));
    }

    /**
     * Distinct session ids referenced from this log table, joined with
     * the sessions table for the display name. Drives the session-id
     * filter dropdown so users can pick instead of typing a number.
     *
     * Includes a synthetic 'none' entry if any orphaned rows (rows whose
     * session_id is NULL) exist, so users can find them via the filter.
     *
     * @return list<array{id: int|string, label: string}>
     */
    public static function distinctSessions(): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $db->getQuery(true)
            ->select([
                'DISTINCT ' . $db->quoteName('a.session_id', 'id'),
                $db->quoteName('s.name', 'name'),
            ])
            ->from($db->quoteName('#__csoverridechecker_actions', 'a'))
            ->leftJoin(
                $db->quoteName('#__csoverridechecker_sessions', 's')
                . ' ON ' . $db->quoteName('s.id') . ' = ' . $db->quoteName('a.session_id')
            )
            ->where($db->quoteName('a.session_id') . ' IS NOT NULL')
            ->order($db->quoteName('a.session_id') . ' DESC');

        $rows = $db->setQuery($query)->loadObjectList() ?: [];

        $out = [];
        foreach ($rows as $row) {
            $id    = (int) $row->id;
            $name  = trim((string) ($row->name ?? ''));
            $label = $name !== '' ? '#' . $id . ' — ' . $name : '#' . $id;
            $out[] = ['id' => $id, 'label' => $label];
        }

        // Detect orphans (session_id IS NULL) so the dropdown can offer
        // a "none" filter that surfaces audit rows whose session was
        // never set (legacy data, manual ActionLog calls without a sid).
        $orphanQuery = $db->getQuery(true)
            ->select('1')
            ->from($db->quoteName('#__csoverridechecker_actions'))
            ->where($db->quoteName('session_id') . ' IS NULL');

        if ($db->setQuery($orphanQuery, 0, 1)->loadResult() !== null) {
            array_unshift($out, ['id' => 'none', 'label' => '(no session)']);
        }

        return $out;
    }

    /**
     * @param array{search?: string, action_type?: string, session_id?: int|string} $filter
     */
    private static function buildBaseQuery(DatabaseInterface $db, array $filter)
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'session_id', 'action', 'details', 'user_id', 'created_at']))
            ->from($db->quoteName('#__csoverridechecker_actions'));

        $search = trim((string) ($filter['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(
                '(' . $db->quoteName('action')  . ' LIKE :search1'
                . ' OR ' . $db->quoteName('details') . ' LIKE :search2)'
            );
            $query->bind(':search1', $like);
            $query->bind(':search2', $like);
        }

        $actionType = trim((string) ($filter['action_type'] ?? ''));
        if ($actionType !== '') {
            $query->where($db->quoteName('action') . ' = :action_type');
            $query->bind(':action_type', $actionType);
        }

        $sessionFilter = (string) ($filter['session_id'] ?? '');
        if ($sessionFilter === 'none') {
            $query->where($db->quoteName('session_id') . ' IS NULL');
        } elseif ($sessionFilter !== '' && (int) $sessionFilter > 0) {
            $sid = (int) $sessionFilter;
            $query->where($db->quoteName('session_id') . ' = :sid');
            $query->bind(':sid', $sid, ParameterType::INTEGER);
        }

        return $query;
    }

    /**
     * @return list<\stdClass>
     */
    public static function listForSession(int $sessionId): array
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'session_id', 'action', 'details', 'user_id', 'created_at']))
            ->from($db->quoteName('#__csoverridechecker_actions'))
            ->where($db->quoteName('session_id') . ' = :sid')
            ->bind(':sid', $sessionId, ParameterType::INTEGER)
            ->order($db->quoteName('created_at') . ' ASC');

        return $db->setQuery($query)->loadObjectList() ?: [];
    }
}
