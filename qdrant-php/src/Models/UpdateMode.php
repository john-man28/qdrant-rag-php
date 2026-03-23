<?php

declare(strict_types=1);

namespace Qdrant\Models;

enum UpdateMode: string
{
    case UPSERT = 'upsert';
    case INSERT_ONLY = 'insert_only';
    case UPDATE_ONLY = 'update_only';
}
