<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use App\Models\InstanceRenewal;
use App\Services\InstanceRenewalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminRenewalController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'group' => ['nullable', 'in:attention,all,due,grace,expired,PENDING,CONFIRMED,APPLIED,trial'],
            'customer' => ['nullable', 'string', 'max:100'], 'product' => ['nullable', 'string', 'max:50'],
        ]);
        $group = $filters['group'] ?? 'attention';
        $query = Instance::with(['customer', 'product', 'entitlement.productPlan', 'openRenewal', 'latestRenewal', 'syncCredential', 'lastDelivery'])
            ->whereHas('entitlement', fn ($q) => $q->where('commercial_mode', 'SUBSCRIPTION'));
        if ($group !== 'all') {
            $query->where('is_active', true)->whereHas('customer', fn ($q) => $q->where('is_active', true))
                ->whereHas('product', fn ($q) => $q->where('is_active', true));
        }
        $query->when($filters['customer'] ?? null, fn ($q, $name) => $q->whereHas('customer', fn ($c) => $c->where('name', 'like', '%'.$name.'%')))
            ->when($filters['product'] ?? null, fn ($q, $code) => $q->whereHas('product', fn ($p) => $p->where('code', $code)));
        if (in_array($group, ['PENDING', 'CONFIRMED'], true)) {
            $query->whereHas('openRenewal', fn ($q) => $q->where('status', $group));
        } elseif ($group === 'APPLIED') {
            $query->whereHas('latestRenewal', fn ($q) => $q->where('status', 'APPLIED'));
        } elseif ($group === 'trial') {
            $query->whereHas('entitlement', fn ($q) => $q->where('status', 'TRIAL'));
        } elseif (in_array($group, ['due', 'grace', 'expired'], true)) {
            $query->whereHas('entitlement', function ($q) use ($group): void {
                $q->where('status', '!=', 'TRIAL');
                match ($group) {
                    'due' => $q->whereRaw('COALESCE(paid_period_end, expires_at) > ?', [now()])->whereRaw('COALESCE(paid_period_end, expires_at) <= ?', [now()->addDays(15)]),
                    'grace' => $q->where('paid_period_end', '<=', now())->where('expires_at', '>', now()),
                    'expired' => $q->where('expires_at', '<=', now()),
                };
            });
        } elseif ($group === 'attention') {
            // Include every applied record awaiting delivery; it may have a later expiry.
            $query->where(fn ($q) => $q->whereHas('openRenewal')->orWhere(fn ($pending) => $pending->whereHas('renewals', fn ($r) => $r->where('status', 'APPLIED'))->awaitingDelivery())
                ->orWhereHas('entitlement', fn ($e) => $e->whereRaw('COALESCE(paid_period_end, expires_at) <= ?', [now()->addDays(15)])));
        }
        return view('admin.renewals.index', ['instances' => $query->orderBy(
            \App\Models\InstanceEntitlement::selectRaw('COALESCE(paid_period_end, expires_at)')->whereColumn('instance_id', 'instances.id')->limit(1)
        )->orderBy('id')->paginate(20)->withQueryString(), 'group' => $group]);
    }

    public function create(Instance $instance): View
    {
        $instance->load(['customer', 'product', 'entitlement.productPlan', 'openRenewal']);
        if ($instance->openRenewal) {
            return $this->show($instance->openRenewal);
        }
        return view('admin.renewals.form', ['instance' => $instance, 'renewal' => null]);
    }

    public function store(Request $request, Instance $instance, InstanceRenewalService $service): RedirectResponse
    {
        $renewal = $service->save($instance, $request->all(), $request->user());
        return redirect()->route('admin.renewals.show', $renewal)->with('status', 'เปิดรายการแล้ว ยังไม่ได้ยืนยันรับเงินหรือเปลี่ยนสิทธิ์');
    }

    public function show(InstanceRenewal $renewal): View
    {
        $renewal->load(['instance.customer', 'instance.product', 'instance.entitlement.productPlan', 'instance.syncCredential', 'instance.lastDelivery', 'creator', 'confirmer', 'applier', 'voider']);
        return view('admin.renewals.show', ['renewal' => $renewal, 'instance' => $renewal->instance]);
    }

    public function update(Request $request, InstanceRenewal $renewal, InstanceRenewalService $service): RedirectResponse
    {
        $service->save($renewal->instance, $request->all(), $request->user(), $renewal);
        return back()->with('status', 'บันทึกรายการแล้ว ยังไม่ได้ยืนยันรับเงิน');
    }

    public function confirm(Request $request, InstanceRenewal $renewal, InstanceRenewalService $service): RedirectResponse
    {
        $request->validate(['evidence' => ['required', 'file']]);
        $service->confirm($renewal, $request->except('evidence'), $request->file('evidence'), $request->user());
        return back()->with('status', 'ยืนยันรับชำระแล้ว ยังไม่ได้ต่ออายุหรือส่งสิทธิ์');
    }

    public function apply(Request $request, InstanceRenewal $renewal, InstanceRenewalService $service): RedirectResponse
    {
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:10', 'max:500']]);
        $service->apply($renewal, $request->user(), (int) $data['expected_revision'], $data['reason']);
        return back()->with('status', 'นำไปใช้ใน Ops แล้ว ยังไม่ได้ส่งสิทธิ์ กรุณาตรวจและส่งผ่านหน้า Instance');
    }

    public function void(Request $request, InstanceRenewal $renewal, InstanceRenewalService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $service->void($renewal, $request->user(), $data['reason']);
        return back()->with('status', 'ยกเลิกรายการแล้ว ไม่เปลี่ยนสิทธิ์และไม่ลบหลักฐาน');
    }

    public function evidence(InstanceRenewal $renewal): StreamedResponse
    {
        abort_unless($renewal->evidence_path && Storage::disk('local')->exists($renewal->evidence_path), 404);
        return Storage::disk('local')->download($renewal->evidence_path, 'renewal-'.$renewal->id.'.'.match ($renewal->evidence_mime) {
            'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', default => 'bin',
        }, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
