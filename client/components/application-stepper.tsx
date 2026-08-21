import { Check, Send, Clock, MapPin, CheckCircle2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { ApplicationStatus } from '@/lib/api/credit-applications';
import { statusPhase, type StatusPhase } from '@/lib/status-labels';

/**
 * 5-step progress indicator covering the full customer journey:
 *   1. Personal info  2. Credit  3. Project  4. Validation  5. Submit
 *
 * After submission, a secondary status bar shows the review / appointment progress.
 */
const STEPS = [
  { key: 'client', label: 'Informations', threshold: 'DRAFT' },
  { key: 'credit', label: 'Crédit', threshold: 'STEP_1_COMPLETED' },
  { key: 'project', label: 'Projet', threshold: 'STEP_2_COMPLETED' },
  { key: 'validation', label: 'Validation', threshold: 'STEP_3_COMPLETED' },
  { key: 'submit', label: 'Soumission', threshold: 'VALIDATION_1_COMPLETED' },
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

function isStepDone(status: ApplicationStatus, stepIndex: number): boolean {
  const rank = STATUS_ORDER.indexOf(status);
  const thresholdIndex = STEPS[stepIndex].threshold;
  return rank >= STATUS_ORDER.indexOf(thresholdIndex);
}

function isSubmitted(status: ApplicationStatus): boolean {
  return STATUS_ORDER.indexOf(status) >= STATUS_ORDER.indexOf('SUBMITTED');
}

/**
 * Post-submission phase indicator. Shows where the application is in the review pipeline.
 */
const REVIEW_PHASES: { phase: StatusPhase; label: string; icon: React.ElementType }[] = [
  { phase: 'submitted', label: 'Soumise', icon: Send },
  { phase: 'review', label: 'En révision', icon: Clock },
  { phase: 'appointment', label: 'Rendez-vous', icon: MapPin },
  { phase: 'terminal', label: 'Terminée', icon: CheckCircle2 },
];

function getReviewPhaseIndex(status: ApplicationStatus): number {
  const phase = statusPhase(status);
  if (phase === 'terminal') {
    if (status === 'APPROVED' || status === 'STAFF_APPROVED') return 2;
    return 3;
  }
  const idx = REVIEW_PHASES.findIndex((p) => p.phase === phase);
  return idx >= 0 ? idx : 0;
}

export function ApplicationStepper({ status, current }: { status: ApplicationStatus; current: (typeof STEPS)[number]['key'] }) {
  const currentIndex = STEPS.findIndex((s) => s.key === current);
  const submitted = isSubmitted(status);

  return (
    <div className="space-y-4">
      {/* 5-step form progress */}
      <ol aria-label="Application progress" className="flex items-center gap-2 sm:gap-4">
        {STEPS.map((step, index) => {
          const isDone = isStepDone(status, index);
          const isCurrent = index === currentIndex && !submitted;

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
                    'flex size-8 shrink-0 items-center justify-center rounded-full border text-sm font-medium transition-colors',
                    isDone && 'border-primary bg-primary text-primary-foreground',
                    isCurrent && !isDone && 'border-primary text-primary',
                    !isDone && !isCurrent && 'border-border text-muted-foreground'
                  )}
                >
                  {isDone ? <Check className="size-4" /> : index + 1}
                </div>
                <span
                  className={cn(
                    'text-xs font-medium hidden sm:block',
                    isCurrent ? 'text-foreground' : 'text-muted-foreground'
                  )}
                >
                  {step.label}
                </span>
              </div>
              {index < STEPS.length - 1 && (
                <div aria-hidden className={cn('h-px flex-1', isDone ? 'bg-primary' : 'bg-border')} />
              )}
            </li>
          );
        })}
      </ol>

      {/* Post-submission review progress */}
      {submitted && (
        <div className="rounded-lg border border-border/60 bg-card/60 px-4 py-3">
          <ol aria-label="Review progress" className="flex items-center gap-1 sm:gap-2">
            {REVIEW_PHASES.map((rp, index) => {
              const active = index <= getReviewPhaseIndex(status);
              const Icon = rp.icon;
              return (
                <li key={rp.phase} className="flex flex-1 items-center gap-1.5 sm:gap-2">
                  <div className="flex items-center gap-1.5">
                    <Icon className={cn('size-3.5 shrink-0', active ? 'text-primary' : 'text-muted-foreground/50')} />
                    <span className={cn('text-xs', active ? 'font-medium text-foreground' : 'text-muted-foreground')}>
                      {rp.label}
                    </span>
                  </div>
                  {index < REVIEW_PHASES.length - 1 && (
                    <div className={cn('h-px flex-1', active && index < getReviewPhaseIndex(status) ? 'bg-primary' : 'bg-border')} />
                  )}
                </li>
              );
            })}
          </ol>
        </div>
      )}
    </div>
  );
}
