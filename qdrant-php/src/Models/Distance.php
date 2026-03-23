<?php

declare(strict_types=1);

namespace Qdrant\Models;

enum Distance: string
{
    case COSINE = 'Cosine';
    case DOT = 'Dot';
    case EUCLID = 'Euclid';
    case MANHATTAN = 'Manhattan';
}
