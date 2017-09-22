<?php
/**
 * @author    Phil Taylor <phil@phil-taylor.com>
 * @copyright Copyright (C) 2016, 2017 Blue Flame IT Ltd. All rights reserved.
 * @license   GPL
 * @source    https://github.com/PhilETaylor/akeeba
 */

namespace Akeeba\Service;

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
	public $options = [
	];
	/**
	 * @var array
	 */
	public $params = [

	];
	/**
	 * Akeeba Request Data Expected For Next API Call
	 * @var array
	 */
	private $akeeba_params = array();

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

	private $method = 'GET';


	private $useRunScope = FALSE;
	private $runscopeSuffix = '-g2dmtmt4vrsu.runscope.net/';

	/**
	 * Akeeba constructor.
	 */
	public function __construct($redis)
	{
		$this->redis = $redis;
		$this->getConfiguredHTTPClient();
	}

	private function getConfiguredHTTPClient()
	{
		$this->client = new \GuzzleHttp\Client(
			[
				'headers'         => [
					'User-Agent' => 'myJoomla.com/1.0'
				],
//				'verify'  => false,
//				'proxy'   => '0.0.0.0:8888',
				'timeout'         => 120,
				'request.options' => [
					'exceptions' => false,
				]
			]
		);

		$this->params = [
			'option' => 'com_akeeba',
			'view'   => 'json',
			'format' => 'component',
		];
	}

	/**
	 * @see https://www.akeebabackup.com/documentation/json-api/ar01s03s06.html
	 * @param array $params
	 * @return mixed
	 */
	public function listBackups($params = [])
	{
		$this->setAkeebaParameter('from', array_key_exists('from', $params) ? $params['from'] : '0');
		$this->setAkeebaParameter('limit', array_key_exists('limit', $params) ? $params['limit'] : '50');

		return $this->_call('listBackups');
	}

	private function setAkeebaParameter($key, $value)
	{
		$this->akeeba_params[$key] = $value;
	}

	private function _call($method)
	{
		$this->redis->incr('stats:calls:' . $method);

		if (!$this->siteUrl || !$this->key || !$method) {
			throw new \Exception('Needs a site url and key');
		}

		$this->setAkeebaParameter('method', $method);
		$this->params['json'] = $this->getRequestObject($method);

		if ($this->method == 'post') {
			$res = $this->client->request($this->method, $this->siteUrl,
				[
					'form_params' => $this->params,
				]
			);
		} else {
			$res = $this->client->request($this->method, $this->siteUrl,
				[
					'query' => $this->params,
				]
			);
		}

		$ret = $this->postProcessReply($res->getBody());

		return $ret;
	}

	/**
	 * @see https://www.akeebabackup.com/documentation/json-api/ar01s02.html
	 */
	private function getRequestObject($method)
	{
		$obj = new \stdClass();
		$obj->encapsulation = self::ENCAPSULATION_RAW;
		$obj->body = $this->getRequestBody($method, $this->key, []);//$this->akeeba_params

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
			throw new \Exception('No sensible reply from site, we got:'.(str) . $str);
		}

		$status = $dataHAL->body->status;
		$data = \GuzzleHttp\json_decode($dataHAL->body->data);

		return $data;
	}

	/**
	 * @see https://www.akeebabackup.com/documentation/json-api/ar01s03s02.html
	 * @param array $params
	 * @return mixed
	 */
	public function getProfiles($params = [])
	{
		return $this->_call('getProfiles');
	}

	/**
	 * @see https://www.akeebabackup.com/documentation/json-api/ar01s03s18.html
	 * @param array $params
	 * @return mixed
	 */
	public function deleteProfile($profile_id)
	{
		$this->setAkeebaParameter('profile', $profile_id);

		return $this->_call('deleteProfile');
	}

	/**
	 * @see https://www.akeebabackup.com/documentation/json-api/ar01s03s14.html
	 * @param array $params
	 * @return mixed
	 */
	public function saveConfiguration($params = [], $profile_id)
	{
		$this->setAkeebaParameter('profile', $profile_id);
		$this->setAkeebaParameter('engineconfig', $params);

		return $this->_call('saveConfiguration');
	}

	/**
	 * @see https://www.akeebabackup.com/documentation/json-api/ar01s03s15.html
	 * @param array $params
	 * @return mixed
	 */
	public function saveProfile($params = [])
	{
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
	 * @param array $params
	 * @return mixed
	 */
	public function getGUIConfiguration($profile_id = 1)
	{
		$this->setAkeebaParameter('profile', $profile_id);

		$configJson = $this->_call('getGUIConfiguration');

		return $configJson;

	}

	public function getBackupInfo($params = [])
	{
		$this->setAkeebaParameter('backup_id', array_key_exists('backup_id', $params) ? $params['backup_id'] : '');

		return $this->_call('getBackupInfo');

	}

	public function stepBackup($params = [])
	{
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
			$siteUrl = $siteUrl . 'wp-content/plugins/akeebabackupwp/app/';
		}

		$this->siteUrl = $siteUrl;
		$this->key = $siteKey;
	}

	/**
	 * @see https://www.akeebabackup.com/documentation/json-api/ar01s03s03.html
	 * @param array $params
	 * @return mixed
	 */
	public function startBackup($params = [])
	{
		if (!$this->siteUrl) {
			$this->setSite($params['url'], $params['key'], $params['platform']);
		}

//		$cacheKey = sprintf('site:%s:backup:running', $this->site->getId());
//		$this->redis->setex($cacheKey, 3600, '{"Progress":0}');

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
	 */
	public function getVersion()
	{
		return $this->_call('getVersion');
	}

	/**
	 * Do a call and cache the results to a Predis Redis connection
	 *
	 * @param string $method
	 * @param \AppBundle\Entity\Site $site
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

		return \GuzzleHttp\json_decode($data);
	}

	/**
	 * @param \AppBundle\Entity\Site $site
	 */
	public function setSiteFromEntity(\AppBundle\Entity\Site $site)
	{
		$this->site = $site;
		$this->setSite($site->getUrl(), $site->getAkeebaKey(), $site->getPlatform()->getPlatform());
	}

	public function recache($resque, $method, $params = [], $site)
	{
		$job = new \AppBundle\Jobs\Akeeba();
		$job->args = [
			'site_url'      => $site->getUrl(),
			'site_id'       => $site->getId(),
			'akeeba_key'    => $site->getAkeebaKey(),
			'method'        => $method,
			'method_params' => $params
		];

		$res = $resque->enqueue($job);
	}
}
