'use client';

import Link from 'next/link';
import { ArrowLeft, ChevronRight, Home } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface BreadcrumbItem {
  label: string;
  href?: string;
}

export function Breadcrumbs({ items, className }: { items: BreadcrumbItem[]; className?: string }) {
  return (
    <nav aria-label="Fil d’Ariane" className={cn('text-xs text-[#6a7a8b]', className)}>
      <ol className="flex flex-wrap items-center gap-1.5">
        {items.map((item, index) => {
          const isLast = index === items.length - 1;
          return (
            <li key={index} className="flex items-center gap-1">
              {index > 0 && <ChevronRight className="size-3 shrink-0 text-[#9aa7b4]" aria-hidden="true" />}
              {item.href && !isLast ? (
                <Link href={item.href} className="inline-flex items-center gap-1 rounded-md px-1 py-0.5 font-medium transition hover:bg-white hover:text-[#a82027] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#c0272d]/40">
                  {index === 0 && <Home className="size-3" aria-hidden="true" />}
                  {item.label}
                </Link>
              ) : (
                <span className={isLast ? 'font-semibold text-[#1e2d3d]' : ''} aria-current={isLast ? 'page' : undefined}>{item.label}</span>
              )}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}

/** Back link with arrow, used at the top of detail pages. */
export function BackLink({ href, label }: { href: string; label: string }) {
  return (
    <Link
      href={href}
      className="mb-1 inline-flex min-h-9 items-center gap-2 rounded-xl border border-[#dfe5eb] bg-white px-3 text-xs font-semibold text-[#536579] shadow-sm transition hover:border-[#fecaca] hover:bg-[#fdf2f2] hover:text-[#a82027] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#c0272d]/40"
    >
      <ArrowLeft className="size-3.5" aria-hidden="true" />
      {label}
    </Link>
  );
}
