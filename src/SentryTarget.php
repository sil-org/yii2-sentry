<?php
/**
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Sil\Sentry;

use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Integration\ErrorListenerIntegration;
use Sentry\Integration\ExceptionListenerIntegration;
use Sentry\Integration\FatalErrorListenerIntegration;
use Sentry\Integration\IntegrationInterface;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\State\Scope;
use Stringable;
use Throwable;
use Yii;
use yii\helpers\ArrayHelper;
use yii\log\Logger;
use yii\log\Target;
use yii\web\Request;
use yii\web\User;

/**
 * SentryTarget records log messages in a Sentry.
 *
 * @see https://sentry.io
 */
class SentryTarget extends Target
{
    /**
     * @var string Sentry client key.
     */
    public $dsn;
    /**
     * @var array Options of the \Sentry.
     */
    public $clientOptions = [];
    /**
     * @var bool Write the context information. The default implementation will dump user information, system variables, etc.
     */
    public $context = true;
    /**
     * @var callable Callback function that can modify extra's array
     */
    public $extraCallback;
    /**
     * @var callable Callback function that can add tags
     */
    public $tagCallback;

    /**
     * @inheritDoc
     */
    public function __construct($config = [])
    {
        parent::__construct($config);

        $userOptions = array_merge(['dsn' => $this->dsn], $this->clientOptions);
        $builder = ClientBuilder::create($userOptions);

        $options = $builder->getOptions();
        $options->setIntegrations(static function (array $integrations) {
            // Remove the default error and fatal exception listeners to let us handle those
            return array_filter($integrations, static function (IntegrationInterface $integration): bool {
                if ($integration instanceof ErrorListenerIntegration) {
                    return false;
                }
                if ($integration instanceof ExceptionListenerIntegration) {
                    return false;
                }
                if ($integration instanceof FatalErrorListenerIntegration) {
                    return false;
                }

                return true;
            });
        });

        SentrySdk::init()->bindClient($builder->getClient());
    }

    /**
     * @inheritdoc
     */
    protected function getContextMessage()
    {
        return '';
    }

    /**
     * @inheritdoc
     */
    public function export()
    {
        foreach ($this->messages as $message) {
            try {
                $this->exportMessage($message);
            } catch (Throwable $e) {
                $this->reportExportFailure($e, $message);
            }
        }
    }

    /**
     * Sends a single log message to Sentry.
     *
     * @param array $message the log message, as described in [[\yii\log\Logger::messages]]
     */
    protected function exportMessage(array $message)
    {
        [$text, $level, $category] = $message;

        $parsed = $this->parseText($text);

        $data = [
            'message' => $parsed['message'],
            'tags' => ArrayHelper::merge(['category' => $category], $parsed['tags']),
            'context' => $parsed['context'],
            'exception' => $parsed['exception'],
            'extra' => [],
            'userData' => $this->collectUserData(),
        ];

        if ($parsed['messageType'] !== null) {
            $data['tags']['log_message_type'] = $parsed['messageType'];
        }

        if ($this->context) {
            $data['extra']['context'] = parent::getContextMessage();
        }

        \Sentry\withScope(function (Scope $scope) use ($text, $level, $data, $parsed) {
            $data = $this->runExtraCallback($parsed['text'], $data);

            $scope->setUser($data['userData']);
            $scope->setContext('yii2-sentry', $data['context']);
            $data = $this->runTagCallback($data);
            $this->applyTags($scope, $data['tags']);

            if ($text instanceof Throwable) {
                \Sentry\captureException($text);
            } else {
                $event = Event::createEvent();
                $event->setMessage($data['message']);
                $event->setLevel($this->getLogLevel($level));

                \Sentry\captureEvent($event, EventHint::fromArray(array_filter([
                    'exception' => $data['exception'],
                ])));
            }
        });
    }

    /**
     * Returns the IP address and ID of the current user, if available.
     *
     * @return array
     */
    protected function collectUserData(): array
    {
        $userData = [];

        $request = Yii::$app->getRequest();
        if ($request instanceof Request && $request->getUserIP()) {
            $userData['ip_address'] = $request->getUserIP();
        }

        try {
            /** @var User $user */
            $user = Yii::$app->has('user', true) ? Yii::$app->get('user', false) : null;
            if ($user && ($identity = $user->getIdentity(false))) {
                $userData['id'] = $identity->getId();
            }
        } catch (Throwable $e) {
        }

        return $userData;
    }

    /**
     * Splits the logged value into the parts of a Sentry event.
     *
     * @param mixed $text the logged value
     *
     * @return array with keys:
     * - 'text': the logged value, without the keys that were used for other parts (for [[extraCallback]])
     * - 'message': the event message
     * - 'context': data for the 'yii2-sentry' context
     * - 'tags': tags given with the message
     * - 'exception': a Throwable given with the message, or null
     * - 'messageType': the type of a message that is not a string, 'none' if there is no message, otherwise null
     */
    protected function parseText($text): array
    {
        if (is_array($text)) {
            return $this->parseArrayText($text);
        }

        $parsed = [
            'text' => $text,
            'message' => '',
            'context' => [],
            'tags' => [],
            'exception' => null,
            'messageType' => null,
        ];

        if (is_string($text) || $text instanceof Stringable) {
            $parsed['message'] = (string)$text;
        } else {
            $parsed['messageType'] = get_debug_type($text);
            $parsed['message'] = $this->describeNonStringMessage($text);
            $parsed['context'] = ['text' => $text];
        }

        return $parsed;
    }

    /**
     * Splits a logged array into the parts of a Sentry event.
     *
     * @param array $text the logged array
     *
     * @return array as described in [[parseText()]]
     */
    protected function parseArrayText(array $text): array
    {
        $parsed = [
            'message' => '',
            'tags' => [],
            'exception' => null,
            'messageType' => null,
        ];

        if (isset($text['tags'])) {
            $parsed['tags'] = $text['tags'];
            unset($text['tags']);
        }

        if (isset($text['exception']) && $text['exception'] instanceof Throwable) {
            $parsed['exception'] = $text['exception'];
            unset($text['exception']);
        }

        $messageKey = $this->findMessageKey($text);
        $value = $messageKey === null ? null : $text[$messageKey];

        if ($messageKey === null) {
            $parsed['messageType'] = 'none';
            $parsed['message'] = $this->describeNonStringMessage($text);
        } elseif ($value instanceof Throwable) {
            $parsed['exception'] = $parsed['exception'] ?? $value;
            $parsed['message'] = $value->getMessage();
            unset($text[$messageKey]);
        } elseif (is_string($value) || $value instanceof Stringable) {
            $parsed['message'] = (string)$value;
            unset($text[$messageKey]);
        } else {
            // Leave the value in the context, so that it still reaches Sentry.
            $parsed['messageType'] = get_debug_type($value);
            $parsed['message'] = $this->describeNonStringMessage($value);
        }

        $parsed['text'] = $text;
        $parsed['context'] = $text;

        return $parsed;
    }

    /**
     * Returns the key of a logged array that holds the message, or null if there is none.
     *
     * The first key found is the message. Any others are left in the context,
     * so that e.g. ['message' => '...', 'error' => [...]] keeps both.
     *
     * @param array $text the logged array
     *
     * @return string|null
     */
    protected function findMessageKey(array $text): ?string
    {
        foreach (['message', 'msg', 'error'] as $key) {
            if (isset($text[$key])) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Sets the tags of a message on its Sentry scope, skipping empty values.
     *
     * @param Scope $scope
     * @param array $tags
     */
    protected function applyTags(Scope $scope, array $tags)
    {
        foreach ($tags as $key => $value) {
            if ($value) {
                $scope->setTag($key, $value);
            }
        }
    }

    /**
     * Returns a text version of a log message that is not a string, to use as the event message.
     *
     * @param mixed $value
     *
     * @return string
     */
    protected function describeNonStringMessage($value): string
    {
        if (is_object($value)) {
            return get_debug_type($value);
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json === false ? get_debug_type($value) : $json;
    }

    /**
     * Reports a failure to export a log message, so that one bad message does not abort
     * the rest of the batch or cause Yii to disable this target.
     *
     * @param Throwable $e the failure
     * @param array $message the log message that could not be exported
     */
    protected function reportExportFailure(Throwable $e, array $message)
    {
        [$text, $level, $category] = $message;

        \Sentry\withScope(function (Scope $scope) use ($e, $text, $level, $category) {
            $scope->setTag('category', (string)$category);
            $scope->setTag('yii2_sentry_export_failure', 'true');
            $scope->setContext('yii2-sentry', [
                'level' => (string)$this->getLogLevel($level),
                'text' => $text,
            ]);
            \Sentry\captureException($e);
        });
    }

    /**
     * Calls the extra callback if it exists
     *
     * @param mixed $text
     * @param array $data
     *
     * @return array
     */
    public function runExtraCallback($text, $data)
    {
        if (is_callable($this->extraCallback)) {
            $data['extra'] = call_user_func($this->extraCallback, $text, $data['extra'] ?? []);
        }

        return $data;
    }

    /**
     * Calls the tag callback if it exists
     *
     * @param array $data
     *
     * @return array
     */
    public function runTagCallback($data)
    {
        if (is_callable($this->tagCallback)) {
            $data['tags'] = call_user_func($this->tagCallback, $data['tags'] ?? []);
        }
        return $data;
    }

    /**
     * Returns the text display of the specified level for the Sentry.
     *
     * @param int $level The message level, e.g. [[LEVEL_ERROR]], [[LEVEL_WARNING]].
     *
     * @return string
     * @deprecated Deprecated from 1.5, will remove in 2.0
     *
     */
    public static function getLevelName($level)
    {
        static $levels = [
            Logger::LEVEL_ERROR => 'error',
            Logger::LEVEL_WARNING => 'warning',
            Logger::LEVEL_INFO => 'info',
            Logger::LEVEL_TRACE => 'debug',
            Logger::LEVEL_PROFILE_BEGIN => 'debug',
            Logger::LEVEL_PROFILE_END => 'debug',
        ];

        return $levels[$level] ?? 'error';
    }

    /**
     * Translates Yii2 log levels to Sentry Severity.
     *
     * @param int $level
     *
     * @return Severity
     */
    protected function getLogLevel($level): Severity
    {
        switch ($level) {
            case Logger::LEVEL_PROFILE:
            case Logger::LEVEL_PROFILE_BEGIN:
            case Logger::LEVEL_PROFILE_END:
            case Logger::LEVEL_TRACE:
                return Severity::debug();
            case Logger::LEVEL_WARNING:
                return Severity::warning();
            case Logger::LEVEL_ERROR:
                return Severity::error();
            case Logger::LEVEL_INFO:
            default:
                return Severity::info();
        }
    }
}
