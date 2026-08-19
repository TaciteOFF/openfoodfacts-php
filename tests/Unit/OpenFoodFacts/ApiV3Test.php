<?php

namespace OpenFoodFactsTests\Unit\OpenFoodFacts;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OpenFoodFacts\Api;
use OpenFoodFacts\Document;
use OpenFoodFacts\Document\FoodDocument;
use OpenFoodFacts\Exception\BadRequestException;
use OpenFoodFacts\Exception\InvalidParameterException;
use OpenFoodFacts\Exception\MissingCredentialsException;
use OpenFoodFacts\Exception\ProductNotFoundException;
use OpenFoodFacts\Exception\UnknownException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class ApiV3Test extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    private function createApi(MockHandler $mockHandler, string $currentAPI = 'food'): Api
    {
        $this->history = [];
        $handlerStack  = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($this->history));

        return new Api('Unit test', $currentAPI, 'world', null, new Client(['handler' => $handlerStack]));
    }

    private static function successEnvelope(array $product): string
    {
        return (string) json_encode([
            'status'   => 'success',
            'result'   => ['id' => 'product_found', 'name' => 'Product found'],
            'errors'   => [],
            'warnings' => [],
            'code'     => $product['code'] ?? '',
            'product'  => $product,
        ]);
    }

    public function testGetProductRequestsV3VersionedUrl(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], self::successEnvelope([
                'code'         => '3057640385148',
                'product_name' => 'Volvic',
            ])),
        ]);
        $api = $this->createApi($mockHandler);

        $product = $api->getProduct('3057640385148', ['product_name', 'tags_sources'], 'fr');

        $this->assertInstanceOf(FoodDocument::class, $product);
        $this->assertSame('Volvic', $product->product_name);

        $request = $this->history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame(
            '/api/v' . Api::API_VERSION . '/product/3057640385148',
            $request->getUri()->getPath()
        );
        $this->assertSame('fields=product_name%2Ctags_sources&lc=fr', $request->getUri()->getQuery());
        $this->assertSame('world.openfoodfacts.org', $request->getUri()->getHost());
    }

    public function testGetProductReturnsGenericDocumentForUnknownApi(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], self::successEnvelope(['code' => '123', 'product_name' => 'thing'])),
        ]);
        $api = $this->createApi($mockHandler, 'product');

        $document = $api->getProduct('123');
        $this->assertInstanceOf(Document::class, $document);
    }

    public function testGetProductThrowsOnNotFound(): void
    {
        $mockHandler = new MockHandler([
            new Response(404, ['Content-Type' => 'application/json'], (string) json_encode([
                'status' => 'failure',
                'result' => ['id' => 'product_not_found', 'name' => 'Product not found'],
                'errors' => [],
            ])),
        ]);
        $api = $this->createApi($mockHandler);

        $this->expectException(ProductNotFoundException::class);
        $api->getProduct('3057640385140');
    }

    public function testGetProductThrowsOnNotFoundWithoutJsonBody(): void
    {
        $mockHandler = new MockHandler([new Response(404, [], 'Not found')]);
        $api = $this->createApi($mockHandler);

        $this->expectException(ProductNotFoundException::class);
        $api->getProduct('3057640385140');
    }

    public function testGetProductRejectsNonNumericBarcode(): void
    {
        $api = $this->createApi(new MockHandler([]));

        $this->expectException(InvalidParameterException::class);
        $api->getProduct('foo/../bar');
    }

    public function testGetProductThrowsOnNonJsonResponse(): void
    {
        $mockHandler = new MockHandler([new Response(200, [], '<html>maintenance</html>')]);
        $api = $this->createApi($mockHandler);

        $this->expectException(UnknownException::class);
        $api->getProduct('3057640385148');
    }

    public function testUpdateProductRequiresCredentials(): void
    {
        $api = $this->createApi(new MockHandler([]));

        $this->expectException(MissingCredentialsException::class);
        $api->updateProduct('3057640385148', ['product_name_fr' => 'Eau de Volvic']);
    }

    public function testUpdateProductSendsStructuredPatchBody(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], self::successEnvelope([
                'product_name_fr' => 'Eau de Volvic',
            ])),
        ]);
        $api = $this->createApi($mockHandler);
        $api->authentification('user', 'secret');

        $result = $api->updateProduct(
            '3057640385148',
            ['product_name_fr' => 'Eau de Volvic', 'categories_tags' => ['en:waters']],
            ['product_name'],
            'fr'
        );

        $this->assertSame('success', $result['status']);

        $request = $this->history[0]['request'];
        $this->assertSame('PATCH', $request->getMethod());
        $this->assertSame(
            '/api/v' . Api::API_VERSION . '/product/3057640385148',
            $request->getUri()->getPath()
        );

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('user', $body['user_id']);
        $this->assertSame('secret', $body['password']);
        $this->assertSame('fr', $body['lc']);
        $this->assertSame('product_name', $body['fields']);
        $this->assertSame(['en:waters'], $body['product']['categories_tags']);
    }

    public function testUpdateProductThrowsWithReadableErrorsOnFailure(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'status' => 'failure',
                'result' => ['id' => 'product_not_updated', 'name' => 'Product not updated'],
                'errors' => [
                    [
                        'message' => ['id' => 'invalid_user_id_and_password', 'name' => 'Invalid user id and password'],
                        'field'   => ['id' => 'user_id'],
                        'impact'  => ['id' => 'failure'],
                    ],
                ],
            ])),
        ]);
        $api = $this->createApi($mockHandler);
        $api->authentification('user', 'wrong');

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Invalid user id and password (field: user_id)');
        $api->updateProduct('3057640385148', ['product_name_fr' => 'Eau de Volvic']);
    }

    public function testUploadImageSendsBase64PayloadAndSelection(): void
    {
        $imagePath = tempnam(sys_get_temp_dir(), 'off') . '.png';
        file_put_contents($imagePath, 'fake-image-bytes');

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], self::successEnvelope(['images' => []])),
        ]);
        $api = $this->createApi($mockHandler);
        $api->authentification('user', 'secret');

        try {
            $result = $api->uploadImage('3057640385148', 'front', $imagePath, 'fr');
        } finally {
            unlink($imagePath);
        }

        $this->assertSame('success', $result['status']);

        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(
            '/api/v' . Api::API_VERSION . '/product/3057640385148/images',
            $request->getUri()->getPath()
        );

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame(base64_encode('fake-image-bytes'), $body['image_data_base64']);
        $this->assertSame([], $body['selected']['front']['fr']);
    }

    public function testUploadImageRejectsInvalidImageField(): void
    {
        $api = $this->createApi(new MockHandler([]));
        $api->authentification('user', 'secret');

        $this->expectException(BadRequestException::class);
        $api->uploadImage('3057640385148', 'barcode-photo', __FILE__);
    }
}
