@extends('layouts.app')
@section('title', 'Notifications')
@section('content')
    <div class="page-heading"><div><span class="eyebrow">ACCOUNT</span><h1>Notifications</h1><p>Leave workflow updates and actions requiring your attention.</p></div></div>
    <section class="panel notification-center">
        @forelse($notifications as $notification)
            <form method="POST" action="{{ route('notifications.read', $notification->id) }}" class="notification-row {{ $notification->read_at ? 'notification-read' : '' }}">@csrf
                <span class="notification-dot"></span><span class="notification-copy"><b>{{ $notification->data['message'] ?? 'You have an update.' }}</b><small>{{ $notification->created_at->format('F j, Y · g:i A') }}</small></span><button type="submit" class="table-link">{{ $notification->read_at ? 'Open' : 'Mark read · Open' }} →</button>
            </form>
        @empty<p class="empty-note">You have no notifications.</p>@endforelse
        <div class="pagination-wrap">{{ $notifications->links() }}</div>
    </section>
@endsection
