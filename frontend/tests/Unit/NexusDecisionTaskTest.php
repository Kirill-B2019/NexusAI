<?php

namespace Tests\Unit;

use App\Services\NexusDecision;
use App\Services\NexusTask;
use PHPUnit\Framework\TestCase;

class NexusDecisionTaskTest extends TestCase
{
    public function test_decision_parses_api_content_and_superseded_status(): void
    {
        $decision = NexusDecision::fromApi([
            'id' => 'decision-1',
            'title' => 'Архитектура',
            'content' => 'Используем Laravel.',
            'status' => 'superseded',
        ]);

        $this->assertSame('Используем Laravel.', $decision->content);
        $this->assertSame('superseded', $decision->status);
    }

    public function test_task_parses_cancelled_status_and_assignee_expert(): void
    {
        $task = NexusTask::fromApi([
            'id' => 'task-1',
            'title' => 'Подготовить релиз',
            'status' => 'cancelled',
            'assignee_expert' => 'architect',
            'due_date' => null,
        ]);

        $this->assertSame('cancelled', $task->status);
        $this->assertSame('architect', $task->assigneeExpert);
        $this->assertSame('Отменена', $task->statusLabel());
    }
}
