<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SessionSecurityService
{
    public function invalidateOtherSessions(User $user, ?string $currentSessionId = null): void
    {
        $user->forceFill([
            'remember_token' => Str::random(60),
        ]);
        $user->saveQuietly();

        if (Schema::hasTable('sessions')) {
            $query = DB::table('sessions')->where('user_id', $user->getKey());

            if ($currentSessionId) {
                $query->where('id', '!=', $currentSessionId);
            }

            $query->delete();
        }
    }
}
