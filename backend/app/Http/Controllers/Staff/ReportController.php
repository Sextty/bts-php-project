<?php

namespace App\Http\Controllers\Staff;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendReportMessageRequest;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\CreditApplicationResource;
use App\Http\Resources\ReportMessageResource;
use App\Http\Responses\ApiResponse;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\CreditApplication;
use App\Models\ReportMessage;
use App\Models\StaffUser;
use App\Services\AppointmentSchedulingService;
use App\Services\DocumentSecurity\ReportAttachmentService;
use App\Services\NotificationService;
use App\Services\ReportMessageBroadcastService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Any staff role (not admin-only) can see and reply here — same reasoning as the rest of the
 * staff dashboard: a locked application needs a human to pick it up quickly, and gatekeeping that
 * behind admin-only would just slow down the customer's wait for a reply.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportMessageBroadcastService $broadcast,
        private readonly NotificationService $notifications,
        private readonly AppointmentSchedulingService $appointmentScheduling,
        private readonly ReportAttachmentService $attachments,
    ) {}

    public function branches(Request $request): JsonResponse
    {
        /** @var StaffUser $staff */
        $staff = $request->user();
        $branches = Branch::query()
            ->when($staff->isBranchRestricted(), fn ($query) => $query->whereKey($staff->branch_id))
            ->orderBy('ville')
            ->orderBy('name')
            ->get();

        return ApiResponse::ok(['branches' => $branches]);
    }

    public function index(Request $request): JsonResponse
    {
        $applications = CreditApplication::query()
            ->accessibleToStaff($request->user())
            ->where(function ($q) {
                $q->whereIn('status', [
                    CreditApplication::STATUS_APPOINTMENT_LOCKED,
                    CreditApplication::STATUS_CANCELLED,
                ])
                    ->orWhereHas('appointments', fn ($appointments) => $appointments
                        ->where('reschedule_count', '>=', Appointment::MAX_RESCHEDULES))
                    ->orWhereHas('reportMessages');
            })
            ->with(['user', 'client', 'creditRequest', 'project', 'branch'])
            ->withCount('reportMessages')
            ->latest('updated_at')
            ->paginate(25);

        return ApiResponse::ok([
            'applications' => CreditApplicationResource::collection($applications->items()),
            'meta' => [
                'current_page' => $applications->currentPage(),
                'last_page' => $applications->lastPage(),
                'total' => $applications->total(),
            ],
        ]);
    }

    public function show(Request $request, CreditApplication $application): JsonResponse
    {
        $this->assertStaffCanAccess($request->user(), $application);
        $this->assertLocked($application);

        $limit = max(1, min((int) $request->query('limit', 100), 200));
        $query = $application->reportMessages()->with(['user', 'staffUser'])->latest('id');
        if ($request->filled('before_id')) {
            $query->where('id', '<', max(1, (int) $request->query('before_id')));
        }
        $messages = $query->limit($limit + 1)->get();
        $hasMore = $messages->count() > $limit;
        $messages = $messages->take($limit);

        return ApiResponse::ok([
            'messages' => ReportMessageResource::collection($messages->reverse()->values()),
            'meta' => [
                'has_more' => $hasMore,
                'next_before_id' => $hasMore ? $messages->last()?->id : null,
                'limit' => $limit,
            ],
            'is_closed' => $application->isReportClosed(),
            'closed_at' => $application->report_closed_at,
            'closed_reason' => $application->report_closed_reason,
        ]);
    }

    public function store(SendReportMessageRequest $request, CreditApplication $application): JsonResponse
    {
        $this->assertStaffCanAccess($request->user(), $application);
        $this->assertLocked($application);

        /** @var StaffUser $staff */
        $staff = $request->user();

        $attachmentData = [];
        if ($request->hasFile('file')) {
            $attachmentData = $this->attachments->store(
                $application,
                $request->file('file'),
                $staff,
                $request->ip(),
                $request->userAgent(),
            );
        }

        $bodyText = $request->string('body')->toString();
        if (trim($bodyText) === '') {
            $bodyText = ! empty($attachmentData['attachment_name'])
                ? '📎 Document transmis : '.$attachmentData['attachment_name']
                : '📎 Document transmis';
        }

        try {
            $message = DB::transaction(function () use ($application, $staff, $bodyText, $attachmentData) {
                CreditApplication::query()
                    ->whereKey($application->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $message = $application->reportMessages()->create(array_merge([
                    'sender_type' => ReportMessage::SENDER_STAFF,
                    'staff_user_id' => $staff->id,
                    'body' => $bodyText,
                ], $attachmentData));

                $this->broadcast->send($message);

                $this->notifications->notifyUser(
                    $application->user,
                    'report.message',
                    'Nouveau message de BTS Bank (Dossier '.$application->application_number.')',
                    'Un conseiller a répondu sur votre dossier '.$application->application_number.'.',
                    ['application_id' => $application->id, 'application_number' => $application->application_number],
                    dedupeKey: 'report-message-'.$message->id,
                );

                return $message;
            });
        } catch (\Throwable $exception) {
            $this->attachments->deleteStored($attachmentData);
            throw $exception;
        }

        return ApiResponse::created(['message' => new ReportMessageResource($message)]);
    }

    public function closeThread(Request $request, CreditApplication $application): JsonResponse
    {
        $this->assertStaffCanAccess($request->user(), $application);
        /** @var StaffUser $staff */
        $staff = $request->user();

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $reason = $validated['reason'] ?? 'Échanges terminés et dossier traité';

        $message = DB::transaction(function () use ($application, $staff, $reason) {
            $application->update([
                'report_closed_at' => now(),
                'report_closed_by_staff_id' => $staff->id,
                'report_closed_reason' => $reason,
            ]);

            $message = $application->reportMessages()->create([
                'credit_application_id' => $application->id,
                'sender_type' => ReportMessage::SENDER_STAFF,
                'staff_user_id' => $staff->id,
                'body' => "🔒 La discussion a été clôturée par votre conseiller BTS Bank.\nMotif : {$reason}",
            ]);

            $this->broadcast->send($message);
            $this->notifications->notifyUser(
                $application->user,
                'report.closed',
                'Discussion clôturée — Dossier '.$application->application_number,
                'Les échanges concernant votre dossier ont été clôturés par votre conseiller.',
                ['application_id' => $application->id, 'application_number' => $application->application_number],
                dedupeKey: 'report-closed-'.$message->id,
            );

            return $message;
        });

        return ApiResponse::ok([
            'application' => new CreditApplicationResource($application),
            'message' => new ReportMessageResource($message),
        ]);
    }

    public function reopenThread(Request $request, CreditApplication $application): JsonResponse
    {
        $this->assertStaffCanAccess($request->user(), $application);
        /** @var StaffUser $staff */
        $staff = $request->user();

        $message = DB::transaction(function () use ($application, $staff) {
            $application->update([
                'report_closed_at' => null,
                'report_closed_by_staff_id' => null,
                'report_closed_reason' => null,
            ]);

            $message = $application->reportMessages()->create([
                'credit_application_id' => $application->id,
                'sender_type' => ReportMessage::SENDER_STAFF,
                'staff_user_id' => $staff->id,
                'body' => '🔓 La discussion a été rouverte par votre conseiller BTS Bank.',
            ]);

            $this->broadcast->send($message);
            $this->notifications->notifyUser(
                $application->user,
                'report.reopened',
                'Discussion rouverte — Dossier '.$application->application_number,
                'Votre conseiller a rouvert la discussion sur votre dossier.',
                ['application_id' => $application->id, 'application_number' => $application->application_number],
                dedupeKey: 'report-reopened-'.$message->id,
            );

            return $message;
        });

        return ApiResponse::ok([
            'application' => new CreditApplicationResource($application),
            'message' => new ReportMessageResource($message),
        ]);
    }

    public function downloadAttachment(Request $request, CreditApplication $application, ReportMessage $message)
    {
        $this->assertStaffCanAccess($request->user(), $application);

        return $this->attachments->response($application, $message);
    }

    public function scheduleAppointment(Request $request, CreditApplication $application): JsonResponse
    {
        $this->assertStaffCanAccess($request->user(), $application);

        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'scheduled_date' => ['required', 'date'],
            'scheduled_time' => ['required', 'string'],
            'direct_confirm' => ['nullable', 'boolean'],
            'message_body' => ['nullable', 'string', 'max:1000'],
        ]);

        $directConfirm = $request->boolean('direct_confirm', true);
        $branchId = $validated['branch_id'] ?? $application->branch_id ?? Branch::query()->value('id');

        if (! $branchId) {
            throw new ApiException(ApiErrorCode::NotFound, 'Aucune agence trouvée.');
        }
        /** @var StaffUser $staff */
        $staff = $request->user();
        $dateFormatted = Carbon::parse($validated['scheduled_date'])->locale('fr')->isoFormat('dddd D MMMM YYYY');
        $timeFormatted = substr($validated['scheduled_time'], 0, 5);

        $actionText = $directConfirm
            ? '📅 Rendez-vous fixé et validé par votre conseiller :'
            : '📅 Nouveau créneau de rendez-vous proposé par votre conseiller :';

        [$appointment, $message] = DB::transaction(function () use ($application, $staff, $branchId, $validated, $directConfirm, $dateFormatted, $timeFormatted, $actionText, $request) {
            $appointment = $this->appointmentScheduling->scheduleManual(
                $application,
                $staff,
                (int) $branchId,
                $validated['scheduled_date'],
                $validated['scheduled_time'],
                $directConfirm,
                $request->ip(),
                $request->userAgent(),
            );
            $branch = $appointment->branch;
            $body = $actionText."\n"
                .'• Agence BTS : '.$branch->name.' ('.$branch->address.', '.$branch->ville.")\n"
                .'• Date : '.ucfirst($dateFormatted).' à '.$timeFormatted."h\n"
                .($directConfirm ? "• Statut : Confirmé & Verrouillé\n" : "• Statut : En attente de confirmation\n");

            if (! empty($validated['message_body'])) {
                $body .= '• Note du conseiller : '.$validated['message_body'];
            }

            $message = $application->reportMessages()->create([
                'sender_type' => ReportMessage::SENDER_STAFF,
                'staff_user_id' => $staff->id,
                'body' => $body,
            ]);
            $this->broadcast->send($message);
            $this->notifications->notifyUser(
                $application->user,
                'appointment.scheduled',
                'Rendez-vous fixé pour la demande '.$application->application_number,
                'Votre conseiller BTS a fixé un rendez-vous le '.ucfirst($dateFormatted).' à '.$timeFormatted.' à '.$branch->name.'.',
                [
                    'application_id' => $application->id,
                    'application_number' => $application->application_number,
                    'appointment_id' => $appointment->id,
                    'scheduled_date' => $appointment->scheduled_date,
                    'scheduled_time' => $appointment->scheduled_time,
                    'branch_name' => $branch->name,
                ],
                dedupeKey: 'manual-appointment-'.$appointment->id,
            );

            return [$appointment, $message];
        }, 5);

        return ApiResponse::ok([
            'application' => new CreditApplicationResource($application->fresh(['branch', 'appointments', 'user', 'client'])),
            'appointment' => new AppointmentResource($appointment->fresh('branch')),
            'message' => new ReportMessageResource($message),
        ]);
    }

    /**
     * Same branch gate as the review surface — a branch-assigned staff member only reaches
     * applications routed to their branch.
     */
    private function assertStaffCanAccess(StaffUser $staff, CreditApplication $application): void
    {
        if (! $staff->canAccessApplication($application)) {
            throw new ApiException(ApiErrorCode::Forbidden, 'This application is not handled by your branch.');
        }
    }

    private function assertLocked(CreditApplication $application): void
    {
        if (! $application->isReportOpen()) {
            throw new ApiException(ApiErrorCode::ReportNotOpen);
        }
    }
}
