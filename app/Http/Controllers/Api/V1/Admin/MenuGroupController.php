<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\MenuGroup;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Sidebar group headings (General, Flight, DCS ...) and their order. System administrators only. */
class MenuGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureSystemAdmin($request);

        return response()->json(['data' => MenuGroup::query()->withCount('menus')->orderBy('order')->orderBy('id')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureSystemAdmin($request);
        $group = MenuGroup::create($this->validated($request) + ['order' => (int) MenuGroup::query()->max('order') + 1]);

        return $this->created('Menu group created.', $group);
    }

    public function update(Request $request, MenuGroup $menuGroup): JsonResponse
    {
        $this->ensureSystemAdmin($request);
        $menuGroup->update($this->validated($request, true));

        return $this->success('Menu group updated.', $menuGroup);
    }

    public function destroy(Request $request, MenuGroup $menuGroup): JsonResponse
    {
        $this->ensureSystemAdmin($request);
        if ($menuGroup->menus()->exists()) {
            throw ValidationException::withMessages(['menu_group' => 'Move its menus to another group first.']);
        }
        $menuGroup->delete();

        return $this->deleted('Menu group deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $update = false): array
    {
        return $request->validate([
            'name' => [$update ? 'sometimes' : 'required', 'string', 'max:100'],
            'order' => ['sometimes', 'integer', 'min:0'],
        ]);
    }

    private function ensureSystemAdmin(Request $request): void
    {
        if (! $request->user()->isSystemAdmin()) {
            throw new AuthorizationException('Only a system administrator can edit menus.');
        }
    }
}
