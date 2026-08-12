<?php

namespace Tests\Feature\Auth;

use App\Contracts\SmsProviderInterface;
use App\Models\User;
use App\Services\Sms\TelegramOtpDriver;
use App\Services\TelegramLinkService;
use Illuminate\Support\Facades\Http;

/**
 * The one-time Telegram handshake: a bot cannot message anyone who has not started a
 * conversation with it, so a new account has nowhere to receive an OTP until the user presses
 * Start. These cover the flow that closes that gap.
 */
class TelegramLinkTest extends AuthTestCase
{
    private function useTelegramChannel(): void
    {
        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.bot_username', 'test_bot');
        $this->app->instance(SmsProviderInterface::class, new TelegramOtpDriver('test-token'));
    }

    /** Fakes a getUpdates response containing a single "/start <token>" message. */
    private function fakeStartMessage(string $token, int $chatId = 555001): void
    {
        Http::fake([
            'api.telegram.org/*getUpdates*' => Http::response([
                'ok' => true,
                'result' => [[
                    'message' => [
                        'chat' => ['id' => $chatId, 'first_name' => 'Test'],
                        'text' => "/start {$token}",
                    ],
                ]],
            ]),
            'api.telegram.org/*sendMessage*' => Http::response(['ok' => true]),
        ]);
    }

    public function test_registration_returns_a_telegram_link_instead_of_failing(): void
    {
        $this->useTelegramChannel();

        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'Tele',
            'last_name' => 'Test',
            'email' => 'tele@example.com',
            'phone' => '+21620555001',
            'password' => 'CorrectHorseBattery',
            'password_confirmation' => 'CorrectHorseBattery',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.requires_telegram_link', true);

        $this->assertStringContainsString('https://t.me/test_bot?start=', $response->json('data.telegram_link_url'));
    }

    public function test_link_status_reports_not_linked_until_the_user_presses_start(): void
    {
        $this->useTelegramChannel();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        $register = $this->postJson('/api/auth/register', [
            'first_name' => 'Tele',
            'last_name' => 'Test',
            'email' => 'tele2@example.com',
            'phone' => '+21620555002',
            'password' => 'CorrectHorseBattery',
            'password_confirmation' => 'CorrectHorseBattery',
        ]);

        $this->postJson('/api/auth/telegram/link-status', [
            'pre_auth_token' => $register->json('data.pre_auth_token'),
            'purpose' => 'registration',
        ])->assertOk()->assertJsonPath('data.linked', false);
    }

    public function test_pressing_start_links_the_chat_and_dispatches_the_otp(): void
    {
        // No Http::fake() yet on purpose: registration makes no Telegram call (the account is
        // unreachable, so nothing is sent), and an earlier broad stub would shadow the specific
        // getUpdates stub registered below — Laravel matches stubs in registration order.
        $this->useTelegramChannel();

        $register = $this->postJson('/api/auth/register', [
            'first_name' => 'Tele',
            'last_name' => 'Test',
            'email' => 'tele3@example.com',
            'phone' => '+21620555003',
            'password' => 'CorrectHorseBattery',
            'password_confirmation' => 'CorrectHorseBattery',
        ]);

        $user = User::where('email', 'tele3@example.com')->firstOrFail();
        $this->assertNotNull($user->telegram_link_token);
        $this->assertNull($user->telegram_chat_id);

        $this->fakeStartMessage($user->telegram_link_token);

        $this->postJson('/api/auth/telegram/link-status', [
            'pre_auth_token' => $register->json('data.pre_auth_token'),
            'purpose' => 'registration',
        ])->assertOk()->assertJsonPath('data.linked', true);

        $user->refresh();
        $this->assertSame('555001', $user->telegram_chat_id);
        // Consumed, so the deep link can't be replayed to hijack the account later.
        $this->assertNull($user->telegram_link_token);
        // The OTP the registration step couldn't send is dispatched on this transition.
        $this->assertSame(1, $user->otpCodes()->count());
    }

    public function test_relinking_a_chat_already_used_by_another_account_transfers_it(): void
    {
        $this->useTelegramChannel();

        $previousOwner = User::factory()->create(['telegram_chat_id' => '555001']);
        $newOwner = User::factory()->create([
            'telegram_chat_id' => null,
            'telegram_link_token' => 'token-for-new-owner',
        ]);

        $this->fakeStartMessage('token-for-new-owner');

        app(TelegramLinkService::class)->pollAndLink();

        // Unique constraint on telegram_chat_id would 500 without the transfer.
        $this->assertNull($previousOwner->fresh()->telegram_chat_id);
        $this->assertSame('555001', $newOwner->fresh()->telegram_chat_id);
    }

    public function test_login_by_an_unlinked_account_offers_the_link_step(): void
    {
        $this->useTelegramChannel();

        User::factory()->create([
            'email' => 'unlinked@example.com',
            'password' => 'CorrectHorseBattery',
            'telegram_chat_id' => null,
        ]);

        $this->postJson('/api/auth/login', [
            'identifier' => 'unlinked@example.com',
            'password' => 'CorrectHorseBattery',
        ])->assertOk()->assertJsonPath('data.requires_telegram_link', true);
    }
}
