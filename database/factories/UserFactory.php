<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // 登录名与昵称都是全服唯一，且登录名只能是 ASCII（见 User::HANDLE_PATTERN）。
            // 工厂必须跟着这两条约束走：否则用 factory 造出的第一个账号能过，
            // 第二个就会在唯一索引上炸掉，而错误现场离原因很远。
            'name' => Str::lower(fake()->unique()->bothify('?????####')),
            'nickname' => '考据员'.fake()->unique()->numberBetween(1, 999999),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'password_set_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * 外部渠道注册出来的账号：本人不知道密码（库里是一个随机占位哈希）。
     */
    public function withoutKnownPassword(): static
    {
        return $this->state(fn (array $attributes) => [
            'password' => Hash::make(Str::random(40)),
            'password_set_at' => null,
        ]);
    }
}
