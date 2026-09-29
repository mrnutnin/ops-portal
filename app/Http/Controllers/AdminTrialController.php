<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use App\Services\TrialIssuanceService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdminTrialController extends Controller
{
    public function store(Request $request, Instance $instance, TrialIssuanceService $trials): RedirectResponse
    {
        $data = $request->validate([
            'product_plan_id' => ['required', 'integer', 'exists:product_plans,id'],
            'exception_approved' => ['nullable', 'boolean'],
            'production_addon' => ['nullable', 'boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $trials->issue(
            $instance, (int) $data['product_plan_id'], $request->user(), $data['reason'],
            (bool) ($data['exception_approved'] ?? false), (bool) ($data['production_addon'] ?? false),
            $request->ip(), $request->userAgent(),
        );

        return back()->with('status', 'ออก Trial 30 วันให้ Instance แล้ว; ยังไม่ส่งสิทธิ์ไปยัง ERP');
    }

    public function enableProduction(Request $request, Instance $instance, TrialIssuanceService $trials): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $trials->enableProduction($instance, $request->user(), $data['reason'], $request->ip(), $request->userAgent());

        return back()->with('status', 'เปิด Production Trial แล้ว วันหมดอายุเดิมไม่เปลี่ยน; ยังไม่ส่งสิทธิ์ไปยัง ERP');
    }

    public function convertToPaid(Request $request, Instance $instance, TrialIssuanceService $trials): RedirectResponse
    {
        $data = $request->validate([
            'product_plan_id' => ['required', 'integer', 'exists:product_plans,id'],
            'paid_expires_at' => ['required', 'date_format:Y-m-d\\TH:i'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $trials->convertToPaid(
            $instance, (int) $data['product_plan_id'], Carbon::createFromFormat('!Y-m-d\\TH:i', $data['paid_expires_at'], 'Asia/Bangkok')->utc(),
            $request->user(), $data['reason'], $request->ip(), $request->userAgent(),
        );

        return back()->with('status', 'แปลง Trial เป็นแพ็กเกจชำระเงินแล้ว; ยังไม่ส่งสิทธิ์ไปยัง ERP');
    }
}
