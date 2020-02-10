<?php

namespace Tests\Feature\TimeIntervals;

use App\Models\Task;
use App\Models\TimeInterval;
use App\Models\User;
use Tests\Facades\IntervalFactory;
use Tests\Facades\TaskFactory;
use Tests\Facades\UserFactory;
use Tests\TestCase;

/**
 * Class CreateTest
 */
class CreateTest extends TestCase
{
    private const URI = 'v1/time-intervals/create';

    /**
     * @var User
     */
    private $admin;

    /**
     * @var array
     */
    private $intervalData;

    /**
     * @var Task
     */
    private $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = UserFactory::asAdmin()->withTokens()->create();

        $this->task = TaskFactory::forUser($this->admin)->create();

        $this->intervalData = IntervalFactory::generateRandomIntervalData();
    }

    public function test_create(): void
    {
        $this->assertDatabaseMissing('time_intervals', $this->intervalData);

        $response = $this->actingAs($this->admin)->postJson(self::URI, $this->intervalData);

        $response->assertSuccess();
        $this->assertDatabaseHas('time_intervals', $this->intervalData);
    }

    public function test_already_exists(): void
    {
        TimeInterval::create($this->intervalData);
        $this->assertDatabaseHas('time_intervals', $this->intervalData);

        $response = $this->actingAs($this->admin)->postJson(self::URI, $this->intervalData);

        $response->assertConflict();
    }

    public function test_unauthorized(): void
    {
        $response = $this->postJson(self::URI);

        $response->assertUnauthorized();
    }

    public function test_without_params(): void
    {
        $response = $this->actingAs($this->admin)->postJson(self::URI);

        $response->assertValidationError();
    }
}
