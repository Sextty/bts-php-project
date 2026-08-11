import Link from 'next/link';
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export default function Home() {
  return (
    <main className="flex min-h-screen flex-col items-center justify-center gap-6 bg-neutral-50 p-8 text-center">
      <h1 className="text-4xl font-bold">BTS Bank — Credit Application</h1>
      <p className="max-w-lg text-lg text-neutral-600">
        Apply for credit online: register, verify your phone, and manage your application from your dashboard.
      </p>
      <div className="flex gap-4">
        <Link href="/login" className={cn(buttonVariants({ variant: 'default' }), 'h-10 px-6')}>
          Log in
        </Link>
        <Link href="/register" className={cn(buttonVariants({ variant: 'outline' }), 'h-10 px-6')}>
          Create an account
        </Link>
      </div>
    </main>
  );
}
