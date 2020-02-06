<?php

namespace Tests\Feature\Roles;

use App\Models\User;
use Tests\Facades\UserFactory;
use Tests\TestCase;


/**
 * Class EditTest
 */
class EditTest extends TestCase
{
    private const URI = 'v1/roles/edit';

    /**
     * @var User
     */
    private $admin;

    /**
     * @var string
     */
    private $newRoleData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = UserFactory::asAdmin()->withTokens()->create();
        $this->newRoleData = ['id' => 1, 'name' => 'new-name'];
    }

    public function test_edit(): void
    {
        $response = $this->actingAs($this->admin)->postJson(self::URI, $this->newRoleData);

        $response->assertSuccess();
        $response->assertJson(['res' => $this->newRoleData]);
        $this->assertDatabaseHas('role', $this->newRoleData);
    }

    public function test_not_existing_role(): void
    {
        $this->newRoleData['id'] = 42;
        $response = $this->actingAs($this->admin)->postJson(self::URI, $this->newRoleData);

        $response->assertNotFound();
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
