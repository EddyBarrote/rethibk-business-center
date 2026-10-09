<?php

namespace App\Enums;

enum QuestionStatus: string
{
    case Pending = 'pending';
    case Answered = 'answered';
    /** The run ended another way (cancelled, failed) before anyone answered. */
    case Cancelled = 'cancelled';
}
