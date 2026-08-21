import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { ApplicationStatus } from '@/lib/api/credit-applications';

const STEPS = [
  { key: 'client', label: 'Client', threshold: 'DRAFT' },
  { key: 'credit', label: 'Crédit', threshold: 'STEP_1_COMPLETED' },
  { key: 'project', label: 'Projet', threshold: 'STEP_2_COMPLETED' },
  { key: 'validation', label: 'Validation', threshold: 'STEP_3_COMPLETED' },
] as const;

const STATUS_ORDER: ApplicationStatus[] = [
  'DRAFT',
  'STEP_1_COMPLETED',
  'STEP_2_COMPLETED',
  'STEP_3_COMPLETED',
  'READY_FOR_VALIDATION_1',
  'VALIDATION_1_COMPLETED',
  'VALIDATION_2',
  'FINAL_LOCKED',
  'SUBMITTED',
];

/**
 * A step is completed when the application has reached that step's completion threshold —
 * driven purely by status, so visiting a later step out of order never paints earlier
 * steps as done.
 */
function isStepDone(status: ApplicationStatus, stepIndex: number): boolean {
  const rank = STATUS_ORDER.indexOf(status);
  const thresholdIndex = STEPS[stepIndex].threshold;
  return rank >= STATUS_ORDER.indexOf(thresholdIndex);
}

/** Index of the step currently being worked on (0-3), based on the application's status. */
export function ApplicationStepper({ status, current }: { status: ApplicationStatus; current: (typeof STEPS)[number]['key'] }) {
  const currentIndex = STEPS.findIndex((s) => s.key === current);

  return (
    <ol aria-label="Application progress" className="flex items-center gap-2 sm:gap-4">
      {STEPS.map((step, index) => {
        const isDone = isStepDone(status, index);
        const isCurrent = index === currentIndex;

        return (
          <li
            key={step.key}
            aria-current={isCurrent ? 'step' : undefined}
            className="flex flex-1 items-center gap-2 sm:gap-4"
          >
            <div className="flex flex-col items-center gap-1.5">
              <div
                aria-hidden
                className={cn(
                  'flex size-8 shrink-0 items-center justify-center rounded-full border text-sm font-medium',
                  isDone && 'border-primary bg-primary text-primary-foreground',
                  isCurrent && !isDone && 'border-primary text-primary',
                  !isDone && !isCurrent && 'border-border text-muted-foreground'
                )}
              >
                {isDone ? <Check className="size-4" /> : index + 1}
              </div>
              <span
                className={cn(
                  'text-xs font-medium',
                  isCurrent ? 'text-foreground' : 'text-muted-foreground'
                )}
              >
                {step.label}
                {isDone && <span className="sr-only"> (completed)</span>}
                {isCurrent && <span className="sr-only"> (current step)</span>}
              </span>
            </div>
            {index < STEPS.length - 1 && (
              <div aria-hidden className={cn('h-px flex-1', isDone ? 'bg-primary' : 'bg-border')} />
            )}
          </li>
        );
      })}
    </ol>
  );
}
