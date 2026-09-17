<?php

namespace App\Enums;

enum DealType: string
{
    case Entry = 'ENTRY';
    case Exit = 'EXIT';
    case PartialExit = 'PARTIAL_EXIT';
}
