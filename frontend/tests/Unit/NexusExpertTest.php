<?php

namespace Tests\Unit;

use App\Exceptions\NexusApiException;
use App\Services\NexusExpert;
use PHPUnit\Framework\TestCase;

class NexusExpertTest extends TestCase
{
    public function test_it_parses_the_system_advisor_flag(): void
    {
        $expert = NexusExpert::fromApi([
            'key' => 'system_advisor',
            'name' => 'Системный советник',
            'system_prompt' => 'Это системный промпт эксперта.',
            'sort_order' => 20,
            'is_system' => true,
        ]);

        $this->assertTrue($expert->isSystem);
        $this->assertSame('Это системный промпт эксперта.', $expert->systemPrompt);
        $this->assertSame(20, $expert->sortOrder);
    }

    public function test_it_rejects_experts_without_a_boolean_system_flag(): void
    {
        $this->expectException(NexusApiException::class);

        NexusExpert::fromApi([
            'key' => 'custom_advisor',
            'name' => 'Пользовательский советник',
            'system_prompt' => 'Это системный промпт эксперта.',
            'sort_order' => 100,
            'is_system' => 'false',
        ]);
    }
}
