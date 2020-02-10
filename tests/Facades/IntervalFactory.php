<?php

namespace Tests\Facades;

use App\Models\Task;
use App\Models\TimeInterval;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Tests\Factories\IntervalFactory as BaseIntervalFactory;

/**
 * Class IntervalFactory
 *
 * @method static TimeInterval create(array $attributes = [])
 * @method static Collection createMany(int $amount = 1)
 * @method static array getRandomIntervalData()
 * @method static array generateRandomIntervalData()
 * @method static array generateRandomManualIntervalData()
 * @method static BaseIntervalFactory forUser(User $user)
 * @method static BaseIntervalFactory forTask(Task $task)
 * @method static BaseIntervalFactory withRandomRelations()
 *
 * @mixin BaseIntervalFactory
 */
class IntervalFactory extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return BaseIntervalFactory::class;
    }
}
