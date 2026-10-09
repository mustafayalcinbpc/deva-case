<?php

namespace App\Enums;

enum SliceEndReason: string
{
    case Paused = 'paused';
    case WorkersChanged = 'workers_changed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
