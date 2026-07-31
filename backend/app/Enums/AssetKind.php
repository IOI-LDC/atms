<?php

namespace App\Enums;

enum AssetKind: string
{
    case ASSET = 'asset';
    case PACKAGE = 'package';
    case COMPONENT = 'component';
}
