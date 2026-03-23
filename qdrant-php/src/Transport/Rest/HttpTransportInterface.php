<?php

declare(strict_types=1);

namespace Qdrant\Transport\Rest;

interface HttpTransportInterface
{
    public function send(HttpRequest $request): HttpResponse;
}
