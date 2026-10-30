<?php

namespace Sil\Sentry\tests\Unit;

use Codeception\Test\Unit;
use ReflectionClass;
use RuntimeException;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\EventId;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\State\Scope;
use Sil\Sentry\SentryTarget;
use yii\log\Logger;

/**
 * Unit-tests for SentryTarget
 */
class SentryTargetTest extends Unit
{
    private const VALIDATION_ERROR = 'Email is invalid';

    /** @var array test messages */
    protected $messages = [
        ['test', Logger::LEVEL_INFO, 'test', 1481513561.197593, []],
        ['test 2', Logger::LEVEL_INFO, 'test 2', 1481513572.867054, []]
    ];

    /**
     * Testing method getContextMessage()
     * - returns empty string ''
     * @see SentryTarget::getContextMessage
     */
    public function testGetContextMessage()
    {
        $class = new ReflectionClass(SentryTarget::class);
        $method = $class->getMethod('getContextMessage');
        $method->setAccessible(true);

        $sentryTarget = new SentryTarget();
        $result = $method->invokeArgs($sentryTarget, []);

        $this->assertEmpty($result);
    }

    public function testExceptionPassing()
    {
        $logData = [
            'message' => 'This exception was caught, but still needs to be reported',
            'exception' => new RuntimeException('Package loss detected'),
            'something_extra' => ['foo' => 'bar'],
        ];

        [$event, $hint] = $this->exportOne($logData);

        $this->assertSame($logData['exception'], $hint->exception);
        $this->assertSame($logData['message'], $event->getMessage());
    }

    public function messageDataProvider()
    {
        $msg = 'A message';

        // logged value, expected message, expected context, expected log_message_type tag
        yield 'string' => [$msg, $msg, [], null];

        yield 'msg' => [['msg' => $msg], $msg, [], null];

        yield 'error' => [['error' => $msg], $msg, [], null];

        yield 'message' => [['message' => $msg], $msg, [], null];

        yield 'message before msg' => [['message' => $msg, 'msg' => 'Ignored'], $msg, ['msg' => 'Ignored'], null];

        yield 'msg before error' => [['msg' => $msg, 'error' => 'Ignored'], $msg, ['error' => 'Ignored'], null];

        yield 'message before error' => [['error' => 'Ignored', 'message' => $msg], $msg, ['error' => 'Ignored'], null];

        yield 'message with error context' => [
            ['action' => 'create', 'message' => 'Validation failed', 'error' => ['email' => self::VALIDATION_ERROR]],
            'Validation failed',
            ['action' => 'create', 'error' => ['email' => self::VALIDATION_ERROR]],
            null,
        ];

        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'A Stringable message';
            }
        };

        yield 'Stringable' => [$stringable, 'A Stringable message', [], null];

        yield 'Stringable under message' => [['message' => $stringable], 'A Stringable message', [], null];

        yield 'array under error' => [
            ['action' => 'create', 'error' => ['email' => self::VALIDATION_ERROR]],
            '{"email":"Email is invalid"}',
            ['action' => 'create', 'error' => ['email' => self::VALIDATION_ERROR]],
            'array',
        ];

        yield 'int under msg' => [['msg' => 42], '42', ['msg' => 42], 'int'];

        yield 'no message key' => [
            ['one' => 'value 1', 'two' => 'value 2'],
            '{"one":"value 1","two":"value 2"}',
            ['one' => 'value 1', 'two' => 'value 2'],
            'none',
        ];

        yield 'list' => [[1, 2, 3], '[1,2,3]', [1, 2, 3], 'none'];

        yield 'int' => [42, '42', ['text' => 42], 'int'];

        $object = new \stdClass();
        yield 'object' => [$object, 'stdClass', ['text' => $object], 'stdClass'];
    }

    /**
     * The logged value is split into the event message and context. A message that is not a
     * string is converted to text and tagged with its type.
     * @dataProvider messageDataProvider
     * @see SentryTarget::export
     */
    public function testMessageConverting($loggedMessage, $expectedMessage, $expectedContext, $expectedType)
    {
        [$event] = $this->exportOne($loggedMessage);

        $this->assertSame($expectedMessage, $event->getMessage());
        $this->assertEquals(Severity::info(), $event->getLevel());
        $this->assertSame($expectedContext, $event->getContexts()['yii2-sentry'] ?? []);
        $this->assertSame($expectedType, $event->getTags()['log_message_type'] ?? null);
    }

    /**
     * A Throwable used as the message is sent as the event's exception.
     * @see SentryTarget::export
     */
    public function testThrowableMessage()
    {
        $exception = new RuntimeException('Connection error');

        [$event, $hint] = $this->exportOne(['msg' => $exception]);

        $this->assertSame('Connection error', $event->getMessage());
        $this->assertSame($exception, $hint->exception);
    }

    /**
     * extraCallback gets the logged value without the message, tags and exception keys,
     * and tagCallback can change the tags.
     * @see SentryTarget::runExtraCallback
     * @see SentryTarget::runTagCallback
     */
    public function testCallbacks()
    {
        $sentryTarget = $this->getConfiguredSentryTarget();
        $extraCallbackText = null;
        $sentryTarget->extraCallback = function ($text, $extra) use (&$extraCallbackText) {
            $extraCallbackText = $text;
            return $extra;
        };
        $sentryTarget->tagCallback = function ($tags) {
            $tags['foo'] = 'bar';
            unset($tags['removed']);
            return $tags;
        };

        $logData = [
            'message' => 'A message with callbacks',
            'tags' => ['given' => 'yes', 'removed' => 'yes'],
            'exception' => new RuntimeException('Boom'),
            'action' => 'create',
        ];
        [$event] = $this->exportOne($logData, $sentryTarget);

        $this->assertSame(['category' => 'application', 'given' => 'yes', 'foo' => 'bar'], $event->getTags());
        $this->assertSame(['action' => 'create'], $extraCallbackText);
    }

    /**
     * A message that fails to export is reported, and does not stop the rest of the batch.
     * @see SentryTarget::export
     */
    public function testExportFailureIsIsolated()
    {
        // an array tag value makes Scope::setTag() throw a TypeError
        $badMessage = ['message' => 'Bad tags', 'tags' => ['foo' => ['bar']]];

        $sent = $this->export([
            [$badMessage, Logger::LEVEL_INFO, 'application', 1481513561.197593, []],
            ['Good message', Logger::LEVEL_INFO, 'application', 1481513561.197593, []],
        ]);

        [[$exception, $failureEvent]] = $sent['exceptions'];
        $this->assertInstanceOf(\TypeError::class, $exception);
        $this->assertSame('true', $failureEvent->getTags()['yii2_sentry_export_failure']);
        $this->assertSame('application', $failureEvent->getTags()['category']);
        $this->assertSame($badMessage, $failureEvent->getContexts()['yii2-sentry']['text']);

        [[$event]] = $sent['events'];
        $this->assertSame('Good message', $event->getMessage());
    }

    /**
     * If reporting an export failure also fails, it goes to PHP's error log, and the rest of the batch is still sent.
     * @see SentryTarget::export
     */
    public function testExportFailureReportFailureIsLogged()
    {
        $sentryTarget = $this->getConfiguredSentryTarget();
        $sentMessages = [];

        $client = $this->createMock(ClientInterface::class);
        $client->method('captureException')->willThrowException(new RuntimeException('Sentry is down'));
        $client->expects($this->once())
            ->method('captureEvent')
            ->willReturnCallback(function (Event $event) use (&$sentMessages): ?EventId {
                $sentMessages[] = $event->getMessage();

                return EventId::generate();
            });

        SentrySdk::getCurrentHub()->bindClient($client);

        $errorLog = tempnam(sys_get_temp_dir(), 'sentry-target-test');
        $previousErrorLog = ini_set('error_log', $errorLog);
        try {
            $sentryTarget->collect([
                // an array tag value makes Scope::setTag() throw a TypeError
                [['message' => 'Bad tags', 'tags' => ['foo' => ['bar']]], Logger::LEVEL_INFO, 'application', 1481513561.197593, []],
                ['Good message', Logger::LEVEL_INFO, 'application', 1481513561.197593, []],
            ], true);
            $logged = file_get_contents($errorLog);
        } finally {
            ini_set('error_log', $previousErrorLog);
            unlink($errorLog);
        }

        $this->assertStringContainsString('unable to export a log message', $logged);
        $this->assertStringContainsString('Sentry is down', $logged);
        $this->assertSame(['Good message'], $sentMessages);
    }

    /**
     * Testing method getLevelName()
     * - returns level name for each logger level
     * @see SentryTarget::getLevelName
     */
    public function testGetLevelName()
    {
        //valid level names
        $levelNames = [
            'info',
            'error',
            'warning',
            'debug',
        ];

        $loggerClass = new ReflectionClass(Logger::class);
        $loggerLevelConstants = $loggerClass->getConstants();
        foreach ($loggerLevelConstants as $constant => $value) {
            if (strpos($constant, 'LEVEL_') === 0) {
                $level = SentryTarget::getLevelName($value);
                $this->assertNotEmpty($level);
                $this->assertTrue(in_array($level, $levelNames), sprintf('Level "%s" is incorrect', $level));
            }
        }

        //check default level name
        $this->assertEquals('error', SentryTarget::getLevelName(''));
        $this->assertEquals('error', SentryTarget::getLevelName('somerandomstring' . uniqid()));
    }

    /**
     * Testing method collect()
     * - assigns messages to Target property
     * - creates Sentry object
     * @see SentryTarget::collect
     */
    public function testCollect()
    {
        $sentryTarget = $this->getConfiguredSentryTarget();

        $sentryTarget->collect($this->messages, false);
        $this->assertEquals(count($this->messages), count($sentryTarget->messages));
    }

    /**
     * Testing method export()
     * - Sentry::capture is called on collect([...], true)
     * - messages stack is cleaned on  collect([...], true)
     * - Sentry::capture is called on export()
     * @see SentryTarget::export
     */
    public function testExport()
    {
        $sentryTarget = $this->getConfiguredSentryTarget();

        //test calling client and clearing messages on final collect
        $sentryTarget->collect($this->messages, true);
        $this->assertEmpty($sentryTarget->messages);

        //add messages and test simple export() method
        $sentryTarget->collect($this->messages, false);
        $sentryTarget->export();
        $this->assertEquals(count($this->messages), count($sentryTarget->messages));
    }

    /**
     * Returns configured SentryTarget object
     *
     * @return SentryTarget
     * @throws \yii\base\InvalidConfigException
     */
    protected function getConfiguredSentryTarget()
    {
        $sentryTarget = new SentryTarget();
        $sentryTarget->exportInterval = 100;
        $sentryTarget->setLevels(Logger::LEVEL_INFO);
        return $sentryTarget;
    }

    /**
     * Exports log messages through a SentryTarget, with a mock Sentry client, and returns what was sent
     *
     * @param array $messages log messages, as described in [[\yii\log\Logger::messages]]
     * @param SentryTarget|null $sentryTarget defaults to [[getConfiguredSentryTarget()]]
     * @return array with keys:
     * - 'events': [Event, ?EventHint] for each captured event
     * - 'exceptions': [Throwable, Event] for each captured exception
     * The scope of each capture is applied to its Event.
     */
    protected function export(array $messages, ?SentryTarget $sentryTarget = null): array
    {
        // creating the target binds a real client, so it must be done before binding the mock
        $sentryTarget = $sentryTarget ?? $this->getConfiguredSentryTarget();
        $sent = ['events' => [], 'exceptions' => []];

        $client = $this->createMock(ClientInterface::class);
        // global event processors, run by Scope::applyToEvent(), read the client options
        $client->method('getOptions')->willReturn(new Options());
        $client->method('captureEvent')
            ->willReturnCallback(function (Event $event, ?EventHint $hint = null, ?Scope $scope = null) use (&$sent): ?EventId {
                $sent['events'][] = [$scope->applyToEvent($event), $hint];

                return EventId::generate();
            });
        $client->method('captureException')
            ->willReturnCallback(function (\Throwable $exception, ?Scope $scope = null) use (&$sent): ?EventId {
                $sent['exceptions'][] = [$exception, $scope->applyToEvent(Event::createEvent())];

                return EventId::generate();
            });

        SentrySdk::getCurrentHub()->bindClient($client);

        $sentryTarget->collect($messages, true);

        return $sent;
    }

    /**
     * Exports one INFO log message, checks that it was sent as exactly one event, and returns it
     *
     * @param mixed $text the logged value
     * @param SentryTarget|null $sentryTarget
     * @return array [Event, ?EventHint]
     */
    protected function exportOne($text, ?SentryTarget $sentryTarget = null): array
    {
        $sent = $this->export([[$text, Logger::LEVEL_INFO, 'application', 1481513561.197593, []]], $sentryTarget);

        $this->assertCount(1, $sent['events']);
        $this->assertSame([], $sent['exceptions']);

        return $sent['events'][0];
    }
}
