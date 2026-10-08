<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PermissionKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return RoleResource::collection(Role::query()->with('permissions')->withCount('users')->orderBy('name')->get());
    }

    /** The permission catalogue, grouped for the role editor. */
    public function permissions(): JsonResponse
    {
        $groups = collect(PermissionKey::cases())
            ->groupBy(fn (PermissionKey $p) => $p->category())
            ->map(fn ($items) => $items->map(fn (PermissionKey $p) => ['key' => $p->value, 'name' => $p->label()])->values());

        return response()->json(['data' => $groups]);
    }

    public function store(RoleRequest $request): JsonResponse
    {
        $role = DB::transaction(function () use ($request) {
            $role = Role::create($request->safe()->except('permissions'));
            $this->syncPermissions($role, $request->validated('permissions', []));

            return $role;
        });

        return $this->created('Role created.', new RoleResource($role->load('permissions')));
    }

    public function show(Role $role): RoleResource
    {
        return new RoleResource($role->load('permissions')->loadCount('users'));
    }

    public function update(RoleRequest $request, Role $role): JsonResponse
    {
        DB::transaction(function () use ($request, $role) {
            $role->update($request->safe()->except('permissions'));
            if ($request->has('permissions')) {
                $this->syncPermissions($role, $request->validated('permissions', []));
            }
        });

        return $this->success('Role updated.', new RoleResource($role->load('permissions')));
    }

    public function destroy(Role $role): JsonResponse
    {
        if ($role->is_system || $role->users()->exists()) {
            throw ValidationException::withMessages(['role' => 'System roles and roles with users cannot be deleted.']);
        }

        $role->delete();

        return $this->deleted('Role deleted.');
    }

    /** @param array<string> $keys */
    private function syncPermissions(Role $role, array $keys): void
    {
        $role->permissions()->sync(Permission::query()->whereIn('key', $keys)->pluck('id'));
    }
}
