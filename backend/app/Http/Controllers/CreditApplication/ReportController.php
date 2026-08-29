<?php

namespace App\Http\Controllers\CreditApplication;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendReportMessageRequest;
use App\Http\Resources\ReportMessageResource;
use App\Http\Responses\ApiResponse;
use App\Models\CreditApplication;
use App\Models\ReportMessage;
use App\Services\DocumentSecurity\ReportAttachmentService;
use App\Services\NotificationService;
use App\Services\ReportMessageBroadcastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportMessageBroadcastService $broadcast,
        private readonly NotificationService $notifications,
        private readonly ReportAttachmentService $attachments,
    ) {}

    public function index(Request $request, CreditApplication $application): JsonResponse
    {
        $this->authorize('view', $application);
        $this->assertReportOpen($application);

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
        $this->authorize('update', $application);
        $this->assertReportOpen($application);

        if ($application->isReportClosed()) {
            throw new ApiException(
                ApiErrorCode::Forbidden,
                'Cette discussion a été clôturée par la banque. Vous ne pouvez plus envoyer de messages.',
                status: 403
            );
        }

        $attachmentData = [];
        if ($request->hasFile('file')) {
            $attachmentData = $this->attachments->store(
                $application,
                $request->file('file'),
                $request->user(),
                $request->ip(),
                $request->userAgent(),
            );
        }

        $bodyText = $request->string('body')->toString();
        if (trim($bodyText) === '') {
            $bodyText = ! empty($attachmentData['attachment_name'])
                ? '📎 Pièce jointe : '.$attachmentData['attachment_name']
                : '📎 Pièce jointe';
        }

        try {
            $message = DB::transaction(function () use ($application, $request, $bodyText, $attachmentData) {
                CreditApplication::query()
                    ->whereKey($application->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $latestMessage = ReportMessage::query()
                    ->where('credit_application_id', $application->id)
                    ->latest('id')
                    ->first();
                if ($latestMessage?->sender_type === ReportMessage::SENDER_CUSTOMER) {
                    throw new ApiException(
                        ApiErrorCode::RateLimited,
                        'Vous avez déjà envoyé un message. Veuillez attendre la réponse du conseiller pour envoyer un nouveau message.',
                        status: 422
                    );
                }

                $message = $application->reportMessages()->create(array_merge([
                    'sender_type' => ReportMessage::SENDER_CUSTOMER,
                    'user_id' => $request->user()->id,
                    'body' => $bodyText,
                ], $attachmentData));

                $this->broadcast->send($message);

                $this->notifications->queueStaffAudience(
                    $message,
                    'report.message',
                    'New message on '.$application->application_number,
                    'The customer wrote a new message on application '.$application->application_number.'.',
                    [
                        'application_id' => $application->id,
                        'application_number' => $application->application_number,
                        'branch_id' => $application->branch_id,
                    ],
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

    public function downloadAttachment(CreditApplication $application, ReportMessage $message)
    {
        $this->authorize('view', $application);

        return $this->attachments->response($application, $message);
    }

    /** The appointment-escalation report opens after four successful customer reschedules. */
    private function assertReportOpen(CreditApplication $application): void
    {
        if (! $application->isReportOpen()) {
            throw new ApiException(ApiErrorCode::ReportNotOpen);
        }
    }
}
