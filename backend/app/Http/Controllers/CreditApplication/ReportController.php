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
use App\Services\NotificationService;
use App\Services\ReportMessageBroadcastService;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportMessageBroadcastService $broadcast,
        private readonly NotificationService $notifications,
    ) {}

    public function index(CreditApplication $application): JsonResponse
    {
        $this->authorize('view', $application);
        $this->assertReportOpen($application);

        return ApiResponse::ok([
            'messages' => ReportMessageResource::collection($application->reportMessages),
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

        $latestMessage = $application->reportMessages()->latest('id')->first();
        if ($latestMessage && $latestMessage->sender_type === ReportMessage::SENDER_CUSTOMER) {
            throw new ApiException(
                ApiErrorCode::RateLimited,
                'Vous avez déjà envoyé un message. Veuillez attendre la réponse du conseiller pour envoyer un nouveau message.',
                status: 422
            );
        }

        $attachmentData = [];
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store("report_attachments/application-{$application->id}", 'local');
            $attachmentData = [
                'attachment_path' => $path,
                'attachment_name' => $file->getClientOriginalName(),
                'attachment_type' => $file->getMimeType(),
                'attachment_size' => $file->getSize(),
            ];
        }

        $bodyText = $request->string('body')->toString();
        if (trim($bodyText) === '') {
            $bodyText = ! empty($attachmentData['attachment_name'])
                ? '📎 Pièce jointe : '.$attachmentData['attachment_name']
                : '📎 Pièce jointe';
        }

        $message = $application->reportMessages()->create(array_merge([
            'sender_type' => ReportMessage::SENDER_CUSTOMER,
            'user_id' => $request->user()->id,
            'body' => $bodyText,
        ], $attachmentData));

        $this->broadcast->send($message);

        $this->notifications->notifyStaff(
            'report.message',
            'New message on '.$application->application_number,
            'The customer wrote a new message on application '.$application->application_number.'.',
            ['application_id' => $application->id, 'application_number' => $application->application_number],
        );

        return ApiResponse::created(['message' => new ReportMessageResource($message)]);
    }

    public function downloadAttachment(CreditApplication $application, ReportMessage $message)
    {
        $this->authorize('view', $application);

        if ($message->credit_application_id !== $application->id || ! $message->attachment_path) {
            throw new ApiException(ApiErrorCode::NotFound, 'Pièce jointe introuvable.');
        }

        if (! \Illuminate\Support\Facades\Storage::disk('local')->exists($message->attachment_path)) {
            throw new ApiException(ApiErrorCode::NotFound, 'Fichier introuvable sur le serveur.');
        }

        $safeFilename = basename(preg_replace('/[^\w.\-\s]/u', '_', $message->attachment_name ?? 'piece_jointe'));

        return \Illuminate\Support\Facades\Storage::disk('local')->download(
            $message->attachment_path,
            $safeFilename
        );
    }

    /**
     * The report opens only once the application has been locked after 3 rejections — before
     * that, there's nothing to report and no thread for the customer to write into.
     */
    private function assertReportOpen(CreditApplication $application): void
    {
        if (! $application->isReportOpen()) {
            throw new ApiException(ApiErrorCode::ReportNotOpen);
        }
    }
}
