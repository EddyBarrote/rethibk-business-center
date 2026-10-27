<?php

use App\Ai\Dictation\Dictation;
use App\Models\AiCharge;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;

// docs/DECISOES.md, "Falar em vez de escrever".

beforeEach(function () {
    config(['ai.default' => 'gemini', 'ai.providers.gemini.key' => 'test-key']);
    $this->tenant = Tenant::factory()->create();
    $this->user = asTenant($this->tenant, fn () => User::factory()->create());
});

it('turns what the person said into text for the box, charged to the budget', function () {
    Dictation::fake(['  Prepara a cotação de cimento para a Olankha.  ']);

    $this->actingAs($this->user)->postJson(tenantUrl($this->tenant, 'dictation'), ['audio' => UploadedFile::fake()->createWithContent('ditado.wav', str_repeat('a', 2048))->mimeType('audio/wav')])
        ->assertOk()->assertJson(['text' => 'Prepara a cotação de cimento para a Olankha.']);

    expect(asTenant($this->tenant, fn () => AiCharge::query()->where('purpose', 'dictation')->exists()))->toBeTrue();
});

it('takes only audio', function () {
    $this->actingAs($this->user)->postJson(tenantUrl($this->tenant, 'dictation'), ['audio' => UploadedFile::fake()->create('nota.pdf', 40, 'application/pdf')])
        ->assertUnprocessable();
});
