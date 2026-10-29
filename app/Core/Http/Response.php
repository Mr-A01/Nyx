<?php
declare(strict_types=1);

namespace Nyx\Core\Http;

class Response
{
    public function __construct(
        protected mixed $content = '',
        protected int $status = 200,
        protected array $headers = [],
    ) {}

    public function status(): int { return $this->status; }

    public function header(string $name, string $value): static
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        echo $this->content;
    }
}
