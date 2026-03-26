<?php

declare(strict_types=1);

namespace App\Http\Requests\CatalogAgent;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CatalogReloadStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'run_id' => ['required', 'uuid'],
        ];
    }

    public function runId(): string
    {
        return $this->string('run_id')->toString();
    }
}
