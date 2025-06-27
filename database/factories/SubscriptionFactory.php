<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Subscription::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan_id' => $this->faker->randomElement(['basic', 'pro', 'enterprise']),
            'status' => $this->faker->randomElement(['active', 'inactive', 'pending', 'cancelled']),
            'xendit_invoice_id' => 'inv_' . $this->faker->unique()->regexify('[A-Za-z0-9]{20}'),
            'xendit_payment_id' => 'pay_' . $this->faker->unique()->regexify('[A-Za-z0-9]{20}'),
            'amount' => $this->faker->randomElement([199, 399, 999]),
            'currency' => 'PHP',
            'start_date' => $this->faker->dateTimeBetween('-1 month', 'now'),
            'end_date' => $this->faker->dateTimeBetween('now', '+1 month'),
            'payment_status' => $this->faker->randomElement(['pending', 'paid', 'failed', 'cancelled']),
        ];
    }

    /**
     * Indicate that the subscription is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'payment_status' => 'paid',
        ]);
    }

    /**
     * Indicate that the subscription is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'payment_status' => 'pending',
        ]);
    }

    /**
     * Indicate that the subscription is cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
            'payment_status' => 'cancelled',
        ]);
    }

    /**
     * Indicate that the subscription is for basic plan.
     */
    public function basic(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan_id' => 'basic',
            'amount' => 199,
        ]);
    }

    /**
     * Indicate that the subscription is for pro plan.
     */
    public function pro(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan_id' => 'pro',
            'amount' => 399,
        ]);
    }

    /**
     * Indicate that the subscription is for enterprise plan.
     */
    public function enterprise(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan_id' => 'enterprise',
            'amount' => 999,
        ]);
    }
} 