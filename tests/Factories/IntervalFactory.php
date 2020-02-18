<?php

namespace Tests\Factories;

use App\Models\Task;
use App\Models\TimeInterval;
use App\Models\User;
use Carbon\Carbon;
use Faker\Factory as FakerFactory;
use Tests\Facades\TaskFactory;
use Tests\Facades\UserFactory;

class IntervalFactory extends Factory
{
    private ?User $user = null;
    private ?Task $task = null;
    private TimeInterval $interval;

    protected function getModelInstance(): TimeInterval
    {
        return $this->interval;
    }

    public function createRandomModelData(): array
    {
        $randomDateTime = FakerFactory::create()->unique()->dateTimeThisYear();
        $randomDateTime = Carbon::instance($randomDateTime);

        return [
         'end_at' => $randomDateTime->toIso8601String(),
         'start_at' => $randomDateTime->subSeconds(random_int(1, 3600))->toIso8601String(),
         'count_mouse' => random_int(1, 1000),
         'count_keyboard' => random_int(1, 1000)
      ];
    }

    public function createRandomModelDataWithRelation(): array
    {
        return array_merge($this->createRandomModelData(), [
         'task_id' => TaskFactory::create()->id,
         'user_id' => UserFactory::create()->id,
      ]);
    }

    public function forUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function forTask(Task $task): self
    {
        $this->task = $task;
        return $this;
    }

    private function defineUser(): void
    {
        if ($this->randomRelations || !$this->user) {
            $this->user = UserFactory::create();
        }

        $this->interval->user_id = $this->user->id;
    }

    private function defineTask(): void
    {
        if ($this->randomRelations || !$this->task) {
            $this->task = TaskFactory::forUser($this->user)->create();
        }

        $this->interval->task_id = $this->task->id;
    }

    public function withRandomRelations(): self
    {
        $this->randomRelations = true;
        return $this;
    }

    public function create(): TimeInterval
    {
        $modelData = $this->createRandomModelData();
        $this->interval = TimeInterval::make($modelData);

        $this->defineUser();
        $this->defineTask();

        if ($this->timestampsHidden) {
            $this->hideTimestamps();
        }

        $this->interval->save();

        return $this->interval;
    }
}
