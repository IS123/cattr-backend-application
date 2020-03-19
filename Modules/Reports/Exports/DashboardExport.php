<?php

namespace Modules\Reports\Exports;

use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Reports\Entities\DashboardReport;

class DashboardExport implements Exportable
{
    public const SORTABLE_DAYS_FORMAT = 'Y-m-d';
    public const REPORT_DAYS_FORMAT = 'l, d M Y';
    public const ROUND_DIGITS = 3;

    /**
     * @throws Exception
     */
    public function collection(): Collection
    {
        // Please verify that start_at and end_at are fetched in ISO format !
        $queryData = request()->only(
            'start_at',
            'end_at',
            'user_ids',
            'project_ids',
            'order_by',
            'order_dir'
        );

        if (!Arr::has($queryData, ['start_at', 'end_at', 'user_ids'])) {
            throw new Exception('Requested data was not found in request body');
        }

        $queryData['timezone'] = request()->post('timezone', 'UTC');

        $queryData['start_at'] = Carbon::parse(
            $queryData['start_at'],
            $queryData['timezone']
        )->tz('UTC')
            ->toDateTimeString();
        $queryData['end_at'] = Carbon::parse(
            $queryData['end_at'],
            $queryData['timezone']
        )->tz('UTC')
            ->toDateTimeString();

        // If selected one user or exporting report from Timeline tab
        $queryData['user_ids'] = is_array($queryData['user_ids']) ? $queryData['user_ids'] : [$queryData['user_ids']];

        // Prepare collection to the way we need -> assign user his worked time
        $preparedCollection = $this->getPreparedCollection($queryData);

        // Get all dates
        $dates = [];
        foreach ($preparedCollection as $collection) {
            $dates = array_merge($dates, array_keys($collection['per_day']));
        }
        $dates = array_unique($dates);

        // Adjust columns for each user
        $preparedCollection = $preparedCollection->map(static function ($collection) use ($dates) {
            $missingDates = array_diff($dates, array_keys($collection['per_day']));
            foreach ($missingDates as $date) {
                $collection['per_day'][$date] = 0;
            }

            ksort($collection['per_day']);

            return $collection;
        });

        $orderBy = $queryData['order_by'] ?? 'name';
        $orderDir = $queryData['order_dir'] ?? 'asc';
        $preparedCollection = $preparedCollection->toArray();

        usort($preparedCollection, static function ($a, $b) use ($orderBy, $orderDir) {
            return $orderDir === 'asc'
                ? $a[$orderBy] <=> $b[$orderBy]
                : -($a[$orderBy] <=> $b[$orderBy]);
        });
        $preparedCollection = collect($preparedCollection);

        // Create collection which are going to be used for Excel lib
        $returnableData = collect([]);

        // Fill rows with our report data
        foreach ($preparedCollection as $collection) {
            $this->addRowToCollection($returnableData, $collection['name'], $collection['per_day'], $collection['time_worked']);
        }

        return $returnableData;
    }

    /**
     * Get processed, formatted and prepared-to-return collection
     * @throws Exception
     */
    protected function getPreparedCollection(array $collectionData): Collection
    {
        $unpreparedCollection = $this->_getUnpreparedCollection($collectionData);

        return $this->prepareCollection($unpreparedCollection);
    }

    /**
     * Get unprocessed collection from database
     *
     * @param array $queryData
     *
     * @throws Exception
     *
     * @return Builder[]|\Illuminate\Database\Eloquent\Collection
     */
    protected function _getUnpreparedCollection(array $queryData): Collection
    {
        $timezone = $queryData['timezone'];
        $timezoneOffset = (new Carbon())->setTimezone($timezone)->format('P');

        /** @noinspection PhpParamsInspection */
        $query = DashboardReport::select([
            '*',
            DB::raw("(CONVERT_TZ(start_at, '+00:00', '{$timezoneOffset}')) as start_at"),
            DB::raw("(CONVERT_TZ(end_at, '+00:00', '{$timezoneOffset}')) as end_at")
        ])
            ->whereIn('user_id', $queryData['user_ids'])
            ->where('start_at', '>=', $queryData['start_at'])
            ->where('start_at', '<', $queryData['end_at'])
            ->with('users')
            ->orderBy('start_at');

        if (!empty($queryData['project_ids'])) {
            $query = $query->whereHas('task', static function ($query) use ($queryData) {
                $query->whereIn('project_id', $queryData['project_ids']);
            });
        }
        return $query->get();
    }

    /**
     * Preparing returnable collection for "collect" method
     */
    protected function prepareCollection(Collection $collection): Collection
    {
        $plainData = [];

        foreach ($collection as $item) {
            $this->_preparePlainData($item, $plainData, $item->user_id);
        }

        return collect($plainData);
    }

    /**
     * Here we'll need to format plain database data to workable format
     *
     * @param         $item
     * @param array $plainData
     * @param         $userId
     *
     * @return void
     */
    protected function _preparePlainData($item, array &$plainData, $userId): void
    {
        $start = Carbon::createFromFormat('Y-m-d H:i:s', $item->start_at);
        $end = Carbon::createFromFormat('Y-m-d H:i:s', $item->end_at);

        if (!isset($plainData[$userId])) {
            $plainData[$userId] = [
                'id' => $userId,
                'name' => $item->users->full_name,
                'per_day' => [],
                'time_worked' => 0
            ];
        }

        // Use lexicographically sortable keys.
        $key = $start->format(static::SORTABLE_DAYS_FORMAT);
        if (!isset($plainData[$userId]['per_day'][$key])) {
            $plainData[$userId]['per_day'][$key] = 0;
        }

        $seconds = $end->diffInSeconds($start);
        $plainData[$userId]['per_day'][$key] += $seconds;
        $plainData[$userId]['time_worked'] += $seconds;
    }


    /**
     * Add subtotal record to existing collection
     *
     * @throws Exception
     */
    protected function addRowToCollection(Collection $collection, string $userName, $perDay, $totalTime): void
    {
        $timeObject = (new Carbon('@0'))->diff(new Carbon("@$totalTime"));
        $totalTimeDecimal = round($totalTime / 60 / 60, static::ROUND_DIGITS);

        $hours = $timeObject->h + 24 * $timeObject->days;
        $minutes = ($timeObject->i < 10 ? '0' : '') . $timeObject->i;
        $seconds = ($timeObject->s < 10 ? '0' : '') . $timeObject->s;
        $mainInfo = [
            'User' => $userName,
            'Time worked' => "{$hours}:{$minutes}:{$seconds}",
            'Time worked (decimal)' => $totalTimeDecimal,
        ];

        $daysData = [];
        foreach ($perDay as $day => $timeWorked) {
            // The key is represents a day in format "Friday, 01 Nov 2019"
            // Changing REPORT_DAY_FORMAT make sure it will be unique
            $date = Carbon::createFromFormat(static::SORTABLE_DAYS_FORMAT, $day);
            $key = $date->format(static::REPORT_DAYS_FORMAT);

            $daysData[$key] = round($timeWorked / 60 / 60, static::ROUND_DIGITS);
        }

        $collection->push(array_merge($mainInfo, $daysData));
    }

    public function getExporterName(): string
    {
        return 'dashboard';
    }
}
