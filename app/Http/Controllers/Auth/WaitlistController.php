<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\AccessMode;
use App\Http\Controllers\Controller;
use App\Models\ApplicationWaitlistEntry;
use App\Models\TenantApplicationEnvironment;
use App\Support\ApplicationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Records interest in an application that is not accepting sign-ups yet.
 */
class WaitlistController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $environment = ApplicationContext::environment($request);
        abort_unless($environment instanceof TenantApplicationEnvironment, 404);
        abort_unless($environment->accessMode() === AccessMode::Waitlist, 404);

        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);

        // Joining is a statement of interest, not access: nothing is granted
        // until an operator sends an invitation.
        ApplicationWaitlistEntry::query()->firstOrCreate([
            'tenant_application_environment_id' => $environment->getKey(),
            'email' => mb_strtolower(trim($data['email'])),
        ]);

        return back()->with('waitlist_joined', true);
    }
}
