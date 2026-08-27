'use client';

import { useEffect, useId, useRef, type ReactNode } from 'react';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';

export function AccessibleModal({
  open,
  title,
  description,
  onClose,
  children,
  className,
}: {
  open: boolean;
  title: string;
  description?: string;
  onClose: () => void;
  children: ReactNode;
  className?: string;
}) {
  const titleId = useId();
  const descriptionId = useId();
  const panelRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const previousFocus = document.activeElement as HTMLElement | null;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    const panel = panelRef.current;
    panel?.querySelector<HTMLElement>('button, input, textarea, select, a[href]')?.focus();

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') onClose();
      if (event.key !== 'Tab' || !panel) return;
      const focusable = Array.from(
        panel.querySelectorAll<HTMLElement>('button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), a[href]')
      );
      if (focusable.length === 0) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }

    window.addEventListener('keydown', handleKeyDown);
    return () => {
      window.removeEventListener('keydown', handleKeyDown);
      document.body.style.overflow = previousOverflow;
      previousFocus?.focus();
    };
  }, [onClose, open]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <button type="button" aria-label="Fermer la fenêtre" className="absolute inset-0 bg-slate-950/45 backdrop-blur-sm" onClick={onClose} />
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={description ? descriptionId : undefined}
        className={cn('relative z-10 max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-3xl border border-white/70 bg-white p-6 shadow-2xl', className)}
      >
        <div className="mb-5 flex items-start justify-between gap-4 border-b border-slate-200 pb-4">
          <div>
            <h2 id={titleId} className="text-base font-bold text-slate-950">{title}</h2>
            {description && <p id={descriptionId} className="mt-1 text-xs text-slate-600">{description}</p>}
          </div>
          <button type="button" onClick={onClose} aria-label="Fermer" className="icon-button -mr-1 -mt-1">
            <X className="size-4" aria-hidden="true" />
          </button>
        </div>
        {children}
      </div>
    </div>
  );
}
