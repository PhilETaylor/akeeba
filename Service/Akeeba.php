<?php
/**
 * *
 *  * @author    Phil Taylor <phil@phil-taylor.com>
 *  * @copyright Copyright (C) 2011, 2012, 2013, 2014, 2015 Blue Flame IT Ltd. All rights reserved.
 *  * @license   Commercial License - Not to be distributed!
 *  * @link      https://manage.myjoomla.com
 *  * @source    https://github.com/PhilETaylor/bfnetwork
 *
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
    public function __construct()
    {
        $this->getConfiguredHTTPClient();
    }

    private function getConfiguredHTTPClient()
    {
        $this->client = new \GuzzleHttp\Client();

        $this->params = [
            'option' => 'com_akeeba',
            'view'   => 'json',
            'format' => 'component',
        ];
    }

    public function setSite($siteUrl, $siteKey, $platform='Joomla')
    {
        if ($this->useRunScope) {
            $siteurl = substr($siteUrl, 0, strlen($siteUrl) - 1);
            $hookurl = str_replace('-', '--', $siteurl);
            $hookurl = str_replace('.', '-', $hookurl);
            $siteUrl = str_replace($siteUrl, $hookurl . $this->runscopeSuffix, $siteUrl);
        }

        if ($platform=='Wordpress'){
            $siteUrl = $siteUrl .'wp-content/plugins/akeebabackupwp/app/';
        }

        $this->siteUrl = $siteUrl;
        $this->key = $siteKey;
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

        if (!$this->siteUrl || !$this->key || !$method) {
            throw new \Exception('Needs a site url and key');
        }

        $this->setAkeebaParameter('method', $method);
        $this->params['json'] = $this->getRequestObject($method);

//        try {
            $res = $this->client->request($this->method, $this->siteUrl,
                [
                    'query' => $this->params
                ]
            );

            $ret = $this->postProcessReply($res->getBody());
//        } catch (\GuzzleHttp\Exception\RequestException $e) {
//            dump($e);
//            die;
//             @todo handle this
//            $ret = '';
//        }


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
        $dataHAL = \json_decode(preg_replace('/^###|###$/', '', (string)$str));

        $status = $dataHAL->body->status;
        $data = \GuzzleHttp\json_decode($dataHAL->body->data);

        return $data;
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
}
