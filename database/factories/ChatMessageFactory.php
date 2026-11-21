<?php

namespace Database\Factories;

use App\Enums\ChatMessageRole;
use App\Enums\ChatMessageStatus;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatMessage>
 */
class ChatMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'thread_id' => ChatThread::factory(),
            'role' => ChatMessageRole::User,
            'body' => $this->faker->paragraph(),
            'status' => null,
        ];
    }

    /**
     * A pending assistant turn (its completion job has not settled it).
     */
    public function pendingAssistant(): static
    {
        return $this->state(fn () => [
            'role' => ChatMessageRole::Assistant,
            'body' => null,
            'status' => ChatMessageStatus::Pending,
        ]);
    }

    /**
     * A settled assistant reply.
     */
    public function completedAssistant(): static
    {
        return $this->state(fn () => [
            'role' => ChatMessageRole::Assistant,
            'body' => $this->faker->paragraph(),
            'status' => ChatMessageStatus::Complete,
        ]);
    }
}
