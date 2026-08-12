<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Links a user account to the Telegram chat that will receive its OTP codes.
 *
 * Telegram bots cannot message a user who has not started a conversation with them first, so the
 * chat_id only exists after the user sends /start to the bot. This command reads those pending
 * conversations via getUpdates and attaches the chat_id to an account.
 *
 * getUpdates (polling) is used rather than a webhook deliberately: a webhook needs a publicly
 * reachable HTTPS URL, which a local XAMPP/artisan-serve setup does not have. Swap to a webhook
 * when this is deployed somewhere with a real domain.
 */
class TelegramLink extends Command
{
    protected $signature = 'telegram:link
                            {email? : Email of the account to link}
                            {--list : Only list the chats waiting to be linked}';

    protected $description = 'Link a user account to their Telegram chat for OTP delivery';

    public function handle(): int
    {
        $token = config('services.telegram.bot_token');

        if (! $token) {
            $this->error('TELEGRAM_BOT_TOKEN is not set in .env.');

            return self::FAILURE;
        }

        $response = Http::timeout(15)->get("https://api.telegram.org/bot{$token}/getUpdates");

        if (! $response->successful() || $response->json('ok') !== true) {
            $this->error('Telegram rejected the request: '.($response->json('description') ?? "HTTP {$response->status()}"));

            return self::FAILURE;
        }

        $chats = $this->extractChats($response->json('result', []));

        if ($chats === []) {
            $this->warn('No conversations found. Open the bot in Telegram and send /start, then run this again.');

            return self::FAILURE;
        }

        $this->table(
            ['chat_id', 'name', 'username', 'last message'],
            array_map(fn ($c) => [$c['id'], $c['name'], $c['username'], $c['text']], $chats),
        );

        if ($this->option('list')) {
            return self::SUCCESS;
        }

        $email = $this->argument('email');

        if (! $email) {
            $this->info('Pass an email to link one of these chats, e.g. php artisan telegram:link you@example.com');

            return self::SUCCESS;
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email {$email}.");

            return self::FAILURE;
        }

        // Most recent conversation wins — with a single tester that is unambiguous, and the table
        // above shows exactly which chat was chosen.
        $chat = end($chats);

        $user->forceFill(['telegram_chat_id' => (string) $chat['id']])->save();

        $this->info("Linked {$email} to Telegram chat {$chat['id']} ({$chat['name']}).");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $updates
     * @return array<int, array{id: int|string, name: string, username: string, text: string}>
     */
    private function extractChats(array $updates): array
    {
        $chats = [];

        foreach ($updates as $update) {
            $chat = $update['message']['chat'] ?? null;

            if (! $chat) {
                continue;
            }

            // Keyed by id so repeated messages from one person collapse to their latest.
            $chats[$chat['id']] = [
                'id' => $chat['id'],
                'name' => trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? '')),
                'username' => $chat['username'] ?? '—',
                'text' => $update['message']['text'] ?? '',
            ];
        }

        return array_values($chats);
    }
}
