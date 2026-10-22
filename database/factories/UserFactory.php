<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 *
 * Wajib dipanggil di dalam konteks sekolah (TenantContext) agar school_id terisi.
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'username' => fake()->unique()->numerify('##########'),
            'name' => fake()->name(),
            'password' => 'RahasiaUji123',
            'role' => User::ROLE_STUDENT,
            'gender' => fake()->randomElement(['L', 'P']),
            'status' => 'active',
            'must_change_password' => false,
        ];
    }

    public function teacher(): static
    {
        return $this->state(fn () => ['role' => User::ROLE_TEACHER, 'username' => fake()->unique()->userName()]);
    }
}
