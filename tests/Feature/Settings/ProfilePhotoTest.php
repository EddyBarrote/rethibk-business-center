<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

// docs/DECISOES.md, "Foto de perfil".

beforeEach(function () {
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create();
    $this->other = Tenant::factory()->create();
    [$this->person, $this->colleague] = asTenant($this->tenant, fn () => [User::factory()->create(['name' => 'Ana Mondlane']), User::factory()->create()]);
    $this->stranger = asTenant($this->other, fn () => User::factory()->create());
});

it('saves the photo as a 512 px square without the camera metadata', function () {
    $this->actingAs($this->person)
        ->post(tenantUrl($this->tenant, 'settings/profile/avatar'), ['avatar' => UploadedFile::fake()->image('eu.png', 900, 600)])
        ->assertRedirect()->assertSessionHas('success');

    $path = $this->person->fresh()->avatar_path;
    expect($path)->toStartWith("avatars/{$this->tenant->id}/people/{$this->person->id}-")->toEndWith('.jpg');

    [$width, $height, $type] = getimagesizefromstring(Storage::disk('local')->get($path));
    expect([$width, $height, $type])->toBe([512, 512, IMAGETYPE_JPEG]);
});

it('shows the photo to colleagues, wherever their name appears, and not to other companies', function () {
    $this->actingAs($this->person)->post(tenantUrl($this->tenant, 'settings/profile/avatar'), ['avatar' => UploadedFile::fake()->image('eu.jpg', 300, 300)]);
    $url = $this->person->fresh()->avatarUrl();

    $this->actingAs($this->colleague)->get(tenantUrl($this->tenant, 'settings/profile'))
        ->assertInertia(fn (Assert $page) => $page->where('people_photos', ['Ana Mondlane' => $url])->where('auth.user.avatar_url', null));
    $this->actingAs($this->person)->get(tenantUrl($this->tenant, 'settings/profile'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.avatar_url', $url));

    $this->actingAs($this->colleague)->get(tenantUrl($this->tenant, "people/{$this->person->id}/avatar"))->assertOk();
    $this->actingAs($this->stranger)->get(tenantUrl($this->other, "people/{$this->person->id}/avatar"))->assertNotFound();
});

it('goes back to the initials and deletes the file', function () {
    $this->actingAs($this->person)->post(tenantUrl($this->tenant, 'settings/profile/avatar'), ['avatar' => UploadedFile::fake()->image('eu.jpg', 300, 300)]);
    $path = $this->person->fresh()->avatar_path;

    $this->actingAs($this->person)->delete(tenantUrl($this->tenant, 'settings/profile/avatar'))->assertRedirect();

    expect($this->person->fresh()->avatar_path)->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

it('refuses files that are not images', function () {
    $this->actingAs($this->person)
        ->post(tenantUrl($this->tenant, 'settings/profile/avatar'), ['avatar' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')])
        ->assertSessionHasErrors('avatar');

    expect($this->person->fresh()->avatar_path)->toBeNull();
});
