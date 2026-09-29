<?php

namespace Tests\Feature\Foundation;

use Tests\TestCase;

class HealthApiTest extends TestCase
{
    public function test_api_health_endpoint_works_without_authentication(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertExactJson([
            'success' => true,
            'message' => 'Success',
            'data' => ['status' => 'ok'],
            'errors' => null,
        ]);
    }
}
