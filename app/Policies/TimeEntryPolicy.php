<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\TimeEntry;
use App\Models\User;

class TimeEntryPolicy
{
    /**
     * Wpis da się zmienić tylko w otwartej części tygodnia: administrator każdy, pracownik własny.
     */
    public function update(User $user, TimeEntry $entry): bool
    {
        if ($entry->workWeek->isClosed()) {
            return false;
        }

        if ($user->hasPermission(Permission::ViewAllTimeEntries)) {
            return true;
        }

        return $user->hasPermission(Permission::LogOwnTime) && $entry->user_id === $user->id;
    }

    public function delete(User $user, TimeEntry $entry): bool
    {
        return $this->update($user, $entry);
    }
}
