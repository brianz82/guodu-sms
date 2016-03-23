<?php

namespace Homer\Sms\Guodu;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;

/**
 * Short message service implemented with Guodu's api
 */
class Service
{
    /**
     * base url for sending short message
     * @var string
     */
    const SEND_URL = 'http://221.179.180.158:9007/QxtSms/QxtFirewall';

    /**
     * base url for querying quota
     * @var string
     */
    const QUOTA_URL = 'http://221.179.180.158:8081/QxtSms_surplus/surplus';

    // ==== Message type =====
    /**
     * message will be delivered as 普通短信
     */
    const SEND_TYPE_PLAIN      = 8;

    /**
     * message will be delivered as 长短信
     */
    const SEND_TYPE_LONG       = 15;
    // ==== End of Message type =====

    // ==== Name position ====

    /**
     * don't add name to message
     */
    const NAME_POS_NONE        = 0;

    /**
     * append the name to message
     */
    const NAME_POS_APPEND      = 1;

    /**
     * prepend the name to message
     */
    const NAME_POS_PREPEND     = 2;
    // ==== End of Name position =====

    const RESPONSE_PHRASES = [
        '00' => '短信提交成功',  // 批量短信
        '01' => '短信提交成功',  // 个性化短信
        '02' => 'IP限制',
        '03' => '短信提交成功',  // 单条
        '04' => '用户名错误',
        '05' => '密码错误',
        '06' => '自定义短信手机号个数与内容个数不相等',
        '07' => '发送时间错误',
        '08' => '短信包含敏感内容',  // 黑内容
        '09' => '同天内不能向用户重复发送该短信内容',
        '10' => '扩展号错误',
        '11' => '余额不足',
        '-1' => '短信服务器异常',
    ];

    /**
     * account used to send message
     * @var string
     */
    private $account;

    /**
     * password corresponding to account
     * @var string
     */
    private $password;

    /**
     * more options
     *
     * @var array
     */
    private $options;

    /**
     * http client
     * @var \GuzzleHttp\ClientInterface
     */
    private $client;

    /**
     * @param string $account           account used to send message
     * @param string $password          password paired with account, should be MD5'd
     * @param array $options            some more configurations:
     *                                  - name       name of merchant, will be either prepend or append to
     *                                               the message. e.g., 【XXX】
     *                                  - affix      附加号码 a part of sender's number that will be used to
     *                                               send the message. not more than 6 digits, suggested 4.
     *                                  - send_url   url used to send message
     *                                  - quota_url  url used to query quota
     * @param ClientInterface $client   client used to sending http request
     */
    public function __construct($account, $password, $options = [], ClientInterface $client = null)
    {
        $this->account   = $account;
        $this->password  = $password;
        $this->options   = array_merge([
            'send_url'  => self::SEND_URL,
            'quota_url' => self::QUOTA_URL,
        ], $options);

        $this->client = $client ?: $this->createDefaultHttpClient();
    }

    /**
     * @param string $message           message to deliver
     * @param string|array $subscriber  subscriber or a list of subscribers
     * @param array $options            available options include:
     *                                  - send_time  (optional) when will this message be delivered. If empty, the
     *                                               message will be delivered right away (in YYYYMMDDHHIISS format)
     *                                  - msg_type   (optional)  message type. Should one either SEND_TYPE_PLAIN (for
     *                                               普通短信, which is default) or SEND_TYPE_LONG (for 长短信)
     *                                  - name_pos   (optional) name position in the message, should be one of
     *                                               NAME_POS_NONE, NAME_POS_APPEND(default) and NAME_POS_PREPEND
     *                                  - expires_at (optional) message can be temporarily stored on message server, and
     *                                               we're allowed to give it an expiry time (in YYYYMMDDHHIISS format)
     *                                  - round_trip (optional) when turned on, the response from guodu will be parsed
     *                                               and returned. default false.
     * @return null|array
     */
    public function send($message, $subscriber, array $options = [])
    {
        // check subscriber(s)
        if (empty($subscriber)) {
            throw new \InvalidArgumentException('短信接收用户未指定');
        }

        // check message to send
        $message = $message ? trim($message) : $message;
        if (empty($message)) {
            throw new \InvalidArgumentException('短信内容为空');
        }
        if ($this->limitExceeded($message)) {
            throw new \InvalidArgumentException('短信内容过长');
        }

        // send the message
        $namePos = isset($options['name_pos']) ? $options['name_pos'] : self::NAME_POS_APPEND;
        return $this->sendMessage($this->formatMessage($message, $namePos), (array)$subscriber, $options);
    }

    // check whether the given message exceeds the limit in length
    private function limitExceeded($message, $limit = 500)
    {
        // by default, $limit equals 500, which is required by Guodu
        return mb_strlen($message) > $limit;
    }

    /**
     * find number of short messages that can be sent
     *
     * @return int
     * @throws \Exception   exception will be thrown if service does not work.
     */
    public function queryQuota()
    {
        $response = $this->client->request('GET', $this->buildRequestUrlForConsulting());

        /* @var $response \GuzzleHttp\Psr7\Response */
        if ($response->getStatusCode() != 200) {
            throw new \Exception('短信服务器异常');
        }

        $response = $this->parseResponseAsSimpleXmlElement((string)$response->getBody());
        /*
         * sample response:
         * <?xml version="1.0" encoding="GBK"?><resRoot><rcode>10</rcode></resRoot>
         */
        return intval((string)$response->rcode);
    }

    // send message to given subscribers
    private function sendMessage($message, array $subscribers, array $options) {
        // #. of subscribers per batch, Guodu restricts it to be 200
        $numberOfSubscribersPerBatch = 200;
        $numberOfBatch = ceil(count($subscribers) / $numberOfSubscribersPerBatch);
        $offset = 0;

        $response = []; // the response of sending message

        // send message to subscribers in batch
        for ($batch = 0; $batch < $numberOfBatch; $batch++) {
            // find subscribers for each batch
            $subscribersPerBatch = array_slice($subscribers, $offset, $numberOfSubscribersPerBatch);
            $offset += $numberOfSubscribersPerBatch;
            if (!empty($subscribersPerBatch)) { // got some subscriber
                $batchResponse = $this->doSendMessage($message, $subscribersPerBatch, $options);
                if (!empty($batchResponse)) {
                    $response = $response + $batchResponse;
                }

                // if the actual #. of subscribers is less than batch size
                // we're sure that the last batch was just processed
                if (count($subscribersPerBatch) < $numberOfSubscribersPerBatch) {
                    break;
                } else {
                    continue;
                }
            }

            break;  // no subscriber(s)
        }

        return empty($response) ? null : $response;
    }

    // do the actual work of sending short message
    private function doSendMessage($message, $subscribers, array $options = [])
    {
        // send request and parse response
        $response = $this->client->request('POST', $this->getSendUrl(),
            [RequestOptions::FORM_PARAMS => $this->buildRequestForSending($subscribers, $message, $options)]);

        if ($response) {
            return $this->parseSendResponse($response, array_get($options, 'round_trip', false));
        } else {
            throw new \Exception('短信服务异常');
        }
    }

    // build http request for sending message
    private function buildRequestForSending(array $subscribes, $message, array $options = [])
    {
        // message to send should be converted into 'GBK' encoding
        // for example, url encoded '中文短信abc' in GBK encoding should be '%D6%D0%CE%C4%B6%CC%D0%C5abc'

        // when will this message be delivered, if null/empty, the message will
        // be delivered at once
        $sendTime = array_get($options, 'send_time');
        $sendType = array_get($options, 'msg_type', self::SEND_TYPE_PLAIN);
        if (!in_array($sendType, [self::SEND_TYPE_PLAIN, self::SEND_TYPE_LONG])) {
            $sendType = self::SEND_TYPE_PLAIN;
        }
        // message can be temporarily stored at message server, and we're allowed to give it an expiry time
        $expiresAt = array_get($options, 'expires_at', date('YmdHis', time() + 86400 /* 1 day */));

        return [
            'OperID'      => $this->account,
            'OperPass'    => $this->password,
            'SendTime'    => $sendTime,
            'ValidTime'   => $expiresAt,
            'AppendID'    => $this->getAffix(),   // 附加号码
            'DesMobile'   => implode(',', $subscribes),
            'Content'     => mb_convert_encoding($message, 'gbk', 'utf-8'),
            'ContentType' => $sendType,
        ];
    }

    // build http request for querying quota
    private function buildRequestUrlForConsulting()
    {
        return self::buildHttpGetUrl($this->getQuotaUrl(), [
            'OperID'      => $this->account,
            'OperPass'    => $this->password,
        ]);
    }

    private static function buildHttpGetUrl($base, array $params = [])
    {
        $questionMarkPos = strpos($base, '?');
        if ($questionMarkPos === false) {
            return $base . (empty($params) ? '' : ('?' . http_build_query($params)));
        }

        if ($questionMarkPos == strlen($base) - 1) { // question mark is the last character
            return $base . (empty($params) ? '' : http_build_query($params));
        }

        return $base . (empty($params) ? '' : ('&' . http_build_query($params)));
    }

    // parse the message sending response
    private function parseSendResponse(Response $response, $roundTrip = false)
    {
        if ($response->getStatusCode() != 200) {
            throw new \Exception('短信服务异常');
        }

        // response for sending message is just an XML with <response> as its root
        // <response> has a <code> child to show the status, and followed by a list
        // of <message>s, each of which shows the message id of the sending to specific
        // subscriber
        $response = $this->parseResponseAsSimpleXmlElement((string)$response->getBody());

        // succeeded?
        if (!in_array((string)$response->code, ['00', '01', '03'])) { // no
            throw new \Exception(array_get(self::RESPONSE_PHRASES, (string)$response->code,
                sprintf('短信发送异常(%s)', $response->code)));
        }

        // parse message id
        if (!$roundTrip) {  // id for each message does not matter
            return;
        }

        // the response is not a standard one as the <response> contains multiple <message> node
        // as well as non-<message> node, (e.g., the <code>), and this brings us some trouble -
        // the first will prevent the parser from parsing subsequent ones, and thus there will
        // leave only one (the first) message parsed
        // there's one way to overcome this - by json_encode and re-decode
        $response = json_decode(json_encode($response), false);

        // $response->message can either be an object or an array. But when it's an object, converting
        // it with (array)obj will produce an array with each object's attribute as key, which is
        // not desired - we need an array with that object nested
        $messages = $response->message;
        if (!is_array($messages)) {
            $messages = [$messages];
        }

        return array_reduce($messages, function ($messages, $message) {
            $messages[(string)$message->desmobile] = $message->msgid;
            return $messages;
        }, []);
    }

    //
    // parse the response from Guodu as SimpleXMLElement object
    //
    // @param string $response
    // @returns
    //
    private function parseResponseAsSimpleXmlElement($response)
    {
        // $response are encoded in 'GBK', so we'll have to convert that into 'UTF-8'
        // And there is another thing: simplexml_load_string will try to read the encoding declared in the xml
        // and generate a warning if that encoding is not supported, for example:
        //  warning: simplexml_load_string(): Entity: line 1: parser error : Unsupported encoding GBK

        $encoding = null;
        $response = preg_replace_callback('/(<\?xml.+?encoding\s*=\s*["\'])([^"\']+)(.+\?>)/', function ($matches) use (&$encoding) {
            // $matches[1] is the encoding, something like 'GBK', 'gbk'
            $encoding = strtolower($matches[2]);

            // replace the encoding so that simplexml_load_string() can work
            return $matches[1] . 'utf-8' . $matches[3];
        }, $response, 1);

        // convert the encoding
        if (!empty($encoding)) {
            $response = mb_convert_encoding($response, $encoding, 'utf-8');
        }

        return simplexml_load_string($response);
    }

    /**
     * format the message that will be delivered
     *
     * @param string $message     message to send
     * @param int $namePosition   position of merchant name in the message
     * @return string
     */
    private function formatMessage($message, $namePosition)
    {
        $name = $this->getName();
        if (empty($name)) {  // no name
            return $message;
        }

        switch ($namePosition) {
            case self::NAME_POS_NONE:
                return $message;
            case self::NAME_POS_APPEND:
                return $message . $name;
            case self::NAME_POS_PREPEND:
                return $name . $message;
        }

        return $message;
    }

    /**
     * create default http client
     *
     * @param array $config        Client configuration settings. See \GuzzleHttp\Client::__construct()
     * @return \GuzzleHttp\Client
     */
    private function createDefaultHttpClient(array $config = [])
    {
        return new Client($config);
    }

    /**
     * get url for sending message
     *
     * @return string
     */
    private function getSendUrl()
    {
        return array_get($this->options, 'send_url');
    }

    /**
     * get url for querying quota
     *
     * @return string
     */
    private function getQuotaUrl()
    {
        return array_get($this->options, 'quota_url');
    }

    /**
     * get affix
     *
     * @return mixed
     */
    private function getAffix()
    {
        return array_get($this->options, 'affix');
    }

    /**
     * get merchant name
     *
     * @return string
     */
    private function getName()
    {
        return array_get($this->options, 'name');
    }

}