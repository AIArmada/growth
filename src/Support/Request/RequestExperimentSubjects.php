<?php

declare(strict_types=1);

namespace AIArmada\Growth\Support\Request;

use AIArmada\Signals\Features\Ingestion\Models\SignalIdentity;
use AIArmada\Signals\Features\Ingestion\Models\SignalSession;

final readonly class RequestExperimentSubjects
{
    public function __construct(
        public ?SignalIdentity $identity,
        public ?SignalSession $session,
        public ?string $anonymousId,
    ) {}
}
