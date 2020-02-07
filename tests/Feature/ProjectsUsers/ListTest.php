<?php

namespace Tests\Feature\ProjectsUsers;

use App\Models\ProjectsUsers;
use App\Models\User;
use Tests\Facades\ProjectUserFactory;
use Tests\Facades\UserFactory;
use Tests\TestCase;

/**
 * Class ListTest
 */
class ListTest extends TestCase
{
    private const URI = 'v1/projects-users/list';

    private const PROJECTS_USERS_AMOUNT = 10;

    /**
     * @var User
     */
    private $admin;


    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = UserFactory::asAdmin()->withTokens()->create();

        ProjectUserFactory::createMany(self::PROJECTS_USERS_AMOUNT);
    }

    public function test_list(): void
    {
        $response = $this->actingAs($this->admin)->getJson(self::URI);

        $response->assertOk();
        $response->assertJson(ProjectsUsers::all()->toArray());
    }

    public function test_unauthorized(): void
    {
        $response = $this->getJson(self::URI);

        $response->assertUnauthorized();
    }
}
