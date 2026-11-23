@extends('layouts.app')
@section('title', 'Notifications')
@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">ACCOUNT</span><h1>Notifications</h1><p>Leave workflow updates and actions requiring your attention.</p></div>
        @if($notifications->total())
            <form method="POST" action="{{ route('notifications.read-all') }}">@csrf<button type="submit" class="button button-secondary">Mark all as read</button></form>
        @endif
    </div>
    <section class="panel notification-center">
        @forelse($notifications as $notification)
            @php
                $status = (string) ($notification->data['status'] ?? '');
                $message = (string) ($notification->data['message'] ?? 'You have an HRIS update.');
                $category = match (true) {
                    str_contains(strtolower($message), 'approved') || $status === 'Approved' => 'Leave application approved',
                    str_contains(strtolower($message), 'disapproved') || $status === 'Disapproved' => 'Leave application disapproved',
                    str_contains(strtolower($message), 'returned') || $status === 'Returned for Correction' => 'Leave application returned',
                    str_contains(strtolower($message), 'endorsed') || str_contains(strtolower($message), 'hrmo') => 'HRMO verification update',
                    str_contains(strtolower($status), 'alternate office head') || str_contains(strtolower($message), 'reassigned') => 'Alternate Office Head review',
                    in_array($status, ['Awaiting Alternate HR Reviewer Assignment', 'Awaiting Authorized Final Approver Assignment'], true) => 'Reviewer assignment required',
                    str_contains(strtolower($status), 'attendance') => 'Attendance update',
                    isset($notification->data['availability_id']) => 'Office Head availability',
                    isset($notification->data['application_id']) => 'Leave application submitted',
                    default => 'System update',
                };
            @endphp
            <article class="notification-row {{ $notification->read_at ? 'notification-read' : '' }}">
                <span class="notification-dot"></span>
                <div class="notification-copy">
                    <span class="notification-category">{{ $category }}</span>
                    <b>{{ $message }}</b>
                    <small>{{ $status !== '' ? 'Status: '.$status : 'HRIS system update' }} · {{ $notification->created_at->format('F j, Y · g:i A') }}</small>
                </div>
                <div class="notification-row-actions">
                    @unless($notification->read_at)
                        <form method="POST" action="{{ route('notifications.read', $notification->id) }}">@csrf<button type="submit" class="table-link">Mark as read</button></form>
                    @endunless
                    @if(isset($notification->data['application_id']))
                        @if($notification->read_at)
                            <a href="{{ route('leave.show', $notification->data['application_id']) }}" class="table-link">Open application →</a>
                        @else
                            <form method="POST" action="{{ route('notifications.read', $notification->id) }}">@csrf<button type="submit" class="table-link">Open application →</button></form>
                        @endif
                    @elseif(isset($notification->data['availability_url']))
                        @if($notification->read_at)
                            <a href="{{ $notification->data['availability_url'] }}" class="table-link">Open availability records →</a>
                        @else
                            <form method="POST" action="{{ route('notifications.read', $notification->id) }}">@csrf<button type="submit" class="table-link">Open availability records →</button></form>
                        @endif
                    @endif
                </div>
            </article>
        @empty<p class="empty-note">You have no notifications.</p>@endforelse
        <div class="pagination-wrap">{{ $notifications->links() }}</div>
    </section>
@endsection
