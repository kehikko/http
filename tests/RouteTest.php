<?php
declare (strict_types = 1);

final class RouteTest extends PHPUnit\Framework\TestCase
{
    public function testRoute(): void
    {
        cfg_init(__DIR__ . '/test-config-no-log.yml', null, true);
        route_init(__DIR__ . '/test-route.yml');
        $this->assertEmpty(route('/does-not-exist'));
        $this->assertEquals('Index', route_render('/'));
        $this->assertEquals('Item 10', route_render('/item/10'));
        $this->assertEquals('Item 10', route_render('/item/1'));
    }
}
