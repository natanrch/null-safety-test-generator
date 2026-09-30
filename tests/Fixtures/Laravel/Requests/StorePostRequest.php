<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePostRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string'],
            'email' => 'required|email',
            'published_at' => ['nullable', 'date'],
        ];
    }
}
