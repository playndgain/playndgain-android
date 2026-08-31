<?php

namespace App\Enums;

enum OutgoingPostbackStatus: string
{
    case Queued = 'queued';
    case Success = 'success';
    case Retrying = 'retrying';
    case Failed = 'failed';
}
