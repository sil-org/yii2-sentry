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
                try {
                    $this->reportExportFailure($e, $message);
                } catch (Throwable $reportError) {
                    // Sentry can't take the report either. Use PHP's error log, as throwing
                    // would make Yii disable this target.
                    error_log(sprintf(
                        '%s: unable to export a log message (%s), or to report that to Sentry (%s)',
                        static::class,
                        $e->getMessage(),
                        $reportError->getMessage()
                    ));
                }
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

        $data = [
            'message' => '',
            'tags' => ['category' => $category],
            'context' => [],
            'extra' => [],
            'userData' => [],
        ];

        $request = Yii::$app->getRequest();
        if ($request instanceof Request && $request->getUserIP()) {
            $data['userData']['ip_address'] = $request->getUserIP();
        }

        try {
            /** @var User $user */
            $user = Yii::$app->has('user', true) ? Yii::$app->get('user', false) : null;
            if ($user && ($identity = $user->getIdentity(false))) {
                $data['userData']['id'] = $identity->getId();
            }
        } catch (Throwable $e) {
        }

        \Sentry\withScope(function (Scope $scope) use ($text, $level, $data) {
            if (is_array($text)) {
                // The first key found is the message. Any others are left in the context,
                // so that e.g. ['message' => '...', 'error' => [...]] keeps both.
                $messageException = null;
                foreach (['message', 'msg', 'error'] as $key) {
                    if (isset($text[$key])) {
                        $message = $text[$key];
                        unset($text[$key]);
                        if ($message instanceof Throwable) {
                            $messageException = $message;
                            $message = $message->getMessage();
                        }
                        $data['message'] = (string)$message;
                        break;
                    }
                }
                // if none of the above have text, stringify the object
                if (!isset($data['message'])) {
                    $data['message'] = json_encode($text);
                }

                if (isset($text['tags'])) {
                    $data['tags'] = ArrayHelper::merge($data['tags'], $text['tags']);
                    unset($text['tags']);
                }

                if (isset($text['exception']) && $text['exception'] instanceof Throwable) {
                    $data['exception'] = $text['exception'];
                    unset($text['exception']);
                }
                if (!isset($data['exception']) && $messageException !== null) {
                    $data['exception'] = $messageException;
                }

                $data['context'] = $text;
            } else {
                $data['message'] = (string)$text;
            }

            if ($this->context) {
                $data['extra']['context'] = parent::getContextMessage();
            }

            $data = $this->runExtraCallback($text, $data);

            $scope->setUser($data['userData']);
            $scope->setContext('yii2-sentry', $data['context']);
            $data = $this->runTagCallback($data);
            foreach ($data['tags'] as $key => $value) {
                if ($value) {
                    $scope->setTag($key, $value);
                }
            }

            if ($text instanceof Throwable) {
                \Sentry\captureException($text);
            } else {
                $event = Event::createEvent();
                $event->setMessage($data['message']);
                $event->setLevel($this->getLogLevel($level));

                \Sentry\captureEvent($event, EventHint::fromArray(array_filter([
                    'exception' => $data['exception'] ?? null,
                ])));
            }
        });
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
