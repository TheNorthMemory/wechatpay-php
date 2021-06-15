<?php

namespace WeChatPay;

use function assert;
use function abs;
use function intval;
use function is_string;
use function is_numeric;
use function is_resource;
use function is_object;
use function is_array;
use function count;

use InvalidArgumentException;

use GuzzleHttp\Client;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** @var int - The maximum clock offset in second */
const MAXIMUM_CLOCK_OFFSET = 301;

const WechatpayNonce = 'Wechatpay-Nonce';
const WechatpaySerial = 'Wechatpay-Serial';
const WechatpaySignature = 'Wechatpay-Signature';
const WechatpayTimestamp = 'Wechatpay-Timestamp';

/**
 * JSON based Client interface for sending HTTP requests.
 */
trait ClientJsonTrait
{
    /**
     * @var Client - The APIv3's `GuzzleHttp\Client`
     */
    protected $v3;

    /**
     * @var array - The defaults configuration whose pased in `GuzzleHttp\Client`.
     */
    protected static $defaults = [
        'base_uri' => 'https://api.mch.weixin.qq.com/',
        'headers' => [
            'Accept' => 'application/json, text/plain, application/x-gzip',
            'Content-Type' => 'application/json; charset=utf-8',
        ],
    ];

    /**
     * Deep merge the input with the defaults
     *
     * @param array $config - The configuration.
     *
     * @return array - With the built-in configuration.
     */
    abstract protected static function withDefaults(array $config = []): array;

    /**
     * APIv3's signer middleware stack
     *
     * @param string|int $mchid - The merchant ID
     * @param string $serial - The serial number of the merchant certificate
     * @param OpenSSLAsymmetricKey|OpenSSLCertificate|resource|array|string $privateKey - The merchant private key.
     *
     * @return callable
     * @throws InvalidArgumentException
     */
    public static function signer(string $mchid, string $serial, $privateKey): callable
    {
        return static function (RequestInterface $request) use ($mchid, $serial, $privateKey): RequestInterface {
            $nonce = Formatter::nonce();
            $timestamp = (string) Formatter::timestamp();
            $signature = Crypto\Rsa::sign(Formatter::request(
                $request->getMethod(), $request->getRequestTarget(), $timestamp, $nonce, static::body($request)
            ), $privateKey);

            return $request->withHeader('Authorization', Formatter::authorization(
                $mchid, $nonce, $signature, $timestamp, $serial
            ));
        };
    }

    /**
     * APIv3's verifier middleware stack
     *
     * @param array $certs The wechatpay platform serial and certificate(s), `[serial => certificate]` pair
     *
     * @return callable
     * @throws InvalidArgumentException
     */
    public static function verifier(array &$certs): callable
    {
        return static function (ResponseInterface $response) use (&$certs): ResponseInterface {
            assert($response->hasHeader(WechatpayNonce) && $response->hasHeader(WechatpaySerial)
                && $response->hasHeader(WechatpaySignature) && $response->hasHeader(WechatpayTimestamp),
                new InvalidArgumentException('The response\'s Headers incomplete.')
            );

            list($nonce) = $response->getHeader(WechatpayNonce);
            list($serial) = $response->getHeader(WechatpaySerial);
            list($signature) = $response->getHeader(WechatpaySignature);
            list($timestamp) = $response->getHeader(WechatpayTimestamp);

            $localTimestamp = Formatter::timestamp();

            assert(
                abs($localTimestamp - intval($timestamp)) < MAXIMUM_CLOCK_OFFSET,
                new InvalidArgumentException(
                    "It's allowed time offset in ± 5 minutes, the response was on ${timestamp}, your's localtime on ${localTimestamp}."
                )
            );

            assert(
                Crypto\Rsa::verify(Formatter::response($timestamp, $nonce, static::body($response)), $signature, $certs[$serial]),
                new InvalidArgumentException(
                    "Verify the response's data with: timestamp=${timestamp}, nonce=${nonce}, signature=${signature}, cert=[${serial}: publicKey] failed."
                )
            );

            return $response;
        };
    }

    /**
     * Create an APIv3's client
     *
     * @param array $config - configuration
     * @param string|int $config[mchid] - The merchant ID
     * @param string $config[serial] - The serial number of the merchant certificate
     * @param OpenSSLAsymmetricKey|OpenSSLCertificate|resource|array|string $config[privateKey] - The merchant private key.
     * @param array $config[certs] - The wechatpay platform serial and certificate(s), `[serial => certificate]` pair
     *
     * @return Client - The `GuzzleHttp\Client` instance
     * @throws InvalidArgumentException
     */
    public static function jsonBased(array $config = []): Client
    {
        assert(
            isset($config['mchid']) && (is_string($config['mchid']) || is_numeric($config['mchid'])),
            new InvalidArgumentException('The merchant\' ID aka `mchid` is required, usually numerical.')
        );

        assert(
            isset($config['serial']) && is_string($config['serial']),
            new InvalidArgumentException('The serial number of the merchant\'s certificate aka `serial` is required, usually hexadecial.')
        );

        assert(
            isset($config['privateKey']) && (is_string($config['privateKey']) || is_resource($config['privateKey']) || is_object($config['privateKey'])),
            new InvalidArgumentException('The merchant\'s private key aka `privateKey` is required, usual as pem format.')
        );

        assert(
            isset($config['certs']) && is_array($config['certs']) && count($config['certs']),
            new InvalidArgumentException('The platform certificate(s) aka `certs` is required, paired as of `[serial => certificate]`.')
        );

        $handler = $config['handler'] ?? HandlerStack::create();
        $handler->unshift(Middleware::mapRequest(static::signer($config['mchid'], $config['serial'], $config['privateKey'])), 'signer');
        $handler->unshift(Middleware::mapResponse(static::verifier($config['certs'])), 'verifier');
        $config['handler'] = $handler;

        unset($config['mchid'], $config['serial'], $config['privateKey'], $config['certs'], $config['secret'], $config['merchant']);

        return new Client(static::withDefaults($config));
    }
}
