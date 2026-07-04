<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class OperatorController extends Controller
{
    public function index(Request $r)
    {
        return User::query()
            ->where('role', 'operator')
            ->when($r->filled('q'), function ($query) use ($r) {
                $term = trim((string) $r->string('q'));

                $query->where(function ($inner) use ($term) {
                    $inner
                        ->where('name', 'like', '%'.$term.'%')
                        ->orWhere('email', 'like', '%'.$term.'%');
                });
            })
            ->when($r->has('is_active') && $r->get('is_active') !== null && $r->get('is_active') !== '', function ($query) use ($r) {
                $query->where('is_active', filter_var($r->get('is_active'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE));
            })
            ->paginate($r->integer('per_page', 10));
    }

    public function store(Request $r)
    {
        $d = $r->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users',
            'password' => ['required', Password::min(8)->mixedCase()->numbers()],
        ]);

        return response()->json(['message' => 'Operator dibuat.', 'operator' => User::create([...$d, 'role' => 'operator', 'is_active' => true])], 201);
    }

    public function show(User $operator)
    {
        abort_unless($operator->role === 'operator', 404);

        return ['operator' => $operator];
    }

    public function update(Request $r, User $operator)
    {
        $operator->update($r->validate(['name' => 'sometimes|string', 'email' => 'sometimes|email|unique:users,email,'.$operator->id, 'is_active' => 'sometimes|boolean']));

        return ['message' => 'Operator diperbarui.', 'operator' => $operator];
    }

    public function status(Request $r, User $operator)
    {
        $operator->update($r->validate(['is_active' => 'required|boolean']));

        return ['message' => 'Status diperbarui.', 'operator' => $operator];
    }

    public function destroy(User $operator)
    {
        $operator->delete();

        return ['message' => 'Operator dihapus.'];
    }
}
