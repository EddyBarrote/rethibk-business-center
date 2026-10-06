<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\DepartmentRequest;
use App\Models\Department;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Department::class);

        $departments = Department::query()
            ->with('parent:id,name')
            ->withCount('users')
            ->orderBy('name')
            ->get()
            ->map(fn (Department $department) => [
                'id' => $department->id,
                'name' => $department->name,
                'slug' => $department->slug,
                'parent_id' => $department->parent_id,
                'parent' => $department->parent?->name,
                'users_count' => $department->users_count,
            ]);

        return Inertia::render('Settings/Departments/Index', [
            'departments' => $departments,
            'can' => ['manage' => request()->user()?->canManageTenant() ?? false],
        ]);
    }

    public function store(DepartmentRequest $request): RedirectResponse
    {
        Gate::authorize('create', Department::class);

        Department::query()->create($request->validated());

        return back()->with('success', __('Departamento criado.'));
    }

    public function update(DepartmentRequest $request, Department $department): RedirectResponse
    {
        Gate::authorize('update', $department);

        $department->update($request->validated());

        return back()->with('success', __('Departamento actualizado.'));
    }

    public function destroy(Department $department): RedirectResponse
    {
        Gate::authorize('delete', $department);

        $department->delete();

        return back()->with('success', __('Departamento removido.'));
    }
}
