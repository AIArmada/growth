<?php

declare(strict_types=1);

namespace AIArmada\Growth\Support;

use AIArmada\Signals\Models\SignalIdentity;
use AIArmada\Signals\Models\SignalSession;

final readonly class RequestExperimentSubjects
{
    public function __construct(
        public ?SignalIdentity $identity,
        public ?SignalSession $session,
        public ?string $anonymousId,
    ) {}
}
