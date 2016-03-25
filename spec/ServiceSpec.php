<?php

namespace spec\Homer\Sms\Guodu;

use GuzzleHttp\RequestOptions;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;

/**
 * Spec for unit testing Guodu Short Message Service
 */
class ServiceSpec extends ObjectBehavior
{
    function let(ClientInterface $client)
    {
        $this->beAnInstanceOf(\Homer\Sms\Guodu\Service::class, [
            'account',    // account
            'password',   // password
            [ 'name' => '', 'affix' => '1234' ], // options
            $client,      // http client
        ]);
    }
    
    //=====================================
    //          Send Message
    //=====================================
    function it_throws_exception_if_message_too_long(ClientInterface $client)
    {
        // generate message which is larger than 500 in length
        $message = str_repeat('message', 80); // str_len('message') * 80 = 560 > 500

        $this->shouldThrow(new \Exception('短信内容过长'))
             ->duringSend($message, '13800138000');
    }

    function it_throws_exception_if_no_subscribers_given(ClientInterface $client)
    {
        $this->shouldThrow(new \Exception('短信接收用户未指定'))
             ->duringSend('whatever message', '');
    }

    function it_should_have_message_sent_for_single_subscriber(ClientInterface $client)
    {
        $client->request('POST', 'http://221.179.180.158:9007/QxtSms/QxtFirewall',
            Argument::that(function (array $request) {
                $request = $request[RequestOptions::FORM_PARAMS];
                return $request['OperID']    == 'account' &&
                       $request['OperPass']  == 'password' &&
                       $request['AppendID']  == '1234' &&
                       $request['DesMobile'] == '13800138000' &&
                       // it's GBK-encoded
                       mb_convert_encoding($request['Content'], 'utf-8', 'gbk')  == '发送消息';
            }))->shouldBeCalledTimes(1)->willReturn(new Response(200, [], file_get_contents(__DIR__ . '/data/single_success.xml')));

        $this->shouldNotThrow()->duringSend('发送消息', '13800138000');
    }

    function it_should_have_message_sent_for_multiple_subscribers(ClientInterface $client)
    {
        $client->request('POST', 'http://221.179.180.158:9007/QxtSms/QxtFirewall',
            Argument::that(function (array $request) {
                $request = $request[RequestOptions::FORM_PARAMS];
                return $request['OperID']    == 'account' &&
                       $request['OperPass']  == 'password' &&
                       $request['AppendID']  == '1234' &&
                       $request['DesMobile'] == '13800138000,13800138001' &&
                       // it's GBK-encoded
                       mb_convert_encoding($request['Content'], 'utf-8', 'gbk')  == '发送消息';
        }))->shouldBeCalled()->willReturn(new Response(200, [],
                                              file_get_contents(__DIR__ . '/data/multi_success.xml')));

        $this->shouldNotThrow()->duringSend('发送消息', ['13800138000', '13800138001']);
    }

    function it_should_have_message_sent_for_subscribers_that_exceeds_the_limit(ClientInterface $client)
    {
        $subscribersBatchOne = $this->makeSubscribers('13800',   1, 200); // 200 subscribers
        $subscribersBatchTwo = $this->makeSubscribers('13800', 201, 300); // 100 subscribers

        // the first batch
        $client->request('POST', 'http://221.179.180.158:9007/QxtSms/QxtFirewall',
            Argument::that(function (array $request) use ($subscribersBatchOne) {
                $request = $request[RequestOptions::FORM_PARAMS];
                return $request['OperID']    == 'account' &&
                       $request['OperPass']  == 'password' &&
                       $request['AppendID']  == '1234' &&
                       $request['DesMobile'] == implode(',', $subscribersBatchOne) &&
                       $request['Content']== 'message';
            }))->shouldBeCalled()->willReturn(new Response(200, [],
                file_get_contents(__DIR__ . '/data/multi_success.xml')));

        // and the second batch
        $client->request('POST', 'http://221.179.180.158:9007/QxtSms/QxtFirewall',
            Argument::that(function (array $request) use ($subscribersBatchTwo) {
                $request = $request[RequestOptions::FORM_PARAMS];
                return $request['OperID']    == 'account' &&
                       $request['OperPass']  == 'password' &&
                       $request['AppendID']  == '1234' &&
                       $request['DesMobile'] == implode(',', $subscribersBatchTwo) &&
                       $request['Content']== 'message';
            }))->shouldBeCalled()->willReturn(new Response(200, [],
                file_get_contents(__DIR__ . '/data/multi_success.xml')));

        $this->shouldNotThrow()->duringSend('message', array_merge($subscribersBatchOne, $subscribersBatchTwo));
    }

    function it_should_have_message_sent_roundtrip_for_single_subscriber(ClientInterface $client)
    {
        $client->request('POST', 'http://221.179.180.158:9007/QxtSms/QxtFirewall',
            Argument::that(function (array $request) {
                $request = $request[RequestOptions::FORM_PARAMS];
                return $request['OperID']    == 'account' &&
                $request['OperPass']  == 'password' &&
                $request['AppendID']  == '1234' &&
                $request['DesMobile'] == '13800138000' &&
                // it's GBK-encoded
                mb_convert_encoding($request['Content'], 'utf-8', 'gbk')  == '发送消息';
            }))->shouldBeCalledTimes(1)->willReturn(new Response(200, [], file_get_contents(__DIR__ . '/data/single_success.xml')));

        $ret = $this->send('发送消息', '13800138000', [ 'round_trip' => true ]);
        $ret->shouldHaveCount(1);
        $ret->shouldHaveKeyWithValue('13800138000', '20081104123453654785');
    }

    function it_should_have_message_sent_roundtrip_for_multiple_subscriber(ClientInterface $client)
    {
        $client->request('POST', 'http://221.179.180.158:9007/QxtSms/QxtFirewall',
            Argument::that(function (array $request) {
                $request = $request[RequestOptions::FORM_PARAMS];
                return $request['OperID']    == 'account' &&
                $request['OperPass']  == 'password' &&
                $request['AppendID']  == '1234' &&
                $request['DesMobile'] == '13800138000' &&
                // it's GBK-encoded
                mb_convert_encoding($request['Content'], 'utf-8', 'gbk')  == '发送消息';
            }))->shouldBeCalledTimes(1)->willReturn(new Response(200, [], file_get_contents(__DIR__ . '/data/multi_success.xml')));

        $ret = $this->send('发送消息', '13800138000', [ 'round_trip' => true ]);
        $ret->shouldHaveCount(2);
        $ret->shouldHaveKeyWithValue('13800138000', '20081104123453654785');
        $ret->shouldHaveKeyWithValue('13800138001', '20081104123453654786');
    }

    private function makeSubscribers($prefix, $from, $to)
    {
        $pad = 11 - strlen($prefix);  // each mobile number takes 11 in length

        $subscribers = [];
        for ($i = $from; $i <= $to; $i++) {
            $subscribers[] = $prefix . str_pad($i, $pad, STR_PAD_LEFT);
        }

        return $subscribers;
    }

    //=====================================
    //          Query Quota
    //=====================================
    function it_should_have_quota_queried(ClientInterface $client)
    {
        $client->request('GET', 'http://221.179.180.158:8081/QxtSms_surplus/surplus?OperID=account&OperPass=password')
               ->shouldBeCalledTimes(1)
               ->willReturn(new Response(200, [], file_get_contents(__DIR__ . '/data/surplus.xml')));

        $this->queryQuota()->shouldBe(10);
    }
}
