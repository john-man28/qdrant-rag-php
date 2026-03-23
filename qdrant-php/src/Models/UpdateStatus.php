<?php

declare(strict_types=1);

namespace Qdrant\Models;

enum UpdateStatus: string
{
    case ACKNOWLEDGED = 'acknowledged';
    case COMPLETED = 'completed';
    case WAIT_TIMEOUT = 'wait_timeout';
}
