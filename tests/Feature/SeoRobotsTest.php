<?php

namespace Tests\Feature;

use Tests\TestCase;

class SeoRobotsTest extends TestCase
{
    public function test_robots_txt_disallows_private_sections_and_points_to_sitemap(): void
    {
        $contents = file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('Disallow: /login', $contents);
        $this->assertStringContainsString('Disallow: /account', $contents);
        $this->assertStringContainsString('Sitemap: https://ekoyoga-ik.ru/sitemap.xml', $contents);
    }
}
