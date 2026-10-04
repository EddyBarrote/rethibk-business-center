<?php

namespace App\Http\Requests\Settings;

use App\Enums\ErpTransport;
use App\Models\ErpConnection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ErpConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $connection = ErpConnection::query()->first();

        return $connection !== null
            ? $this->user()?->can('update', $connection) ?? false
            : $this->user()?->can('create', ErpConnection::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'transport' => ['required', Rule::enum(ErpTransport::class)],
            'base_url' => ['nullable', 'required_if:transport,web', 'url:https,http', 'max:2048'],
            // Blank keeps the stored token.
            'token' => ['nullable', 'string', 'max:4096'],
            'enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['base_url' => 'endereço do servidor', 'transport' => 'transporte', 'token' => 'token'];
    }
}
