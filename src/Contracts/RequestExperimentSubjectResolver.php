<?php

declare(strict_types=1);

namespace AIArmada\Growth\Contracts;

use AIArmada\Growth\Models\Experiment;
use AIArmada\Growth\Support\RequestExperimentSubjects;
use Illuminate\Http\Request;

interface RequestExperimentSubjectResolver
{
    public function resolve(Request $request, Experiment $experiment): RequestExperimentSubjects;
}
