# SMS Service
Use APIs exposed by Guodu to implement SMS-related service, which includes sending SMS, checking quota/surplus, etc.

This service provides only the most basic features, and designated to be integrated into other project as infrastructure. 

```php
use Homer\Sms\Guodu\Service as GuoduSmsService;

$service = new GuoduSmsService('account', 'password');
// - or the full version
// $service = new GuoduSmsService('account', 'password', $optionsOfService, $instanceOfClient);
$service->send('message', $subscriber, $optionsOfMessage);
```

## Service Configuration
Options for creating a GuoduSmsService include:

* ``name`` name of merchant(e.g., 【XXX】), can be either prepend or append to the message. 
* ``affix`` 附加号码 a part of sender's number that will be used to
* ``send_url``  url for sending message (typically, you will be change it at all)
* ``quota_url``  url for querying quota

All options are optional.

## Mesage Configuration
Options for sending a message include:

* ``send_time``  when will this message be delivered. If not set, the message will be delivered right away. It's in YYYYMMDDHHIISS format.
* ``msg_type``   message type. Should one either 8 (for 普通短信, which is default) or 15 (for 长短信)
* ``name_pos``  name position in the message, should be one of 0(for hiding name), 1(for appending, which is default) and 2 (for prepending to the message)
* ``expires_at`` message can be temporarily stored on message server, and we're allowed to give it an expiry time. It's in YYYYMMDDHHIISS format.

All options are optional.

