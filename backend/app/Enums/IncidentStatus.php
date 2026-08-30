<?php

namespace App\Enums;

enum IncidentStatus: string
{
    case Detected = 'detected';
    case AwaitingApproval = 'awaiting_approval';
    case Dispatched = 'dispatched';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';
}
