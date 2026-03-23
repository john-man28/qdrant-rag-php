<?php

declare(strict_types=1);

namespace Qdrant\Models;

enum WriteOrdering: string
{
    case WEAK = 'weak';
    case MEDIUM = 'medium';
    case STRONG = 'strong';
}
