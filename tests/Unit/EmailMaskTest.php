<?php

namespace Tests\Unit;

use App\Support\EmailMask;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailMaskTest extends TestCase
{
    #[Test]
    public function test_keeps_the_first_local_character_and_the_full_domain(): void
    {
        $this->assertSame('a***@example.com', EmailMask::mask('alice@example.com'));
    }

    #[Test]
    public function test_masks_a_single_character_local_part(): void
    {
        $this->assertSame('a***@x.y', EmailMask::mask('a@x.y'));
    }

    #[Test]
    public function test_masks_an_empty_local_part(): void
    {
        $this->assertSame('***@example.com', EmailMask::mask('@example.com'));
    }

    #[Test]
    public function test_keeps_multibyte_first_characters(): void
    {
        $this->assertSame('é***@example.com', EmailMask::mask('éric@example.com'));
    }
}
