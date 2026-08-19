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
use OpenFoodFacts\Exception\ProductUpdateException;
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

    public function testPatchKeepsMethodAndBodyAcrossRedirects(): void
    {
        $mockHandler = new MockHandler([
            new Response(302, ['Location' => 'https://world.openfoodfacts.org/api/v' . Api::API_VERSION . '/product/3057640385148']),
            new Response(200, ['Content-Type' => 'application/json'], self::successEnvelope([])),
        ]);
        $api = $this->createApi($mockHandler);
        $api->authentification('user', 'secret');

        $api->updateProduct('3057640385148', ['product_name_fr' => 'Eau de Volvic']);

        $this->assertCount(2, $this->history);
        $redirectedRequest = $this->history[1]['request'];
        $this->assertSame('PATCH', $redirectedRequest->getMethod(), 'the redirected request must NOT be downgraded to GET');

        $body = json_decode((string) $redirectedRequest->getBody(), true);
        $this->assertSame('Eau de Volvic', $body['product']['product_name_fr'], 'the redirected request must keep its body');
    }

    public function testUpdateProductThrowsOnPartialFailure(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'status' => 'success_with_errors',
                'result' => ['id' => 'product_updated', 'name' => 'Product updated'],
                'errors' => [
                    [
                        'message' => ['id' => 'invalid_field_value', 'name' => 'Invalid field value'],
                        'field'   => ['id' => 'packagings'],
                        'impact'  => ['id' => 'field_ignored'],
                    ],
                ],
                'product' => ['product_name_fr' => 'Eau de Volvic'],
            ])),
        ]);
        $api = $this->createApi($mockHandler);
        $api->authentification('user', 'secret');

        try {
            $api->updateProduct('3057640385148', ['product_name_fr' => 'Eau de Volvic', 'packagings' => 'bad']);
            $this->fail('a partially rejected write must throw');
        } catch (ProductUpdateException $productUpdateException) {
            $this->assertStringContainsString('partially failed', $productUpdateException->getMessage());
            $this->assertStringContainsString('Invalid field value (field: packagings)', $productUpdateException->getMessage());
            // the envelope stays available: part of the data was saved anyway
            $this->assertSame('success_with_errors', $productUpdateException->getResponse()['status']);
        }
    }

    public function testUpdateProductReturnsOnSuccessWithWarnings(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'status'   => 'success_with_warnings',
                'result'   => ['id' => 'product_updated'],
                'errors'   => [],
                'warnings' => [['message' => ['id' => 'unexpected_value', 'name' => 'Unexpected value']]],
            ])),
        ]);
        $api = $this->createApi($mockHandler);
        $api->authentification('user', 'secret');

        $result = $api->updateProduct('3057640385148', ['product_name_fr' => 'Eau de Volvic']);
        $this->assertSame('success_with_warnings', $result['status']);
    }

    public function testGetProductSendsProductTypeForCrossFlavorRedirects(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], self::successEnvelope(['code' => '123'])),
        ]);
        $api = $this->createApi($mockHandler);

        $api->getProduct('123', null, null, null, null, 'all');

        $this->assertSame('product_type=all', $this->history[0]['request']->getUri()->getQuery());
    }

    public function testTestModeUsesBasicGateSeparatedFromAccountCredentials(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], self::successEnvelope([])),
        ]);
        $api = $this->createApi($mockHandler);
        $api->activeTestMode();
        $api->authentification('realuser', 'realpass');

        $api->updateProduct('3057640385148', ['product_name_fr' => 'Eau de Volvic']);

        $request = $this->history[0]['request'];
        $this->assertSame('world.openfoodfacts.net', $request->getUri()->getHost());
        $this->assertSame('Basic ' . base64_encode('off:off'), $request->getHeaderLine('Authorization'), 'the staging HTTP Basic gate must stay off/off');

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('realuser', $body['user_id'], 'account credentials must come from authentification(), not from the staging gate');
        $this->assertSame('realpass', $body['password']);
    }

    public function testUpdateProductInTestModeWithoutAccountCredentialsIsRejectedLocally(): void
    {
        $api = $this->createApi(new MockHandler([]));
        $api->activeTestMode();

        $this->expectException(MissingCredentialsException::class);
        $api->updateProduct('3057640385148', ['product_name_fr' => 'Eau de Volvic']);
    }
}
