<?php

namespace App\Http\Controllers\Staff;

use App\Enums\ApiErrorCode;
use App\Enums\Permission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReportMessageResource;
use App\Http\Responses\ApiResponse;
use App\Models\CreditApplication;
use App\Models\ReportMessage;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use App\Services\ReportMessageBroadcastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserBanController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly NotificationService $notifications,
        private readonly ReportMessageBroadcastService $broadcast,
    ) {}

    /**
     * List all banned/suspended users for Staff and Admin view.
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query()
            ->whereNotNull('banned_at')
            ->orWhere('status', 'suspended')
            ->with(['bannedByStaff', 'creditApplications.client'])
            ->latest('banned_at');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $bannedUsers = $query->paginate(20);

        return ApiResponse::ok([
            'banned_users' => $bannedUsers->map(function (User $user) {
                $latestApp = $user->creditApplications()->latest()->first();
                $cin = $latestApp?->client?->numero_piece_identite ?? 'Non renseigné';

                return [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'name' => "{$user->first_name} {$user->last_name}",
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'cin' => $cin,
                    'status' => $user->status,
                    'banned_at' => $user->banned_at?->toIso8601String(),
                    'banned_reason' => $user->banned_reason,
                    'banned_by' => $user->bannedByStaff ? [
                        'id' => $user->bannedByStaff->id,
                        'name' => "{$user->bannedByStaff->first_name} {$user->bannedByStaff->last_name}",
                        'role' => $user->bannedByStaff->role,
                    ] : null,
                    'applications_count' => $user->creditApplications()->count(),
                ];
            }),
            'meta' => [
                'current_page' => $bannedUsers->currentPage(),
                'last_page' => $bannedUsers->lastPage(),
                'total' => $bannedUsers->total(),
            ],
        ]);
    }

    /**
     * Ban client directly from the application / chat interface.
     */
    public function banFromApplication(Request $request, CreditApplication $application): JsonResponse
    {
        /** @var StaffUser $staff */
        $staff = $request->user();

        if ($staff instanceof StaffUser && ! $staff->canAccessApplication($application)) {
            throw new ApiException(ApiErrorCode::Forbidden, 'Cette demande de crédit ne relève pas de votre agence.');
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:3|max:1000',
        ]);

        $user = $application->user;
        if (! $user) {
            throw new ApiException(ApiErrorCode::NotFound, 'Utilisateur introuvable.');
        }

        $reason = $validated['reason'];

        $message = DB::transaction(function () use ($user, $reason, $staff, $application) {
            $user->ban($reason, $staff);

            // Also close the discussion thread automatically
            $application->update([
                'report_closed_at' => now(),
                'report_closed_by_staff_id' => $staff->id,
                'report_closed_reason' => "Compte utilisateur suspendu : {$reason}",
            ]);

            // Post notice in the chat thread
            $msg = ReportMessage::create([
                'credit_application_id' => $application->id,
                'sender_type' => ReportMessage::SENDER_STAFF,
                'staff_user_id' => $staff->id,
                'body' => "🚫 AVERTISSEMENT : Le compte de l'utilisateur a été suspendu par l'administration BTS Bank.\nMotif : {$reason}\nLa discussion est désormais clôturée.",
            ]);

            $this->auditLog->log('user.banned', $user, newState: [
                'reason' => $reason,
                'banned_by' => $staff->id,
                'application_id' => $application->id,
            ]);

            $this->broadcast->send($msg);

            return $msg;
        });

        return ApiResponse::ok([
            'user' => [
                'id' => $user->id,
                'name' => "{$user->first_name} {$user->last_name}",
                'status' => $user->status,
                'banned_at' => $user->banned_at,
                'banned_reason' => $user->banned_reason,
            ],
            'message' => new ReportMessageResource($message),
        ]);
    }

    /**
     * Unban / unlock a client account.
     */
    public function unban(Request $request, User $user): JsonResponse
    {
        /** @var StaffUser $staff */
        $staff = $request->user();

        if ($staff instanceof StaffUser && ! $staff->isSuperuser() && ! $staff->hasPermission(Permission::UsersManage)) {
            throw new ApiException(ApiErrorCode::Forbidden, 'Vous n\'avez pas les droits nécessaires pour débloquer un compte client.');
        }

        DB::transaction(function () use ($user, $staff) {
            $user->unban();

            $this->auditLog->log('user.unbanned', $user, newState: [
                'unbanned_by' => $staff->id,
            ]);

            $this->notifications->notifyUser(
                $user,
                'account.reactivated',
                'Compte BTS Bank débloqué',
                'Votre compte a été réactivé avec succès par un administrateur BTS Bank.',
                dedupeKey: 'account-reactivated-'.$user->id.'-'.$user->updated_at?->getTimestamp(),
            );
        });

        return ApiResponse::ok([
            'message' => 'Le compte du client a été débloqué avec succès.',
            'user' => [
                'id' => $user->id,
                'name' => "{$user->first_name} {$user->last_name}",
                'status' => $user->status,
                'banned_at' => null,
            ],
        ]);
    }
}
