<?php

namespace Tests\Feature\Roles;

use App\Models\User;
use Tests\Facades\UserFactory;
use Tests\TestCase;

/**
 * Class AllowedRulesTest
 */
class AllowedRulesTest extends TestCase
{
    private const URI = 'v1/roles/allowed-rules';

    /**
     * @var User
     */
    private $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = UserFactory::withTokens()->asAdmin()->create();
    }

    public function test_allowed_rules(): void
    {
        $adminResponse = $this->actingAs($this->admin)->getJson(self::URI);

        $adminResponse->assertOk();

        // TODO Check Response Json
    }
}
