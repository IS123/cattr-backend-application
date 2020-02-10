<?php

namespace App\Http\Controllers\Api\v1;

use App\Models\Role;
use App\Models\Screenshot;
use App\Models\TimeInterval;
use App\Rules\BetweenDate;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\EventFilter\Facades\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

/**
 * Class TimeIntervalController
 */
class TimeIntervalController extends ItemController
{
    /**
     * @return string
     */
    public function getItemClass(): string
    {
        return TimeInterval::class;
    }

    /**
     * @return array
     */
    public function getValidationRules(): array
    {
        return [
            'task_id' => 'exists:tasks,id|required',
            'user_id' => 'exists:users,id|required',
            'start_at' => 'date|required',
            'end_at' => 'date|required',
        ];
    }

    /**
     * @return array
     */
    public static function getControllerRules(): array
    {
        return [
            'index' => 'time-intervals.list',
            'count' => 'time-intervals.list',
            'create' => 'time-intervals.create',
            'bulkCreate' => 'time-intervals.bulk-create',
            'edit' => 'time-intervals.edit',
            'bulkEdit' => 'time-intervals.bulk-edit',
            'show' => 'time-intervals.show',
            'destroy' => 'time-intervals.remove',
            'bulkDestroy' => 'time-intervals.bulk-remove',
            'manualCreate' => 'time-intervals.create',
        ];
    }

    /**
     * @param array $intervalData
     * @return bool
     */
    public function validateEndDate(array $intervalData): bool
    {
        $start_at = $intervalData['start_at'] ?? '';
        $end_at_rules = [];
        $timeOffset = 3600; /* one hour */
        $beforeTimestamp = strtotime($start_at) + $timeOffset;
        $beforeDate = date(DATE_ATOM, $beforeTimestamp);
        $end_at_rules[] = new BetweenDate($start_at, $beforeDate);

        $validator = Validator::make(
            $intervalData,
            Filter::process(
                $this->getEventUniqueName('validation.item.create'),
                ['end_at' => $end_at_rules]
            )
        );

        return !$validator->fails();
    }

    /**
     * @api             {post} /v1/time-intervals/create Create
     * @apiDescription  Create Time Interval
     *
     * @apiVersion      1.0.0
     * @apiName         Create
     * @apiGroup        Time Interval
     *
     * @apiUse          AuthHeader
     *
     * @apiPermission   time_intervals_create
     * @apiPermission   time_intervals_full_access
     *
     * @apiParam {Integer}  task_id           Task id
     * @apiParam {Integer}  user_id           User id
     * @apiParam {String}   start_at          Interval time start
     * @apiParam {String}   end_at            Interval time end
     *
     * @apiParam {Integer}  [count_mouse]     Mouse events count
     * @apiParam {Integer}  [count_keyboard]  Keyboard events count
     *
     * @apiParamExample {json} Request Example
     * {
     *   "task_id": 1,
     *   "user_id": 1,
     *   "start_at": "2013-04-12T16:40:00-04:00",
     *   "end_at": "2013-04-12T16:40:00-04:00"
     * }
     *
     * @apiSuccess {Boolean}  success   Indicates successful request when `TRUE`
     * @apiSuccess {Object}   interval  Interval
     *
     * @apiUse          TimeIntervalObject
     *
     * @apiSuccessExample {json} Response Example
     *  HTTP/1.1 200 OK
     *  {
     *    "success": true,
     *    "interval": {
     *      "id": 2251,
     *      "task_id": 1,
     *      "start_at": "2013-04-12 20:40:00",
     *      "end_at": "2013-04-12 20:40:00",
     *      "is_manual": true,
     *      "created_at": "2018-10-01 03:20:59",
     *      "updated_at": "2018-10-01 03:20:59",
     *      "count_mouse": 0,
     *      "count_keyboard": 0,
     *      "user_id": 1
     *    }
     *  }
     *
     * @apiUse         400Error
     * @apiUse         ValidationError
     * @apiUse         UnauthorizedError
     * @apiUse         ForbiddenError
     */
    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function create(Request $request): JsonResponse
    {
        $intervalData = [
            'task_id' => $request->input('task_id'),
            'user_id' => $request->input('user_id'),
            'start_at' => $request->input('start_at'),
            'end_at' => $request->input('end_at'),
            'count_mouse' => $request->input('count_mouse'),
            'count_keyboard' => $request->input('count_keyboard')
        ];

        $validator = Validator::make(
            $intervalData,
            Filter::process(
                $this->getEventUniqueName('validation.item.create'),
                $this->getValidationRules()
            )
        );

        if ($validator->fails()) {
            return response()->json(
                Filter::process($this->getEventUniqueName('answer.error.item.create'), [
                    'success' => false,
                    'error_type' => 'validation',
                    'message' => 'Validation error',
                    'info' => $validator->errors()
                ]),
                400
            );
        }

        $existing = TimeInterval::where('user_id', $intervalData['user_id'])
            ->where('start_at', $intervalData['start_at'])
            ->where('end_at', $intervalData['end_at'])
            ->first();

        if ($existing) {
            return response()->json(
                Filter::process($this->getEventUniqueName('answer.error.item.create'), [
                    'success' => false,
                    'error_type' => 'query.item_already_exists',
                    'message' => 'Interval already exists'
                ]),
                409
            );
        }

        if (!$this->validateEndDate($intervalData)) {
            return response()->json(
                Filter::process($this->getEventUniqueName('answer.error.item.create'), [
                    'success' => false,
                    'error_type' => 'validation',
                    'message' => 'Validation error',
                    'info' => 'Invalid interval'
                ]),
                400
            );
        }

        $timeInterval = TimeInterval::create($intervalData);

        //create screenshot
        if (isset($request->screenshot)) {
            $path = Filter::process(
                $this->getEventUniqueName('request.item.create'),
                $request->screenshot->store('uploads/screenshots')
            );
            $screenshot = Image::make(storage_path('app/' . $path));
            $thumbnail = $screenshot->resize(280, null, static function ($constraint) {
                $constraint->aspectRatio();
            });
            $thumbnailPath = str_replace('uploads/screenshots', 'uploads/screenshots/thumbs', $path);
            Storage::put($thumbnailPath, (string)$thumbnail->encode());

            $screenshotData = [
                'time_interval_id' => $timeInterval->id,
                'path' => $path,
                'thumbnail_path' => $thumbnailPath,
            ];

            Filter::process('item.create.screenshot', Screenshot::create($screenshotData));
        }

        return response()->json(
            Filter::process($this->getEventUniqueName('answer.success.item.create'), [
                'success' => true,
                'interval' => $timeInterval,
            ])
        );
    }

    /**
     * @api             {post} /v1/time-intervals/manual-create Manual Create
     * @apiDescription  Manual Create Time Interval
     *
     * @apiVersion      1.0.0
     * @apiName         Manual Create
     * @apiGroup        Time Interval
     *
     * @apiPermission   time_intervals_manual_create
     *
     * @apiParam {Integer}  task_id   Task id
     * @apiParam {Integer}  user_id   User id
     * @apiParam {String}   start_at  Interval time start
     * @apiParam {String}   end_at    Interval time end
     *
     * @apiParamExample {json} Request Example
     * {
     *   "task_id": 1,
     *   "user_id": 1,
     *   "start_at": "2013-04-12T16:40:00-04:00",
     *   "end_at": "2013-04-12T16:40:00-04:00"
     * }
     *
     * @apiSuccess {Boolean}  success   Indicates successful request when `TRUE`
     * @apiSuccess {Object}   interval  Interval
     *
     * @apiSuccessExample {json} Response Example
     *  HTTP/1.1 200 OK
     *  {
     *    "success": true,
     *    "interval": {
     *     "id": 2251,
     *     "task_id": 1,
     *     "start_at": "2013-04-12 20:40:00",
     *     "end_at": "2013-04-12 20:40:00",
     *     "is_manual": true,
     *     "created_at": "2018-10-01 03:20:59",
     *     "updated_at": "2018-10-01 03:20:59",
     *     "count_mouse": 0,
     *     "count_keyboard": 0,
     *     "user_id": 1
     *   }
     * }
     *
     * @apiUse         400Error
     * @apiUse         ValidationError
     * @apiUse         UnauthorizedError
     * @apiUse         ForbiddenError
     */
    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function manualCreate(Request $request): JsonResponse
    {
        /* TODO: Add permission validation to time management for other users */

        // TODO DUPLICATED CODE

        if (!$request->user()->manual_time) {
            return response()->json(
                Filter::process($this->getEventUniqueName('answer.error.item.show'), [
                    'success' => false,
                    'error_type' => 'authorization.forbidden',
                    'message' => 'Access denied',
                    'info' => 'User does not have access to manual time editing'
                ]),
                403
            );
        }

        $intervalData = [
            'task_id' => $request->input('task_id'),
            'user_id' => $request->input('user_id'),
            'start_at' => $request->input('start_at'),
            'end_at' => $request->input('end_at'),
            'is_manual' => true,
        ];

        $validator = Validator::make(
            $intervalData,
            Filter::process(
                $this->getEventUniqueName('validation.item.create'),
                $this->getValidationRules()
            )
        );

        if ($validator->fails()) {
            return response()->json(
                Filter::process($this->getEventUniqueName('answer.error.item.create'), [
                    'success' => false,
                    'error_type' => 'validation',
                    'message' => 'Validation error',
                    'info' => $validator->errors()
                ]),
                400
            );
        }

        $existing = TimeInterval::where('user_id', $intervalData['user_id'])
            ->where('start_at', $intervalData['start_at'])
            ->where('end_at', $intervalData['end_at'])
            ->first();

        if ($existing) {
            return response()->json(
                Filter::process($this->getEventUniqueName('answer.error.item.create'), [
                    'success' => false,
                    'error_type' => 'query.item_already_exists',
                    'message' => 'Interval already exists'
                ]),
                409
            );
        }

        $timeInterval = Filter::process(
            $this->getEventUniqueName('item.create'),
            TimeInterval::create($intervalData)
        );

        return response()->json(
            Filter::process($this->getEventUniqueName('answer.success.item.create'), [
                'success' => true,
                'interval' => $timeInterval,
            ])
        );
    }

    /**
     * @apiDeprecated   since 1.0.0
     * @api             {post} /v1/time-intervals/bulk-create Bulk Create
     * @apiDescription  Create Time Intervals
     *
     * @apiVersion      1.0.0
     * @apiName         Bulk Create
     * @apiGroup        Time Interval
     */

    /**
     * @return string
     */
    public function getEventUniqueNamePart(): string
    {
        return 'timeinterval';
    }

    /**
     * @api             {post} /v1/time-intervals/list List
     * @apiDescription  Get list of Time Intervals
     *
     * @apiVersion      1.0.0
     * @apiName         List
     * @apiGroup        Time Interval
     *
     * @apiUse          AuthHeader
     *
     * @apiPermission   time_intervals_list
     * @apiPermission   time_intervals_full_access
     *
     * @apiUse          TimeIntervalParams
     * @apiUse          TimeIntervalObject
     *
     * @apiSuccessExample {json} Response Example
     *  HTTP/1.1 200 OK
     *  [
     *    {
     *      "id":1,
     *      "task_id":1,
     *      "start_at":"2006-06-20 15:54:40",
     *      "end_at":"2006-06-20 15:59:38",
     *      "created_at":"2018-10-15 05:54:39",
     *      "updated_at":"2018-10-15 05:54:39",
     *      "deleted_at":null,
     *      "count_mouse":42,
     *      "count_keyboard":43,
     *      "user_id":1
     *    }
     *  ]
     *
     * @apiUse         400Error
     * @apiUse         UnauthorizedError
     * @apiUse         ForbiddenError
     */
    /**
     * @param Request $request
     * @return JsonResponse
     * @throws Exception
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->all();
        $request->get('project_id') ? $filters['task.project_id'] = $request->get('project_id') : false;

        $baseQuery = $this->applyQueryFilter(
            $this->getQuery(),
            $filters ?: []
        );

        $itemsQuery = Filter::process(
            $this->getEventUniqueName('answer.success.item.list.query.prepare'),
            $baseQuery
        );

        return response()->json(
            Filter::process(
                $this->getEventUniqueName('answer.success.item.list.result'),
                $itemsQuery->get()
            )
        );
    }

    /**
     * @api             {post} /v1/time-intervals/show Show
     * @apiDescription  Show Time Interval
     *
     * @apiVersion      1.0.0
     * @apiName         Show
     * @apiGroup        Time Interval
     *
     * @apiUse          AuthHeader
     *
     * @apiPermission   time_intervals_show
     * @apiPermission   time_intervals_full_access
     *
     * @apiParam {Integer}  id     Time Interval id
     *
     * @apiUse          TimeIntervalParams
     *
     * @apiParamExample {json} Request Example
     * {
     *   "id": 1
     * }
     *
     * @apiUse          TimeIntervalObject
     *
     * @apiSuccessExample {json} Response Example
     * {
     *   "id": 1,
     *   "task_id": 1,
     *   "start_at": "2006-05-31 16:15:09",
     *   "end_at": "2006-05-31 16:20:07",
     *   "created_at": "2018-09-25 06:15:08",
     *   "updated_at": "2018-09-25 06:15:08",
     *   "deleted_at": null,
     *   "count_mouse": 88,
     *   "count_keyboard": 127,
     *   "user_id": 1
     * }
     *
     * @apiUse         400Error
     * @apiUse         UnauthorizedError
     * @apiUse         ItemNotFoundError
     * @apiUse         ForbiddenError
     * @apiUse         ValidationError
     */

    /**
     * @api             {post} /v1/time-intervals/edit Edit
     * @apiDescription  Edit Time Interval
     *
     * @apiVersion      1.0.0
     * @apiName         Edit
     * @apiGroup        Time Interval
     *
     * @apiUse          AuthHeader
     *
     * @apiPermission   time_intervals_edit
     * @apiPermission   time_intervals_full_access
     *
     * @apiParam {Integer}  id           Time Interval id
     *
     * @apiUse          TimeIntervalParams
     *
     * @apiSuccess {Boolean}  success  Indicates successful request when `TRUE`
     * @apiSuccess {Object}   res      TimeInterval
     *
     * @apiSuccessExample {json} Response Example
     *  HTTP/1.1 200 OK
     *  {
     *    "success": true,
     *    "res": {
     *      "id":1,
     *      "task_id":1,
     *      "start_at":"2018-10-03 10:00:00",
     *      "end_at":"2018-10-03 10:00:00",
     *      "created_at":"2018-10-15 05:50:39",
     *      "updated_at":"2018-10-15 05:50:43",
     *      "deleted_at":null,
     *      "count_mouse":42,
     *      "count_keyboard":43,
     *      "user_id":1
     *    }
     *  }
     *
     * @apiUse         400Error
     * @apiUse         ValidationError
     * @apiUse         UnauthorizedError
     * @apiUse         ItemNotFoundError
     */
    /**
     * @param Request $request
     * @return JsonResponse
     * @throws Exception
     */
    public function edit(Request $request): JsonResponse
    {
        $requestData = Filter::process(
            $this->getEventUniqueName('request.item.edit'),
            $request->all()
        );

        $validationRules = $this->getValidationRules();
        $validationRules['id'] = 'required|integer';

        $validator = Validator::make(
            $requestData,
            Filter::process(
                $this->getEventUniqueName('validation.item.edit'),
                $validationRules
            )
        );

        if ($validator->fails()) {
            return response()->json(
                Filter::process($this->getEventUniqueName('answer.error.item.edit'), [
                    'success' => false,
                    'error_type' => 'validation',
                    'message' => 'Validation error',
                    'info' => $validator->errors()
                ]),
                400
            );
        }

        //create time interval
        $requestData['start_at'] = (new Carbon($requestData['start_at']))->setTimezone('UTC')->toDateTimeString();
        $requestData['end_at'] = (new Carbon($requestData['end_at']))->setTimezone('UTC')->toDateTimeString();

        /** @var Builder $itemsQuery */
        $itemsQuery = Filter::process(
            $this->getEventUniqueName('answer.success.item.query.prepare'),
            $this->applyQueryFilter(
                $this->getQuery()
            )
        );

        /** @var Model $item */
        $item = collect($itemsQuery->get())->first(static function ($val, $key) use ($request) {
            return $val['id'] === $request->get('id');
        });

        if (!$item) {
            return response()->json(
                Filter::process($this->getEventUniqueName('answer.error.item.edit'), [
                    'success' => false,
                    'error_type' => 'query.item_not_found',
                    'message' => 'Item not found',
                ]),
                404
            );
        }

        $item->fill($this->filterRequestData($requestData));
        if (!$this->validateEndDate($requestData)) {
            return response()->json(
                Filter::process($this->getEventUniqueName('answer.success.item.edit'), [
                    'success' => false,
                    'error_type' => 'validation',
                    'message' => 'Validation error',
                    'info' => 'Invalid interval'
                ]),
                400
            );
        }
        $item = Filter::process($this->getEventUniqueName('item.edit'), $item);
        $item->save();

        return response()->json(
            Filter::process($this->getEventUniqueName('answer.success.item.edit'), [
                'success' => true,
                'res' => $item,
            ])
        );
    }

    /**
     * @api             {post} /v1/time-intervals/bulk-edit Bulk Edit
     * @apiDescription  Multiple Edit TimeInterval to assign tasks to them
     *
     * @apiVersion      1.0.0
     * @apiName         Bulk Edit
     * @apiGroup        Time Interval
     *
     * @apiUse          AuthHeader
     *
     * @apiParam {Object[]}  intervals          Time Intervals to edit
     * @apiParam {Integer}   intervals.id       Time Interval ID
     * @apiParam {Integer}   intervals.task_id  Task ID
     *
     * @apiParamExample {json} Request Example
     * {
     *   "intervals": [
     *     {
     *       "id": 12,
     *       "task_id": 12
     *     },
     *     {
     *       "id": 13,
     *       "task_id": 16
     *     }
     *   ]
     * }
     *
     * @apiSuccess {Boolean}    success    Indicates successful request when `TRUE`
     * @apiSuccess {String}     message    Message from server
     * @apiSuccess {Integer[]}  updated    Updated intervals
     * @apiSuccess {Integer[]}  not_found  Not found intervals
     *
     * @apiSuccessExample {json} Response Example
     *  HTTP/1.1 200 OK
     *  {
     *    "success": true,
     *    "message": "Intervals successfully updated",
     *    "updated": [12, 123, 45],
     *  }
     *
     * @apiSuccessExample {json} Not all intervals updated Response Example
     *  HTTP/1.1 207 Multi-Status
     *  {
     *    "success": true,
     *    "message": "Some intervals have not been updated",
     *    "updated": [12, 123, 45],
     *    "not_found": [154, 77, 66]
     *  }
     *
     * @apiUse          400Error
     * @apiUse          ValidationError
     * @apiUse          UnauthorizedError
     * @apiUse          ForbiddenError
     */
    /**
     * @param Request $request
     * @return JsonResponse
     * @throws Exception
     */
    public function bulkEdit(Request $request): JsonResponse
    {
        $validationRules = [
            'intervals' => 'required|array',
            'intervals.*.id' => 'required|integer',
            'intervals.*.task_id' => 'required|integer'
        ];

        $validator = Validator::make(
            Filter::process($this->getEventUniqueName('request.item.edit'), $request->all()),
            Filter::process($this->getEventUniqueName('validation.item.edit'), $validationRules)
        );

        if ($validator->fails()) {
            return response()->json(
                Filter::process($this->getEventUniqueName('answer.error.item.bulkEdit'), [
                    'success' => false,
                    'error_type' => 'validation',
                    'message' => 'Validation error',
                    'info' => $validator->errors()
                ]),
                400
            );
        }

        $intervalsData = collect($validator->validated()['intervals']);

        /** @var Builder $itemsQuery */
        $itemsQuery = Filter::process(
            $this->getEventUniqueName('answer.success.item.query.prepare'),
            $this->applyQueryFilter($this->getQuery(), ['id' => ['in', $intervalsData->pluck('id')->toArray()]])
        );

        $foundIds = $itemsQuery->pluck('id')->toArray();
        $notFoundIds = array_diff($intervalsData->pluck('id')->toArray(), $foundIds);

        $itemsQuery->each(static function (Model $item) use ($intervalsData) {
            $item->update(array_only($intervalsData->where('id', $item->id)->first(), 'task_id'));
        });

        $responseData = [
            'success' => true,
            'message' => 'Intervals successfully updated',
            'updated' => $foundIds
        ];

        if ($notFoundIds) {
            $responseData['message'] = 'Some intervals have not been updated';
            $responseData['not_found'] = array_values($notFoundIds);
        }

        return response()->json(
            Filter::process($this->getEventUniqueName('answer.success.item.edit'), $responseData),
            ($notFoundIds) ? 207 : 200
        );
    }

    /**
     * @api             {post} /v1/users/remove Destroy
     * @apiDescription  Destroy Time Interval
     *
     * @apiVersion      1.0.0
     * @apiName         Destroy
     * @apiGroup        Time Interval
     *
     * @apiUse          AuthHeader
     *
     * @apiPermission   time_intervals_remove
     * @apiPermission   time_intervals_full_access
     *
     * @apiParam {Integer}  id  ID of the target interval
     *
     * @apiParamExample {json} Request Example
     * {
     *   "id": 1
     * }
     *
     * @apiSuccess {Boolean}  success  Indicates successful request when `TRUE`
     * @apiSuccess {String}   message  Destroy status
     *
     * @apiSuccessExample {json} Response Example
     *  HTTP/1.1 200 OK
     *  {
     *    "success": true,
     *    "message": "Item has been removed"
     *  }
     *
     * @apiUse          400Error
     * @apiUse          ValidationError
     * @apiUse          ForbiddenError
     * @apiUse          UnauthorizedError
     */

    /**
     * @api             {get,post} /v1/time-intervals/count Count
     * @apiDescription  Count Time Intervals
     *
     * @apiVersion      1.0.0
     * @apiName         Count
     * @apiGroup        Time Interval
     *
     * @apiUse          AuthHeader
     *
     * @apiSuccess {Boolean}  success  Indicates successful request when `TRUE`
     * @apiSuccess {String}   total    Amount of users that we have
     *
     * @apiSuccessExample {json} Response Example
     *  HTTP/1.1 200 OK
     *  {
     *    "success": true,
     *    "total": 2
     *  }
     *
     * @apiUse          400Error
     * @apiUse          ForbiddenError
     * @apiUse          UnauthorizedError
     */

    /**
     * @api            {post} /v1/time-intervals/bulk-remove Bulk Destroy
     * @apiDescription Multiple Destroy TimeInterval
     *
     * @apiVersion     1.0.0
     * @apiName        Bulk Destroy
     * @apiGroup       Time Interval
     *
     * @apiUse         AuthHeader
     *
     * @apiPermission   time_intervals_bulk_remove
     * @apiPermission   time_intervals_full_access
     *
     * @apiParam {Integer[]}  intervals  Intervals ID to delete
     *
     * @apiParamExample {json} Request Example
     * {
     *   "intervals": [ 1, 2, 3 ]
     * }
     *
     * @apiSuccess {Boolean}    success    Indicates successful request when `TRUE`
     * @apiSuccess {String}     message    Message from server
     * @apiSuccess {Integer[]}  removed    Removed intervals
     * @apiSuccess {Integer[]}  not_found  Not found intervals
     *
     * @apiSuccessExample {json} Response Example
     *  HTTP/1.1 200 OK
     *  {
     *    "success": true,
     *    "message": "Intervals successfully removed",
     *    "removed": [12, 123, 45],
     *  }
     *
     * @apiSuccessExample {json} Not all intervals removed Response Example
     *  HTTP/1.1 207 Multi-Status
     *  {
     *    "success": true,
     *    "message": "Some intervals have not been removed",
     *    "removed": [12, 123, 45],
     *    "not_found": [154, 77, 66]
     *  }
     *
     * @apiUse         400Error
     * @apiUse         ValidationError
     * @apiUse         ForbiddenError
     * @apiUse         UnauthorizedError
     */
    /**
     * @param Request $request
     * @return JsonResponse
     * @throws Exception
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $validationRules = [
            'intervals' => 'required|array',
            'intervals.*' => 'integer'
        ];

        $validator = Validator::make(
            Filter::process($this->getEventUniqueName('request.item.destroy'), $request->all()),
            Filter::process($this->getEventUniqueName('validation.item.destroy'), $validationRules)
        );

        if ($validator->fails()) {
            return response()->json(
                Filter::process($this->getEventUniqueName('answer.error.item.bulkDestroy'), [
                    'success' => false,
                    'error_type' => 'validation',
                    'message' => 'Validation error',
                    'info' => $validator->errors()
                ]),
                400
            );
        }

        $intervalIds = $validator->validated()['intervals'];

        /** @var Builder $itemsQuery */
        $itemsQuery = Filter::process(
            $this->getEventUniqueName('answer.success.item.query.prepare'),
            $this->applyQueryFilter($this->getQuery(), ['id' => ['in', $intervalIds]])
        );

        $foundIds = $itemsQuery->pluck('id')->toArray();
        $notFoundIds = array_diff($intervalIds, $foundIds);


        // to cascade screenshots soft deleting
        foreach ($itemsQuery->getModels() as $item) {
            $item->delete();
        }

        $responseData = [
            'success' => true,
            'message' => 'Intervals successfully removed',
            'removed' => $foundIds
        ];

        if ($notFoundIds) {
            $responseData['message'] = 'Some intervals have not been removed';
            $responseData['not_found'] = array_values($notFoundIds);
        }

        return response()->json(
            Filter::process($this->getEventUniqueName('answer.success.item.remove'), $responseData),
            ($notFoundIds) ? 207 : 200
        );
    }

    /**
     * @param bool $withRelations
     *
     * @param bool $withSoftDeleted
     * @return Builder
     */
    protected function getQuery($withRelations = true, $withSoftDeleted = false): Builder
    {
        /** @var User $user */
        $user = Auth::user();
        $query = parent::getQuery($withRelations, $withSoftDeleted);
        $full_access = $user->allowed('time-intervals', 'full_access');
        $action_method = Route::getCurrentRoute()->getActionMethod();

        if ($full_access) {
            return $query;
        }

        $rules = $this->getControllerRules();
        $rule = $rules[$action_method] ?? null;
        if (isset($rule)) {
            [$object, $action] = explode('.', $rule);
            // Check user default role
            if (Role::can($user, $object, $action)) {
                return $query;
            }

            $query->where(function (Builder $query) use ($user, $object, $action) {
                $user_id = $user->id;

                // Filter by project roles of the user
                $query->whereHas('task.project.usersRelation', static function (Builder $query) use ($user_id, $object, $action) {
                    $query->where('user_id', $user_id)->whereHas('role', static function (Builder $query) use ($object, $action) {
                        $query->whereHas('rules', static function (Builder $query) use ($object, $action) {
                            $query->where([
                                'object' => $object,
                                'action' => $action,
                                'allow' => true,
                            ])->select('id');
                        })->select('id');
                    })->select('id');
                });

                // For read and delete access include user own intervals
                $query->when($action !== 'edit', static function (Builder $query) use ($user_id) {
                    $query->orWhere('user_id', $user_id)->select('user_id');
                });

                $query->when($action === 'edit' && (bool)$user->manual_time, static function (Builder $query) use ($user_id) {
                    $query->orWhere('user_id', $user_id)->select('user_id');
                });
            });
        }

        return $query;
    }
}
