<?php

declare(strict_types=1);

namespace DifferentTechnology\MfaSms\Sms;

use RuntimeException;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Transport\TransportInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class AwsSnsTransport
 *
 * This class provides an own Transport definition for AWS SNS - without using the (big) AWS SDK.
 * Just configure an DSN with the scheme "sns+https://".
 *
 * Example: sns+https://MY_ACCESS_KEY:MY_URL_ENCODED_SECRET@default?region=eu-west-1
 *
 * @author Markus Hölzle <typo3@markus-hoelzle.de>
 */
class AwsSnsTransport implements TransportInterface
{
    public const SCHEME = 'sns+https';
    protected const HOST = 'sns.%region%.amazonaws.com';

    protected string $maxPrice = '1.0';
    protected string $region = 'eu-west-1';
    protected string $accessKey;
    protected string $secretKey;

    /**
     * AwsSnsTransport constructor.
     * @param string $accessKey
     * @param string $secretKey
     * @param string|null $region
     */
    public function __construct(string $accessKey, string $secretKey, string $region = null)
    {
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->region = $region ? $region : $this->region;
    }

    /**
     * Send request to AWS using signature version 4
     * @see https://docs.aws.amazon.com/general/latest/gr/sigv4_signing.html
     * @param MessageInterface $message
     * @return SentMessage
     */
    public function send(MessageInterface $message): SentMessage
    {
        if (!($message instanceof SmsMessage)) {
            throw new RuntimeException('SNS can only handle class SmsMessage');
        }
        $isoDate = gmdate('Ymd\THis\Z');
        $method = 'POST';
        $urlParams = $this->getUrlParameterString($message);
        $requestOptions = [
            'headers' => [
                'Host' => $this->getEndpoint(),
                'Date' => $isoDate,
            ],
            'body' => '',
        ];

        // Create canonical request
        $canonHeaders = $this->getCanonicalHeaders($requestOptions['headers']);
        $canonHeaderKeys = implode(';', array_keys($canonHeaders));
        $canonHeaderString = '';
        foreach ($canonHeaders as $key => $value) {
            $canonHeaderString .= $key . ':' . $value . "\n";
        }
        $canon = $method . "\n" .
            '/' . "\n" .
            $urlParams . "\n" .
            $canonHeaderString . "\n" .
            $canonHeaderKeys . "\n" .
            hash('sha256', $requestOptions['body']);

        // Calculate signature
        $scope = implode('/', [gmdate('Ymd'), $this->region, 'sns', 'aws4_request']);
        $stringToSign = 'AWS4-HMAC-SHA256' . "\n" . $isoDate . "\n" . $scope . "\n" . hash('sha256', $canon);
        $signature = $this->getSignature($stringToSign);

        // Add additional headers
        $requestOptions['headers']['Accept'] = 'application/json';
        $requestOptions['headers']['Authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $this->accessKey . '/' . $scope .
            ', SignedHeaders=' . $canonHeaderKeys . ', Signature=' . $signature;

        // Send request
        $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
        $response = $requestFactory->request('https://' . $this->getEndpoint() . '?' . $urlParams, $method, $requestOptions);
        $result = json_decode($response->getBody()->getContents(), true);

        // Check response
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Unable to send SMS: ' . $response->getReasonPhrase(), 1615644443);
        }
        if (empty($result['PublishResponse']['PublishResult']['MessageId'])) {
            throw new RuntimeException('Unknown API error', 1615644444);
        }

        return GeneralUtility::makeInstance(SentMessage::class, $message, $this->__toString());
    }

    /**
     * Create (ordered) parameter string for request (like a=b&c=d)
     * @see https://docs.aws.amazon.com/general/latest/gr/sigv4-create-canonical-request.html
     * @param SmsMessage $message
     * @return string
     */
    protected function getUrlParameterString(SmsMessage $message): string
    {
        $params = [
            'Action' => 'Publish',
            'Version' => '2010-03-31',
            'Message' => $message->getSubject(),
            'PhoneNumber' => trim($message->getPhone()),
            // Set max price
            'MessageAttributes.entry.1.Name' => 'AWS.SNS.SMS.MaxPrice',
            'MessageAttributes.entry.1.Value.DataType' => 'Number',
            'MessageAttributes.entry.1.Value.StringValue' => $this->maxPrice,
            // Set SMS type transactional
            'MessageAttributes.entry.2.Name' => 'AWS.SNS.SMS.SMSType',
            'MessageAttributes.entry.2.Value.DataType' => 'String',
            'MessageAttributes.entry.2.Value.StringValue' => 'Transactional',
            // Set sender ID
            'MessageAttributes.entry.3.Name' => 'AWS.SNS.SMS.SenderID',
            'MessageAttributes.entry.3.Value.DataType' => 'String',
            'MessageAttributes.entry.3.Value.StringValue' => 'TYPO3',
        ];
        ksort($params, SORT_STRING);
        foreach ($params as $key1 => &$value1) {
            if (is_array($value1)) {
                ksort($value1, SORT_STRING);
                foreach ($value1 as $key2 => &$value2) {
                    if (is_array($value2)) {
                        ksort($value2, SORT_STRING);
                    }
                }
            }
        }

        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Create canonical headers
     * @see https://docs.aws.amazon.com/general/latest/gr/sigv4-create-canonical-request.html
     * @param array $headers
     * @return array
     */
    protected function getCanonicalHeaders(array $headers): array
    {
        $canonHeaders = [];
        foreach ($headers as $key => $value) {
            $canonHeaders[strtolower($key)] = trim($value);
        }
        ksort($canonHeaders);
        return $canonHeaders;
    }

    /**
     * Create AWS signature version 4
     * @see https://docs.aws.amazon.com/general/latest/gr/sigv4-calculate-signature.html
     * @param string $stringToSign
     * @return string
     */
    protected function getSignature(string $stringToSign): string
    {
        $signingKey = hash_hmac('sha256', gmdate('Ymd'), 'AWS4' . $this->secretKey, true);
        $signingKey = hash_hmac('sha256', $this->region, $signingKey, true);
        $signingKey = hash_hmac('sha256', 'sns', $signingKey, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $signingKey, true);
        return bin2hex(hash_hmac('sha256', $stringToSign, $signingKey, true));
    }

    /**
     * Get endpoint for configured region
     * @return string
     */
    protected function getEndpoint(): string
    {
        return str_replace('%region%', $this->region, self::HOST);
    }

    public function __toString(): string
    {
        return sprintf(self::SCHEME . '://%s@%s', $this->accessKey, $this->getEndpoint());
    }

    public function supports(MessageInterface $message): bool
    {
        return $message instanceof SmsMessage;
    }
}
