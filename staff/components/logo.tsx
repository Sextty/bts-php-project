import Image from 'next/image';
import { cn } from '@/lib/utils';

export function Logo({ size = 40, className }: { size?: number; className?: string }) {
  return (
    <Image
      src="/logo.png"
      alt="BTS Bank"
      width={size}
      height={size}
      priority
      className={cn('shrink-0', className)}
    />
  );
}
