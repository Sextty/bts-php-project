<?php

namespace App\Http\Controllers\Staff;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnalyticsQueryRequest;
use App\Http\Responses\ApiResponse;
use App\Models\StaffUser;
use App\Services\Analytics\AnalyticsDataQualityService;
use App\Services\Analytics\AnalyticsFilter;
use App\Services\Analytics\AnalyticsService;
use App\Services\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AnalyticsController extends Controller
{
    public function __construct(
        private readonly AnalyticsService $analytics,
        private readonly AnalyticsDataQualityService $quality,
        private readonly AuditLogService $audit,
    ) {}

    public function overview(AnalyticsQueryRequest $request): JsonResponse
    {
        return ApiResponse::ok($this->analytics->snapshot($this->filter($request)));
    }

    public function dataQuality(AnalyticsQueryRequest $request): JsonResponse
    {
        /** @var StaffUser $viewer */
        $viewer = $request->user();
        if (! $viewer->isSuperuser()) {
            throw new ApiException(ApiErrorCode::Forbidden, 'Global analytics quality checks require an administrator.');
        }

        return ApiResponse::ok($this->quality->verify());
    }

    public function export(AnalyticsQueryRequest $request): JsonResponse|StreamedResponse
    {
        $validated = $request->validated();
        $filter = $this->filter($request);
        $snapshot = $this->analytics->snapshot($filter);
        $dataset = (string) ($validated['dataset'] ?? 'timeline');
        $format = (string) ($validated['format'] ?? 'csv');
        $rows = $this->rowsFor($snapshot, $dataset);
        $limit = max(1, (int) config('analytics.export_max_rows', 2500));
        if (count($rows) > $limit) {
            throw new ApiException(ApiErrorCode::AnalyticsExportLimit, "The aggregate export is limited to {$limit} rows.");
        }

        /** @var StaffUser $viewer */
        $viewer = $request->user();
        $this->audit->log(
            action: 'analytics.aggregate_exported',
            newState: [
                'dataset' => $dataset,
                'format' => $format,
                'rows' => count($rows),
                'from' => $filter->from->toDateString(),
                'to' => $filter->to->toDateString(),
                'branch_id' => $filter->branchId,
            ],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            staffUser: $viewer,
        );

        if ($format === 'json') {
            return ApiResponse::ok([
                'dataset' => $dataset,
                'count' => count($rows),
                'filters' => $filter->metadata(),
                'rows' => $rows,
            ]);
        }

        $filename = 'bts-analytics-'.$dataset.'-'.$filter->from->toDateString().'-'.$filter->to->toDateString().'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                return;
            }
            fwrite($stream, "\xEF\xBB\xBF");
            if ($rows !== []) {
                fputcsv($stream, array_keys($rows[0]), ';', '"', '');
                foreach ($rows as $row) {
                    fputcsv($stream, array_map(fn ($value) => is_scalar($value) || $value === null ? $value : json_encode($value), $row), ';', '"', '');
                }
            }
            fclose($stream);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function filter(AnalyticsQueryRequest $request): AnalyticsFilter
    {
        $validated = $request->validated();
        /** @var StaffUser $viewer */
        $viewer = $request->user();
        $requestedBranch = isset($validated['branch_id']) ? (int) $validated['branch_id'] : null;

        if ($viewer->isBranchRestricted()) {
            if ($requestedBranch !== null && $requestedBranch !== $viewer->branch_id) {
                throw new ApiException(ApiErrorCode::Forbidden, 'Analytics are restricted to your assigned branch.');
            }
            $branchId = $viewer->branch_id ?? -1;
        } else {
            $branchId = $requestedBranch;
        }

        $defaultDays = max(1, (int) config('analytics.default_days', 365));
        $from = isset($validated['from'])
            ? CarbonImmutable::parse($validated['from'])->startOfDay()
            : CarbonImmutable::today()->subDays($defaultDays - 1)->startOfDay();
        $to = isset($validated['to'])
            ? CarbonImmutable::parse($validated['to'])->endOfDay()
            : CarbonImmutable::today()->endOfDay();

        return new AnalyticsFilter($from, $to, $branchId, $validated['status'] ?? null);
    }

    /** @param array<string,mixed> $snapshot @return list<array<string,mixed>> */
    private function rowsFor(array $snapshot, string $dataset): array
    {
        return match ($dataset) {
            'timeline' => $snapshot['timeline'],
            'statuses' => $snapshot['statuses'],
            'branches' => $snapshot['branches'],
            'appointments' => collect($snapshot['appointments']['timeline'])->all(),
            'workflow' => $snapshot['workflow']['stages'],
            'credit' => collect($snapshot['credit']['by_currency'])->map(fn (array $row) => array_merge(['dimension' => 'currency'], $row))->all(),
            'geography' => collect($snapshot['geography'])->flatMap(fn (array $rows, string $dimension) => collect($rows)->map(fn (array $row) => array_merge(['dimension' => $dimension], $row)))->values()->all(),
        };
    }
}
