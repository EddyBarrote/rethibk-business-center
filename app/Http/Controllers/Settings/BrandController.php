<?php

namespace App\Http\Controllers\Settings;

use App\Documents\Brand;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The look of generated documents: colour, logo and footer line
 * (tenants.settings.brand). Owners and admins only.
 */
class BrandController extends Controller
{
    public function show(Request $request): Response
    {
        abort_unless($this->user($request)->canManageTenant(), 403);
        $brand = Brand::current();

        return Inertia::render('Settings/Brand', [
            'brand' => [
                'name' => $brand->name,
                'color' => $brand->color,
                'footer' => $brand->footer,
                'has_logo' => $brand->logoPath !== null,
            ],
            'default_color' => Brand::DEFAULT_COLOR,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->canManageTenant(), 403);
        $data = $request->validate([
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'footer' => ['nullable', 'string', 'max:160'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
            'remove_logo' => ['boolean'],
        ]);

        $tenant = Tenant::current();
        abort_if($tenant === null, 404);
        $settings = $tenant->settings ?? [];
        $brand = (array) ($settings['brand'] ?? []);
        $old = $brand['logo_path'] ?? null;

        if ($request->hasFile('logo')) {
            $brand['logo_path'] = $request->file('logo')->storeAs('brand', $tenant->id.'-'.Str::random(8).'.'.$request->file('logo')->extension(), 'local');
        } elseif ($data['remove_logo'] ?? false) {
            $brand['logo_path'] = null;
        }

        if (is_string($old) && $old !== ($brand['logo_path'] ?? null)) {
            Storage::disk('local')->delete($old);
        }

        $brand['color'] = strtoupper($data['color']);
        $brand['footer'] = $data['footer'] ?? null;
        $settings['brand'] = $brand;
        $tenant->update(['settings' => $settings]);
        AuditLog::record($user, 'settings.brand_updated', ['color' => $brand['color'], 'logo' => $brand['logo_path'] !== null]);

        return back()->with('success', 'Marca guardada.');
    }

    public function logo(Request $request): StreamedResponse
    {
        $this->user($request);
        $path = data_get(Tenant::current()?->settings, 'brand.logo_path');
        abort_unless(is_string($path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }
}
