<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AdminAudit;
use App\Services\Storage\StorageOverview;
use App\Services\Storage\StorageReservations;
use App\Support\Bytes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Storage administration for non-technical owners: limits are chosen from presets or
 * human units. Paths, mounts and partitions stay in the operator's server configuration.
 */
class StorageController extends Controller
{
    public function __construct(private StorageReservations $reservations, private StorageOverview $overview) {}

    public function index(Request $request): View
    {
        Gate::authorize('view-storage');
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return view('storage.index', $this->overview->build($request->user(), trim($data['q'] ?? '')));
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manage-storage-quota', $user);
        $field = 'quota_'.$user->id;
        $bytes = $this->requestedBytes($request, $field);
        $this->guard($field, function () use ($request, $user, $bytes): void {
            DB::transaction(function () use ($request, $user, $bytes): void {
                // Re-check roles under lock, in a stable order, as UserManagementController does.
                $locked = User::whereIn('id', [$request->user()->id, $user->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $actor = $locked->get($request->user()->id);
                $target = $locked->get($user->id);
                abort_unless($actor instanceof User && $target instanceof User, 404);
                Gate::forUser($actor)->authorize('manage-storage-quota', $target);
                $previous = $this->reservations->setUserQuota($target, $bytes);
                if ($previous !== $bytes) {
                    app(AdminAudit::class)->recordStorage($actor, $target, 'storage.user_quota', null, $bytes, $previous);
                }
            });
        });

        return back()->with('status', 'Espacio de '.$user->name.': '.Bytes::format($bytes).' como máximo.');
    }

    public function updatePool(Request $request, string $pool): RedirectResponse
    {
        Gate::authorize('manage-storage-pools');
        $field = 'pool_'.$pool;
        $bytes = $this->requestedBytes($request, $field);
        $this->guard($field, function () use ($request, $pool, $bytes): void {
            $previous = $this->reservations->setPoolLimit($pool, $bytes);
            if ($previous !== $bytes) {
                app(AdminAudit::class)->recordStorage($request->user(), $request->user(), 'storage.pool_limit', $pool, $bytes, $previous);
            }
        });

        return back()->with('status', 'Límite del disco '.$pool.': '.Bytes::format($bytes).'.');
    }

    public function updateDefault(Request $request): RedirectResponse
    {
        Gate::authorize('manage-storage-pools');
        $bytes = $this->requestedBytes($request, 'default');
        $this->guard('default', function () use ($request, $bytes): void {
            $previous = $this->reservations->setDefaultUserQuota($bytes);
            if ($previous !== $bytes) {
                app(AdminAudit::class)->recordStorage($request->user(), $request->user(), 'storage.default_quota', null, $bytes, $previous);
            }
        });

        return back()->with('status', 'Espacio por defecto para personas nuevas: '.Bytes::format($bytes).'.');
    }

    private function requestedBytes(Request $request, string $field): int
    {
        $data = $request->validate([
            'preset' => ['required', 'string', 'regex:/^(custom|0|[1-9][0-9]{0,15})$/D'],
            'amount' => ['nullable', 'required_if:preset,custom', 'string', 'max:12'],
            'unit' => ['nullable', 'required_if:preset,custom', 'string', 'in:'.implode(',', array_keys(Bytes::UNITS))],
        ], [
            'preset.required' => 'Elige una cantidad.',
            'amount.required_if' => 'Escribe la cantidad.',
        ]);
        try {
            $bytes = $data['preset'] === 'custom'
                ? Bytes::fromInput((string) $data['amount'], (string) $data['unit'])
                : (int) $data['preset'];
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
        if ($bytes > StorageReservations::MAX_BYTES) {
            throw ValidationException::withMessages([$field => 'La cantidad supera el máximo admitido.']);
        }

        return $bytes;
    }

    /** Turns domain refusals into a message next to the form that caused them. */
    private function guard(string $field, callable $operation): void
    {
        try {
            $operation();
        } catch (RuntimeException|InvalidArgumentException $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }
}
