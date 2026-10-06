<?php

namespace Database\Factories;

use App\Models\AdminActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AdminActivityLogFactory extends Factory
{
    protected $model = AdminActivityLog::class;

    public function definition(): array
    {
        return ['user_id' => User::factory(), 'action' => 'staff.update', 'subject_type' => 'user', 'subject_uuid' => (string) Str::uuid(), 'old_values' => ['is_active' => true], 'new_values' => ['is_active' => false]];
    }
}
