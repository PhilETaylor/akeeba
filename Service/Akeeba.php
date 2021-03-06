<?php
/**
 * @author    Phil Taylor <phil@phil-taylor.com>
 * @copyright Copyright (C) 2016, 2017 Blue Flame IT Ltd. All rights reserved.
 * @license   GPL
 * @source    https://github.com/PhilETaylor/akeeba
 */

namespace Akeeba\Service;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions as RO;
use GuzzleHttp\HandlerStack;
use Exception;

/**
 * Class Akeeba
 * @package Akeeba\Service
 */
class Akeeba
{
    /**
     * The JSON-encoded body is represented as plain text (no encryption)
     */
    const ENCAPSULATION_RAW = 1;
    /**
     * The JSON-encoded body is encrypted using AES-128 in CTR (counter) mode.
     * The result is base64-encoded before transport.
     * An implementation for CTR encryption and decryption exists for PHP and Javascript.
     */
    const ENCAPSULATION_AESCTR128 = 2;
    /**
     * ENCAPSULATION_AESCTR256. The JSON-encoded body is encrypted using AES-256 in CTR (counter) mode.
     * The result is base64-encoded before transport. An implementation for CTR encryption and decryption
     * exists for PHP and Javascript.
     */
    const ENCAPSULATION_AESCTR256 = 3;
    /**
     * ENCAPSULATION_AESCBC128. The JSON-encoded body is encrypted using AES-128 in CBC mode.
     * Since the original message is padded with 0x00 for its size to become a multiple of 16,
     * the length of the plaintext is appended to the ciphertext (4 bytes).
     * The result is base64-encoded before transport.
     */
    const ENCAPSULATION_AESCBC128 = 4;
    /**
     * ENCAPSULATION_AESCBC256. The JSON-encoded body is encrypted using AES-256 in CBC mode.
     * Since the original message is padded with 0x00 for its size to become a multiple of 16,
     * the length of the plaintext is appended to the ciphertext (4 bytes).
     * The result is base64-encoded before transport.
     */
    const ENCAPSULATION_AESCBC256 = 5;

    const STARTED = 'Started';
    const RUNNING = 'Running';
    const STALLED = 'Stalled';
    const FINISHED = 'Complete';
    const ERRORED = 'Errored';
    const KILLED = 'Killed';

    /**
     * @var array
     */
    public $options = [];

    /**
     * @var array
     */
    public $params = [
        'option' => 'com_akeeba',
        'view' => 'json',
        'format' => 'component',
    ];

    /**
     * Akeeba Request Data Expected For Next API Call
     *
     * @var array
     */
    private $akeeba_params = [];

    /**
     * @var string
     */
    private $siteUrl;

    /**
     * @var string
     */
    private $key;

    /**
     * @var \GuzzleHttp\Client
     */
    private $client;

    /**
     * @var array
     */
    private $responseStatusIdentifiers = array(
        200 => 'Normal reply. Your request was successful.',
        401 => 'Invalid credentials. The challenge you provided failed, or you used an invalid key to encrypt your request.',
        403 => 'Inadequate privileges. The system administrator doesnt allow the requested action to be performed over the JSON API (reserved status code for future use)',
        404 => 'Requested resource not found. This can imply that the backup record ID or the file you requested to download is not present in the system.',
        405 => 'Unknown JSON method. The JSON method requested is unknown.',
        500 => 'An error occurred. An unspecified error occurred while processing your request. More information will be provided in the data part of the response.',
        501 => 'Not implemented. The method you requested is not implemented by the server.',
        503 => 'Remote service not activated. The system administrator has not activated the front-end or remote backup feature of Akeeba Backup.',
    );

    /**
     * @var string
     */
    private $method = 'GET';

    /**
     * @var bool
     */
    private $useRunScope = FALSE;

    /**
     * @var string
     */
    private $runscopeSuffix = '-g2dmtmt4vrsu.runscope.net/';

    /**
     * @var array
     */
    private $auth = null;

    /**
     * Akeeba constructor.
     */
    public function __construct($redis, $env = 'prod', $charlesrootcert = "/var/www/build/dev/charles-ssl-proxying-certificate.pem")
    {
        $this->redis = $redis;
        $this->env = $env;
        $this->charlesrootcert = $charlesrootcert;
        $this->getConfiguredHTTPClient();
    }

    private function getConfiguredHTTPClient()
    {
        $config =  [
            RO::SYNCHRONOUS => true,
            RO::ALLOW_REDIRECTS => [
                'allow_redirects' => [
                    'max' => 10,        // allow at most 10 redirects.
                    'strict' => true,      // use "strict" RFC compliant redirects.
                    'referer' => true,      // add a Referer header
                ],
            ],
            RO::AUTH => [],
            RO::DELAY => 0,
//            RO::VERIFY => ($this->env == 'prod' ? true : false),
            RO::VERIFY => false, //  FORCE TO FALSE FOR NOW
            RO::CONNECT_TIMEOUT => 30,  // 30 seconds
            RO::DEBUG => false,
            RO::TIMEOUT => 180, // 3 mins
            RO::HTTP_ERRORS => true,
            RO::DECODE_CONTENT => true,
            RO::FORCE_IP_RESOLVE => 'v4',
            RO::HEADERS => [
                'User-Agent' => 'myJoomla/2.0 (myJoomla.com)',
                'Accept' => 'application/json',
                'X-MyJoomla-FAQ' => 'For full details see myJoomla.com or email phil@phil-taylor.com',
            ],
        ];

        if ('prod' != $this->env) {
            putenv('HTTP_PROXY=host.docker.internal:8888');
            putenv('HTTPS_PROXY=host.docker.internal:8888');

            $proxyConfig = [
                RO::PROXY => [
                    'http'  => 'host.docker.internal:8888',
                    'https' => 'host.docker.internal:8888',
                ],
                RO::VERIFY => (file_exists($this->charlesrootcert) ? $this->charlesrootcert : null),
            ];
            $config = array_merge($config, $proxyConfig);
        }

        $this->handlerStack = HandlerStack::create();

        $config = array_merge($config, ['handler' => $this->handlerStack]);

        $this->client = new \GuzzleHttp\Client(
            $config
        );
    }

    /**
     * @see https://www.akeebabackup.com/documentation/json-api/ar01s03s06.html
     * @param array $params
     * @return mixed
     * @throws GuzzleException
     */
    public function listBackups($params = [])
    {
        if (array_key_exists('auth', $params)) {
            $this->setAuth($params['auth']);
        }

        $this->setAkeebaParameter('from', array_key_exists('from', $params) ? $params['from'] : '0');
        $this->setAkeebaParameter('limit', array_key_exists('limit', $params) ? $params['limit'] : '50');

        return $this->_call('listBackups');
    }

    /**
     * @param string $key
     * @param string $value
     */
    private function setAkeebaParameter($key, $value)
    {
        $this->akeeba_params[$key] = $value;
    }

    /**
     * @param $method
     * @return mixed|null
     * @throws GuzzleException
     */
    private function _call($method)
    {
        $this->redis->incr('stats:calls:' . $method);

        if (!$this->siteUrl || !$this->key || !$method) {
            throw new Exception('Needs a site url and key');
        }

        $this->setAkeebaParameter('method', $method);
        $this->params['json'] = $this->getRequestObject($method);

        if ($this->method == 'post') {
            $res = $this->client->request('POST', $this->siteUrl,
                [
                    'form_params' => $this->params,
                    'auth' => $this->auth
                ],


            );
        } else {
            $res = $this->client->request('GET', $this->siteUrl,
                [
                    'query' => $this->params,
                    'auth' => $this->auth
                ]
            );
        }

        $ret = $this->postProcessReply($res->getBody());

        return $ret;
    }

    /**
     * @see https://www.akeebabackup.com/documentation/json-api/ar01s02.html
     *
     * @param string $method
     *
     * @return mixed|string
     */
    private function getRequestObject($method)
    {
        $obj = new \stdClass();
        $obj->encapsulation = self::ENCAPSULATION_RAW;
        $obj->body = $this->getRequestBody($method, $this->key, []);

        return json_encode($obj);
    }

    /**
     * @see https://www.akeebabackup.com/documentation/json-api/ar01s02.html
     *
     * @param $method
     * @param $key
     * @param $data
     *
     * @return mixed|string|void
     */
    private function getRequestBody($method, $key, $data)
    {
        $obj = new \stdClass();
        $obj->challenge = $this->getChallengeString($key);
        $obj->key = $key;
        $obj->method = $method;

        $this->setAkeebaParameter('tag', 'json');

        if (@$this->config['backupid']) {
            $this->setAkeebaParameter('backupid', $this->config['backupid']);
        }

        $obj->data = $this->akeeba_params;

        return json_encode($obj);
    }

    /**
     * This field is required if and only if the encapsulation is 1 (ENCAPSULATION_RAW).
     * It consists of a salt string and an MD5 hash, separated by a colon, like this: salt:md5.
     * The salt can be an arbitrary length alphanumeric string. The md5 part of the challenge is
     * the result of the MD5 hash of the concatenated string of the salt and the Akeeba Backup
     * front-end secret key, as configured in the component's Parameters. For example, if the
     * salt is foo and the secret key is bar, the md5 is md5(foobar) = 3858f62230ac3c915f300c664312c63f,
     * therefore the challenge is foo:3858f62230ac3c915f300c664312c63f.
     *
     * @param      $api_key
     * @param null $salt
     *
     * @return string The challenge string
     */
    private function getChallengeString($api_key, $salt = NULL)
    {
        if (NULL === $salt) {
            $salt = $this->getSalt();
        }

        $challengeString = sprintf('%s:%s', $salt, md5($salt . trim($api_key)));

        return $challengeString;
    }

    /**
     * @param int $length
     * @param bool $specialChars
     * @return string
     */
    private function getSalt($length = 32, $specialChars = FALSE)
    {

        $salt = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";

        $len = strlen($salt);
        $resultantSalt = '';
        mt_srand(10000000 * ( double )microtime());
        for ($i = 0; $i < $length; $i++) {
            $resultantSalt .= $salt [mt_rand(0, $len - 1)];
        }
        while (is_numeric(substr($resultantSalt, 0, 1))) {
            $resultantSalt = self::getSalt($length, $specialChars);
        }

        return $resultantSalt;
    }

    /**
     * @param $str
     * @return mixed|null
     * @throws Exception
     */
    private function postProcessReply($str)
    {
        if (!$str) return NULL;

        $str = trim($str);

        $dataHAL = \json_decode(preg_replace('/^###|###$/', '', (string)$str));

        if (!$dataHAL ||
            !property_exists($dataHAL, 'body') ||
            !property_exists($dataHAL->body, 'status') ||
            !property_exists($dataHAL->body, 'data')
        ) {
            throw new Exception('No sensible reply from site, we got:' . $str);
        }

        $status = $dataHAL->body->status;
        $data = \json_decode($dataHAL->body->data);

        return $data;
    }

    /**
     * @see https://www.akeebabackup.com/documentation/json-api/ar01s03s02.html
     * @param array $params
     * @return mixed
     * @throws GuzzleException
     */
    public function getProfiles($params = [])
    {
        if (array_key_exists('auth', $params)) {
            $this->setAuth($params['auth']);
        }
        return $this->_call('getProfiles');
    }

    /**
     * @see https://www.akeebabackup.com/documentation/json-api/ar01s03s18.html
     * @param $profile_id
     * @return mixed
     * @throws GuzzleException
     */
    public function deleteProfile($profile_id, $auth=null)
    {
        if (null !== $auth) {
            $this->setAuth($auth);
        }
        
        $this->setAkeebaParameter('profile', $profile_id);

        return $this->_call('deleteProfile');
    }

    /**
     * @see https://www.akeebabackup.com/documentation/json-api/ar01s03s14.html
     * @param array $params
     * @param $profile_id
     * @return mixed
     * @throws GuzzleException
     */
    public function saveConfiguration($params = [], $profile_id)
    {
        if (array_key_exists('auth', $params)) {
            $this->setAuth($params['auth']);
        }

        $this->setAkeebaParameter('profile', $profile_id);
        $this->setAkeebaParameter('engineconfig', $params);

        return $this->_call('saveConfiguration');
    }

    /**
     * @see https://www.akeebabackup.com/documentation/json-api/ar01s03s15.html
     * @param array $params
     * @return mixed
     * @throws GuzzleException
     */
    public function saveProfile($params = [])
    {
        if (array_key_exists('auth', $params)) {
            $this->setAuth($params['auth']);
        }

        $this->setAkeebaParameter('profile', array_key_exists('profile', $params) ? $params['profile'] : 0);

        if (array_key_exists('source', $params)) {
            $this->setAkeebaParameter('source', $params['source']);
        }

        $this->setAkeebaParameter('description', $params['description']);

        $this->setAkeebaParameter('quickicon', array_key_exists('quickicon', $params) ? $params['quickicon'] : 1);

        return $this->_call('saveProfile');
    }

    /**
     * @see: https://www.akeebabackup.com/documentation/json-api/ar01s03s13.html
     * @param int $profile_id
     * @return mixed
     * @throws GuzzleException
     */
    public function getGUIConfiguration($profile_id = 1)
    {
        $this->setAkeebaParameter('profile', $profile_id);

        $configJson = $this->_call('getGUIConfiguration');

        return $configJson;

    }

    /**
     * @param array $params
     * @return mixed|null
     * @throws GuzzleException
     */
    public function getBackupInfo($params = [])
    {
        if (array_key_exists('auth', $params)) {
            $this->setAuth($params['auth']);
        }

        $this->setAkeebaParameter('backup_id', array_key_exists('backup_id', $params) ? $params['backup_id'] : '');

        return $this->_call('getBackupInfo');

    }

    /**
     * @param array $params
     */
    private function setAuth($authparams){
        $this->auth = $authparams;
    }

    /**
     * @param array $params
     * @return mixed|null
     * @throws GuzzleException
     */
    public function stepBackup($params = [])
    {
        if (array_key_exists('auth', $params)) {
            $this->setAuth($params['auth']);
        }

        if (!$this->siteUrl) {
            $this->setSite($params['url'], $params['key'], $params['platform']);
        }

        $backupid = array_key_exists('akeebaBackupid', $params) ? $params['akeebaBackupid'] : '';


        if (!$backupid) {
            $backupid = array_key_exists('backupid', $params) ? $params['backupid'] : '';
        }
        if (!$backupid) {
            $backupid = array_key_exists('akeeba_BackupId', $params) ? $params['akeeba_BackupId'] : '';
        }

        $this->setAkeebaParameter('tag', array_key_exists('tag', $params) ? $params['tag'] : 'json');
        $this->setAkeebaParameter('backupid', (string)$backupid);

        return $this->_call('stepBackup');
    }

    /**
     * @param $siteUrl
     * @param $siteKey
     * @param string $platform
     */
    public function setSite($siteUrl, $siteKey, $platform = 'Joomla')
    {
        if ($this->useRunScope) {
            $siteurl = substr($siteUrl, 0, strlen($siteUrl) - 1);
            $hookurl = str_replace('-', '--', $siteurl);
            $hookurl = str_replace('.', '-', $hookurl);
            $siteUrl = str_replace($siteUrl, $hookurl . $this->runscopeSuffix, $siteUrl);
        }

        if ($platform == 'Wordpress') {
            $siteUrl = $siteUrl . 'wp-content/plugins/akeebabackupwp/app/remote.php?view=remote&key='. $siteKey;

        } else {
            $siteUrl = $siteUrl . 'index.php';    
        }

        

        $this->siteUrl = $siteUrl;
        $this->key = $siteKey;
    }

    /**
     * @see https://www.akeebabackup.com/documentation/json-api/ar01s03s03.html
     * @param array $params
     * @return mixed
     * @throws GuzzleException
     */
    public function startBackup($params = [])
    {
        if (array_key_exists('auth', $params)) {
            $this->setAuth($params['auth']);
        }

        if (!$this->siteUrl) {
            $this->setSite($params['url'], $params['key'], $params['platform']);
        }

        $this->method = array_key_exists('method', $params) ? $params['method'] : 'post';
        $this->setAkeebaParameter('profile', array_key_exists('profile', $params) ? $params['profile'] : '1');
        $this->setAkeebaParameter('description', array_key_exists('description', $params) ? $params['description'] : '');
        $this->setAkeebaParameter('comment', array_key_exists('comment', $params) ? $params['comment'] : 'Created with myAkeeba.io');
        $this->setAkeebaParameter('tag', array_key_exists('tag', $params) ? $params['tag'] : '');
        $this->setAkeebaParameter('overrides', array_key_exists('overrides', $params) ? $params['overrides'] : '');

        $this->redis->incr('stats:runningbackups');

        return $this->_call('startBackup');
    }

    /**
     * @see https://www.akeebabackup.com/documentation/json-api/ar01s03.html
     *
     * @return mixed
     * @throws Exception
     */
    public function getVersion()
    {
        return $this->_call('getVersion');
    }

    /**
     * Do a call and cache the results to a Predis Redis connection
     *
     * @param string $method
     * @param $site
     * @param array $params
     * @param bool $forcerefresh
     * @param int $ttl The number of seconds the cache will stay in redis
     * @return mixed
     */
    public function getCachedDataIfAvailableElseDoCall($method, $site, $params = [], $forcerefresh = FALSE, $ttl = 86400)
    {
        $this->setSiteFromEntity($site);

        // getBackupInfo
        if (array_key_exists('backup_id', $params) && $method == 'getBackupInfo') {
            $cacheKey = sprintf('site:%s:akeeba:' . $method . ':' . $params['backup_id'], $site->getId());
        } else {
            // Alll others
            $cacheKey = sprintf('site:%s:akeeba:' . $method, $site->getId());
        }

        $data = $this->redis->get($cacheKey);

        if (!$data || $forcerefresh === TRUE) {

            $this->setSiteFromEntity($site);

            $data = $this->$method($params);

            $data = \GuzzleHttp\json_encode($data);

            $this->redis->setex($cacheKey, $ttl, $data);
        }

        return \json_decode($data);
    }

    /**
     * @param $site
     */
    public function setSiteFromEntity($site)
    {
        $this->site = $site;
        $this->setSite($site->getUrl(), $site->getAkeebaKey(), $site->getPlatform()->getPlatform());
    }
}
