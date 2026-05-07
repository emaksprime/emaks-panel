<?php

namespace App\Http\Requests;

use App\Services\ActivationCodeSearchService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ActivationCodeSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'query' => ['required', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $normalizedQuery = app(ActivationCodeSearchService::class)
                    ->normalizeSearchValue((string) $this->input('query'));

                if (strlen($normalizedQuery) < ActivationCodeSearchService::MIN_QUERY_LENGTH) {
                    $validator->errors()->add('query', 'En az 6 karakter seri no girin.');
                }
            },
        ];
    }
}
