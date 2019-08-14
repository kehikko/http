<?php
declare (strict_types = 1);

final class HttpTest extends PHPUnit\Framework\TestCase
{
    public function testHttp(): void
    {
        cfg_init(__DIR__ . '/test-config-no-log.yml', null, true);
        $this->assertEquals('none', http_method());
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->assertEquals('get', http_method());
        $this->assertTrue(http_using_method(['get']));
        $this->assertFalse(http_using_method(['put', 'post']));
    }

    public function testHttpPayload(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $GLOBALS['__kehikko_term_payload__'] = '{"one":1,"two":2}';
        $this->assertIsIterable(http_request_payload_json());
    }
}
