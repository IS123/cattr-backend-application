<?php

namespace App\Http\Controllers\Api;

use App\Contracts\ScreenshotService;
use App\Http\Requests\TimeInterval\BulkDestroyTimeIntervalRequestCattr;
use App\Http\Requests\TimeInterval\BulkEditTimeIntervalRequestCattr;
use App\Http\Requests\TimeInterval\CreateTimeIntervalRequestCattr;
use App\Http\Requests\TimeInterval\DestroyTimeIntervalRequestCattr;
use App\Http\Requests\TimeInterval\EditTimeIntervalRequestCattr;
use App\Http\Requests\TimeInterval\PutScreenshotRequestCattr;
use App\Http\Requests\TimeInterval\ScreenshotRequestCattr;
use App\Http\Requests\TimeInterval\ShowTimeIntervalRequestCattr;
use App\Http\Requests\TimeInterval\TrackAppRequestCattr;
use App\Jobs\AssignAppsToTimeInterval;
use App\Models\TrackedApplication;
use App\Models\User;
use Filter;
use App\Models\TimeInterval;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Settings;
use Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Validator;

class TimeIntervalController extends ItemController
{
    public function __construct(protected ScreenshotService $screenshotService)
    {
    }

    public function getItemClass(): string
    {
        return TimeInterval::class;
    }

    public function create(CreateTimeIntervalRequestCattr $request): JsonResponse
    {
        $intervalData = $request->validated();
        $timezone = Settings::scope('core')->get('timezone', 'UTC');

        $intervalData['start_at'] = Carbon::parse($intervalData['start_at'])->setTimezone($timezone);
        $intervalData['end_at'] = Carbon::parse($intervalData['end_at'])->setTimezone($timezone);

        $timeInterval = TimeInterval::create($intervalData);

        if ($request->hasFile('screenshot') && optional($request->file('screenshot'))->isValid()) {
            $this->screenshotService->saveScreenshot($request->file('screenshot'), $timeInterval);
        }

        if (User::find($intervalData['user_id'])->web_and_app_monitoring) {
            AssignAppsToTimeInterval::dispatchAfterResponse($timeInterval);
        }

        return responder()->success($timeInterval)->respond();
    }

    /**
     * @api             {post} /time-intervals/create Create
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
     * @apiParam {Integer}  [activity_fill]   Activity rate as a percentage
     * @apiParam {Integer}  [mouse_fill]      Time spent using the mouse as a percentage
     * @apiParam {Integer}  [keyboard_fill]   Time spent using the keyboard as a percentage
     *
     * @apiParamExample {json} Request Example
     * {
     *   "task_id": 1,
     *   "user_id": 1,
     *   "start_at": "2013-04-12T16:40:00-04:00",
     *   "end_at": "2013-04-12T16:40:00-04:00"
     * }
     *
     * @apiSuccess {Object}   interval  Interval
     *
     * @apiUse          TimeIntervalObject
     *
     * @apiSuccessExample {json} Response Example
     *  HTTP/1.1 200 OK
     *  {
     *    "interval": {
     *      "id": 2251,
     *      "task_id": 1,
     *      "start_at": "2013-04-12 20:40:00",
     *      "end_at": "2013-04-12 20:40:00",
     *      "is_manual": true,
     *      "created_at": "2018-10-01 03:20:59",
     *      "updated_at": "2018-10-01 03:20:59",
     *      "activity_fill": 0,
     *      "mouse_fill": 0,
     *      "keyboard_fill": 0,
     *      "user_id": 1
     *    }
     *  }
     *
     * @apiUse          400Error
     * @apiUse          ValidationError
     * @apiUse          UnauthorizedError
     * @apiUse          ForbiddenError
     */

    public function getEventUniqueNamePart(): string
    {
        return 'timeinterval';
    }

    /**
     * @param Request $request
     *
     * @return JsonResponse
     * @throws Exception
     */
    public function index(Request $request): JsonResponse
    {
        Filter::listen(Filter::getQueryPrepareFilterName(), static function ($filters) use ($request) {
            if ($request->get('project_id')) {
                $filters['task.project_id'] = $request->get('project_id');
            }

            return $filters;
        });

        return $this->_index($request);
    }

    /**
     * @apiDeprecated   since 1.0.0
     * @api             {post} /time-intervals/bulk-create Bulk Create
     * @apiDescription  Create Time Intervals
     *
     * @apiVersion      1.0.0
     * @apiName         Bulk Create
     * @apiGroup        Time Interval
     */

    /**
     * @param ShowTimeIntervalRequestCattr $request
     *
     * @return JsonResponse
     * @throws Exception
     * @api             {post} /time-intervals/show Show
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
     *   "activity_fill": 42,
     *   "mouse_fill": 43,
     *   "keyboard_fill": 43,
     *   "user_id": 1
     * }
     *
     * @apiUse          400Error
     * @apiUse          UnauthorizedError
     * @apiUse          ItemNotFoundError
     * @apiUse          ForbiddenError
     * @apiUse          ValidationError
     */
    public function show(ShowTimeIntervalRequestCattr $request): JsonResponse
    {
        return $this->_show($request);
    }

    /**
     * @api             {post} /time-intervals/list List
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
     *      "id": 1,
     *      "task_id": 1,
     *      "start_at": "2006-06-20 15:54:40",
     *      "end_at": "2006-06-20 15:59:38",
     *      "created_at": "2018-10-15 05:54:39",
     *      "updated_at": "2018-10-15 05:54:39",
     *      "deleted_at": null,
     *      "activity_fill": 42,
     *      "mouse_fill": 43,
     *      "keyboard_fill": 43,
     *      "user_id":1
     *    }
     *  ]
     *
     * @apiUse          400Error
     * @apiUse          UnauthorizedError
     * @apiUse          ForbiddenError
     */

    /**
     * @param EditTimeIntervalRequestCattr $request
     *
     * @return JsonResponse
     * @throws Exception
     */
    public function edit(EditTimeIntervalRequestCattr $request): JsonResponse
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
            return new JsonResponse(
                Filter::process($this->getEventUniqueName('answer.error.item.edit'), [
                    'error_type' => 'validation',
                    'message' => 'Validation error',
                    'info' => $validator->errors(),
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
            return new JsonResponse(
                Filter::process($this->getEventUniqueName('answer.error.item.edit'), [
                    'error_type' => 'query.item_not_found',
                    'message' => 'Item not found',
                ]),
                404
            );
        }

        $item->fill($this->filterRequestData($requestData));
        $item = Filter::process($this->getEventUniqueName('item.edit'), $item);
        $item->save();

        return responder()->success($item)->respond();
    }

    /**
     * @api             {post} /time-intervals/edit Edit
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
     * @apiSuccess {Object}   res      TimeInterval
     *
     * @apiSuccessExample {json} Response Example
     *  HTTP/1.1 200 OK
     *  {
     *    "res": {
     *      "id":1,
     *      "task_id":1,
     *      "start_at":"2018-10-03 10:00:00",
     *      "end_at":"2018-10-03 10:00:00",
     *      "created_at":"2018-10-15 05:50:39",
     *      "updated_at":"2018-10-15 05:50:43",
     *      "deleted_at":null,
     *      "activity_fill": 42,
     *      "mouse_fill": 43,
     *      "keyboard_fill": 43,
     *      "user_id":1
     *    }
     *  }
     *
     * @apiUse          400Error
     * @apiUse          ValidationError
     * @apiUse          UnauthorizedError
     * @apiUse          ItemNotFoundError
     */

    /**
     * @api             {post} /time-intervals/bulk-edit Bulk Edit
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
     * @apiSuccess {String}     message    Message from server
     * @apiSuccess {Integer[]}  updated    Updated intervals
     * @apiSuccess {Integer[]}  not_found  Not found intervals
     *
     * @apiSuccessExample {json} Response Example
     *  HTTP/1.1 200 OK
     *  {
     *    "message": "Intervals successfully updated",
     *    "updated": [12, 123, 45],
     *  }
     *
     * @apiSuccessExample {json} Not all intervals updated Response Example
     *  HTTP/1.1 207 Multi-Status
     *  {
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

    public function getValidationRules(): array
    {
        return [];
    }

    /**
     * @throws Exception
     * @api             {get,post} /time-intervals/count Count
     * @apiDescription  Count Time Intervals
     *
     * @apiVersion      1.0.0
     * @apiName         Count
     * @apiGroup        Time Interval
     *
     * @apiUse          AuthHeader
     *
     * @apiSuccess {String}   total    Amount of users that we have
     *
     * @apiSuccessExample {json} Response Example
     *  HTTP/1.1 200 OK
     *  {
     *    "total": 2
     *  }
     *
     * @apiUse          400Error
     * @apiUse          ForbiddenError
     * @apiUse          UnauthorizedError
     */
    public function count(Request $request): JsonResponse
    {
        return $this->_count($request);
    }

    /**
     * @throws Exception
     * @api             {post} /time-intervals/remove Destroy
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
     * @apiSuccess {String}   message  Destroy status
     *
     * @apiSuccessExample {json} Response Example
     *  HTTP/1.1 200 OK
     *  {
     *    "message": "Item has been removed"
     *  }
     *
     * @apiUse          400Error
     * @apiUse          ValidationError
     * @apiUse          ForbiddenError
     * @apiUse          UnauthorizedError
     */
    public function destroy(DestroyTimeIntervalRequestCattr $request): JsonResponse
    {
        return $this->_destroy($request);
    }

    /**
     * @param BulkEditTimeIntervalRequestCattr $request
     *
     * @return JsonResponse
     * @throws Exception
     */
    public function bulkEdit(BulkEditTimeIntervalRequestCattr $request): JsonResponse
    {
        $intervalsData = collect($request->validated()['intervals']);

        /** @var Builder $itemsQuery */
        $itemsQuery = Filter::process(
            $this->getEventUniqueName('answer.success.item.query.prepare'),
            $this->applyQueryFilter($this->getQuery(), ['id' => ['in', $intervalsData->pluck('id')->toArray()]])
        );

        $itemsQuery->each(static function (Model $item) use ($intervalsData) {
            $item->update(Arr::only($intervalsData->where('id', $item->id)->first() ?: [], 'task_id'));
        });

        return responder()->success()->respond(204);
    }

    /**
     * @throws Exception
     * @api             {post} /time-intervals/bulk-remove Bulk Destroy
     * @apiDescription  Multiple Destroy TimeInterval
     *
     * @apiVersion      1.0.0
     * @apiName         Bulk Destroy
     * @apiGroup        Time Interval
     *
     * @apiUse          AuthHeader
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
     * @apiSuccess {String}     message    Message from server
     * @apiSuccess {Integer[]}  removed    Removed intervals
     * @apiSuccess {Integer[]}  not_found  Not found intervals
     *
     * @apiSuccessExample {json} Response Example
     *  HTTP/1.1 200 OK
     *  {
     *    "message": "Intervals successfully removed",
     *    "removed": [12, 123, 45],
     *  }
     *
     * @apiSuccessExample {json} Not all intervals removed Response Example
     *  HTTP/1.1 207 Multi-Status
     *  {
     *    "message": "Some intervals have not been removed",
     *    "removed": [12, 123, 45],
     *    "not_found": [154, 77, 66]
     *  }
     *
     * @apiUse          400Error
     * @apiUse          ValidationError
     * @apiUse          ForbiddenError
     * @apiUse          UnauthorizedError
     *
     */
    public function bulkDestroy(BulkDestroyTimeIntervalRequestCattr $request): JsonResponse
    {
        $intervalIds = $request->validated()['intervals'];

        /** @var Builder $itemsQuery */
        $itemsQuery = Filter::process(
            $this->getEventUniqueName('answer.success.item.query.prepare'),
            $this->applyQueryFilter($this->getQuery(), ['id' => ['in', $intervalIds]])
        );

        // to cascade screenshots soft deleting
        foreach ($itemsQuery->getModels() as $item) {
            $item->delete();
        }

        return responder()->success()->respond(204);
    }

    public function trackApp(TrackAppRequestCattr $request): JsonResponse
    {
        $user = auth()->user();
        if (!isset($user)) {
            abort(401);
        }

        $item = TrackedApplication::create(array_merge($request->validated(), ['user_id' => $user->id]));

        return responder()->success($item)->respond();
    }

    public function showScreenshot(ScreenshotRequestCattr $request, TimeInterval $interval): BinaryFileResponse
    {
        $path = $this->screenshotService->getScreenshotPath($interval);
        if (!Storage::exists($path)) {
            abort(404);
        }

        $fullPath = Storage::path($path);

        return response()->file($fullPath);
    }

    public function showThumbnail(ScreenshotRequestCattr $request, TimeInterval $interval): BinaryFileResponse
    {
        $path = $this->screenshotService->getThumbPath($interval);
        if (!Storage::exists($path)) {
            abort(404);
        }

        $fullPath = Storage::path($path);

        return response()->file($fullPath);
    }

    public function putScreenshot(PutScreenshotRequestCattr $request, TimeInterval $interval): JsonResponse
    {
        $data = $request->validated();

        abort_if(
            Storage::exists($this->screenshotService->getScreenshotPath($interval)),
            409,
            __('Screenshot for requested interval already exists')
        );

        $this->screenshotService->saveScreenshot($data['screenshot'], $interval);

        return responder()->success()->respond(204);
    }
}
