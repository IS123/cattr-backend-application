<?php

namespace Tests\Feature\Time;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\Facades\IntervalFactory;
use Tests\Facades\UserFactory;
use Tests\TestCase;

/**
 * Class TotalTest
 */
class TotalTest extends TestCase
{
    private const URI = 'v1/time/total';

    private const INTERVALS_AMOUNT = 10;

    /**
     * @var Collection
     */
    private $intervals;

    /**
     * @var User
     */
    private $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = UserFactory::asAdmin()->withTokens()->create();

        $this->intervals = IntervalFactory::forUser($this->admin)->createMany(self::INTERVALS_AMOUNT);

    }

    public function test_total(): void
    {
        $requestData = [
            'start_at' => $this->intervals->min('start_at'),
            'end_at' => Carbon::create($this->intervals->max('end_at'))->addMinute(),
            'user_id' => $this->admin->id
        ];

        $response = $this->actingAs($this->admin)->postJson(self::URI, $requestData);
        $response->assertSuccess();

        $totalTime = $this->intervals->sum(static function ($interval) {
            return Carbon::parse($interval->end_at)->diffInSeconds($interval->start_at);
        });

        $response->assertJson(['time' => $totalTime]);
        $response->assertJson(['start' => $this->intervals->min('start_at')]);
        $response->assertJson(['end' => $this->intervals->max('end_at')]);
    }

    public function test_unauthorized(): void
    {
        $response = $this->getJson(self::URI);

        $response->assertUnauthorized();
    }

    public function test_without_params(): void
    {
        $response = $this->actingAs($this->admin)->getJson(self::URI);

        $response->assertValidationError();
    }
}
