@php
    $nameParts = preg_split('/\s+/', trim($user->name)) ?: [];
    $initials = collect($nameParts)->filter()->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('');
    $photoPath = $user->profile_photo_path;
    $photoDisk = \Illuminate\Support\Facades\Storage::disk('public');
    $photoUrl = $photoPath && \Illuminate\Support\Str::startsWith($photoPath, 'profile-photos/')
        && $photoDisk->exists($photoPath)
        ? url('storage/'.$photoPath)
        : null;
@endphp
<span class="avatar profile-image-avatar {{ $size ?? '' }}" aria-label="{{ $user->name }}">
    <span class="avatar-initials" aria-hidden="true">{{ $initials ?: 'U' }}</span>
    @if($photoUrl)
        <img src="{{ $photoUrl }}" alt="{{ $user->name }} profile photo" onerror="this.remove()">
    @endif
</span>
