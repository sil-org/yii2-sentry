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
use Sentry\State\Scope;
use Sil\Sentry\SentryTarget;
use yii\log\Logger;

/**
 * Unit-tests for SentryTarget
 */
class SentryTargetTest extends Unit
{
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
