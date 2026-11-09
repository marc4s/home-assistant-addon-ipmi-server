<?php

/**
 * @author Adrian Teodorescu (ateodorescu@gmail.com)
 *
 * This class allows interaction with `ipmitool` executable.
 */

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;

class IpmiController
{
    private array $ipmiTypes = ["lanplus", "lan", "imb", "open"];
    private array $sensorTypes = [
        'temperature' => 'degrees C',
        'voltage' => 'Volts',
        'fan' => 'RPM'
    ];
    private array $unitsOfMeasure = [
        'degrees C' => 'temperature',
        'Volts' => 'voltage',
        'RPM' => 'fan',
        'Amps' => 'current',
        'Watts' => 'power'
    ];
    private array $debug = [];
    private string $password = '';
    private string $kgKey = '';
    
    /**
     * Add debug message to the debug array.
     */
    private function addDebug(string $message): void
    {
        $this->debug[] = $message;
        error_log('[IPMI Debug] ' . $message);
    }
    
    /**
     * Add debug message with data (will anonymize secrets).
     */
    private function addDebugWithData(string $message, array $data = []): void
    {
        $dataStr = $data ? ' ' . json_encode($this->anonymizeDebugData($data)) : '';
        $this->addDebug($message . $dataStr);
    }
    
    /**
     * Anonymize data for debugging (replace secrets with ####).
     */
    private function anonymizeDebugData(array $data): array
    {
        $anonymized = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, ['password', 'passwd', 'pass', 'pwd', 'secret', 'key', 'kg_key', 'kgkey'])) {
                $anonymized[$key] = is_string($value) ? str_repeat('*', strlen($value)) : $value;
            } else {
                $anonymized[$key] = $value;
            }
        }
        return $anonymized;
    }
    // Hard ceiling per ipmitool invoke. Keep below nginx/php-fpm (120s).
    const COMMAND_TIMEOUT = 45;
    const DEFAULT_PORT = 623;
    public const PASSWORD_HEADER = 'X-Ipmi-Password';
    public const KG_KEY_HEADER = 'X-Ipmi-Kg-Key';
    public const API_VERSION = 1;
    public const ADDON_VERSION = '2.7.6';
    public const CAPABILITY_RESILIENT_POLL = 'resilient_poll';
    public const CAPABILITY_SENSOR_TYPES_FILTER = 'sensor_types_filter';
    public const CAPABILITY_STATUSES = 'statuses';

    /** @var list<string> */
    private const API_CAPABILITIES = [
        self::CAPABILITY_RESILIENT_POLL,
        self::CAPABILITY_SENSOR_TYPES_FILTER,
        self::CAPABILITY_STATUSES,
    ];

    public function meta(): JsonResponse
    {
        return new JsonResponse($this->apiMetadata());
    }

    public function index(Request $request): Response
    {
        // Browsers opening http://host:9595/ expect the Web UI; keep JSON for API clients.
        if ($this->shouldRedirectRootToUi($request)) {
            return new RedirectResponse('/ui');
        }

        // DEBUG: Log the incoming request details
        $this->addDebug('=== INCOMING REQUEST DEBUG ===');
        $this->addDebug('Method: ' . $request->getMethod());
        $this->addDebug('URI: ' . $request->getUri());
        $this->addDebug('Query params: ' . json_encode($request->query->all()));
        $this->addDebug('Request headers: ' . json_encode($request->headers->all()));
        
        if (empty($request->query->get('host'))) {
            $this->addDebug('Request rejected: No host provided');
            return $this->finalizeJsonResponse([
                'success' => false,
                'message' => 'No hostname provided! Open /ui for the web UI, or pass ?host=... for the JSON API.',
            ]);
        }

        $this->addDebug('=== PROCESSING REQUEST ===');
        $this->hydrateSecrets($request);
        $requestedTypes = $this->parseRequestedSensorTypes($request);
        $this->addDebug('Requested types: ' . json_encode($requestedTypes));
        $info = $this->getDeviceInfo($request, $requestedTypes);
        
        $this->addDebug('Device info result: ' . json_encode($info));

        if ($info['success']) {
            if ($this->isPowerOnlyPoll($requestedTypes)) {
                $info = array_merge($info, $this->emptySensorPayload());
            } else {
                $sensors = $this->getSensors($request, $requestedTypes);
                $this->addDebug('Sensors result: ' . json_encode($sensors));
                if (!empty($sensors['success'])) {
                    $info['sensors'] = $sensors['sensors'] ?? $this->emptySensorBuckets();
                    $info['states'] = $sensors['states'] ?? [];
                    $info['statuses'] = $sensors['statuses'] ?? [];
                } else {
                    // Pre-2.6.0 clients treat success=true as a complete poll and
                    // will not fall back to RMCP. Empty buckets would drop sensors.
                    $info['success'] = false;
                    if (!empty($sensors['message'])) {
                        $info['message'] = $sensors['message'];
                    }
                }
            }
        } else {
            $this->addDebug('Device info failed: ' . ($info['message'] ?? 'unknown'));
        }

        $this->addDebug('=== FINAL RESPONSE ===');
        $this->addDebug('Response success: ' . ($info['success'] ? 'true' : 'false'));
        $this->addDebug('Response message: ' . ($info['message'] ?? 'none'));

        return $this->finalizeJsonResponse($info);
    }

    public function command(Request $request): JsonResponse
    {
        $this->hydrateSecrets($request);
        $cmd = str_getcsv($request->query->get('params', ''), ' ', '"', '');
        $this->captureSecretsFromCommandArgs($cmd);
        $cmd = $this->mergeSecretArgs($cmd);
        array_unshift($cmd, 'ipmitool');
        $ret = $this->runCommand($cmd);
        $done = ($ret !== false);

        return $this->finalizeJsonResponse([
            'success' => $done,
            'output' => $done ? $ret : implode("\n", $this->debug)
        ]);
    }

    public function sensors(Request $request): JsonResponse
    {
        $this->hydrateSecrets($request);
        $requestedTypes = $this->parseRequestedSensorTypes($request);
        if ($this->isPowerOnlyPoll($requestedTypes)) {
            return $this->finalizeJsonResponse(array_merge(
                ['success' => true],
                $this->emptySensorPayload()
            ));
        }

        $response = $this->getSensors($request, $requestedTypes);
        if (!empty($response['success'])) {
            $response['sensors'] = $response['sensors'] ?? $this->emptySensorBuckets();
            $response['states'] = $response['states'] ?? [];
            $response['statuses'] = $response['statuses'] ?? [];
        }

        return $this->finalizeJsonResponse($response);
    }

    public function power_on(Request $request): JsonResponse
    {
        return $this->runChassisCommand($request, 'on');
    }

    public function power_off(Request $request): JsonResponse
    {
        return $this->runChassisCommand($request, 'off');
    }

    public function power_cycle(Request $request): JsonResponse
    {
        return $this->runChassisCommand($request, 'cycle');
    }

    public function power_reset(Request $request): JsonResponse
    {
        return $this->runChassisCommand($request, 'reset');
    }

    public function soft_shutdown(Request $request): JsonResponse
    {
        return $this->runChassisCommand($request, 'soft');
    }

    private function generateId($name): string
    {
        $id = preg_replace("/[^A-Za-z0-9 _]/", '', $name);

        return strtolower(str_replace(' ', '_', $id));
    }

    /**
     * @return array<string, mixed>
     */
    private function apiMetadata(): array
    {
        return [
            'api_version' => self::API_VERSION,
            'addon_version' => self::ADDON_VERSION,
            'capabilities' => self::API_CAPABILITIES,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function finalizeJsonResponse(array $payload): JsonResponse
    {
        $this->addDebug('=== FINALIZE JSON RESPONSE DEBUG ===');
        $this->addDebug('Payload before merge: ' . json_encode($payload));
        
        $payload = array_merge($this->apiMetadata(), $payload);
        $payload['debug'] = implode("\n", $this->debug);

        if (array_key_exists('message', $payload)) {
            $payload['message'] = $this->anonymizeSecrets((string) $payload['message']);
        }

        $this->addDebug('Final response success: ' . ($payload['success'] ? 'true' : 'false'));
        $this->addDebug('Final response message: ' . ($payload['message'] ?? 'none'));

        return new JsonResponse($payload);
    }

    /**
     * Standalone / direct :9595 visits hit GET / with Accept: text/html and no host.
     * Redirect those to /ui; leave JSON API clients (curl, HA integration) alone.
     */
    private function shouldRedirectRootToUi(Request $request): bool
    {
        if (!empty($request->query->get('host'))) {
            return false;
        }

        $accept = (string) $request->headers->get('Accept', '');

        return str_contains($accept, 'text/html');
    }

    /**
     * Parse sensor_types query param.
     *
     * null  = omitted (legacy full discovery)
     * []    = explicit empty (power / device only)
     * list  = only these integration sensor groups
     *
     * @return list<string>|null
     */
    private function parseRequestedSensorTypes(Request $request): ?array
    {
        if (!$request->query->has('sensor_types')) {
            return null;
        }

        $raw = trim((string) $request->query->get('sensor_types', ''));
        if ($raw === '') {
            return [];
        }

        $requested = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && \in_array($part, $this->allSensorTypeKeys(), true)) {
                $requested[] = $part;
            }
        }

        return array_values(array_unique($requested));
    }

    /**
     * @return list<string>
     */
    private function allSensorTypeKeys(): array
    {
        return array_values(array_unique(array_merge(
            array_values($this->unitsOfMeasure),
            ['time']
        )));
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function emptySensorBuckets(): array
    {
        $buckets = [];
        foreach ($this->allSensorTypeKeys() as $type) {
            $buckets[$type] = [];
        }

        return $buckets;
    }

    /**
     * @return array{sensors: array<string, array<string, string>>, states: array<string, mixed>, statuses: array<string, string>}
     */
    private function emptySensorPayload(): array
    {
        return [
            'sensors' => $this->emptySensorBuckets(),
            'states' => [],
            'statuses' => [],
        ];
    }

    /**
     * @param list<string>|null $requestedTypes
     */
    private function shouldIncludeSensorType(?array $requestedTypes, string $type): bool
    {
        if ($requestedTypes === null) {
            return true;
        }

        return \in_array($type, $requestedTypes, true);
    }

    /**
     * Explicit empty sensor_types (power / device only). Omitted param is legacy full discovery.
     *
     * @param list<string>|null $requestedTypes
     */
    private function isPowerOnlyPoll(?array $requestedTypes): bool
    {
        return $requestedTypes !== null && count($requestedTypes) === 0;
    }

    /**
     * @param list<string>|null $requestedTypes
     */
    private function shouldSkipFru(?array $requestedTypes): bool
    {
        return $this->isPowerOnlyPoll($requestedTypes);
    }

    /**
     * @param list<string>|null $requestedTypes
     */
    private function shouldRunSdr(?array $requestedTypes): bool
    {
        if ($requestedTypes === null) {
            return true;
        }

        if (count($requestedTypes) === 0) {
            return false;
        }

        foreach ($requestedTypes as $type) {
            if ($type !== 'power' && $type !== 'time') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string>|null $requestedTypes
     */
    private function shouldRunDcmi(?array $requestedTypes): bool
    {
        if ($requestedTypes === null) {
            return true;
        }

        return \in_array('power', $requestedTypes, true)
            || \in_array('time', $requestedTypes, true);
    }

    /**
     * True when ipmitool returned a parseable reading (including 0 RPM under cr/nc/nr).
     * Skips empty rows and explicit "no reading" placeholders.
     */
    private function hasUsableSensorReading(string $reading): bool
    {
        $reading = trim($reading);
        if ($reading === '') {
            return false;
        }

        return stripos($reading, 'no reading') === false;
    }

    private function anonymizeSecrets(string $message): string
    {
        foreach ([$this->password, $this->kgKey] as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '####', $message);
            }
        }

        return $message;
    }

    private function hydrateSecrets(Request $request): void
    {
        $this->addDebug('=== HYDRATE SECRETS DEBUG ===');
        $this->addDebug('Request method: ' . $request->getMethod());
        
        $this->password = $this->getSecret($request, 'password', self::PASSWORD_HEADER);
        $this->kgKey = $this->getSecret($request, 'kg_key', self::KG_KEY_HEADER);
        
        $this->addDebug('Password set: ' . ($this->password !== '' ? 'YES (length: ' . strlen($this->password) . ')' : 'NO'));
        $this->addDebug('KG Key set: ' . ($this->kgKey !== '' ? 'YES (length: ' . strlen($this->kgKey) . ')' : 'NO'));
    }
    
    private function getSecret(Request $request, string $field, string $header): string
    {
        $fromHeader = $request->headers->get($header);
        if (is_string($fromHeader) && $fromHeader !== '') {
            $this->addDebug("Secret '$field' found in header");
            return $fromHeader;
        }

        $fromBody = $request->request->get($field);
        if (is_string($fromBody) && $fromBody !== '') {
            $this->addDebug("Secret '$field' found in request body");
            return $fromBody;
        }

        $fromQuery = trim((string) $request->query->get($field, ''));
        $this->addDebug("Secret '$field' from query: " . ($fromQuery !== '' ? 'FOUND (length: ' . strlen($fromQuery) . ')' : 'NOT FOUND'));
        return $fromQuery;
    }

    /**
     * @param list<string> $cmd
     *
     * @return list<string>
     */
    private function mergeSecretArgs(array $cmd): array
    {
        $hasPassword = false;
        $hasKgKey = false;

        foreach ($cmd as $part) {
            if ($part === '-P') {
                $hasPassword = true;
            } elseif ($part === '-y') {
                $hasKgKey = true;
            }
        }

        // Insert secrets with connection options, BEFORE the IPMI subcommand
        // (e.g. "bmc info"). Options after the subcommand are ignored by ipmitool.
        $insertAt = $this->findIpmiSubcommandIndex($cmd);

        if (!$hasKgKey && $this->kgKey !== '') {
            array_splice($cmd, $insertAt, 0, ['-y', $this->kgKey]);
        }
        if (!$hasPassword && $this->password !== '') {
            array_splice($cmd, $insertAt, 0, ['-P', $this->password]);
        }

        return $cmd;
    }

    /**
     * Index of the first IPMI subcommand token (non-option), or end of array.
     *
     * @param list<string> $cmd
     */
    private function findIpmiSubcommandIndex(array $cmd): int
    {
        $optionsWithValue = [
            '-A', '-b', '-B', '-C', '-d', '-D', '-e', '-f', '-H', '-I', '-k', '-K',
            '-l', '-L', '-m', '-N', '-o', '-O', '-p', '-P', '-R', '-S', '-t', '-T',
            '-U', '-y', '-z',
        ];

        $count = \count($cmd);
        for ($i = 0; $i < $count; $i++) {
            $part = $cmd[$i];
            if ($part === '' || $part[0] !== '-') {
                return $i;
            }
            if (\in_array($part, $optionsWithValue, true)) {
                $i++; // skip option value
            }
        }

        return $count;
    }

    /**
     * @param list<string> $cmd
     */
    private function captureSecretsFromCommandArgs(array $cmd): void
    {
        foreach ($cmd as $index => $part) {
            $next = $cmd[$index + 1] ?? null;
            if (!is_string($next) || $next === '') {
                continue;
            }

            if ($part === '-P' && $this->password === '') {
                $this->password = $next;
            } elseif ($part === '-y' && $this->kgKey === '') {
                $this->kgKey = $next;
            }
        }
    }

    private function runChassisCommand(Request $request, string $type):JsonResponse
    {
        $this->hydrateSecrets($request);
        $done = false;
        $cmd = $this->getCommand($request);
        $interface = $request->query->get('interface', '');

        if ($cmd !== false) {
            if (empty($interface)) {
                foreach ($this->ipmiTypes as $interface) {
                    $ret = $this->runCommand(array_merge($cmd, ['-I', $interface, 'chassis', 'power', $type]));

                    if ($ret) {
                        $done = true;
                        break;
                    }
                }
            }
            else {
                $ret = $this->runCommand(array_merge($cmd, ['-I', $interface, 'chassis', 'power', $type]));
                $done = $ret !== false;
            }
        }

        return $this->finalizeJsonResponse([
            'success' => $done
        ]);
    }

    private function runCommand($command, $ignoreErrors = false): bool|string
    {
        $this->addDebug('=== RUN COMMAND DEBUG ===');
        $this->addDebug('Command: ' . json_encode($command));
        
        $result = $this->executeIpmiCommand($command, $ignoreErrors);
        if ($result !== false) {
            $this->addDebug('Command succeeded on first attempt');
            return $result;
        }

        // Super Micro / OpenSSL 3: try common RMCP+ cipher suites when default fails.
        // Suite 17 is modern but often unsupported; 3/8/12 are common on older BMCs.
        if (\in_array('lanplus', $command, true) && !\in_array('-C', $command, true)) {
            $this->addDebug('Trying cipher suites as fallback...');
            foreach ([3, 8, 12, 17, 15, 16, 6, 7, 11, 1, 2] as $cipher) {
                $retry = $command;
                $lanplusIdx = array_search('lanplus', $retry, true);
                if ($lanplusIdx === false) {
                    break;
                }
                array_splice($retry, $lanplusIdx + 1, 0, ['-C', (string) $cipher]);
                $this->addDebug('Trying cipher ' . $cipher . '...');
                $result = $this->executeIpmiCommand($retry, true);
                if ($result !== false) {
                    $this->addDebug('Command succeeded with cipher ' . $cipher);
                    return $result;
                }
                $this->addDebug('Cipher ' . $cipher . ' failed');
            }
            $this->addDebug('All cipher attempts failed');
        } else {
            if (\in_array('-C', $command, true)) {
                $this->addDebug('Cipher already specified in command, not retrying');
            }
        }

        return false;
    }

    /**
     * @param list<string> $command
     */
    private function executeIpmiCommand(array $command, bool $ignoreErrors = false): bool|string
    {
        $errorIntro = "Error occurred when running \"" . implode(" ", array_map($this->anonymizeSecrets(...), $command)) . "\".\n" ;
        
        $this->addDebug('=== EXECUTE IPMI COMMAND DEBUG ===');
        $this->addDebug('Command: ' . json_encode($command));
        $this->addDebug('Command timeout: ' . self::COMMAND_TIMEOUT . ' seconds');
        $this->addDebug('Working directory: ' . sys_get_temp_dir());

        try {
            $proc = new Process($command);
            $proc->setTimeout(self::COMMAND_TIMEOUT);
            // Keep any crash dumps out of the nginx document root (public/).
            $proc->setWorkingDirectory(sys_get_temp_dir());
            $opensslConf = '/etc/ssl/ipmitool-openssl.cnf';
            $env = [];
            if (is_readable($opensslConf)) {
                $env['OPENSSL_CONF'] = $opensslConf;
                $this->addDebug('OPENSSL_CONF set to: ' . $opensslConf);
            }
            $this->addDebug('Running ipmitool command...');
            $proc->run(null, $env);
            $output = $proc->getOutput();
            $exitCode = $proc->getExitCode();
            
            $this->addDebug('Command exit code: ' . $exitCode);
            $this->addDebug('Command output length: ' . strlen($output) . ' bytes');
            $this->addDebug('Command error output: ' . $proc->getErrorOutput());
            $this->addDebug('Command output (first 500 chars): ' . substr($output, 0, 500));

            if ($exitCode) {
                $message = $this->anonymizeSecrets($errorIntro.$proc->getErrorOutput());
                $this->addDebug('Command failed with exit code: ' . $exitCode);
                $this->addDebug($message);
                $this->debug[] = $message;

                if (!$ignoreErrors) {
                    error_log($message);
                }

                return false;
            }
        }
        catch (\Exception $exception) {
            // let's log this error
            $message = $this->anonymizeSecrets($errorIntro . $exception->getMessage());
            $this->addDebug('Exception caught: ' . get_class($exception));
            $this->addDebug($message);
            $this->debug[] = $message;

            if (!$ignoreErrors) {
                error_log($message);
            }

            return false;
        }

        $this->addDebug('Command executed successfully');
        return $output;
    }

    private function getCommand(Request $request): array|bool
    {
        $this->addDebug('=== GET COMMAND DEBUG ===');
        $this->addDebug('Request host: ' . $request->query->get('host'));
        $this->addDebug('Request port: ' . $request->query->get('port', self::DEFAULT_PORT));
        $this->addDebug('Request user: ' . $request->query->get('user', ''));
        $this->addDebug('Request interface: ' . $request->query->get('interface', ''));
        $this->addDebug('Request privilege_level: ' . $request->query->get('privilege_level', ''));
        $this->addDebug('Request extra: ' . $request->query->get('extra', ''));
        
        $this->hydrateSecrets($request);
        $query = $request->query;
        $host = $query->get('host');

        if (empty($host)) {
            $message = 'No hostname provided! Open /ui for the web UI, or pass ?host=... for the JSON API.';
            $this->debug[] = $message;
            error_log($message);

            return false;
        }

        $user = $query->get('user', '');
        $pass = $this->password;
        $kg_key = $this->kgKey;
        $privilege_level = $query->get('privilege_level', '');
        $extra = $query->get('extra', '');

        $cmd = ['ipmitool', '-H', $host, '-p', $query->get('port', self::DEFAULT_PORT)];

        if (!empty($user)) {
            $cmd[] = '-U';
            $cmd[] = $user;
        }

        if (!empty($pass)) {
            $cmd[] = '-P';
            $cmd[] = $pass;
        }

        // Add Kg key for encrypted IPMI sessions
        if (!empty($kg_key)) {
            $cmd[] = '-y';
            $cmd[] = $kg_key;
        }

        // Add privilege level
        if (!empty($privilege_level)) {
            $cmd[] = '-L';
            $cmd[] = $privilege_level;
        }

        // Parse extra params if provided
        if (!empty($extra)) {
            // If extra contains multiple arguments, parse them properly
            $extraArgs = str_getcsv($extra, ' ', '"', '');
            // Filter out empty elements and validate each argument
            foreach ($extraArgs as $arg) {
                $arg = trim($arg);
                if ($arg !== '') {
                    $cmd[] = $arg;
                }
            }
        }

        return $cmd;
    }

    private function getDeviceInfo(Request $request, ?array $requestedTypes = null): array
    {
        $this->addDebug('=== GET DEVICE INFO DEBUG ===');
        $this->addDebug('Requested types: ' . json_encode($requestedTypes));
        
        $response = [
            'success' => false,
            'message' => 'Wrong connection data provided!'
        ];

        $interface = $request->query->get('interface', '');
        $this->addDebug('Interface from request: ' . ($interface ?: 'auto (will try all)'));

        if (empty($interface)) {
            foreach ($this->ipmiTypes as $interface) {
                $response = $this->getDeviceInfoByInterface($request, $interface, $requestedTypes);

                if ($response['success']) {
                    break;
                }
            }
        }
        else {
            $response = $this->getDeviceInfoByInterface($request, $interface, $requestedTypes);
        }

        return $response;
    }

    private function getDeviceInfoByInterface(Request $request, string $interface, ?array $requestedTypes = null): array
    {
        $this->addDebug('=== GET DEVICE INFO BY INTERFACE DEBUG ===');
        $this->addDebug('Processing interface: ' . $interface);
        
        $response = [
            'success' => false
        ];

        $cmd = $this->getCommand($request);
        $on = false;
        $error = 'Wrong connection data provided!';
        
        $this->addDebug('Command built: ' . json_encode($cmd));

        if ($cmd === false) {
            $response['message'] = empty($request->query->get('host'))
                ? 'No hostname provided! Open /ui for the web UI, or pass ?host=... for the JSON API.'
                : $error;
        }
        else {
            try {
                $this->addDebug('Running bmc info command...');
                $ret = $this->runCommand(array_merge($cmd, ['-I', $interface, 'bmc', 'info']));
                $this->addDebug('bmc info result: ' . ($ret !== false ? 'SUCCESS' : 'FAILED'));

                if ($ret) {
                    $results = explode(PHP_EOL, $ret);
                    $this->addDebug('bmc info lines count: ' . count($results));
                    $device = $this->extractValuesFromResults($results);
                    $this->addDebug('Device info extracted: ' . json_encode($device));

                    if (!$this->shouldSkipFru($requestedTypes)) {
                        $this->addDebug('Running fru command...');
                        $ret = $this->runCommand(array_merge($cmd, ['-I', $interface, 'fru']));
                        $this->addDebug('fru result: ' . ($ret !== false ? 'SUCCESS' : 'FAILED'));

                        if ($ret) {
                            $results = explode(PHP_EOL, $ret);
                            $device = array_merge($device, $this->extractValuesFromResults($results));
                        }
                    }

                    $this->addDebug('Running chassis power status command...');
                    $ret = $this->runCommand(array_merge($cmd, ['-I', $interface, 'chassis', 'power', 'status']));

                    if ($ret) {
                        $on = (trim($ret) === "Chassis Power is on");
                        $this->addDebug('Chassis power status: ' . ($on ? 'ON' : 'OFF'));
                    }

                    $response['success'] = true;
                    $response['device'] = $device;
                    $response['power_on'] = $on;
                }
                else {
                    $response['message'] = $error;
                }

            } catch (\Exception $exception) {
                $this->addDebug('Exception in getDeviceInfoByInterface: ' . $exception->getMessage());
                $response['message'] = $exception->getMessage();
            }
        }

        $this->addDebug('getDeviceInfoByInterface returning: ' . json_encode($response));
        return $response;
    }

    private function extractValuesFromResults($results): array
    {
        $this->addDebug('=== EXTRACT VALUES FROM RESULTS DEBUG ===');
        $this->addDebug('Input results count: ' . count($results));
        $this->addDebug('Input results (first 3): ' . json_encode(array_slice($results, 0, 3)));
        
        $data = [];
        $results = array_values(array_filter($results, function ($line) {
            return str_contains($line, ':');
        }));
        
        $this->addDebug('Results after filtering (with colon): ' . count($results));

        if (!empty($results)) {
            foreach ($results as $result) {
                if (!empty($result)) {
                    $values = array_map('trim', explode(':', $result, 2));
                    $this->addDebug('Processing result: ' . substr($result, 0, 50) . '...');
                    $this->addDebug('Values array: ' . json_encode($values));
                    
                    $description = $values[0] ?? '';
                    $value = $values[1] ?? '';

                    if (!empty($value)) {
                        $id = $this->generateId($description);
                        $this->addDebug("Added: '$description' => '$value' (id: $id)");
                        $data[$id] = $value;
                    }
                }
            }
        }

        $this->addDebug('Total items extracted: ' . count($data));
        return $data;
    }

    private function getSensors(Request $request, ?array $requestedTypes = null): array
    {
        $this->addDebug('=== GET SENSORS DEBUG ===');
        $this->addDebug('Requested sensor types: ' . json_encode($requestedTypes));
        
        $response = [
            'success' => false,
            'message' => 'Wrong connection data provided!'
        ];

        $interface = $request->query->get('interface', '');
        $this->addDebug('Interface: ' . ($interface ?: 'auto'));

        if (empty($interface)) {
            foreach ($this->ipmiTypes as $interface) {
                $response = $this->getSensorsByInterface($request, $interface, $requestedTypes);

                if ($response['success']) {
                    break;
                }
            }
        }
        else {
            $response = $this->getSensorsByInterface($request, $interface, $requestedTypes);
        }

        return $response;
    }

    private function getSensorsByInterface(Request $request, string $interface, ?array $requestedTypes = null): array
    {
        $this->addDebug('=== GET SENSORS BY INTERFACE DEBUG ===');
        $this->addDebug('Processing interface: ' . $interface);
        
        $response = [
            'success' => false
        ];

        $states = [];
        $statuses = [];
        $sensorData = [];

        foreach ($this->unitsOfMeasure as $uom => $type) {
            $sensorData[$type] = [];
        }

        $cmd = $this->getCommand($request);
        
        $this->addDebug('Command: ' . json_encode($cmd));

        if ($cmd !== false) {
            try {
                $sdrOk = true;
                if ($this->shouldRunSdr($requestedTypes)) {
                    $this->addDebug('Running SDR command...');
                    $sdrOk = $this->extractFromSdrCommand($cmd, $interface, $sensorData, $states, $statuses, $requestedTypes);
                    $this->addDebug('SDR command result: ' . ($sdrOk ? 'SUCCESS' : 'FAILED'));
                }
                // DCMI is optional; skip it when SDR was required and failed so
                // auto-detect can try the next interface without another timeout.
                if ($sdrOk && $this->shouldRunDcmi($requestedTypes)) {
                    $this->addDebug('Running DCMI command...');
                    $this->extractFromDcmiPowerReadingCommand($cmd, $interface, $sensorData, $states, $requestedTypes);
                    $this->addDebug('DCMI command completed');
                }
                $response['success'] = $sdrOk;
            } catch (\Exception $exception) {
                $this->addDebug('Exception in getSensorsByInterface: ' . $exception->getMessage());
                $response['message'] = $exception->getMessage();
            }
        }

        $response['sensors'] = $sensorData;
        $response['states'] = $states;
        $response['statuses'] = $statuses;

        $this->addDebug('Sensors found: ' . count($sensorData) . ' total');
        foreach ($sensorData as $type => $data) {
            $this->addDebug('  ' . $type . ': ' . count($data) . ' sensors');
        }

        return $response;
    }

    private function extractFromSensorCommand(array $cmd, string $interface, array &$sensorData, array &$states, array &$statuses): bool
    {
        $ret = $this->runCommand(array_merge($cmd, ['-I', $interface, 'sensor']), true);

        if ($ret) {
            $lines = explode(PHP_EOL, $ret);

            if (!empty($lines)) {
                foreach ($lines as $line) {
                    if (!empty($line)) {
                        $values = array_map('trim', explode('|', $line));

                        if (count($values) < 4 || !$this->hasUsableSensorReading($values[1])) {
                            continue;
                        }

                        $description = $values[0];
                        $id = $this->generateId($description);
                        $value = $values[1];
                        $uom = $values[2];
                        $type = array_key_exists($uom, $this->unitsOfMeasure) ? $this->unitsOfMeasure[$uom] : null;

                        if ($type) {
                            $sensorData[$type][$id] = $description;
                            $states[$id] = $value;
                            $statuses[$id] = $values[3];
                        }
                    }
                }
            }
        }

        return $ret !== false;
    }

    private function preg_array_key_exists($pattern, $array): array
    {
        $keys = array_keys($array);
        return preg_grep($pattern,$keys);
    }


    private function extractFromSdrCommand(
        array $cmd,
        string $interface,
        array &$sensorData,
        array &$states,
        array &$statuses,
        ?array $requestedTypes = null
    ): bool
    {
        $this->addDebug('=== EXTRACT FROM SDR COMMAND DEBUG ===');
        $this->addDebug('Running SDR list full command...');
        $ret = $this->runCommand(array_merge($cmd, ['-I', $interface, 'sdr', 'list', 'full']), true);
        $this->addDebug('SDR list result: ' . ($ret !== false ? 'SUCCESS' : 'FAILED'));

        if ($ret) {
            $lines = explode(PHP_EOL, $ret);
            $this->addDebug('SDR lines count: ' . count($lines));
            $this->addDebug('First 5 lines: ' . json_encode(array_slice($lines, 0, 5)));

            if (!empty($lines)) {
                foreach ($lines as $line) {
                    if (!empty($line)) {
                        $values = array_map('trim', explode('|', $line));
                        
                        $this->addDebug('Processing SDR line: ' . substr($line, 0, 60) . '...');
                        $this->addDebug('Values: ' . json_encode($values));

                        if (count($values) < 3 || !$this->hasUsableSensorReading($values[1])) {
                            $this->addDebug('SKIPPED: insufficient values or no usable reading');
                            continue;
                        }

                        $description = $values[0];
                        $id = $this->generateId($description);
                        $value = $values[1];
                        $status = $values[2];

                        foreach($this->unitsOfMeasure as $uom => $type) {
                            if (!$this->shouldIncludeSensorType($requestedTypes, $type)) {
                                continue;
                            }

                            if (str_contains($value, $uom)) {
                                $value = trim(str_replace($uom, '', $value));

                                $id_pattern = "/^".$id."/";
                                $id_count = count($this->preg_array_key_exists($id_pattern, $sensorData[$type]));
                                if ($id_count > 0) {
                                    $description .= ' ' . ($id_count + 1);
                                    $id = $this->generateId($description);
                                }

                                $sensorData[$type][$id] = $description;
                                $states[$id] = $value;
                                $statuses[$id] = $status;
                                $this->addDebug("Added SDR sensor: $description ($type)");
                            }
                        }
                    }
                }
            }
        }

        $this->addDebug('Total SDR sensors extracted: ' . count($sensorData));
        return $ret !== false;
    }

    private function extractFromDcmiPowerReadingCommand(
        array $cmd,
        string $interface,
        array &$sensorData,
        array &$states,
        ?array $requestedTypes = null
    ): void
    {
        $this->addDebug('=== EXTRACT FROM DCMI POWER DEBUG ===');
        $this->addDebug('Running DCMI power reading command...');
        $ret = $this->runCommand(array_merge($cmd, ['-I', $interface, 'dcmi', 'power', 'reading']), true);
        $this->addDebug('DCMI result: ' . ($ret !== false ? 'SUCCESS' : 'FAILED'));

        if ($ret) {
            $this->addDebug('DCMI output: ' . $ret);
            // extract power usage from servers that support this command
            $results = explode(PHP_EOL, $ret);
            $this->addDebug('DCMI results count: ' . count($results));

            if (!empty($results)) {
                foreach ($results as $result) {
                    $extract = false;
                    $sensorType = 'power';

                    if (!empty($result)) {
                        if (str_contains($result, 'Watts')) {
                            if (!$this->shouldIncludeSensorType($requestedTypes, 'power')) {
                                continue;
                            }

                            $values = array_map('trim', explode(':', $result, 2));
                            $description = $values[0] ?? '';
                            $value = $values[1] ?? '';
                            $value = trim(str_replace('Watts', '', $value));
                            $extract = true;
                        } else if (str_contains($result, 'Seconds')) {
                            if (!$this->shouldIncludeSensorType($requestedTypes, 'time')) {
                                continue;
                            }

                            $description = 'Sampling period';
                            $pattern = "/" . $description . ":\K.+?(?=Seconds)/";
                            $success = preg_match($pattern, $result, $match);
                            $sensorType = 'time';

                            if ($success) {
                                $extract = true;
                                $value = trim($match[0]);
                            }
                        }

                        if ($extract) {
                            $id = $this->generateId($description);
                            $sensorData[$sensorType][$id] = $description;
                            $states[$id] = $value;
                        }
                    }
                }
            }
        }
    }

    private function getSensorsByType(Request $request, string $type, string $unit): array
    {
        $sensors = [];
        $states = [];

        $cmd = $this->getCommand($request);
        $found = false;

        if ($cmd !== false) {
            foreach ($this->ipmiTypes as $ipmi_type) {
                $ret = $this->runCommand(array_merge($cmd, ['-I', $ipmi_type, 'sdr', 'type', $type]));

                if ($ret) {
                    $results = explode(PHP_EOL, $ret);

                    if (!empty($results)) {
                        foreach ($results as $result) {
                            if (!empty($result)) {
                                $values = array_map('trim', explode('|', $result));
                                [$description, $a, $b, $c, $value] = $values;
                                $id = $this->generateId($description);

                                if (str_contains($value, $unit)) {
                                    $value = trim(str_replace($unit, '', $value));
                                } else {
                                    $value = null;
                                }

                                $sensors[$id] = $description;
                                $states[$id] = $value;
                            }
                        }

                        $found = true;
                        break;
                    }
                }
            }
        }

        return [
            'found' => $found,
            'sensors' => $sensors,
            'states' => $states
        ];
    }


}
