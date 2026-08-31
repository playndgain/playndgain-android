<?php

namespace App\Enums;

enum SyncStatus: string
{
    case Ok = 'ok';
    case Failed = 'failed';
}
