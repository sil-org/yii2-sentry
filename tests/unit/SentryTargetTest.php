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
        $sentryTarget = $this->getConfiguredSentryTarget();

        $logData = [
            'message' => 'This exception was caught, but still needs to be reported',
            'exception' => new RuntimeException('Package loss detected'),
            'something_extra' => ['foo' => 'bar'],
        ];

        $messageWasSent = false;

        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('captureEvent')
            ->willReturnCallback(function (Event $event, ?EventHint $hint = null, ?Scope $scope = null) use ($logData, &$messageWasSent): ?EventId {
                $messageWasSent = true;
                $this->assertSame($logData['exception'], $hint->exception);
                $this->assertSame($logData['message'], $event->getMessage());

                return EventId::generate();
            });

        SentrySdk::getCurrentHub()->bindClient($client);

        $sentryTarget->collect([[$logData, Logger::LEVEL_INFO, 'application', 1481513561.197593, []]], true);
        $this->assertTrue($messageWasSent);
    }

    public function messageDataProvider()
    {
        $msg = 'A message';

        yield [$msg, $msg];

        yield [$msg, ['msg' => $msg]];

        yield [$msg, ['error' => $msg]];

        yield [$msg, ['message' => $msg]];

        yield [$msg, ['message' => $msg, 'msg' => 'Ignored']];

        yield [$msg, ['msg' => $msg, 'error' => 'Ignored']];

        yield [$msg, ['error' => 'Ignored', 'message' => $msg]];
    }

    /**
     * @dataProvider messageDataProvider
     */
    public function testMessageConverting($expectedMessageText, $loggedMessage)
    {
        $sentryTarget = $this->getConfiguredSentryTarget();
        $messageWasSent = false;

        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('captureEvent')
            ->willReturnCallback(function (Event $event, ?EventHint $hint = null, ?Scope $scope = null) use ($expectedMessageText, &$messageWasSent): ?EventId {
                $messageWasSent = true;
                $this->assertSame($expectedMessageText, $event->getMessage());

                return EventId::generate();
            });

        SentrySdk::getCurrentHub()->bindClient($client);

        $sentryTarget->collect([[$loggedMessage, Logger::LEVEL_INFO, 'application', 1481513561.197593, []]], true);
        $this->assertTrue($messageWasSent);
    }

    /**
     * Message keys that are not used as the message are kept in the context.
     * @see SentryTarget::export
     */
    public function testUnusedMessageKeysAreKeptAsContext()
    {
        $sentryTarget = $this->getConfiguredSentryTarget();
        $messageWasSent = false;

        $client = $this->createClientMock();
        $client->expects($this->once())
            ->method('captureEvent')
            ->willReturnCallback(function (Event $event, ?EventHint $hint = null, ?Scope $scope = null) use (&$messageWasSent): ?EventId {
                $messageWasSent = true;
                $this->assertSame('Validation failed', $event->getMessage());
                $context = $scope->applyToEvent($event)->getContexts()['yii2-sentry'];
                $this->assertSame(['email' => self::VALIDATION_ERROR], $context['error']);
                $this->assertSame('create', $context['action']);

                return EventId::generate();
            });

        SentrySdk::getCurrentHub()->bindClient($client);

        $logData = [
            'action' => 'create',
            'message' => 'Validation failed',
            'error' => ['email' => self::VALIDATION_ERROR],
        ];
        $sentryTarget->collect([[$logData, Logger::LEVEL_INFO, 'application', 1481513561.197593, []]], true);
        $this->assertTrue($messageWasSent);
    }

    /**
     * A Throwable used as the message is sent as the event's exception.
     * @see SentryTarget::export
     */
    public function testThrowableMessage()
    {
        $sentryTarget = $this->getConfiguredSentryTarget();
        $exception = new RuntimeException('Connection error');
        $messageWasSent = false;

        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('captureEvent')
            ->willReturnCallback(function (Event $event, ?EventHint $hint = null) use ($exception, &$messageWasSent): ?EventId {
                $messageWasSent = true;
                $this->assertSame('Connection error', $event->getMessage());
                $this->assertSame($exception, $hint->exception);

                return EventId::generate();
            });

        SentrySdk::getCurrentHub()->bindClient($client);

        $sentryTarget->collect([[['msg' => $exception], Logger::LEVEL_INFO, 'dbms', 1481513561.197593, []]], true);
        $this->assertTrue($messageWasSent);
    }

    public function nonStringMessageDataProvider()
    {
        yield 'array under error' => [
            ['action' => 'create', 'error' => ['email' => self::VALIDATION_ERROR]],
            '{"email":"Email is invalid"}',
            'array',
            ['action' => 'create', 'error' => ['email' => self::VALIDATION_ERROR]],
        ];

        yield 'int under msg' => [['msg' => 42], '42', 'int', ['msg' => 42]];

        yield 'no message key' => [
            ['one' => 'value 1', 'two' => 'value 2'],
            '{"one":"value 1","two":"value 2"}',
            'none',
            ['one' => 'value 1', 'two' => 'value 2'],
        ];

        yield 'list' => [[1, 2, 3], '[1,2,3]', 'none', [1, 2, 3]];

        yield 'int' => [42, '42', 'int', ['text' => 42]];

        $object = new \stdClass();
        yield 'object' => [$object, 'stdClass', 'stdClass', ['text' => $object]];
    }

    /**
     * A log message that is not a string is converted to text, kept in the context, and tagged.
     * @dataProvider nonStringMessageDataProvider
     * @see SentryTarget::export
     */
    public function testNonStringMessageIsConverted($loggedMessage, $expectedMessage, $expectedType, $expectedContext)
    {
        $sentryTarget = $this->getConfiguredSentryTarget();
        $messageWasSent = false;

        $client = $this->createClientMock();
        $client->expects($this->never())->method('captureException');
        $client->expects($this->once())
            ->method('captureEvent')
            ->willReturnCallback(function (Event $event, ?EventHint $hint = null, ?Scope $scope = null) use ($expectedMessage, $expectedType, $expectedContext, &$messageWasSent): ?EventId {
                $messageWasSent = true;
                $event = $scope->applyToEvent($event);
                $this->assertSame($expectedMessage, $event->getMessage());
                $this->assertEquals(Severity::info(), $event->getLevel());
                $this->assertSame($expectedType, $event->getTags()['log_message_type']);
                $this->assertSame($expectedContext, $event->getContexts()['yii2-sentry']);

                return EventId::generate();
            });

        SentrySdk::getCurrentHub()->bindClient($client);

        $sentryTarget->collect([[$loggedMessage, Logger::LEVEL_INFO, 'application', 1481513561.197593, []]], true);
        $this->assertTrue($messageWasSent);
    }

    public function validMessageDataProvider()
    {
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'A Stringable message';
            }
        };

        yield 'Stringable' => [$stringable];

        yield 'Stringable under message' => [['message' => $stringable]];
    }

    /**
     * Stringable messages are valid and are not reported.
     * @dataProvider validMessageDataProvider
     * @see SentryTarget::export
     */
    public function testStringableMessageIsNotReported($loggedMessage)
    {
        $sentryTarget = $this->getConfiguredSentryTarget();

        $client = $this->createClientMock();
        $client->expects($this->once())
            ->method('captureEvent')
            ->willReturnCallback(function (Event $event, ?EventHint $hint = null, ?Scope $scope = null): ?EventId {
                $event = $scope->applyToEvent($event);
                $this->assertSame('A Stringable message', $event->getMessage());
                $this->assertArrayNotHasKey('log_message_type', $event->getTags());

                return EventId::generate();
            });

        SentrySdk::getCurrentHub()->bindClient($client);

        $sentryTarget->collect([[$loggedMessage, Logger::LEVEL_INFO, 'application', 1481513561.197593, []]], true);
    }

    /**
     * A message that fails to export is reported, and does not stop the rest of the batch.
     * @see SentryTarget::export
     */
    public function testExportFailureIsIsolated()
    {
        $sentryTarget = $this->getConfiguredSentryTarget();

        // an array tag value makes Scope::setTag() throw a TypeError
        $badMessage = ['message' => 'Bad tags', 'tags' => ['foo' => ['bar']]];

        $sentEvents = [];
        $sentExceptions = [];

        $client = $this->createClientMock();
        $client->expects($this->once())
            ->method('captureException')
            ->willReturnCallback(function (\Throwable $exception, ?Scope $scope = null) use (&$sentExceptions): ?EventId {
                $sentExceptions[] = [$exception, $scope->applyToEvent(Event::createEvent())];

                return EventId::generate();
            });
        $client->expects($this->once())
            ->method('captureEvent')
            ->willReturnCallback(function (Event $event) use (&$sentEvents): ?EventId {
                $sentEvents[] = $event;

                return EventId::generate();
            });

        SentrySdk::getCurrentHub()->bindClient($client);

        $sentryTarget->collect([
            [$badMessage, Logger::LEVEL_INFO, 'application', 1481513561.197593, []],
            ['Good message', Logger::LEVEL_INFO, 'application', 1481513561.197593, []],
        ], true);

        [[$exception, $failureEvent]] = $sentExceptions;
        $this->assertInstanceOf(\TypeError::class, $exception);
        $this->assertSame('true', $failureEvent->getTags()['yii2_sentry_export_failure']);
        $this->assertSame('application', $failureEvent->getTags()['category']);
        $this->assertSame($badMessage, $failureEvent->getContexts()['yii2-sentry']['text']);

        $this->assertSame('Good message', $sentEvents[0]->getMessage());
    }

    /**
     * If reporting an export failure also fails, it goes to PHP's error log, and the rest of the batch is still sent.
     * @see SentryTarget::export
     */
    public function testExportFailureReportFailureIsLogged()
    {
        $sentryTarget = $this->getConfiguredSentryTarget();
        $sentMessages = [];

        $client = $this->createClientMock();
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
     * Returns a Sentry client mock that can also be used to apply a Scope to an Event
     *
     * @return ClientInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected function createClientMock()
    {
        $client = $this->createMock(ClientInterface::class);
        // global event processors, run by Scope::applyToEvent(), read the client options
        $client->method('getOptions')->willReturn(new Options());

        return $client;
    }

    /**
     * Returns reflected 'client' property
     *
     * @param SentryTarget $sentryTarget
     * @return \ReflectionProperty
     */
    protected function getAccessibleClientProperty(SentryTarget $sentryTarget)
    {
        $sentryTargetClass = new ReflectionClass("\Sentry\Client");
        $clientProperty = $sentryTargetClass->getProperty('transport');
        $clientProperty->setAccessible(true);

        return $clientProperty;
    }

    /**
     * Compatible version of creating mock method
     *
     * @param string $className
     * @return \PHPUnit_Framework_MockObject_MockObject
     */
    protected function getMockCompatible($className)
    {
        return method_exists($this, 'createMock') ?
            self::createMock($className) : $this->getMock($className);
    }
}
