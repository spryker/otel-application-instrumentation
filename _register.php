<?php

declare(strict_types=1);

use Spryker\Shared\Opentelemetry\Instrumentation\CachedInstrumentation;
use Spryker\Shared\Opentelemetry\Request\RequestProcessor;
use Spryker\Service\OtelApplicationInstrumentation\OpenTelemetry\ApplicationInstrumentation;

if (extension_loaded('opentelemetry') === false) {
    return;
}

ApplicationInstrumentation::register(new CachedInstrumentation(), new RequestProcessor());

