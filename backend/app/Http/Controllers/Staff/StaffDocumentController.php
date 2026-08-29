<?php

namespace App\Http\Controllers\Staff;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\CreditApplication;
use App\Models\Document;
use App\Models\StaffUser;
use App\Services\DocumentStorage\DocumentStorage;
use App\Services\DocumentSecurity\DocumentAccessPolicy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Staff/admin document download. Same security posture as the customer download in
 * DocumentController, with the staff layer's own authorization on top:
 *
 *   - the route requires auth:sanctum + the staff role + permission:application.review;
 *   - branch-assigned staff are further scoped via StaffUser::canAccessApplication() —
 *     a guessed URL to another branch's file is denied loudly (403), the same rule the
 *     review queue and report inbox enforce;
 *   - only applications actually under review (SUBMITTED → decision) are reachable, so
 *     staff can't pull documents out of a customer's still-open draft;
 *   - the file streams through the application from the private disk — no storage URL is
 *     ever exposed.
 */
class StaffDocumentController extends Controller
{
    private const REVIEW_STATUSES = [
        CreditApplication::STATUS_SUBMITTED,
        CreditApplication::STATUS_STAFF_APPROVED,
        CreditApplication::STATUS_STAFF_REJECTED,
        CreditApplication::STATUS_APPROVED,
        CreditApplication::STATUS_REJECTED,
    ];

    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly DocumentAccessPolicy $documentAccess,
    ) {}

    public function download(Request $request, CreditApplication $application, Document $document): BinaryFileResponse|StreamedResponse
    {
        /** @var StaffUser $staff */
        $staff = $request->user();

        $this->assertStaffCanAccess($staff, $application);
        $this->assertUnderReview($application);

        if ($document->credit_application_id !== $application->id || $document->trashed()) {
            throw new ApiException(ApiErrorCode::DocumentNotFound);
        }

        $this->documentAccess->assertDownloadable($document);

        return $this->storage->response(
            $document->disk_path,
            $document->original_filename,
            $document->mime_type,
        );
    }

    private function assertStaffCanAccess(StaffUser $staff, CreditApplication $application): void
    {
        if (! $staff->canAccessApplication($application)) {
            throw new ApiException(ApiErrorCode::Forbidden, 'This application is not handled by your branch.');
        }
    }

    private function assertUnderReview(CreditApplication $application): void
    {
        if (! in_array($application->status, self::REVIEW_STATUSES, true)) {
            throw new ApiException(ApiErrorCode::NotUnderReview);
        }
    }
}
