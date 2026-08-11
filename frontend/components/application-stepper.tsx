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

/** Index of the step currently being worked on (0-3), based on the application's status. */
function activeStepIndex(status: ApplicationStatus): number {
  const rank = STATUS_ORDER.indexOf(status);
  if (rank >= STATUS_ORDER.indexOf('STEP_3_COMPLETED')) return 3;
  if (rank >= STATUS_ORDER.indexOf('STEP_2_COMPLETED')) return 2;
  if (rank >= STATUS_ORDER.indexOf('STEP_1_COMPLETED')) return 1;
  return 0;
}

export function ApplicationStepper({ status, current }: { status: ApplicationStatus; current: (typeof STEPS)[number]['key'] }) {
  const active = activeStepIndex(status);
  const currentIndex = STEPS.findIndex((s) => s.key === current);

  return (
    <ol className="flex items-center gap-2 sm:gap-4">
      {STEPS.map((step, index) => {
        const isDone = index < active || (index === active && currentIndex > index);
        const isCurrent = step.key === current;

        return (
          <li key={step.key} className="flex flex-1 items-center gap-2 sm:gap-4">
            <div className="flex flex-col items-center gap-1.5">
              <div
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
              </span>
            </div>
            {index < STEPS.length - 1 && (
              <div className={cn('h-px flex-1', isDone ? 'bg-primary' : 'bg-border')} />
            )}
          </li>
        );
      })}
    </ol>
  );
}
