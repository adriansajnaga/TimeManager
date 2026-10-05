<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Models\WeeklyReport;

class WeeklyReportPolicy
{
    /**
     * Opis tygodnia i materiały: administrator albo pracownik przypisany do projektu,
     * dopóki część tygodnia nie jest zamknięta.
     */
    public function update(User $user, WeeklyReport $report): bool
    {
        if ($report->workWeek->isClosedFor($report->project->contractor_id)) {
            return false;
        }

        if ($user->hasPermission(Permission::ViewAllTimeEntries)) {
            return true;
        }

        return $user->hasPermission(Permission::LogOwnTime)
            && $report->project->users()->whereKey($user->id)->exists();
    }
}
