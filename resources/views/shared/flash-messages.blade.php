@php
    $flashNotices = collect([
        ['type' => 'success', 'title' => 'Success', 'message' => session('success')],
        ['type' => 'warning', 'title' => 'Review required', 'message' => session('warning')],
        ['type' => 'error', 'title' => 'Unable to complete the request', 'message' => session('error')],
        ['type' => 'info', 'title' => 'Information', 'message' => session('info')],
        ['type' => 'info', 'title' => 'Account update', 'message' => session('status')],
    ])->filter(fn (array $notice) => is_string($notice['message']) && trim($notice['message']) !== '')
        ->unique('message')
        ->values();

    if ($errors->any()) {
        $flashNotices->prepend([
            'type' => 'error',
            'title' => 'Please review the highlighted fields',
            'message' => null,
        ]);
    }
@endphp

@if($flashNotices->isNotEmpty())
    <div class="hris-flash-stack" data-hris-flash @if($errors->any()) id="validation-errors" @endif>
        @foreach($flashNotices as $notice)
            <section class="hris-notice hris-notice-{{ $notice['type'] }}" role="{{ $notice['type'] === 'error' ? 'alert' : 'status' }}">
                <div class="hris-notice-copy">
                    <strong>{{ $notice['title'] }}</strong>
                    @if($notice['message'])
                        <p>{{ $notice['message'] }}</p>
                    @endif
                    @if($notice['type'] === 'error' && $errors->any())
                        <ul>
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                <button type="button" class="hris-notice-dismiss" data-dismiss-notice aria-label="Dismiss notification">Dismiss</button>
            </section>
        @endforeach
    </div>
@endif
