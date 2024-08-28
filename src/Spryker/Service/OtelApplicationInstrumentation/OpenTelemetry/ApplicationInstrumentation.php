<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Service\OtelApplicationInstrumentation\OpenTelemetry;

use Exception;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextStorageScopeInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SemConv\TraceAttributes;
use Spryker\Service\Opentelemetry\Storage\CustomParameterStorage;
use Spryker\Shared\Application\Application;
use Spryker\Shared\Opentelemetry\Instrumentation\CachedInstrumentation;
use Spryker\Shared\Opentelemetry\Request\RequestProcessor;
use Spryker\Zed\Opentelemetry\Business\Generator\SpanFilter\SamplerSpanFilter;
use Symfony\Component\HttpFoundation\Request;
use Throwable;
use function OpenTelemetry\Instrumentation\hook;

class ApplicationInstrumentation
{
    /**
     * @var string
     */
    protected const METHOD_NAME = 'run';

    /**
     * @var string
     */
    protected const SPAN_NAME_PLACEHOLDER = '%s %s';

    /**
     * @var string
     */
    protected const APPLICATION_TRACE_ID_KEY = 'application_trace_id';

    /**
     * @var string
     */
    protected const ERROR_MESSAGE = 'error_message';

    /**
     * @var string
     */
    protected const ERROR_CODE = 'error_code';

    /**
     * @var string
     */
    protected const ERROR_TEXT_PLACEHOLDER = 'Error: %s in %s on line %d';

    /**
     * @return void
     */
    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation();
        $request = new RequestProcessor();

        // phpcs:disable
        hook(
            class: Application::class,
            function: static::METHOD_NAME,
            pre: static function ($instance, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $request): void {
                if ($instrumentation::getCachedInstrumentation() === null || $request->getRequest() === null) {
                    return;
                }

                $span = $instrumentation::getCachedInstrumentation()
                    ->tracer()
                    ->spanBuilder(static::formatSpanName($request->getRequest()))
                    ->setParent(static::prepareContext())
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno)
                    ->setAttribute(TraceAttributes::URL_QUERY, $request->getRequest()->getQueryString())
                    ->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function ($instance, array $params, $returnValue, ?Throwable $exception): void {
                $scope = Context::storage()->scope();

                if ($scope === null) {
                    return;
                }

                $span = static::handleError($scope);
                $span = SamplerSpanFilter::filter($span);

                static::setCustomParametersIntoRootSpan();

                $span->end();
            },
        );
        // phpcs:enable
    }

    /**
     * @return \OpenTelemetry\Context\ContextInterface
     */
    protected static function prepareContext(): ContextInterface
    {
        $context = Context::getCurrent();
        $envVars = [
            'backoffice_trace_id' => 'OTEL_BACKOFFICE_TRACE_ID',
            'cli_trace_id' => 'OTEL_CLI_TRACE_ID',
            'merchant_portal_trace_id' => 'OTEL_MERCHANT_PORTAL_TRACE_ID',
            'glue_trace_id' => 'OTEL_GLUE_TRACE_ID',
            'yves_trace_id' => 'OTEL_YVES_TRACE_ID',
            'backend_gateway_trace_id' => 'OTEL_BACKEND_GATEWAY_TRACE_ID',
        ];

        $extractTraceIdFromEnv = function (array $envVars): array {
            foreach ($envVars as $key => $envVar) {
                if (defined($envVar)) {
                    return [$key => constant($envVar)];
                }
            }

            return [];
        };

        $traceId = $extractTraceIdFromEnv($envVars);
        if ($traceId !== []) {
            return TraceContextPropagator::getInstance()->extract($traceId);
        }

        return $context;
    }

    /**
     * @param \OpenTelemetry\Context\ContextStorageScopeInterface $scope
     *
     * @return \OpenTelemetry\SDK\Trace\ReadableSpanInterface
     */
    protected static function handleError(ContextStorageScopeInterface $scope): ReadableSpanInterface
    {
        $error = error_get_last();
        $exception = null;

        if (is_array($error) && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
            $exception = new Exception(
                sprintf(static::ERROR_TEXT_PLACEHOLDER, $error['message'], $error['file'], $error['line']),
            );
        }

        $scope->detach();
        $span = Span::fromContext($scope->context());

        if ($exception !== null) {
            $span->recordException($exception);
        }

        $span->setAttribute(static::ERROR_MESSAGE, $exception !== null ? $exception->getMessage() : '');
        $span->setAttribute(static::ERROR_CODE, $exception !== null ? $exception->getCode() : '');
        $span->setStatus($exception !== null ? StatusCode::STATUS_ERROR : StatusCode::STATUS_OK);

        /** @var \OpenTelemetry\SDK\Trace\ReadableSpanInterface $span */
        return $span;
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return string
     */
    protected static function formatSpanName(Request $request): string
    {
        $relativeUriWithoutQueryString = str_replace('?' . $request->getQueryString(), '', $request->getUri());

        return sprintf(static::SPAN_NAME_PLACEHOLDER, $request->getMethod(), $relativeUriWithoutQueryString);
    }

    /**
     * @return void
     */
    protected static function setCustomParametersIntoRootSpan(): void
    {
        $customParamsStorage = CustomParameterStorage::getInstance();
        $currentContext = Context::getCurrent();
        $parentSpan = Span::fromContext($currentContext);
        $parentSpan->setAttributes($customParamsStorage->getAttributes());
        $parentSpan->end();
    }
}
