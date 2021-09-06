<?php

namespace Keepsuit\LaravelOpenTelemetry\Watchers;

use Illuminate\Database\Events\QueryExecuted;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\Sdk\Trace\Clock;
use OpenTelemetry\Sdk\Trace\Span;

class QueryWatcher extends Watcher
{
    public const MSEC_TO_NSEC = 1000000;

    public function register($app)
    {
        $app['events']->listen(QueryExecuted::class, [$this, 'recordQuery']);
    }

    public function recordQuery(QueryExecuted $event)
    {
        $traceName = sprintf('%s %s', $event->connection->getDriverName(), $event->connection->getDatabaseName());

        Tracer::start($traceName);
        Tracer::stop($traceName, function (Span $span) use ($event) {
            $span->setAttribute('db.system', $event->connection->getDriverName());
            $span->setAttribute('db.name', $event->connection->getDatabaseName());
            $span->setAttribute('db.statement', $this->replaceBindings($event));
            $span->setAttribute('net.peer.name', $event->connection->getConfig('host'));
            $span->setAttribute('net.peer.port', $event->connection->getConfig('port'));

            // Set the correct span start time
            $moment = Clock::get()->moment();
            $durationNs = (int)($event->time * self::MSEC_TO_NSEC);
            $span->setStartEpochTimestamp($moment[0] - $durationNs);
            $span->setStart($moment[1] - $durationNs);
        });
    }

    /**
     * Format the given bindings to strings.
     *
     * @param \Illuminate\Database\Events\QueryExecuted $event
     * @return array
     */
    protected function formatBindings($event)
    {
        return $event->connection->prepareBindings($event->bindings);
    }

    /**
     * Replace the placeholders with the actual bindings.
     *
     * @param \Illuminate\Database\Events\QueryExecuted $event
     * @return string
     */
    public function replaceBindings($event)
    {
        $sql = $event->sql;

        foreach ($this->formatBindings($event) as $key => $binding) {
            $regex = is_numeric($key)
                ? "/\?(?=(?:[^'\\\']*'[^'\\\']*')*[^'\\\']*$)/"
                : "/:{$key}(?=(?:[^'\\\']*'[^'\\\']*')*[^'\\\']*$)/";

            if ($binding === null) {
                $binding = 'null';
            } elseif (! is_int($binding) && ! is_float($binding)) {
                $binding = $this->quoteStringBinding($event, $binding);
            }

            $sql = preg_replace($regex, $binding, $sql, 1);
        }

        return $sql;
    }

    /**
     * Add quotes to string bindings.
     *
     * @param \Illuminate\Database\Events\QueryExecuted $event
     * @param string                                    $binding
     * @return string
     */
    protected function quoteStringBinding($event, $binding)
    {
        try {
            return $event->connection->getPdo()->quote($binding);
        } catch (\PDOException $e) {
            throw_if('IM001' !== $e->getCode(), $e);
        }

        // Fallback when PDO::quote function is missing...
        $binding = \strtr($binding, [
            chr(26) => '\\Z',
            chr(8) => '\\b',
            '"' => '\"',
            "'" => "\'",
            '\\' => '\\\\',
        ]);

        return "'".$binding."'";
    }
}