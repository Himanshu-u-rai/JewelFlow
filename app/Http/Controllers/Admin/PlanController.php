<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Platform\Plan;
use App\Services\PlatformAuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function index()
    {
        $plans = Plan::withCount([
            'subscriptions',
            'subscriptions as active_subscriptions_count' => fn ($query) => $query->whereIn('status', ['active', 'trial', 'grace']),
        ])->orderBy('name')->get();
        return view('super-admin.plans.index', compact('plans'));
    }

    public function create()
    {
        return view('super-admin.plans.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|unique:plans,code|max:255',
            'name' => 'required|string|max:255',
            'price_monthly' => 'required|numeric|min:0',
            'price_yearly' => 'nullable|numeric|min:0',
            'trial_days' => 'required|integer|min:0|max:365',
            'grace_days' => 'required|integer|min:0',
            'downgrade_to_read_only_on_due' => 'required|boolean',
            'is_active' => 'required|boolean',
            'features' => 'required|json',
        ]);

        $data['is_active'] = $this->dbBool($request->boolean('is_active'));
        $data['downgrade_to_read_only_on_due'] = $this->dbBool($request->boolean('downgrade_to_read_only_on_due'));

        $plan = Plan::create($data);

        $this->audit->log(
            auth('platform_admin')->user(),
            'admin.plan.created',
            Plan::class,
            $plan->id,
            null,
            $plan->toArray(),
            'New subscription plan created',
            $request
        );

        return redirect()->route('admin.plans.index')->with('success', 'Plan created successfully.');
    }

    public function edit(Plan $plan)
    {
        return view('super-admin.plans.edit', compact('plan'));
    }

    public function update(Request $request, Plan $plan)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'price_monthly' => 'required|numeric|min:0',
            'price_yearly' => 'nullable|numeric|min:0',
            'trial_days' => 'required|integer|min:0|max:365',
            'grace_days' => 'required|integer|min:0',
            'downgrade_to_read_only_on_due' => 'required|boolean',
            'is_active' => 'required|boolean',
            'features' => 'required|json',
        ]);

        $data['is_active'] = $this->dbBool($request->boolean('is_active'));
        $data['downgrade_to_read_only_on_due'] = $this->dbBool($request->boolean('downgrade_to_read_only_on_due'));

        $requestedActive = (bool) $request->boolean('is_active');

        // Keep behavior consistent with toggle(): cannot deactivate a plan
        // while active/trial/grace subscriptions are still attached.
        if (!$requestedActive && $plan->is_active) {
            $hasLiveSubscribers = $plan->subscriptions()
                ->whereIn('status', ['active', 'trial', 'grace'])
                ->exists();

            if ($hasLiveSubscribers) {
                return back()->with('error', 'Cannot deactivate plan. There are active subscriptions using it.');
            }
        }

        $before = $plan->toArray();
        $plan->update($data);
        $after = $plan->fresh()->toArray();

        $this->audit->log(
            auth('platform_admin')->user(),
            'admin.plan.updated',
            Plan::class,
            $plan->id,
            $before,
            $after,
            'Subscription plan updated',
            $request
        );

        return redirect()->route('admin.plans.index')->with('success', 'Plan updated successfully.');
    }

    public function toggle(Request $request, Plan $plan)
    {
        $newState = !$plan->is_active;

        if ($newState === false) { // Deactivating
            if ($plan->subscriptions()->whereIn('status', ['active', 'trial', 'grace'])->exists()) {
                return back()->with('error', 'Cannot deactivate plan. There are active subscriptions using it.');
            }
        }

        $before = $plan->only('is_active');
        $plan->update(['is_active' => $this->dbBool($newState)]);
        $after = $plan->fresh()->only('is_active');

        $this->audit->log(
            auth('platform_admin')->user(),
            'admin.plan.toggled',
            Plan::class,
            $plan->id,
            $before,
            $after,
            'Plan status toggled to ' . ($newState ? 'Active' : 'Inactive'),
            $request
        );

        return redirect()->route('admin.plans.index')->with('success', 'Plan status updated.');
    }

    private function dbBool(bool $value)
    {
        return $value;
    }
}
