<?php

declare(strict_types=1);

use Spryker\Service\OtelApplicationInstrumentation\OpenTelemetry\ApplicationInstrumentation;
use Spryker\Service\OtelApplicationInstrumentation\OpenTelemetry\BackendApiInstrumentation;
use Spryker\Service\OtelApplicationInstrumentation\OpenTelemetry\BackendGatewayInstrumentation;

if (extension_loaded('opentelemetry') === false) {
    error_log('The opentelemetry extension must be loaded in order to autoload the OpenTelemetry Spryker Framework auto-instrumentation', E_USER_WARNING);

    return;
}

ApplicationInstrumentation::register();
BackendGatewayInstrumentation::register();
BackendApiInstrumentation::register();

