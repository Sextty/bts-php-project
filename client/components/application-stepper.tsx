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
    <section className="portal-panel space-y-5 p-4 sm:p-5" aria-label="Progression du dossier">
      <div className="flex items-center justify-between gap-3">
        <div>
          <p className="text-[10px] font-bold uppercase tracking-[0.16em] text-[#a82027]">Parcours de demande</p>
          <p className="mt-1 text-xs text-[#6a7a8b]">Complétez chaque étape pour transmettre votre dossier.</p>
        </div>
        <span className="hidden rounded-full bg-[#f3f6f8] px-3 py-1 text-[10px] font-bold text-[#536579] sm:inline-flex">
          Étape {Math.max(currentIndex + 1, 1)} / {STEPS.length}
        </span>
      </div>
      {/* 5-step form progress */}
      <ol aria-label="Progression de la demande" className="flex items-start gap-1.5 sm:gap-3">
        {STEPS.map((step, index) => {
          const isDone = isStepDone(status, index);
          const isCurrent = index === currentIndex && !submitted;

          return (
            <li
              key={step.key}
              aria-current={isCurrent ? 'step' : undefined}
              className="flex flex-1 items-start gap-1.5 sm:gap-3"
            >
              <div className="flex flex-col items-center gap-1.5">
                <div
                  aria-hidden
                  className={cn(
                    'flex size-9 shrink-0 items-center justify-center rounded-xl border text-xs font-bold shadow-sm transition-all',
                    isDone && 'border-[#c0272d] bg-[#c0272d] text-white',
                    isCurrent && !isDone && 'border-[#c0272d] bg-[#fdf2f2] text-[#a82027] ring-4 ring-[#c0272d]/10',
                    !isDone && !isCurrent && 'border-[#dce3ea] bg-white text-[#82909e]'
                  )}
                >
                  {isDone ? <Check className="size-4" /> : index + 1}
                </div>
                <span
                  className={cn(
                    'hidden max-w-20 text-center text-[10px] font-semibold leading-tight sm:block',
                    isCurrent ? 'text-[#0c1825]' : 'text-[#6a7a8b]'
                  )}
                >
                  {step.label}
                </span>
              </div>
              {index < STEPS.length - 1 && (
                <div aria-hidden className={cn('mt-4 h-0.5 flex-1 rounded-full', isDone ? 'bg-[#c0272d]' : 'bg-[#dfe5eb]')} />
              )}
            </li>
          );
        })}
      </ol>

      {/* Post-submission review progress */}
      {submitted && (
        <div className="rounded-2xl border border-[#dce3ea] bg-[#f7f9fa] px-4 py-3.5">
          <ol aria-label="Progression de l’étude" className="flex items-center gap-1 sm:gap-2">
            {REVIEW_PHASES.map((rp, index) => {
              const active = index <= getReviewPhaseIndex(status);
              const Icon = rp.icon;
              return (
                <li key={rp.phase} className="flex flex-1 items-center gap-1.5 sm:gap-2">
                  <div className="flex items-center gap-1.5">
                    <Icon className={cn('size-3.5 shrink-0', active ? 'text-[#c0272d]' : 'text-[#a8b2bc]')} />
                    <span className={cn('text-[10px] sm:text-xs', active ? 'font-semibold text-[#1e2d3d]' : 'text-[#82909e]')}>
                      {rp.label}
                    </span>
                  </div>
                  {index < REVIEW_PHASES.length - 1 && (
                    <div className={cn('h-0.5 flex-1 rounded-full', active && index < getReviewPhaseIndex(status) ? 'bg-[#c0272d]' : 'bg-[#dfe5eb]')} />
                  )}
                </li>
              );
            })}
          </ol>
        </div>
      )}
    </section>
  );
}
