<?php

declare(strict_types=1);

namespace Messenger\Transport;

use AymDev\MessengerAzureBundle\Messenger\Transport\DsnParser;
use PHPUnit\Framework\TestCase;

class DsnParserTest extends TestCase
{
    /**
     * @return iterable<string, array{dsn: string, options: mixed[], expectedResult: mixed[]}>
     */
    public static function provideValidConfigurations(): iterable
    {
        yield 'simple DSN' => [
            'dsn' => 'azure://key-name:key-value@namespace-name',
            'options' => ['entity_path' => 'path'],
            'expectedResult' => [
                'entity_path' => 'path',
                'shared_access_key_name' => 'key-name',
                'shared_access_key' => 'key-value',
                'namespace' => 'namespace-name',
                'subscription' => null,
                'token_expiry' => 3600,
                'receive_mode' => 'peek-lock',
            ],
        ];

        yield 'custom token_expiry in query' => [
            'dsn' => 'azure://key-name:key-value@namespace-name?token_expiry=7200',
            'options' => ['entity_path' => 'path'],
            'expectedResult' => [
                'entity_path' => 'path',
                'shared_access_key_name' => 'key-name',
                'shared_access_key' => 'key-value',
                'namespace' => 'namespace-name',
                'subscription' => null,
                'token_expiry' => 7200,
                'receive_mode' => 'peek-lock',
            ],
        ];
    }

    /**
     * @dataProvider provideValidConfigurations
     * @param mixed[] $options
     * @param mixed[] $expectedResult
     */
    public function testParseValidDsn(string $dsn, array $options, array $expectedResult): void
    {
        $parser = new DsnParser();
        $result = $parser->parseDsn($dsn, $options, 'my-transport');

        self::assertEquals($expectedResult, $result);
    }

    public function testDsnOptionsAreUrlDecoded(): void
    {
        $parser = new DsnParser();
        $result = $parser->parseDsn(
            'azure://key%20name:key%20value@namespace%20name?entity_path=entity%20path',
            [],
            'my-transport',
        );

        self::assertEquals([
            'entity_path' => 'entity path',
            'shared_access_key_name' => 'key name',
            'shared_access_key' => 'key value',
            'namespace' => 'namespace name',
            'subscription' => null,
            'token_expiry' => 3600,
            'receive_mode' => 'peek-lock',
        ], $result);
    }

    /**
     * @return iterable<string, array{dsn: string, options: mixed[], expected: mixed[]}>
     */
    public function provideMergingOrderTestData(): iterable
    {
        yield 'dsn options' => [
            'dsn' => 'azure://key-name:key-value@namespace-name?entity_path=entity-path&token_expiry=7200',
            'options' => [
                'shared_access_key_name' => 'from-options',
                'shared_access_key' => 'from-options',
                'namespace' => 'from-options',
                'entity_path' => 'from-options',
                'token_expiry' => 1600,
            ],
            'expected' => [
                'shared_access_key_name' => 'key-name',
                'shared_access_key' => 'key-value',
                'namespace' => 'namespace-name',
                'entity_path' => 'entity-path',
                'subscription' => null,
                'token_expiry' => 7200,
                'receive_mode' => 'peek-lock',
            ],
        ];

        yield 'regular options' => [
            'dsn' => 'azure://namespace-name',
            'options' => [
                'shared_access_key_name' => 'key-name',
                'shared_access_key' => 'key-value',
                'entity_path' => 'entity-path',
            ],
            'expected' => [
                'shared_access_key_name' => 'key-name',
                'shared_access_key' => 'key-value',
                'namespace' => 'namespace-name',
                'entity_path' => 'entity-path',
                'subscription' => null,
                'token_expiry' => 3600,
                'receive_mode' => 'peek-lock',
            ],
        ];
    }

    /**
     * @dataProvider provideMergingOrderTestData
     * @param mixed[] $options
     * @param mixed[] $expectedResult
     */
    public function testOptionsMergingOrder(string $dsn, array $options, array $expectedResult): void
    {
        $parser = new DsnParser();
        $result = $parser->parseDsn($dsn, $options, 'my-transport');

        self::assertSame($expectedResult, $result);
    }

    /**
     * @return iterable<string, array{dsn: string, options: mixed[], error: string, code?: int}>
     */
    public static function provideInvalidConfigurations(): iterable
    {
        yield 'unparseable DSN' => [
            'dsn' => 'http://?',
            'options' => [],
            'error' =>
                'Invalid Azure Service Bus DSN for the "my-transport" transport. ' .
                'It must be in the following format: azure://SharedAccessKeyName:SharedAccessKey@namespace',
            'code' => 1643988474,
        ];

        yield 'wrong scheme' => [
            'dsn' => 'http://SharedAccessKeyName:SharedAccessKey@namespace',
            'options' => [],
            'error' =>
                'Invalid Azure Service Bus DSN for the "my-transport" transport. ' .
                'It must be in the following format: azure://SharedAccessKeyName:SharedAccessKey@namespace',
            'code' => 1643988474,
        ];

        yield 'unknown option' => [
            'dsn' => 'azure://SharedAccessKeyName:SharedAccessKey@namespace',
            'options' => ['foo' => 'bar'],
            'error' =>
                'Unknown option found: [foo]. ' .
                'Allowed options are [shared_access_key_name, shared_access_key, namespace, entity_path, ' .
                'subscription, token_expiry, receive_mode].',
        ];

        yield 'unknown query param' => [
            'dsn' => 'azure://SharedAccessKeyName:SharedAccessKey@namespace?foo=bar',
            'options' => [],
            'error' =>
                'Unknown option found in DSN: [foo]. ' .
                'Allowed options are [shared_access_key_name, shared_access_key, namespace, entity_path, ' .
                'subscription, token_expiry, receive_mode].',
        ];

        yield 'missing entity_path' => [
            'dsn' => 'azure://SharedAccessKeyName:SharedAccessKey@namespace',
            'options' => [],
            'error' =>
                'Invalid "entity_path" (queue or topic) for the "my-transport" transport. ' .
                'Expected string, got null',
            'code' => 1643989596,
        ];

        yield 'invalid entity_path' => [
            'dsn' => 'azure://SharedAccessKeyName:SharedAccessKey@namespace',
            'options' => [
                'entity_path' => 123,
            ],
            'error' =>
                'Invalid "entity_path" (queue or topic) for the "my-transport" transport. ' .
                'Expected string, got int',
            'code' => 1643989596,
        ];

        yield 'invalid subscription' => [
            'dsn' => 'azure://SharedAccessKeyName:SharedAccessKey@namespace',
            'options' => [
                'entity_path' => 'foo',
                'subscription' => 123,
            ],
            'error' => 'Invalid "subscription" for the "my-transport" transport. Expected string, got int',
            'code' => 1643989596,
        ];

        yield 'invalid token_expiry (in options)' => [
            'dsn' => 'azure://SharedAccessKeyName:SharedAccessKey@namespace',
            'options' => [
                'entity_path' => 'foo',
                'token_expiry' => 123.45,
            ],
            'error' => 'Invalid "token_expiry" for the "my-transport" transport. Expected integer, got float',
            'code' => 1643989596,
        ];

        yield 'invalid token_expiry (in DSN)' => [
            'dsn' => 'azure://SharedAccessKeyName:SharedAccessKey@namespace?token_expiry=123.45',
            'options' => [
                'entity_path' => 'foo',
            ],
            'error' => 'Invalid "token_expiry" for the "my-transport" transport. Expected integer, got string',
            'code' => 1643989596,
        ];

        yield 'invalid receive_mode (in options)' => [
            'dsn' => 'azure://SharedAccessKeyName:SharedAccessKey@namespace',
            'options' => [
                'entity_path' => 'foo',
                'receive_mode' => 'foo',
            ],
            'error' => 'Invalid "foo" receive_mode for the "my-transport" transport. It must be one of: ' .
                'peek-lock, receive-and-delete.',
            'code' => 1643994036,
        ];

        yield 'invalid receive_mode (in DNS)' => [
            'dsn' => 'azure://SharedAccessKeyName:SharedAccessKey@namespace?receive_mode=foo',
            'options' => [
                'entity_path' => 'foo',
            ],
            'error' => 'Invalid "foo" receive_mode for the "my-transport" transport. It must be one of: ' .
                'peek-lock, receive-and-delete.',
            'code' => 1643994036,
        ];

        yield 'missing shared_access_key_name' => [
            'dsn' => 'azure://:SharedAccessKey@namespace?receive_mode=peek-lock',
            'options' => [
                'entity_path' => 'foo',
            ],
            'error' => 'Missing shared_access_key_name for the "my-transport" transport.',
            'code' => 1702724695,
        ];

        yield 'invalid shared_access_key_name' => [
            'dsn' => 'azure://:SharedAccessKey@namespace?receive_mode=peek-lock',
            'options' => [
                'entity_path' => 'foo',
                'shared_access_key_name' => 123,
            ],
            'error' => 'Invalid "shared_access_key_name" for the "my-transport" transport. Expected string, got int',
            'code' => 1702724695,
        ];

        yield 'missing shared_access_key' => [
            'dsn' => 'azure://SharedAccessKeyName@namespace?receive_mode=peek-lock',
            'options' => [
                'entity_path' => 'foo',
            ],
            'error' => 'Missing shared_access_key for the "my-transport" transport.',
            'code' => 1702724695,
        ];

        yield 'invalid shared_access_key' => [
            'dsn' => 'azure://SharedAccessKeyName@namespace?receive_mode=peek-lock',
            'options' => [
                'entity_path' => 'foo',
                'shared_access_key' => 123,
            ],
            'error' => 'Invalid "shared_access_key" for the "my-transport" transport. Expected string, got int',
            'code' => 1702724695,
        ];
    }

    /**
     * @dataProvider provideInvalidConfigurations
     * @param mixed[] $options
     */
    public function testInvalidConfigThrowsException(
        string $dsn,
        array $options,
        string $expectedError,
        int $expectedCode = 0
    ): void {
        $parser = new DsnParser();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedError);
        $this->expectExceptionCode($expectedCode);

        $parser->parseDsn($dsn, $options, 'my-transport');
    }
}
