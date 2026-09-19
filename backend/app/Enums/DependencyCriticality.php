<?php

namespace App\Enums;

enum DependencyCriticality: string
{
    case Critical = 'CRITICAL';
    case Optional = 'OPTIONAL';
}
