<?php

namespace Tests\Feature\Chat;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_page_shows_the_chat_interface(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertViewIs('chat')
            ->assertSee('Weather AI Agent')
            ->assertSee('<meta name="csrf-token"', false)
            ->assertSee('id="chat-form"', false)
            ->assertSee('action="'.route('chat.stream').'"', false)
            ->assertSee('maxlength="1000"', false)
            ->assertSee('Will it rain in London tomorrow?')
            ->assertSee('Is it warmer in Mumbai or Paris?')
            ->assertSee(config('ai.providers.ollama.models.text.default'));
    }

    public function test_bootstrap_is_loaded_from_a_pinned_cdn_with_integrity_hashes(): void
    {
        $this->get('/')
            ->assertSee('https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css', false)
            ->assertSee('integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"', false)
            ->assertSee('https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js', false)
            ->assertSee(asset('js/chat.js'), false);
    }

    public function test_the_page_does_not_expose_provider_configuration(): void
    {
        config([
            'ai.providers.ollama.key' => 'secret-test-key-123',
            'ai.providers.ollama.url' => 'http://10.9.8.7:11434',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('secret-test-key-123')
            ->assertDontSee('10.9.8.7')
            ->assertDontSee('11434');
    }

    public function test_static_assets_exist(): void
    {
        $this->assertFileExists(public_path('js/chat-core.js'));
        $this->assertFileExists(public_path('js/chat.js'));
        $this->assertFileExists(public_path('css/chat.css'));
    }

    public function test_the_core_script_loads_before_the_chat_script_that_depends_on_it(): void
    {
        $this->get('/')->assertSeeInOrder([asset('js/chat-core.js'), asset('js/chat.js')], false);

        // chat.js reads window.ChatCore at start-up, so a wrong order would break the whole page.
        $this->assertStringContainsString('window.ChatCore', file_get_contents(public_path('js/chat.js')));
        $this->assertStringContainsString('global.ChatCore =', file_get_contents(public_path('js/chat-core.js')));
    }
}
