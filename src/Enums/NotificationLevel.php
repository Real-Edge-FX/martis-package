<?php

namespace Martis\Enums;

/**
 * Severity level for in-app notifications surfaced in the Martis bell
 * dropdown. Maps to the `level` key stored in the database notification
 * payload and rendered by the React bell component.
 *
 * The TSX renderer accepts exactly these four values; any other string
 * silently falls back to the InfoIcon and produces an unknown CSS class.
 */
enum NotificationLevel: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';
}
