<?php

declare(strict_types=1);

namespace Serafim\Boson;

final readonly class CreateInfo
{
    /**
     * @param int<0, max> $width
     * @param int<0, max> $height
     */
    public function __construct(
        public string $title = '',
        public int $width = 640,
        public int $height = 480,
        public bool $resizable = false,
        public bool $closable = true,
        public bool $debug = false,
    ) {}
}
