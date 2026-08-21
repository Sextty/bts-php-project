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
import { register } from '@/lib/api/auth';
import { ApiError } from '@/lib/api/client';
import { setPreAuthToken } from '@/lib/auth/pre-auth-token';

export default function RegisterPage() {
  const router = useRouter();
  const [form, setForm] = useState({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
  });
  const [error, setError] = useState<string | null>(null);
  const [errorFields, setErrorFields] = useState<Record<string, string[]> | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setErrorFields(null);

    if (form.password !== form.password_confirmation) {
      setError('The passwords do not match.');
      return;
    }

    setSubmitting(true);
    try {
      const result = await register(form);
      setPreAuthToken(result.pre_auth_token);

      router.push('/register/verify-otp');
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
    <AuthCard title="Create an account" description="We'll send a verification code to your email or phone once you submit.">
      <ErrorAlert message={error} fields={errorFields} />
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="space-y-2">
            <Label htmlFor="first_name">First name</Label>
            <Input
              id="first_name"
              required
              autoComplete="given-name"
              value={form.first_name}
              onChange={(e) => setForm({ ...form, first_name: e.target.value })}
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="last_name">Last name</Label>
            <Input
              id="last_name"
              required
              autoComplete="family-name"
              value={form.last_name}
              onChange={(e) => setForm({ ...form, last_name: e.target.value })}
            />
          </div>
        </div>
        <div className="space-y-2">
          <Label htmlFor="email">Email</Label>
          <Input
            id="email"
            type="email"
            required
            autoComplete="email"
            value={form.email}
            onChange={(e) => setForm({ ...form, email: e.target.value })}
          />
        </div>
        <div className="space-y-2">
          <Label htmlFor="phone">Phone number</Label>
          <Input
            id="phone"
            type="tel"
            placeholder="+21620000000"
            required
            autoComplete="tel"
            value={form.phone}
            onChange={(e) => setForm({ ...form, phone: e.target.value })}
          />
        </div>
        <div className="space-y-2">
          <Label htmlFor="password">Password</Label>
          <Input
            id="password"
            type="password"
            required
            minLength={10}
            autoComplete="new-password"
            aria-describedby="password-hint"
            value={form.password}
            onChange={(e) => setForm({ ...form, password: e.target.value })}
          />
          <p id="password-hint" className="text-xs text-muted-foreground">
            Use at least 10 characters.
          </p>
        </div>
        <div className="space-y-2">
          <Label htmlFor="password_confirmation">Confirm password</Label>
          <Input
            id="password_confirmation"
            type="password"
            required
            autoComplete="new-password"
            value={form.password_confirmation}
            onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })}
          />
        </div>
        <Button type="submit" className="w-full" disabled={submitting}>
          {submitting ? 'Creating account…' : 'Create account'}
        </Button>
      </form>
      <GoogleSignInButton />
      <p className="mt-4 text-center text-sm text-muted-foreground">
        Already have an account?{' '}
        <Link href="/login" className="font-medium text-primary underline">
          Log in
        </Link>
      </p>
    </AuthCard>
  );
}
