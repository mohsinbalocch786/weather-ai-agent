<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\CalculatorAssistant;
use App\Ai\Tools\AddNumbers;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers the Milestone-0 calculator agent, which proved that the local model can call tools.
 */
class CalculatorAssistantTest extends TestCase
{
    #[DataProvider('sums')]
    public function test_add_numbers_returns_the_sum(mixed $a, mixed $b, string $expected): void
    {
        $result = (new AddNumbers)->handle(new Request(['number1' => $a, 'number2' => $b]));

        $this->assertSame("The sum is {$expected}", (string) $result);
    }

    public static function sums(): array
    {
        return [
            'integers' => [2, 3, '5'],
            'floats' => [1.5, 2.25, '3.75'],
            'negative' => [-4, 10, '6'],
            'zero' => [0, 0, '0'],
            'numeric strings from the model' => ['2', '3', '5'],
        ];
    }

    public function test_the_schema_describes_two_numbers(): void
    {
        $properties = (new AddNumbers)->schema(new JsonSchemaTypeFactory);

        $this->assertSame('Add two numbers and return their sum.', (string) (new AddNumbers)->description());
        $this->assertSame(['number1', 'number2'], array_keys($properties));
        $this->assertSame('number', $properties['number1']->toArray()['type']);
        $this->assertSame('number', $properties['number2']->toArray()['type']);
    }

    public function test_the_agent_registers_the_tool_and_instructs_the_model_to_use_it(): void
    {
        $agent = new CalculatorAssistant;
        $tools = iterator_to_array($agent->tools(), false);

        $this->assertCount(1, $tools);
        $this->assertInstanceOf(AddNumbers::class, $tools[0]);
        $this->assertStringContainsString('you MUST use the AddNumbers tool', (string) $agent->instructions());
    }

    public function test_the_agent_runs_the_tool_loop(): void
    {
        CalculatorAssistant::fake([
            new ToolCall('call_1', 'AddNumbers', ['number1' => 19, 'number2' => 23]),
            '19 plus 23 is 42.',
        ])->preventStrayPrompts();

        $response = (new CalculatorAssistant)->prompt('What is 19 + 23?');

        $this->assertSame('19 plus 23 is 42.', $response->text);
        $this->assertSame('The sum is 42', $response->toolResults->first()->result);
        CalculatorAssistant::assertPrompted('What is 19 + 23?');
    }
}
