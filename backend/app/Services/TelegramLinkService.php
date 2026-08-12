<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Connects a user account to the Telegram chat that will receive its OTP codes.
 *
 * Telegram forbids a bot from messaging anyone who has not opened a conversation with it first,
 * so every account needs a one-time handshake: we hand the user a deep link carrying a token,
 * they press Start, and the bot receives "/start <token>" — which is how we learn their chat_id.
 */
class TelegramLinkService
{
    /**
     * Issues (or reuses) the token embedded in the user's deep link. Kept until the chat_id is
     * captured so a user who abandons the flow and comes back gets the same working link.
     */
    public function issueLinkToken(User $user): string
    {
        if (! $user->telegram_link_token) {
            // 32 chars: Telegram caps the /start payload at 64 and allows only [A-Za-z0-9_-].
            $user->forceFill(['telegram_link_token' => Str::random(32)])->save();
        }

        return $user->telegram_link_token;
    }

    public function deepLinkFor(User $user): string
    {
        $username = config('services.telegram.bot_username');

        return "https://t.me/{$username}?start=".$this->issueLinkToken($user);
    }

    /**
     * Reads pending bot conversations and links every one whose /start payload matches a waiting
     * account. Returns the number of accounts newly linked.
     *
     * Deliberately links *all* matches rather than just the caller's: getUpdates is a single
     * shared stream, so a poll triggered by one user also surfaces other users' /start messages.
     * Ignoring those would drop them, since the next poll may not see them again.
     *
     * Uses polling rather than a webhook because a webhook needs a publicly reachable HTTPS URL,
     * which a local artisan-serve setup does not have. Swap to a webhook once this is deployed.
     */
    public function pollAndLink(): int
    {
        $token = config('services.telegram.bot_token');

        if (! $token) {
            return 0;
        }

        $response = Http::timeout(15)->get("https://api.telegram.org/bot{$token}/getUpdates");

        if (! $response->successful() || $response->json('ok') !== true) {
            return 0;
        }

        $linked = 0;

        foreach ($response->json('result', []) as $update) {
            $text = $update['message']['text'] ?? '';
            $chatId = $update['message']['chat']['id'] ?? null;

            if (! $chatId || ! Str::startsWith($text, '/start ')) {
                continue;
            }

            $payload = trim(Str::after($text, '/start '));

            $user = User::where('telegram_link_token', $payload)->first();

            if (! $user) {
                continue;
            }

            // telegram_chat_id is unique — one Telegram account addresses exactly one BTS
            // account. Someone re-linking a chat they already used elsewhere would otherwise hit
            // a constraint violation and a 500, so transfer it instead: whoever most recently
            // proved control of the Telegram account wins. Nothing is gained by an attacker
            // luring a victim into this — the codes still land in the victim's own Telegram.
            User::where('telegram_chat_id', (string) $chatId)
                ->where('id', '!=', $user->id)
                ->update(['telegram_chat_id' => null]);

            $user->forceFill([
                'telegram_chat_id' => (string) $chatId,
                'telegram_link_token' => null,
            ])->save();

            $linked++;
        }

        return $linked;
    }
}
