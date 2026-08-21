'use client';

import { useState, type FormEvent } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { AuthCard } from '@/components/auth-card';
import { ErrorAlert } from '@/components/error-alert';
import { GoogleSignInButton } from '@/components/google-sign-in-button';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { login } from '@/lib/api/auth';
import { ApiError } from '@/lib/api/client';
import { setPreAuthToken } from '@/lib/auth/pre-auth-token';

export default function LoginPage() {
  const router = useRouter();
  const [identifier, setIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [errorFields, setErrorFields] = useState<Record<string, string[]> | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setErrorFields(null);
    setSubmitting(true);
    try {
      const result = await login({ identifier, password });
      setPreAuthToken(result.pre_auth_token);

      router.push('/login/verify-otp');
    } catch (err) {
      if (err instanceof ApiError) {
        setError(err.message);
        setErrorFields(err.fields ?? null);
      } else {
        setError('Something went wrong. Please try again.');
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <AuthCard title="Log in" description="We'll send a verification code to your email or phone every time you sign in.">
      <ErrorAlert message={error} fields={errorFields} />
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="space-y-2">
          <Label htmlFor="identifier">Email or phone</Label>
          <Input id="identifier" required autoComplete="username" value={identifier} onChange={(e) => setIdentifier(e.target.value)} />
        </div>
        <div className="space-y-2">
          <div className="flex items-center justify-between">
            <Label htmlFor="password">Password</Label>
            <Link href="/forgot-password" className="text-sm text-muted-foreground underline">
              Forgot password?
            </Link>
          </div>
          <Input
            id="password"
            type="password"
            required
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
        </div>
        <Button type="submit" className="w-full" disabled={submitting}>
          {submitting ? 'Signing in…' : 'Continue'}
        </Button>
      </form>
      <GoogleSignInButton />
      <p className="mt-4 text-center text-sm text-muted-foreground">
        New to BTS Bank?{' '}
        <Link href="/register" className="font-medium text-primary underline">
          Create an account
        </Link>
      </p>
    </AuthCard>
  );
}
