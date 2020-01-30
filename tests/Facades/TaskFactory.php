<?php

namespace Tests\Facades;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Tests\Factories\TaskFactory as BaseTaskFactory;

/**
 * Class TaskFactory
 *
 * @method static Task create(array $attributes = [])
 * @method static Collection createMany(int $amount = 1)
 * @method static BaseTaskFactory forUser(User $user)
 * @method static array getRandomTaskData()
 * @method static BaseTaskFactory withIntervals(int $quantity = 1)
 *
 * @mixin BaseTaskFactory
 */
class TaskFactory extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return BaseTaskFactory::class;
    }
}
