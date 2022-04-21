<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Task\CreateTaskRequestCattr;
use App\Http\Requests\Task\DestroyTaskRequestCattr;
use App\Http\Requests\Task\EditTaskRequestCattr;
use App\Http\Requests\Task\ShowTaskRequestCattr;
use App\Models\Priority;
use App\Models\Project;
use Exception;
use Filter;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use DB;
use Event;
use Illuminate\Support\Arr;
use Settings;
use Throwable;

class TaskController extends ItemController
{
    public function getItemClass(): string
    {
        return Task::class;
    }

    public function getValidationRules(): array
    {
        return [];
    }

    public function getEventUniqueNamePart(): string
    {
        return 'task';
    }

    /**
     * @api             {post} /tasks/list List
     * @apiDescription  Get list of Tasks
     *
     * @apiVersion      1.0.0
     * @apiName         List
     * @apiGroup        Task
     *
     * @apiUse          AuthHeader
     *
     * @apiPermission   tasks_list
     * @apiPermission   tasks_full_access
     *
     * @apiUse          TaskParams
     * @apiUse          TaskObject
     *
     * @apiSuccessExample {json} Response Example
     *  HTTP/1.1 200 OK
     *  [
     *    {
     *      "id": 2,
     *      "project_id": 1,
     *      "task_name": "Delectus.",
     *      "description": "Et qui sed qui vero quis.
     *                      Vitae corporis sapiente saepe dolor rerum. Eligendi commodi quia rerum ut.",
     *      "active": 1,
     *      "user_id": 1,
     *      "assigned_by": 1,
     *      "url": null,
     *      "created_at": "2020-01-23T09:42:26+00:00",
     *      "updated_at": "2020-01-23T09:42:26+00:00",
     *      "deleted_at": null,
     *      "priority_id": 2,
     *      "important": 0
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
        return $this->_index($request);
    }

    /**
     * @throws Throwable
     * @api             {post} /tasks/edit Edit
     * @apiDescription  Edit Task
     *
     * @apiVersion      1.0.0
     * @apiName         Edit
     * @apiGroup        Task
     *
     * @apiUse          AuthHeader
     *
     * @apiPermission   tasks_edit
     * @apiPermission   tasks_full_access
     *
     * @apiParam {Integer}  id           ID
     * @apiParam {Integer}  project_id   Project
     * @apiParam {Integer}  active       Is Task active. Available value: {0,1}
     * @apiParam {Array}    users        Task Users
     * @apiParam {Integer}  priority_id  Priority ID
     *
     * @apiUse         TaskParams
     *
     * @apiParamExample {json} Simple Request Example
     *  {
     *    "id": 1,
     *    "project_id": 2,
     *    "active": 1,
     *    "users": [3],
     *    "assigned_by": 2,
     *    "task_name": "lorem",
     *    "description": "test",
     *    "url": "url",
     *    "priority_id": 1
     *  }
     *
     * @apiSuccess {Object}   res      Task
     *
     * @apiUse         TaskObject
     *
     * @apiSuccessExample {json} Response Example
     *  HTTP/1.1 200 OK
     *  {
     *    "res": {
     *      "id": 2,
     *      "project_id": 1,
     *      "task_name": "Delectus.",
     *      "description": "Et qui sed qui vero quis.
     *                      Vitae corporis sapiente saepe dolor rerum. Eligendi commodi quia rerum ut.",
     *      "active": 1,
     *      "users": [],
     *      "assigned_by": 1,
     *      "url": null,
     *      "created_at": "2020-01-23T09:42:26+00:00",
     *      "updated_at": "2020-01-23T09:42:26+00:00",
     *      "deleted_at": null,
     *      "priority_id": 2,
     *      "important": 0
     *    }
     *  }
     *
     * @apiUse         400Error
     * @apiUse         ValidationError
     * @apiUse         UnauthorizedError
     * @apiUse         ItemNotFoundError
     */
    public function edit(EditTaskRequestCattr $request): JsonResponse
    {
        Filter::listen($this->getEventUniqueName('request.item.edit'), static function (array $data) {
            if (empty($data['priority_id'])) {
                $project = Project::where(['id' => $data['project_id']])->first();
                if (isset($project) && !empty($project->default_priority_id)) {
                    $data['priority_id'] = $project->default_priority_id;
                } elseif (Settings::scope('core')->get('default_priority_id') !== null) {
                    $data['priority_id'] = Settings::scope('core')->get('default_priority_id');
                } elseif (($priority = Priority::query()->first()) !== null) {
                    $data['priority_id'] = $priority->id;
                } else {
                    throw new Exception('Priorities should be configured to edit tasks.');
                }
            }

            return $data;
        });

        Filter::listen($this->getEventUniqueName('item.edit'), static function (Task $task) use ($request) {
            $users = $request->get('users');
            $changes = $task->users()->sync($users);
            if (!empty($changes['attached']) || !empty($changes['detached']) || !empty($changes['updated'])) {
                TaskHistory::create([
                    'task_id' => $task->id,
                    'user_id' => auth()->id(),
                    'field' => 'users',
                    'new_value' => json_encode(User::query()->withoutGlobalScopes()->whereIn('id', $users)->select(
                        'id',
                        'full_name'
                    )->get()->toArray(), JSON_THROW_ON_ERROR),
                ]);
            }

            return $task;
        });

        Event::listen($this->getEventUniqueName('item.edit.after'), static function (Task $item, array $requestData) {
            $changes = $item->getChanges();
            foreach ($changes as $key => $value) {
                if (in_array($key, ['relative_position', 'created_at', 'updated_at', 'deleted_at'])) {
                    continue;
                }

                TaskHistory::create([
                    'task_id' => $item->id,
                    'user_id' => auth()->id(),
                    'field' => $key,
                    'new_value' => $value,
                ]);
            }
        });

        Filter::listen(Filter::getSuccessResponseFilterName(), static fn($data) => $data->load('users'));

        return $this->_edit($request);
    }

    /**
     * @param CreateTaskRequestCattr $request
     * @return JsonResponse
     *
     * @api             {post} /tasks/create Create
     * @apiDescription  Create Task
     *
     * @apiVersion      1.0.0
     * @apiName         Create
     * @apiGroup        Task
     *
     * @apiUse          AuthHeader
     *
     * @apiPermission   tasks_create
     * @apiPermission   tasks_full_access
     *
     * @apiParam {Integer}  project_id   Project
     * @apiParam {String}   task_name    Name
     * @apiParam {String}   description  Description
     * @apiParam {String}   url          Url
     * @apiParam {Integer}  active       Active/Inactive Task. Available value: {0,1}
     * @apiParam {Array}    users        Users
     * @apiParam {Integer}  assigned_by  User who assigned task
     * @apiParam {Integer}  priority_id  Priority ID
     *
     * @apiParamExample {json} Simple Request Example
     *  {
     *    "project_id":"163",
     *    "task_name":"retr",
     *    "description":"fdgfd",
     *    "active":1,
     *    "users":[3],
     *    "assigned_by":"1",
     *    "url":"URL",
     *    "priority_id": 1
     *  }
     *
     * @apiSuccess {Object}   res      Task
     *
     * @apiUse TaskObject
     *
     * @apiSuccessExample {json} Response Example
     *  HTTP/1.1 200 OK
     *  {
     *    "res": {
     *      "id": 2,
     *      "project_id": 1,
     *      "task_name": "Delectus.",
     *      "description": "Et qui sed qui vero quis.
     *                      Vitae corporis sapiente saepe dolor rerum. Eligendi commodi quia rerum ut.",
     *      "active": 1,
     *      "users": [],
     *      "assigned_by": 1,
     *      "url": null,
     *      "created_at": "2020-01-23T09:42:26+00:00",
     *      "updated_at": "2020-01-23T09:42:26+00:00",
     *      "deleted_at": null,
     *      "priority_id": 2,
     *      "important": 0
     *    }
     *  }
     *
     * @apiUse         400Error
     * @apiUse         ValidationError
     * @apiUse         UnauthorizedError
     * @apiUse         ForbiddenError
     */
    public function create(CreateTaskRequestCattr $request): JsonResponse
    {
        Filter::listen($this->getEventUniqueName('item.create'), static function (Task $task) use ($request) {
            $users = $request->get('users');
            $task->users()->sync($users);
            return $task;
        });

        Filter::listen(Filter::getSuccessResponseFilterName(), static fn($data) => $data->load('users'));

        Filter::listen($this->getEventUniqueName('request.item.create'), static function (array $data) {
            if (empty($data['priority_id'])) {
                $project = Project::where(['id' => $data['project_id']])->first();
                if (isset($project) && !empty($project->default_priority_id)) {
                    $data['priority_id'] = $project->default_priority_id;
                } elseif (Settings::scope('core')->get('default_priority_id') !== null) {
                    $data['priority_id'] = Settings::scope('core')->get('default_priority_id');
                } elseif (($priority = Priority::query()->first()) !== null) {
                    $data['priority_id'] = $priority->id;
                } else {
                    throw new Exception('Priorities should be configured to create tasks.');
                }
            }

            return $data;
        });

        return $this->_create($request);
    }

    /**
     * @throws Exception
     * @api             {post} /tasks/remove Destroy
     * @apiDescription  Destroy Task
     *
     * @apiVersion      1.0.0
     * @apiName         Destroy
     * @apiGroup        Task
     *
     * @apiUse          AuthHeader
     *
     * @apiPermission   tasks_remove
     * @apiPermission   tasks_full_access
     *
     * @apiParam {Integer}  id  ID of the target task
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
    public function destroy(DestroyTaskRequestCattr $request): JsonResponse
    {
        return $this->_destroy($request);
    }

    /**
     * @throws Exception
     * @api             {get,post} /tasks/count Count
     * @apiDescription  Count Tasks
     *
     * @apiVersion      1.0.0
     * @apiName         Count
     * @apiGroup        Task
     *
     * @apiUse          AuthHeader
     *
     * @apiPermission   tasks_count
     * @apiPermission   tasks_full_access
     *
     * @apiSuccess {String}   total    Amount of tasks that we have
     *
     * @apiSuccessExample {json} Response Example
     *  HTTP/1.1 200 OK
     *  {
     *    "total": 2
     *
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
     * @throws Throwable
     * @api             {post} /tasks/show Show
     * @apiDescription  Show Task
     *
     * @apiVersion      1.0.0
     * @apiName         Show
     * @apiGroup        Task
     *
     * @apiUse          AuthHeader
     *
     * @apiPermission   tasks_show
     * @apiPermission   tasks_full_access
     *
     * @apiParam {Integer}  id  ID
     *
     * @apiUse          TaskParams
     *
     * @apiParamExample {json} Simple Request Example
     *  {
     *    "id": 1,
     *    "project_id": ["=", [1,2,3]],
     *    "active": 1,
     *    "user_id": ["=", [1,2,3]],
     *    "assigned_by": ["=", [1,2,3]],
     *    "task_name": ["like", "%lorem%"],
     *    "description": ["like", "%lorem%"],
     *    "url": ["like", "%lorem%"],
     *    "created_at": [">", "2019-01-01 00:00:00"],
     *    "updated_at": ["<", "2019-01-01 00:00:00"]
     *  }
     *
     * @apiUse          TaskObject
     *
     * @apiSuccessExample {json} Response Example
     *  HTTP/1.1 200 OK
     *  {
     *    "id": 2,
     *    "project_id": 1,
     *    "task_name": "Delectus.",
     *    "description": "Et qui sed qui vero quis.
     *                    Vitae corporis sapiente saepe dolor rerum. Eligendi commodi quia rerum ut.",
     *    "active": 1,
     *    "user_id": 1,
     *    "assigned_by": 1,
     *    "url": null,
     *    "created_at": "2020-01-23T09:42:26+00:00",
     *    "updated_at": "2020-01-23T09:42:26+00:00",
     *    "deleted_at": null,
     *    "priority_id": 2,
     *    "important": 0
     *  }
     *
     * @apiUse         400Error
     * @apiUse         UnauthorizedError
     * @apiUse         ItemNotFoundError
     * @apiUse         ForbiddenError
     * @apiUse         ValidationError
     */
    public function show(ShowTaskRequestCattr $request): JsonResponse
    {
        Filter::listen(Filter::getSuccessResponseFilterName(), static function ($task) {
            $totalTracked = 0;

            $workers = DB::table('time_intervals AS i')
                ->leftJoin('tasks AS t', 'i.task_id', '=', 't.id')
                ->join('users AS u', 'i.user_id', '=', 'u.id')
                ->select(
                    'i.user_id',
                    'u.full_name',
                    'i.task_id',
                    'i.start_at',
                    'i.end_at',
                    DB::raw('SUM(TIMESTAMPDIFF(SECOND, i.start_at, i.end_at)) as duration')
                )
                ->whereNull('i.deleted_at')
                ->where('task_id', $task['id'])
                ->groupBy('i.user_id')
                ->get();

            foreach ($workers as $worker) {
                $totalTracked += $worker->duration;
            }

            $task['workers'] = $workers;
            $task['total_spent_time'] = $totalTracked;

            return $task;
        });

        return $this->_show($request);
    }

    /**
     * Opportunity to filtering request data
     *
     * Override this in child class for filtering
     */
    protected function filterRequestData(array $requestData): array
    {
        return Arr::except($requestData, ['users']);
    }
}
