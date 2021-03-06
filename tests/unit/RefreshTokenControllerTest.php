<?php

use GuzzleHttp\{Client, HandlerStack};
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;

use PHPUnit\Framework\TestCase;

use NPR\One\Controllers\RefreshTokenController;
use NPR\One\DI\DI;
use NPR\One\Interfaces\{ConfigInterface, EncryptionInterface, StorageInterface};
use NPR\One\Models\AccessTokenModel;
use NPR\One\Providers\{CookieProvider, EncryptionProvider, SecureCookieProvider};

class RefreshTokenControllerTest extends TestCase
{
    const ACCESS_TOKEN_RESPONSE = '{"access_token": "LT8gvVDyeKwQJVVf6xwKAWdK0bOik64faketoken","token_type": "Bearer","expires_in": 690448786,"refresh_token": "6KVn9BOhHhUFR1Yqi2T2pzpTWI9WIfakerefresh"}';
    const ACCESS_TOKEN_RESPONSE_2 = '{"access_token": "LT8gvVDyeKwQJVVf6xwKAWdK0bOik64faketoken","token_type": "Bearer","expires_in": 690448786}';

    /** @var SecureCookieProvider */
    private $mockSecureCookie;
    /** @var EncryptionProvider */
    private $mockEncryption;
    /** @var ConfigInterface */
    private $mockConfig;
    /** @var EncryptionInterface */
    private $mockEncrypt;
    /** @var StorageInterface */
    private $mockStorage;
    /** @var Client */
    private $mockClient;

    /** @var string */
    private static $clientId = 'fake_client_id';


    public function setUp(): void
    {
        $this->mockSecureCookie = $this->getMockBuilder(SecureCookieProvider::class)->getMock();

        $this->mockEncryption = $this->getMockBuilder(EncryptionProvider::class)->setMethods(['isValid', 'set'])->getMock();
        $this->mockEncryption->method('isValid')->willReturn(true);
        $this->mockEncryption->method('set')->willReturn(true);

        $this->mockStorage = $this->createMock(StorageInterface::class);
        $this->mockStorage->method('compare')->willReturn(true);

        $this->mockEncrypt = $this->createMock(EncryptionInterface::class);
        $this->mockEncrypt->method('isValid')->willReturn(false);

        $this->mockConfig = $this->createMock(ConfigInterface::class);
        $this->mockConfig->method('getClientId')->willReturn(self::$clientId);
        $this->mockConfig->method('getNprAuthorizationServiceHost')->willReturn('https://authorization.api.npr.org');
        $this->mockConfig->method('getCookieDomain')->willReturn('.example.com');
        $this->mockConfig->method('getEncryptionSalt')->willReturn('asYh&%D9ne!j8HKQ');

        $this->mockClient = new Client(['handler' => HandlerStack::create(new MockHandler())]);

        DI::container()->set(SecureCookieProvider::class, $this->mockSecureCookie);
        DI::container()->set(EncryptionProvider::class, $this->mockEncryption);
        DI::container()->set(Client::class, $this->mockClient); // just in case
    }

    /**
     * Expect exception type\RuntimeException
     * @expectedExceptionMessageRegExp   #ConfigProvider must be set. See.*setConfigProvider#
     */
    public function testConfigProviderException()
    {
        $this->expectException(\RuntimeException::class);
        $controller = new RefreshTokenController();
        $controller->generateNewAccessTokenFromRefreshToken();
    }

    /**
     * Expect exception type\RuntimeException
     * @expectedExceptionMessageRegExp   #WARNING: It is strongly discouraged to use CookieProvider as your secure storage provider.#
     */
    public function testSecureStorageProviderException()
    {
        $mockCookie = $this->createMock(StorageInterface::class);
        $mockCookie->method('compare')->willReturn(false);

        $this->expectException(\Exception::class); // fix later

        $controller = new RefreshTokenController();
        $controller->setConfigProvider($this->mockConfig);
        $controller->setSecureStorageProvider($this->mockStorage);
        $controller->generateNewAccessTokenFromRefreshToken();
    }

    /**
     * Expect exception type\RuntimeException
     * @expectedExceptionMessageRegExp   #EncryptionProvider must be valid. See.*EncryptionInterface::isValid#
     */
    public function testEncryptionProviderException()
    {
        $mockEncryption = $this->createMock(EncryptionProvider::class);
        $mockEncryption->method('isValid')->willReturn(false);

        $this->expectException(\Exception::class);

        $controller = new RefreshTokenController();
        $controller->setConfigProvider($this->mockConfig);
        $controller->setEncryptionProvider($this->mockEncryption);
        $controller->generateNewAccessTokenFromRefreshToken();
    }

    /**
     * Expect exception type\Exception
     * @expectedExceptionMessage   Could not locate a refresh token
     */
    public function testGenerateNewAccessTokenFromRefreshTokenMissingRefreshToken()
    {
        $this->expectException(\Exception::class);

        $controller = new RefreshTokenController();
        $controller->setConfigProvider($this->mockConfig);
        $controller->generateNewAccessTokenFromRefreshToken();
    }

    /**
     * Expect exception type\Exception
     */
    public function testGenerateNewAccessTokenFromRefreshTokenWithApiError()
    {
        $mock = new MockHandler([
            new Response(500, [], ''),
        ]);

        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        DI::container()->set(Client::class, $client);

        $this->mockSecureCookie->method('get')->willReturn('i_am_a_refresh_token');
        $this->expectException(\Exception::class);

        $controller = new RefreshTokenController();
        $controller->setConfigProvider($this->mockConfig);
        $controller->generateNewAccessTokenFromRefreshToken();
    }

    public function testGenerateNewAccessTokenFromRefreshToken()
    {
        $mock = new MockHandler([
            new Response(200, [], self::ACCESS_TOKEN_RESPONSE),
        ]);

        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        DI::container()->set(Client::class, $client);

        $this->mockSecureCookie->method('get')->willReturn('i_am_a_refresh_token');

        $controller = new RefreshTokenController();
        $controller->setConfigProvider($this->mockConfig);
        $accessToken = $controller->generateNewAccessTokenFromRefreshToken();

        $this->assertInstanceOf(AccessTokenModel::class, $accessToken, 'generateNewAccessTokenFromRefreshToken response was not of type AccessTokenModel: ' . print_r($accessToken, 1));
        $this->assertEquals(0, $mock->count(), 'Expected additional HTTP requests to be made');
    }

    public function testGenerateNewAccessTokenFromRefreshTokenNoNewRefreshToken()
    {
        $mock = new MockHandler([
            new Response(200, [], self::ACCESS_TOKEN_RESPONSE_2),
        ]);

        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        DI::container()->set(Client::class, $client);

        $this->mockSecureCookie->method('get')->willReturn('i_am_a_refresh_token');

        $controller = new RefreshTokenController();
        $controller->setConfigProvider($this->mockConfig);
        $accessToken = $controller->generateNewAccessTokenFromRefreshToken();

        $this->assertInstanceOf(AccessTokenModel::class, $accessToken, 'generateNewAccessTokenFromRefreshToken response was not of type AccessTokenModel: ' . print_r($accessToken, 1));
        $this->assertEquals(0, $mock->count(), 'Expected additional HTTP requests to be made');
    }
}
