<?php

declare(strict_types=1);

namespace AIArmada\Growth\EntryPoints\Http;

use AIArmada\Growth\Models\Experiment;
use Illuminate\Http\Request;

interface RequestExperimentSubjectResolver
{
    public function resolve(Request $request, Experiment $experiment): RequestExperimentSubjects;
}
