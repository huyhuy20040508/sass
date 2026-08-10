<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Trang gốc không có nội dung riêng — nó luôn đẩy vào khu quản trị.
     * (Test mặc định của Laravel kỳ vọng 200 nên báo đỏ sai ở app này.)
     */
    public function test_the_application_redirects_to_admin(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }
}
