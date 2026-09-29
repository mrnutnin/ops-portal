@extends('layouts.ops')
@section('title', 'ประวัติการดำเนินการ · AlexiaSoft Ops')
@section('content')
<section class="card">
    <div class="actions spread">
        <div><h1>ประวัติการดำเนินการ</h1><p class="muted">แสดงรายการล่าสุดไม่เกิน 50 รายการต่อหน้า</p></div>
        @include('admin.partials.navigation')
    </div>
    <div class="table-wrap"><table>
        <thead><tr><th>เวลาไทย (UTC+7)</th><th>ผู้ดำเนินการ</th><th>เหตุการณ์</th><th>รายการ</th><th>รายละเอียด</th></tr></thead>
        <tbody>
        @forelse ($events as $event)
            <tr>
                <td>{{ $event->created_at->copy()->timezone('Asia/Bangkok')->format('d/m/Y H:i:s') }}</td>
                <td>{{ $event->actor?->name ?? 'ระบบ / CLI' }}</td>
                <td>{{ $event->action_label }}</td>
                <td>{{ class_basename($event->subject_type) }} #{{ $event->subject_id }}</td>
                <td><details><summary>ดูข้อมูล</summary><pre class="audit-values">{{ json_encode(['old' => $event->old_values, 'new' => $event->new_values, 'ip_address' => $event->ip_address, 'user_agent' => $event->user_agent], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></details></td>
            </tr>
        @empty
            <tr><td colspan="5">ยังไม่มีประวัติ</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div>{{ $events->links() }}</div>
</section>
@endsection
