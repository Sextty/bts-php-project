import Echo from 'laravel-echo';
import Pusher, { type AuthorizerCallback } from 'pusher-js';

type ChannelAuthorizationData = NonNullable<Parameters<AuthorizerCallback>[1]>;

const API_URL = (process.env.NEXT_PUBLIC_API_URL ?? 'http://127.0.0.1:8000').replace(/\/$/, '');

/**
 * One Echo client per token, so a customer channel subscription and a staff channel subscription
 * never share a socket/auth identity — matches the app's dual-token pattern (bts_access_token vs
 * bts_staff_access_token) rather than a single global client.
 */
export function createEchoClient(getToken: () => string | null): Echo<'reverb'> {
  return new Echo({
    broadcaster: 'reverb',
    key: process.env.NEXT_PUBLIC_REVERB_APP_KEY,
    wsHost: process.env.NEXT_PUBLIC_REVERB_HOST,
    wsPort: Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? 8080),
    forceTLS: process.env.NEXT_PUBLIC_REVERB_SCHEME === 'https',
    enabledTransports: ['ws', 'wss'],
    Pusher,
    // Custom authorizer instead of the default authEndpoint: broadcasting/auth needs the same
    // Bearer token every other API call uses, not a cookie — Echo's built-in fetch has no way to
    // attach one.
    authorizer: (channel: { name: string }) => ({
      authorize(socketId: string, callback: AuthorizerCallback) {
        const token = getToken();

        fetch(`${API_URL}/broadcasting/auth`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            Accept: 'application/json',
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
          },
          body: new URLSearchParams({ socket_id: socketId, channel_name: channel.name }),
        })
          .then((response) => {
            if (!response.ok) throw new Error('Channel authorization failed.');
            return response.json();
          })
          .then((data: ChannelAuthorizationData) => callback(null, data))
          .catch((err) => callback(err instanceof Error ? err : new Error('Channel authorization failed.'), null));
      },
    }),
  });
}
