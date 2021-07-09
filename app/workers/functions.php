<?php

use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Event\Event;
use Appwrite\Resque\Worker;
use Appwrite\Utopia\Response\Model\Execution;
use Cron\CronExpression;
use Swoole\Runtime;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;

require_once __DIR__.'/../workers.php';

Runtime::enableCoroutine(0);

Console::title('Functions V1 Worker');
Console::success(APP_NAME.' functions worker v1 has started');

$runtimes = Config::getParam('runtimes');

/**
 * Warmup Docker Images
 */
$warmupStart = \microtime(true);

Co\run(function() use ($runtimes) {  // Warmup: make sure images are ready to run fast 🚀

    $dockerUser = App::getEnv('DOCKERHUB_PULL_USERNAME', null);
    $dockerPass = App::getEnv('DOCKERHUB_PULL_PASSWORD', null);
    $dockerEmail = App::getEnv('DOCKERHUB_PULL_EMAIL', null);
    $dockerToken = null;

    if($dockerUser) {
        $stdout = '';
        $stderr = '';

        //Console::execute('docker login --username '.$dockerUser.' --password-stdin', $dockerPass, $stdout, $stderr);
        //Console::log('Docker Login'. $stdout.$stderr);

        /**
         * Login to Docker Hub
         */

        $ch = \curl_init();
        \curl_setopt($ch, CURLOPT_URL, "http://localhost/auth");
        \curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        \curl_setopt($ch, CURLOPT_POST, 1);

        $body = array(
            "username" => $dockerUser,
            "password" => $dockerPass
        );
        \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($body));

        $headers = [
            'Content-Type: application/json',
            'Content-Length: ' . \strlen(\json_encode($body))
        ];
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = \curl_exec($ch);
        $responseCode = \curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($responseCode == 204 || $responseCode == 200) {
            Console::info("Successfully authenticated as {$dockerUser}!");

            $TokenData = array(
                "username" => $dockerUser,
                "password" => $dockerPass,
                "auth" => "",
                "email" => $dockerEmail
            );

            $dockerToken = base64_encode(json_encode($TokenData));
        } else {
            Console::error('Failed to sign in to Docker Hub. Please check your login credentials and try again!');
        }
    }

    foreach($runtimes as $runtime) {
        go(function() use ($runtime, $dockerToken) {       
            Console::info('Warming up '.$runtime['name'].' '.$runtime['version'].' environment...');
        
            /*
             * Pull image using Docker API
             */
            $ch = \curl_init();
            \curl_setopt($ch, CURLOPT_URL, "http://localhost/images/create");
            \curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            \curl_setopt($ch, CURLOPT_POST, 1);
            
            $body = array(
                "fromImage" => $runtime['image']
            );

            \curl_setopt($ch, CURLOPT_POSTFIELDS, \http_build_query($body));

            if ($dockerToken) {
                $headers = array (
                    "X-Registry-Auth: " . $dockerToken
                );

                \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            };
    
            $response = \curl_exec($ch);
            $JSONChunks = \explode("\n", $response);

            foreach($JSONChunks as $chunk) {
                try {
                    $ParsedChunk = \json_decode($chunk, true);
                    if (isset($ParsedChunk['status'])) {
                        Console::log(\strval($ParsedChunk['status']));
                    }
                } catch (Exception $e) {
                    Console::error("Something went wrong processing a Docker Status Message: {$e}");
                }
            }

            if (\curl_getinfo($ch, CURLINFO_RESPONSE_CODE) !== 200) {
                $data = \json_decode($response, true);
                if (isset($data['message'])) {
                    Console::error('Something went wrong warming up the: '.$runtime['name'].' '.$runtime['version'].' Enviroment. Error: '.$data["message"]);
                } else {
                    Console::error('Something went wrong warming up the: '.$runtime['name'].' '.$runtime['version'].' Enviroment. Internal Docker Error.');
                }
            }
        });
    }
});

$warmupEnd = \microtime(true);
$warmupTime = $warmupEnd - $warmupStart;

Console::success('Finished warmup in '.$warmupTime.' seconds');

/**
 * List function servers
 */

$executionStart = \microtime(true);

/*
 * Get containers running
 */
$body = array(
    "filters" => json_encode(array('label' => array('appwrite-type=function'))),
    "all" => true
);

$ch = \curl_init();
\curl_setopt($ch, CURLOPT_URL, "http://localhost/containers/json".'?'.\http_build_query($body));
\curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
\curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    
$response = \curl_exec($ch);
$responseCode = \curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$list = [];

\array_map(function($value) use (&$list) {
    $container = [];

    $container = $value;

    if(isset($container['Names'][0])) {
        $container = [
            'name' => ltrim($container['Names'][0], '/'),
            'online' => (\substr($container['Status'], 0, 2) === 'Up'),
            'status' => $container['Status'],
            'labels' => $container['Labels'],
        ];

        \array_map(function($key) use (&$container) {
            if(isset($key) && isset($container['labels'][$key])) {
                $container[$key] = $container['labels'][$key];
            }
        }, array_keys($container['labels']));

        $list[$container['name']] = $container;
    }
}, \json_decode($response, true));

$executionEnd = \microtime(true);

Console::info(count($list)." functions listed in " . ($executionEnd - $executionStart) . " seconds with status code {$responseCode}");

/**
 * 1. Get event args - DONE
 * 2. Unpackage code in the isolated container - DONE
 * 3. Execute in container with timeout
 *      + messure execution time - DONE
 *      + pass env vars - DONE
 *      + pass one-time api key
 * 4. Update execution status - DONE
 * 5. Update execution stdout & stderr - DONE
 * 6. Trigger audit log - DONE
 * 7. Trigger usage log - DONE
 */

//TODO aviod scheduled execution if delay is bigger than X offest

class FunctionsV1 extends Worker
{
    public $args = [];

    public $allowed = [];

    public function init(): void
    {
    }

    public function run(): void
    {
        global $register;

        $db = $register->get('db');
        $cache = $register->get('cache');

        $projectId = $this->args['projectId'] ?? '';
        $functionId = $this->args['functionId'] ?? '';
        $webhooks = $this->args['webhooks'] ?? [];
        $executionId = $this->args['executionId'] ?? '';
        $trigger = $this->args['trigger'] ?? '';
        $event = $this->args['event'] ?? '';
        $scheduleOriginal = $this->args['scheduleOriginal'] ?? '';
        $eventData = (!empty($this->args['eventData'])) ? json_encode($this->args['eventData']) : '';
        $data = $this->args['data'] ?? '';
        $userId = $this->args['userId'] ?? '';
        $jwt = $this->args['jwt'] ?? '';

        $database = new Database();
        $database->setAdapter(new RedisAdapter(new MySQLAdapter($db, $cache), $cache));
        $database->setNamespace('app_'.$projectId);
        $database->setMocks(Config::getParam('collections', []));

        switch ($trigger) {
            case 'event':
                $limit = 30;
                $sum = 30;
                $offset = 0;
                $functions = []; /** @var Document[] $functions */

                while ($sum >= $limit) {

                    Authorization::disable();

                    $functions = $database->getCollection([
                        'limit' => $limit,
                        'offset' => $offset,
                        'orderField' => 'name',
                        'orderType' => 'ASC',
                        'orderCast' => 'string',
                        'filters' => [
                            '$collection='.Database::SYSTEM_COLLECTION_FUNCTIONS,
                        ],
                    ]);

                    Authorization::reset();

                    $sum = \count($functions);
                    $offset = $offset + $limit;

                    Console::log('Fetched '.$sum.' functions...');

                    foreach($functions as $function) {
                        $events =  $function->getAttribute('events', []);
                        $tag =  $function->getAttribute('tag', []);

                        Console::success('Itterating function: '.$function->getAttribute('name'));

                        if(!\in_array($event, $events) || empty($tag)) {
                            continue;
                        }

                        Console::success('Triggered function: '.$event);

                        $this->execute('event', $projectId, '', $database, $function, $event, $eventData, $data, $webhooks, $userId, $jwt);
                    }
                }
                break;

            case 'schedule':
                /*
                 * 1. Get Original Task
                 * 2. Check for updates
                 *  If has updates skip task and don't reschedule
                 *  If status not equal to play skip task
                 * 3. Check next run date, update task and add new job at the given date
                 * 4. Execute task (set optional timeout)
                 * 5. Update task response to log
                 *      On success reset error count
                 *      On failure add error count
                 *      If error count bigger than allowed change status to pause
                 */

                // Reschedule
                Authorization::disable();
                $function = $database->getDocument($functionId);
                Authorization::reset();

                if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
                    throw new Exception('Function not found ('.$functionId.')');
                }

                if($scheduleOriginal && $scheduleOriginal !== $function->getAttribute('schedule')) { // Schedule has changed from previous run, ignore this run.
                    return;
                }

                $cron = new CronExpression($function->getAttribute('schedule'));
                $next = (int) $cron->getNextRunDate()->format('U');

                $function
                    ->setAttribute('scheduleNext', $next)
                    ->setAttribute('schedulePrevious', \time())
                ;

                Authorization::disable();

                $function = $database->updateDocument(array_merge($function->getArrayCopy(), [
                    'scheduleNext' => $next,
                ]));

                Authorization::reset();

                ResqueScheduler::enqueueAt($next, 'v1-functions', 'FunctionsV1', [
                    'projectId' => $projectId,
                    'webhooks' => $webhooks,
                    'functionId' => $function->getId(),
                    'executionId' => null,
                    'trigger' => 'schedule',
                    'scheduleOriginal' => $function->getAttribute('schedule', ''),
                ]);  // Async task rescheduale

                $this->execute($trigger, $projectId, $executionId, $database, $function, /*$event*/'', /*$eventData*/'', $data, $webhooks, $userId, $jwt);
                break;

            case 'http':
                Authorization::disable();
                $function = $database->getDocument($functionId);
                Authorization::reset();

                if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
                    throw new Exception('Function not found ('.$functionId.')');
                }

                $this->execute($trigger, $projectId, $executionId, $database, $function, /*$event*/'', /*$eventData*/'', $data, $webhooks, $userId, $jwt);
                break;
            
            default:
                # code...
                break;
        }
    }

    /**
     * Execute function tag
     * 
     * @param string $trigger
     * @param string $projectId
     * @param string $executionId
     * @param Database $database
     * @param Database $function
     * @param string $event
     * @param string $eventData
     * @param string $data
     * @param array $webhooks
     * @param string $userId
     * @param string $jwt
     * 
     * @return void
     */
    public function execute(string $trigger, string $projectId, string $executionId, Database $database, Document $function, string $event = '', string $eventData = '', string $data = '', array $webhooks = [], string $userId = '', string $jwt = ''): void
    {
        global $list;

        $runtimes = Config::getParam('runtimes');

        Authorization::disable();
        $tag = $database->getDocument($function->getAttribute('tag', ''));
        Authorization::reset();

        if($tag->getAttribute('functionId') !== $function->getId()) {
            throw new Exception('Tag not found', 404);
        }

        Authorization::disable();

        $execution = (!empty($executionId)) ? $database->getDocument($executionId) : $database->createDocument([
            '$collection' => Database::SYSTEM_COLLECTION_EXECUTIONS,
            '$permissions' => [
                'read' => [],
                'write' => [],
            ],
            'dateCreated' => time(),
            'functionId' => $function->getId(),
            'trigger' => $trigger, // http / schedule / event
            'status' => 'processing', // waiting / processing / completed / failed
            'exitCode' => 0,
            'stdout' => '',
            'stderr' => '',
            'time' => 0,
        ]);

        if(false === $execution || ($execution instanceof Document && $execution->isEmpty())) {
            throw new Exception('Failed to create or read execution');
        }
        
        Authorization::reset();

        $runtime = (isset($runtimes[$function->getAttribute('runtime', '')]))
            ? $runtimes[$function->getAttribute('runtime', '')]
            : null;

        if(\is_null($runtime)) {
            throw new Exception('Runtime "'.$function->getAttribute('runtime', '').' is not supported');
        }

        $vars = \array_merge($function->getAttribute('vars', []), [
            'APPWRITE_FUNCTION_ID' => $function->getId(),
            'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name', ''),
            'APPWRITE_FUNCTION_TAG' => $tag->getId(),
            'APPWRITE_FUNCTION_TRIGGER' => $trigger,
            'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'],
            'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'],
            'APPWRITE_FUNCTION_EVENT' => $event,
            'APPWRITE_FUNCTION_EVENT_DATA' => $eventData,
            'APPWRITE_FUNCTION_DATA' => $data,
            'APPWRITE_FUNCTION_USER_ID' => $userId,
            'APPWRITE_FUNCTION_JWT' => $jwt,
            'APPWRITE_FUNCTION_PROJECT_ID' => $projectId,
        ]);

        $apiVars = $vars; // Env vars must be in different formats for API calls and CLI commands

        // Env vars for API calls
        \array_walk($apiVars, function (&$value, $key) {
            $key = $this->filterEnvKey($key);
            $value = \escapeshellarg((empty($value)) ? 'null' : $value);
            $value = "{$key}={$value}";
        });

        // Env vars for Docker CLI commands
        \array_walk($vars, function (&$value, $key) {
            $key = $this->filterEnvKey($key);
            $value = \escapeshellarg((empty($value)) ? '' : $value);
            $value = "--env {$key}={$value}";
        });

        $tagPath = $tag->getAttribute('path', '');
        $tagPathTarget = '/tmp/project-'.$projectId.'/'.$tag->getId().'/code.tar.gz';
        $tagPathTargetDir = \pathinfo($tagPathTarget, PATHINFO_DIRNAME);
        $container = 'appwrite-function-'.$tag->getId();
        $command = \escapeshellcmd($tag->getAttribute('command', ''));

        if(!\is_readable($tagPath)) {
            throw new Exception('Code is not readable: '.$tag->getAttribute('path', ''));
        }

        if (!\file_exists($tagPathTargetDir)) {
            if (!\mkdir($tagPathTargetDir, 0755, true)) {
                throw new Exception('Can\'t create directory '.$tagPathTargetDir);
            }
        }
        
        if (!\file_exists($tagPathTarget)) {
            if(!\copy($tagPath, $tagPathTarget)) {
                throw new Exception('Can\'t create temporary code file '.$tagPathTarget);
            }
        }

        if(isset($list[$container]) && !$list[$container]['online']) { // Remove conatiner if not online
            /*
             * Remove container using Docker API
             */
            $ch = \curl_init();
            \curl_setopt($ch, CURLOPT_URL, "http://localhost/containers/".$container);
            \curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE"); 
    
            $response = \curl_exec($ch);

            $responseCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            if ($responseCode !== 204) {
                $data = json_decode($response, true);
                if (isset($data['message'])) {
                    throw new Exception('Failed to remove offline container: '.$data["message"]);
                } else {
                    throw new Exception('Failed to remove offline container: Internal Docker Error');
                }
            }

            unset($list[$container]);
        }

        /**
         * Limit CPU Usage - DONE
         * Limit Memory Usage - DONE
         * Limit Network Usage
         * Limit Storage Usage (//--storage-opt size=120m \)
         * Make sure no access to redis, mariadb, influxdb or other system services
         * Make sure no access to NFS server / storage volumes
         * Access Appwrite REST from internal network for improved performance
         */
        if(!isset($list[$container])) { // Create contianer if not ready
            $stdout = '';
            $stderr = '';
    
            $executionStart = \microtime(true);
            $executionTime = \time();
            $cpus = App::getEnv('_APP_FUNCTIONS_CPUS', '');
            $memory = App::getEnv('_APP_FUNCTIONS_MEMORY', '');
            $swap = App::getEnv('_APP_FUNCTIONS_MEMORY_SWAP', '');
            $exitCode = Console::execute("docker run ".
                " -d".
                " --entrypoint=\"\"".
                (empty($cpus) ? "" : (" --cpus=".$cpus)).
                (empty($memory) ? "" : (" --memory=".$memory."m")).
                (empty($swap) ? "" : (" --memory-swap=".$swap."m")).
                " --name={$container}".
                " --label appwrite-type=function".
                " --label appwrite-created={$executionTime}".
                " --volume {$tagPathTargetDir}:/tmp:rw".
                " --workdir /usr/local/src".
                " ".\implode(" ", $vars).
                " {$runtime['image']}".
                " sh -c 'mv /tmp/code.tar.gz /usr/local/src/code.tar.gz && tar -zxf /usr/local/src/code.tar.gz --strip 1 && rm /usr/local/src/code.tar.gz && tail -f /dev/null'"
            , '', $stdout, $stderr, 30);

            $executionEnd = \microtime(true);
    
            if($exitCode !== 0) {
                throw new Exception('Failed to create function environment: '.$stderr);
            }

            $list[$container] = [
                'name' => $container,
                'online' => true,
                'status' => 'Up',
                'labels' => [
                    'appwrite-type' => 'function',
                    'appwrite-created' => $executionTime,
                ],
            ];

            Console::info("Function created in " . ($executionEnd - $executionStart) . " seconds with exit code {$exitCode}");
        }
        else {
            Console::info('Container is ready to run');
        }
        
        /*
         * Create execution via Docker API
         */
        $ch = \curl_init();
        \curl_setopt($ch, CURLOPT_URL, "http://localhost/containers/{$container}/exec");
        \curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        \curl_setopt($ch, CURLOPT_POST, 1);

        $body = array(
            "Env" => \array_values($apiVars),
            "Cmd" => \explode(' ', $command),
            "AttachStdout" => true,
            "AttachStderr" => true
        );
        \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($body));

        $headers = [
            'Content-Type: application/json',
            'Content-Length: ' . \strlen(\json_encode($body))
        ];
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = \curl_exec($ch);
        $resultParsed = \json_decode($result, true);
        $execId = $resultParsed['Id'];

        if (\curl_errno($ch)) {
            throw new Exception('Failed to create execution: ' . \curl_error($ch), 500);
        }

        \curl_close($ch);

        /*
         * Start execution without detatching - receives stdout/stderr as stream
         */

        $executionStart = \microtime(true);
        $ch = \curl_init();
        $URL = "http://localhost/exec/{$execId}/start";
        \curl_setopt($ch, CURLOPT_URL, $URL);
        \curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
        \curl_setopt($ch, CURLOPT_POST, 1);
        \curl_setopt($ch, CURLOPT_POSTFIELDS, '{}'); // body is required
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        \curl_setopt($ch, CURLOPT_TIMEOUT, $function->getAttribute('timeout', (int) App::getEnv('_APP_FUNCTIONS_TIMEOUT', 900)));

        $headers = [
            'Content-Type: application/json',
            'Content-Length: 2',
        ];
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        /*
         * Exec logs come back with STDOUT+STDERR multiplexed into a single stream.
         * Each frame of the stream has the following format: 
         *   header := [8]byte{STREAM_TYPE, 0, 0, 0, SIZE1, SIZE2, SIZE3, SIZE4}
         *     STREAM_TYPE is of the following: [0=>'stdin', 1=>'stdout', 2=>'stderr']
         *     SIZE1, SIZE2, SIZE3, SIZE4 are the four bytes of the uint32 size encoded as big endian.
         *     Following the header is the payload, which is the specified number of bytes of STREAM_TYPE.
         *
         * To assign the appropriate stream:
         *   - unpack as an unsigned char ('C*')
         *   - check the first byte of the header to assign stream
         *   - pack up stream, omitting the 8 bytes of header
         *   - concat to stream
         */

        $stdout = '';
        $stderr = '';

        $callback = function ($ch, $str) use (&$stdout, &$stderr) {
            $rawStream = unpack('C*', $str);
            $stream = $rawStream[1]; // 1-based index, not 0-based
            switch ($stream) { // only 1 or 2, as set while creating exec 
                case 1:
                    $packed = pack('C*', ...\array_slice($rawStream, 8));
                    $stdout .= $packed;
                    break;
                case 2:
                    $packed = pack('C*', ...\array_slice($rawStream, 8));
                    $stderr .= $packed;
                    break;
            }
            return strlen($str); // must return full frame from callback
        };
        \curl_setopt($ch, CURLOPT_WRITEFUNCTION, $callback);


        $execData = \curl_exec($ch);

        $timedout = false;

        if (\curl_errno($ch)) {
            if (\curl_errno($ch) == 28) {
                $timedout = true;
            } else {
                throw new Exception('Failed to run execution: ' . \curl_error($ch), 500);
            }
        }

        \curl_close($ch);

        $executionEnd = \microtime(true);

        /*
         * Get execution status code 
         */
        $ch = \curl_init();

        $URL = "http://localhost/exec/{$execId}/json";
        \curl_setopt($ch, CURLOPT_URL, $URL);
        \curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $headers = array();
        $headers[] = 'Content-Type: application/json';
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = \curl_exec($ch);
        $resultParsed = \json_decode($result, true);
        $exitCode = $resultParsed['ExitCode'];
        $execRunning = $resultParsed['Running']; //bool
        $execPid = $resultParsed['Pid'];

        if (\curl_errno($ch)) {
            throw new Exception('Failed to get execution: ' . \curl_error($ch), 500);
        }

        \curl_close($ch);

        /*
         * Kill stray exec process if still running at this point
         */
        $killStdout = '';
        $killStderr = '';
        if ($timedout) {
            // $killProcess = Console::execute("docker exec {$container} kill {$execPid}",'', $killStdout, $killStderr, 900);

            $ch = \curl_init();
            \curl_setopt($ch, CURLOPT_URL, "http://localhost/containers/{$container}/exec");
            \curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            \curl_setopt($ch, CURLOPT_POST, 1);

            $killBody = array(
                "Cmd" => array("kill", "{$execPid}"),
                "AttachStdout" => true,
                "AttachStderr" => true
            );
            \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($killBody));

            $killHeaders = [
                'Content-Type: application/json',
                'Content-Length: ' . \strlen(\json_encode($killBody))
            ];
            \curl_setopt($ch, CURLOPT_HTTPHEADER, $killHeaders);

            $killResult = \curl_exec($ch);
            $killResultParsed = \json_decode($result, true);
    
            if (\curl_errno($ch)) {
                throw new Exception('Failed to kill stray process: ' . \curl_error($ch), 500);
            }

            $killResponseCode = \curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            if ($killResponseCode !== 201) {
                $data = json_decode($response, true);
                if (isset($data['message'])) {
                    Console::error('Failed to kill stray process: '.$data["message"]);
                } else {
                    Console::error('Failed to kill stray process: Internal Docker Error');
                }
            }

            \curl_close($ch);

            // Execute kill process
            /*
             * Start execution without detatching - receives stdout/stderr as stream
             */

            $ch = \curl_init();
            $URL = "http://localhost/exec/{$execId}/start";
            \curl_setopt($ch, CURLOPT_URL, $URL);
            \curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
            \curl_setopt($ch, CURLOPT_POST, 1);
            \curl_setopt($ch, CURLOPT_POSTFIELDS, '{}'); // body is required
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            \curl_setopt($ch, CURLOPT_TIMEOUT, $function->getAttribute('timeout', (int) App::getEnv('_APP_FUNCTIONS_TIMEOUT', 900)));

            $killHeaders = [
                'Content-Type: application/json',
                'Content-Length: 2',
            ];
            \curl_setopt($ch, CURLOPT_HTTPHEADER, $killHeaders);

            /*
             * Exec logs come back with STDOUT+STDERR multiplexed into a single stream.
             * Each frame of the stream has the following format: 
             *   header := [8]byte{STREAM_TYPE, 0, 0, 0, SIZE1, SIZE2, SIZE3, SIZE4}
             *     STREAM_TYPE is of the following: [0=>'stdin', 1=>'stdout', 2=>'stderr']
             *     SIZE1, SIZE2, SIZE3, SIZE4 are the four bytes of the uint32 size encoded as big endian.
             *     Following the header is the payload, which is the specified number of bytes of STREAM_TYPE.
             *
             * To assign the appropriate stream:
             *   - unpack as an unsigned char ('C*')
             *   - check the first byte of the header to assign stream
             *   - pack up stream, omitting the 8 bytes of header
             *   - concat to stream
             */

            $killCallback = function ($ch, $str) use (&$killStdout, &$killStderr) {
                $rawStream = unpack('C*', $str);
                $stream = $rawStream[1]; // 1-based index, not 0-based
                switch ($stream) { // only 1 or 2, as set while creating exec 
                    case 1:
                        $packed = pack('C*', ...\array_slice($rawStream, 8));
                        $killStdout .= $packed;
                        break;
                    case 2:
                        $packed = pack('C*', ...\array_slice($rawStream, 8));
                        $killStderr .= $packed;
                        break;
                }
                return strlen($str); // must return full frame from callback
            };
            \curl_setopt($ch, CURLOPT_WRITEFUNCTION, $killCallback);

            $killExecData = \curl_exec($ch);

            $exitCode = 1; // 124 is Arbitrary, but borrowed from linux timeout EXIT_TIMEDOUT
        }

        $functionStatus = ($exitCode === 0) ? 'completed' : 'failed';
        $executionTime = $executionEnd - $executionStart;

        Console::info("Function executed in " . ($executionTime) . " seconds with exit code {$exitCode}");

        Authorization::disable();
        
        $execution = $database->updateDocument(array_merge($execution->getArrayCopy(), [
            'tagId' => $tag->getId(),
            'status' => $functionStatus,
            'exitCode' => $exitCode,
            'stdout' => \mb_substr($stdout, -4000) ?? '',
            'stderr' => \mb_substr($stderr, -4000) ?? '',
            'time' => $executionTime,
        ]));
        
        Authorization::reset();

        if (false === $function) {
            throw new Exception('Failed saving execution to DB', 500);
        }

        $executionModel = new Execution();
        $executionUpdate = new Event('v1-webhooks', 'WebhooksV1');

        $executionUpdate
            ->setParam('projectId', $projectId)
            ->setParam('userId', $userId)
            ->setParam('webhooks', $webhooks)
            ->setParam('event', 'functions.executions.update')
            ->setParam('eventData', $execution->getArrayCopy(array_keys($executionModel->getRules())));

        $executionUpdate->trigger();

        $usage = new Event('v1-usage', 'UsageV1');

        $usage
            ->setParam('projectId', $projectId)
            ->setParam('functionId', $function->getId())
            ->setParam('functionExecution', 1)
            ->setParam('functionStatus', $functionStatus)
            ->setParam('functionExecutionTime', $executionTime * 1000) // ms
            ->setParam('networkRequestSize', 0)
            ->setParam('networkResponseSize', 0)
        ;
        
        if(App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled') {
            $usage->trigger();
        }

        $this->cleanup();
    }

    /**
     * Cleanup any hanging containers above the allowed max containers.
     * 
     * @return void
     */
    public function cleanup(): void
    {
        global $list;

        Console::success(count($list).' running containers counted');

        $max = (int) App::getEnv('_APP_FUNCTIONS_CONTAINERS');

        if(\count($list) > $max) {
            Console::info('Starting containers cleanup');

            \uasort($list, function ($item1, $item2) {
                return (int)($item1['Labels']['appwrite-created'] ?? 0) <=> (int)($item2['Labels']['appwrite-created'] ?? 0);
            });

            while(\count($list) > $max) {
                $first = \array_shift($list);
                $stdout = '';
                $stderr = '';

                /*
                 * Remove container using Docker API
                 */
                $ch = \curl_init();
                \curl_setopt($ch, CURLOPT_URL, "http://localhost/containers/".$first['name']."?force=true");
                \curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
                \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");

                $response = \curl_exec($ch);

                $responseCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

                if ($responseCode !== 204) {
                    $data = json_decode($response, true);
                    if (isset($data['message'])) {
                        Console::error('Failed to remove container: '.$data["message"]);
                    } else {
                        Console::error('Failed to remove container: Internal Docker Error');
                    }
                } else {
                    Console::info('Removed container: '.$first['name']);
                }
            }
        }
    }

    /**
     * Filter ENV vars
     * 
     * @param string $string
     * 
     * @return string
     */
    public function filterEnvKey(string $string): string
    {
        if(empty($this->allowed)) {
            $this->allowed = array_fill_keys(\str_split('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_'), true);
        }

        $string     = \str_split($string);
        $output     = '';

        foreach ($string as $char) {
            if(\array_key_exists($char, $this->allowed)) {
                $output .= $char;
            }
        }

        return $output;
    }

    public function shutdown(): void
    {
    }
}