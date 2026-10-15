<?php

declare(strict_types=1);

// These narrow substitutes let service contracts run without installing Pelican.
// They do not attempt to test Laravel validation, encryption, or HTTP internals.
namespace App\Enums {
    enum ContainerStatus: string
    {
        case Created = 'created';
        case Starting = 'starting';
        case Running = 'running';
        case Restarting = 'restarting';
        case Exited = 'exited';
        case Paused = 'paused';
        case Dead = 'dead';
        case Removing = 'removing';
        case Stopping = 'stopping';
        case Offline = 'offline';
        case Missing = 'missing';
    }

    enum SubuserPermission: string
    {
        case WebsocketConnect = 'websocket.connect';
        case StartupRead = 'startup.read';
        case ControlStart = 'control.start';
        case ControlRestart = 'control.restart';
    }
}

namespace App\Models {
    use App\Enums\SubuserPermission;
    use LogicException;

    class Server
    {
        public int $id = 1;
        public string $uuid = 'server-uuid-1';
        public object $egg;
        public int $memory = 2048;
        public int $disk = 10000;
        public int $cpu = 200;
        public string $startup = 'java -jar {{SERVER_JARFILE}}';
        public string $image = 'ghcr.io/pelican-eggs/yolks:java_21';
        public array $boundVariables = [];
        public int $relationQueries = 0;

        public function __construct()
        {
            $this->egg = (object) ['name' => 'Custom application'];
        }

        public function variables(): object
        {
            $this->relationQueries++;

            return new class($this->boundVariables) {
                public function __construct(private array $values) {}

                public function get(): array
                {
                    return $this->values;
                }
            };
        }

        public function __get(string $name): never
        {
            throw new LogicException('Unexpected unbound relationship access: ' . $name);
        }

        public function load(mixed ...$relations): never
        {
            throw new LogicException('Variables must be queried through the bound server relation.');
        }
    }

    class User
    {
        public function __construct(public array $permissions, public int $serverId = 1) {}

        public function can(SubuserPermission|string $permission, Server $server): bool
        {
            $value = $permission instanceof SubuserPermission ? $permission->value : $permission;

            return $server->id === $this->serverId && in_array($value, $this->permissions, true);
        }
    }
}

namespace Illuminate\Validation {
    class ValidationException extends \RuntimeException
    {
        public array $messages = [];

        public static function withMessages(array $messages): self
        {
            $exception = new self((string) reset($messages));
            $exception->messages = $messages;

            return $exception;
        }

        public function errors(): array
        {
            return $this->messages;
        }
    }
}

namespace Illuminate\Support\Facades {
    class File
    {
        public static array $files = [];
        public static array $modes = [];

        public static function exists(string $path): bool
        {
            return array_key_exists($path, self::$files);
        }

        public static function get(string $path): string
        {
            return self::$files[$path];
        }

        public static function ensureDirectoryExists(string $path, int $mode = 0755): void
        {
            self::$modes[$path] = $mode;
        }

        public static function replace(string $path, string $content, ?int $mode = null): void
        {
            self::$files[$path] = $content;
            self::$modes[$path] = $mode;
        }
    }

    class Crypt
    {
        private static array $values = [];

        public static function encryptString(string $value): string
        {
            $opaque = 'test-encrypted-' . bin2hex(random_bytes(16));
            self::$values[$opaque] = $value;

            return $opaque;
        }

        public static function decryptString(string $value): string
        {
            if (!array_key_exists($value, self::$values)) {
                throw new \RuntimeException('Unreadable ciphertext.');
            }

            return self::$values[$value];
        }
    }

    class Validator
    {
        public static function make(array $data, array $rules): object
        {
            return new class($data, $rules) {
                public function __construct(private array $data, private array $rules) {}

                public function validate(): array
                {
                    // Framework validation itself belongs to Pelican's test suite.
                    return array_intersect_key($this->data, $this->rules);
                }
            };
        }
    }

    class Http
    {
        public static ?object $response = null;
        public static array $calls = [];
        public static ?\Throwable $failure = null;
        /** Replies returned in order before falling back to $response. */
        public static array $queue = [];

        public static function acceptJson(): object
        {
            self::$calls = [['acceptJson']];

            return new class {
                public function __call(string $method, array $arguments): mixed
                {
                    Http::$calls[] = [$method, ...$arguments];
                    if ($method === 'post') {
                        if (Http::$queue !== []) {
                            $next = array_shift(Http::$queue);
                            if ($next instanceof \Throwable) {
                                throw $next;
                            }

                            return $next;
                        }
                        if (Http::$failure !== null) {
                            throw Http::$failure;
                        }

                        return Http::$response;
                    }

                    return $this;
                }
            };
        }
    }
}

namespace {
    class IntegrationAbortException extends RuntimeException
    {
        public function __construct(public int $status)
        {
            parent::__construct('HTTP ' . $status);
        }
    }

    function abort_unless(mixed $condition, int $status): void
    {
        if (!$condition) {
            throw new IntegrationAbortException($status);
        }
    }

    function storage_path(string $path = ''): string
    {
        return '/private-integration-test/' . $path;
    }

    if (!function_exists('logger')) {
        function logger(): object
        {
            return new class {
                public function warning(string $message, array $context = []): void
                {
                    $GLOBALS['integration_logs'][] = [$message, $context];
                }

                public function info(string $message, array $context = []): void
                {
                    $GLOBALS['integration_info'][] = [$message, $context];
                }
            };
        }
    }
}
