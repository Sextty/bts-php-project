<?php

namespace App\Http\Requests;

use App\Models\CreditApplication;
use App\Models\StaffUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AnalyticsQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof StaffUser;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'status' => ['nullable', Rule::in(CreditApplication::STATUS_ORDER)],
            'format' => ['nullable', Rule::in(['json', 'csv'])],
            'dataset' => ['nullable', Rule::in(['timeline', 'statuses', 'branches', 'appointments', 'workflow', 'credit', 'geography'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $defaultDays = max(1, (int) config('analytics.default_days', 365));
            $maxDays = max($defaultDays, (int) config('analytics.max_days', 730));
            $from = $this->date('from')?->startOfDay() ?? now()->subDays($defaultDays - 1)->startOfDay();
            $to = $this->date('to')?->endOfDay() ?? now()->endOfDay();

            if ($from->greaterThan($to)) {
                $validator->errors()->add('from', 'The start date must be before or equal to the end date.');
            } elseif ($from->diffInDays($to) + 1 > $maxDays) {
                $validator->errors()->add('to', "The analytics range cannot exceed {$maxDays} days.");
            }
        }];
    }
}
