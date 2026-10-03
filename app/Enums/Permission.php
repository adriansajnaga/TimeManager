<?php

namespace App\Enums;

/**
 * Uprawnienia sprawdzane przez Gate (nazwa przypadku = nazwa Gate).
 */
enum Permission: string
{
    case ManageSettings = 'manage-settings';
    case ManageUsers = 'manage-users';
    case ManageContractors = 'manage-contractors';
    case ManageProjects = 'manage-projects';
    case CloseWeeks = 'close-weeks';
    case ManageSettlements = 'manage-settlements';
    case ManageInvoices = 'manage-invoices';
    case ViewAllTimeEntries = 'view-all-time-entries';
    case LogOwnTime = 'log-own-time';
    case UseMailbox = 'use-mailbox';
}
