<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ActivityChannel;
use App\Enums\RoleScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\Access\TwoFactor;
use App\Services\ActivityLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Users. System admins manage everyone; airline admins manage their airline's staff and the users of
 * contracted agencies; agency admins manage their own agency's users (legacy sub-agents).
 */
class UserController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly TwoFactor $twoFactor,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $users = $this->visible($request->user())->with(['role', 'airline', 'agency', 'airport'])
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($x) => $x
                ->where('name', 'like', '%'.$request->string('search').'%')
                ->orWhere('email', 'like', '%'.$request->string('search').'%')))
            ->when($request->filled('role_id'), fn ($q) => $q->where('role_id', $request->integer('role_id')))
            ->when($request->filled('agency_id'), fn ($q) => $q->where('agency_id', $request->integer('agency_id')))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50));

        return UserResource::collection($users);
    }

    public function store(UserRequest $request): JsonResponse
    {
        $data = $this->scopeData($request->user(), $request->validated());
        $user = User::create($data);

        return $this->created('User created.', new UserResource($user->load(['role', 'airline', 'agency', 'airport'])));
    }

    public function show(Request $request, User $user): UserResource
    {
        $this->ensureVisible($request->user(), $user);

        return new UserResource($user->load(['role', 'airline', 'agency', 'airport']));
    }

    public function update(UserRequest $request, User $user): JsonResponse
    {
        $this->ensureVisible($request->user(), $user);
        $data = $this->scopeData($request->user(), $request->validated() + $user->only(['role_id', 'airline_id', 'agency_id', 'airport_id']));
        $user->update($data);

        if (($data['is_active'] ?? true) === false) {
            $user->tokens()->delete();
        } elseif ($user->wasChanged('password')) {
            // a password set by an administrator signs the user out everywhere
            $user->revokeSessions();
            $this->logger->log(ActivityChannel::Auth, 'auth.password_reset', 'Password set by '.$request->user()->email, $user);
        }

        return $this->success('User updated.', new UserResource($user->load(['role', 'airline', 'agency', 'airport'])));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->ensureVisible($request->user(), $user);
        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['user' => 'You cannot delete yourself.']);
        }

        $user->tokens()->delete();
        $user->delete();

        return $this->deleted('User deleted.');
    }

    /** Machine token for the agency API (`/api/v1/partner`). Shown once. */
    public function issueApiToken(Request $request, User $user): JsonResponse
    {
        $this->ensureVisible($request->user(), $user);
        $user->loadMissing('agency');

        if (! $user->agency?->api_enabled) {
            throw ValidationException::withMessages(['user' => 'API access is not enabled for this user\'s agency.']);
        }

        $user->tokens()->where('name', User::PARTNER_TOKEN_NAME)->delete();
        $token = $user->createToken(User::PARTNER_TOKEN_NAME, [User::PARTNER_TOKEN_ABILITY])->plainTextToken;

        return $this->created('API token issued. Store it now, it will not be shown again.', ['token' => $token]);
    }

    public function revokeApiToken(Request $request, User $user): JsonResponse
    {
        $this->ensureVisible($request->user(), $user);
        $user->tokens()->where('name', User::PARTNER_TOKEN_NAME)->delete();

        return $this->deleted('API token revoked.');
    }

    /** Lost phone: a system administrator turns Google Authenticator off; the user can set it up again */
    public function resetTwoFactor(Request $request, User $user): JsonResponse
    {
        if (! $request->user()->isSystemAdmin()) {
            throw new AuthorizationException('Only a system administrator can reset Google Authenticator.');
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_timestep' => null])->save();
        $this->twoFactor->clearLock($user);
        // whoever has the lost phone may also hold a session
        $user->revokeSessions();
        $this->logger->log(ActivityChannel::Auth, 'auth.two_factor_reset', 'Google Authenticator reset by '.$request->user()->email, $user);

        return $this->success('Google Authenticator reset.', new UserResource($user->load(['role', 'airline', 'agency', 'airport'])));
    }

    /** Roles the actor may give in the user form (no administrator roles for airline / agency admins). */
    public function roleOptions(Request $request): JsonResponse
    {
        $actor = $request->user();
        $roles = Role::query()->orderBy('name')
            ->when(! $actor->isSystemAdmin(), fn ($q) => $q->where('scope', '!=', RoleScope::Admin->value))
            ->when(! $actor->isSystemAdmin() && ! $actor->airline_id, fn ($q) => $q->where('scope', RoleScope::Agency->value))
            ->get(['id', 'name', 'slug', 'scope']);

        return response()->json(['data' => $roles->map(fn (Role $r) => [
            'id' => $r->id, 'name' => $r->name, 'slug' => $r->slug, 'scope' => $r->scope->value,
        ])]);
    }

    private function visible(User $actor): Builder
    {
        return User::query()->when(! $actor->isSystemAdmin(), function (Builder $q) use ($actor) {
            if ($actor->airline_id) {
                $agencyIds = $actor->airline?->agencies()->pluck('agencies.id')->all() ?? [];
                $q->where(fn ($x) => $x->where('airline_id', $actor->airline_id)->orWhereIn('agency_id', $agencyIds));
            } else {
                $q->where('agency_id', $actor->agency_id ?? 0);
            }
        });
    }

    private function ensureVisible(User $actor, User $user): void
    {
        if (! $this->visible($actor)->whereKey($user->id)->exists()) {
            throw new AuthorizationException('You do not have access to this user.');
        }
    }

    /**
     * Force the organisation fields to what the actor may assign.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function scopeData(User $actor, array $data): array
    {
        $role = Role::query()->findOrFail($data['role_id']);

        if (! $actor->isSystemAdmin()) {
            if ($role->scope === RoleScope::Admin) {
                throw new AuthorizationException('You cannot assign this role.');
            }

            if ($actor->airline_id) {
                if ($role->scope === RoleScope::Agency) {
                    $contracted = $actor->airline->agencies()->whereKey($data['agency_id'] ?? 0)->exists();
                    if (! $contracted) {
                        throw ValidationException::withMessages(['agency_id' => 'Choose an agency contracted with your airline.']);
                    }
                    $data['airline_id'] = null;
                } else {
                    $data['airline_id'] = $actor->airline_id;
                    $data['agency_id'] = null;
                }
            } else {
                if ($role->scope !== RoleScope::Agency) {
                    throw new AuthorizationException('You can only create agency users.');
                }
                $data['agency_id'] = $actor->agency_id;
                $data['airline_id'] = null;
            }
        }

        if ($role->scope === RoleScope::Agency && empty($data['agency_id'])) {
            throw ValidationException::withMessages(['agency_id' => 'Agency users need an agency.']);
        }
        if (in_array($role->scope, [RoleScope::Airline, RoleScope::Airport], true) && empty($data['airline_id']) && $role->scope === RoleScope::Airline) {
            throw ValidationException::withMessages(['airline_id' => 'Airline users need an airline.']);
        }
        if ($role->scope === RoleScope::Airport && empty($data['airport_id'])) {
            throw ValidationException::withMessages(['airport_id' => 'Airport users need a station.']);
        }

        return $data;
    }
}
