<?php

namespace App\Models;

use App\Scopes\TaskScope;
use App\Traits\ExposePermissions;
use Eloquent as EloquentIdeHelper;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Parsedown;

/**
 * @apiDefine TaskObject
 *
 * @apiSuccess {Integer}  task.id             ID
 * @apiSuccess {Integer}  task.project_id     The ID of the linked project
 * @apiSuccess {Integer}  task.assigned_by    The ID of the user that assigned task
 * @apiSuccess {Integer}  task.priority_id    The ID of the priority
 * @apiSuccess {Integer}  task.status_id      The ID of the status
 * @apiSuccess {String}   task.task_name      Name of the task
 * @apiSuccess {String}   task.description    Description of the task
 * @apiSuccess {Boolean}  task.active         Indicates active task when `TRUE`
 * @apiSuccess {String}   task.important      Indicates important task when `TRUE`
 * @apiSuccess {ISO8601}  task.created_at     Creation DateTime
 * @apiSuccess {ISO8601}  task.updated_at     Update DateTime
 * @apiSuccess {ISO8601}  task.deleted_at     Delete DateTime or `NULL` if wasn't deleted
 * @apiSuccess {Array}    task.timeIntervals  Time intervals of the task
 * @apiSuccess {Array}    task.users          Linked users
 * @apiSuccess {Array}    task.assigned       Users, that assigned this task
 * @apiSuccess {Array}    task.project        The project that task belongs to
 * @apiSuccess {Object}   task.priority       Task priority
 * @apiSuccess {Object}   task.status         Task status
 *
 * @apiVersion 1.0.0
 */

/**
 * @apiDefine TaskParams
 *
 * @apiParam {Integer}  [id]             ID
 * @apiParam {Integer}  [project_id]     The ID of the linked project
 * @apiParam {Integer}  [assigned_by]    The ID of the user that assigned task
 * @apiParam {Integer}  [priority_id]    The ID of the priority
 * @apiParam {Integer}  [status_id]      The ID of the status
 * @apiParam {String}   [task_name]      Name of the task
 * @apiParam {String}   [description]    Description of the task
 * @apiParam {String}   [important]      Indicates important task when `TRUE`
 * @apiParam {ISO8601}  [created_at]     Creation DateTime
 * @apiParam {ISO8601}  [updated_at]     Update DateTime
 * @apiParam {ISO8601}  [deleted_at]     Delete DateTime
 * @apiParam {Array}    [timeIntervals]  Time intervals of the task
 * @apiParam {Array}    [users]          Linked users
 * @apiParam {Array}    [assigned]       Users, that assigned this task
 * @apiParam {Array}    [project]        The project that task belongs to
 * @apiParam {Object}   [priority]       Task priority
 * @apiParam {Object}   [status]         Task status
 *
 * @apiVersion 1.0.0
 */

/**
 * Class Task
 *
 * @property int $id
 * @property int $project_id
 * @property string $task_name
 * @property string|null $description
 * @property int $assigned_by
 * @property string|null $url
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property int $priority_id
 * @property int $important
 * @property int|null $status_id
 * @property float $relative_position
 * @property \Illuminate\Support\Carbon|null $due_date
 * @property-read \App\Models\User $assigned
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\TaskHistory[] $changes
 * @property-read int|null $changes_count
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\TaskComment[] $comments
 * @property-read int|null $comments_count
 * @property-read array $can
 * @property-read \App\Models\Priority $priority
 * @property-read \App\Models\Project $project
 * @property-read \App\Models\Status|null $status
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\TimeInterval[] $timeIntervals
 * @property-read int|null $time_intervals_count
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\User[] $users
 * @property-read int|null $users_count
 * @method static \Database\Factories\TaskFactory factory(...$parameters)
 * @method static EloquentBuilder|Task newModelQuery()
 * @method static EloquentBuilder|Task newQuery()
 * @method static QueryBuilder|Task onlyTrashed()
 * @method static EloquentBuilder|Task query()
 * @method static EloquentBuilder|Task whereAssignedBy($value)
 * @method static EloquentBuilder|Task whereCreatedAt($value)
 * @method static EloquentBuilder|Task whereDeletedAt($value)
 * @method static EloquentBuilder|Task whereDescription($value)
 * @method static EloquentBuilder|Task whereDueDate($value)
 * @method static EloquentBuilder|Task whereId($value)
 * @method static EloquentBuilder|Task whereImportant($value)
 * @method static EloquentBuilder|Task wherePriorityId($value)
 * @method static EloquentBuilder|Task whereProjectId($value)
 * @method static EloquentBuilder|Task whereRelativePosition($value)
 * @method static EloquentBuilder|Task whereStatusId($value)
 * @method static EloquentBuilder|Task whereTaskName($value)
 * @method static EloquentBuilder|Task whereUpdatedAt($value)
 * @method static EloquentBuilder|Task whereUrl($value)
 * @method static QueryBuilder|Task withTrashed()
 * @method static QueryBuilder|Task withoutTrashed()
 * @mixin EloquentIdeHelper
 */
class Task extends Model
{
    use SoftDeletes;
    use ExposePermissions;
    use HasFactory;

    /**
     * table name from database
     * @var string
     */
    protected $table = 'tasks';

    /**
     * @var array
     */
    protected $fillable = [
        'project_id',
        'task_name',
        'description',
        'assigned_by',
        'url',
        'priority_id',
        'status_id',
        'important',
        'relative_position',
        'due_date',
    ];

    /**
     * @var array
     */
    protected $casts = [
        'project_id' => 'integer',
        'task_name' => 'string',
        'description' => 'string',
        'assigned_by' => 'integer',
        'url' => 'string',
        'priority_id' => 'integer',
        'status_id' => 'integer',
        'important' => 'integer',
        'relative_position' => 'float',
    ];

    /**
     * @var array
     */
    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
        'due_date',
    ];

    /**
     * @var array
     */
    protected $appends = ['can'];

    /**
     * @return string
     */
    public static function getTableName(): string
    {
        return with(new static())->getTable();
    }

    /**
     * Override parent boot and Call deleting event
     */
    protected static function boot(): void
    {
        parent::boot();

        static::addGlobalScope(new TaskScope);

        static::deleting(static function (Task $task) {
            /** @var Task $tasks */
            $task->timeIntervals()->delete();
        });
    }

    /**
     * @return HasMany
     */
    public function timeIntervals(): HasMany
    {
        return $this->hasMany(TimeInterval::class, 'task_id');
    }

    /**
     * @return HasMany
     */
    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class, 'task_id');
    }

    /**
     * @return BelongsTo
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id')->withoutGlobalScopes();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tasks_users', 'task_id', 'user_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo
     */
    public function assigned(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * @return BelongsTo
     */
    public function priority(): BelongsTo
    {
        return $this->belongsTo(Priority::class, 'priority_id');
    }

    /**
     * @return BelongsTo
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return (new Parsedown())->text($this->description);
    }

    /**
     * @return HasMany
     */
    public function changes(): HasMany
    {
        return $this->hasMany(TaskHistory::class, 'task_id');
    }
}
