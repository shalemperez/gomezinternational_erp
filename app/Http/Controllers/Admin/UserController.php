<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\SaveUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use App\Support\AuditHistory;
use App\Support\PermissionCatalog;
use App\Support\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    #[Middleware('permission:admin.users.view')]
    public function index(Request $request): Response
    {
        $query = User::query()->with('roles');
        if ($role = $request->query('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $role));
        }
        if ($request->query('status') === 'active') {
            $query->where('is_active', true);
        } elseif ($request->query('status') === 'inactive') {
            $query->where('is_active', false);
        }

        $rows = TableQuery::apply($query, $request, ['name', 'email'], ['name', 'email', 'last_login_at', 'is_active'], 'name', 'asc')
            ->through(fn (User $u) => [...$u->only(['id', 'name', 'email', 'avatar_url', 'is_active', 'last_login_at']), 'roles' => $u->getRoleNames()->all()]);

        return Inertia::render('admin/users/index', [
            'rows' => $rows,
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page', 'role', 'status']),
            'roleOptions' => $this->roleOptions(false),
            'canImpersonate' => $request->user()->can('admin.users.impersonate'),
            'authId' => $request->user()->id,
        ]);
    }

    #[Middleware('permission:admin.users.create')]
    public function create(Request $request): Response
    {
        return Inertia::render('admin/users/form', [
            'roleOptions' => $this->roleOptions(! $request->user()->isSuperAdmin()),
            'authId' => $request->user()->id,
        ]);
    }

    #[Middleware('permission:admin.users.create')]
    public function store(StoreUserRequest $request, SaveUser $action): RedirectResponse
    {
        /** @var array{name: string, email: string, roles: list<string>, is_active?: bool} $data */
        $data = $request->validated();
        $action->handle($data, $request->user());

        return redirect()->route('admin.users.index')->with('success', 'Usuario invitado');
    }

    #[Middleware('permission:admin.users.update')]
    public function edit(Request $request, User $user): Response
    {
        return Inertia::render('admin/users/form', [
            'user' => [...$user->only(['id', 'name', 'email', 'is_active']), 'roles' => $user->getRoleNames()->all()],
            'history' => AuditHistory::for($user),
            'roleOptions' => $this->roleOptions(! $request->user()->isSuperAdmin()),
            'authId' => $request->user()->id,
        ]);
    }

    #[Middleware('permission:admin.users.update')]
    public function update(UpdateUserRequest $request, User $user, SaveUser $action): RedirectResponse
    {
        /** @var array{name: string, email: string, roles: list<string>, is_active?: bool} $data */
        $data = $request->validated();
        $action->handle($data, $request->user(), $user);

        return redirect()->route('admin.users.index')->with('success', 'Usuario actualizado');
    }

    /** @return array<int, array{name: string, label: string}> */
    private function roleOptions(bool $hideSuperAdmin): array
    {
        return collect(PermissionCatalog::ROLES)
            ->reject(fn (string $label, string $name) => $hideSuperAdmin && $name === PermissionCatalog::SUPER_ADMIN)
            ->map(fn (string $label, string $name) => ['name' => $name, 'label' => $label])
            ->values()->all();
    }
}
