<?php

namespace App\Support;

use RuntimeException;

/**
 * The one failure server code throws for a rejected request (bad input, not signed in, not allowed, missing
 * row, conflict, rate limit). bootstrap/app.php renders it per entry point: the action envelope for
 * `/_actions/*`, `{ message }` for `/api/*`, an HTTP error page elsewhere.
 */
class RequestError extends RuntimeException
{
    public const BAD_REQUEST = 'BAD_REQUEST';

    public const UNAUTHORIZED = 'UNAUTHORIZED';

    public const FORBIDDEN = 'FORBIDDEN';

    public const NOT_FOUND = 'NOT_FOUND';

    public const CONFLICT = 'CONFLICT';

    public const UNPROCESSABLE_CONTENT = 'UNPROCESSABLE_CONTENT';

    public const TOO_MANY_REQUESTS = 'TOO_MANY_REQUESTS';

    private const STATUS = [
        self::BAD_REQUEST => 400,
        self::UNAUTHORIZED => 401,
        self::FORBIDDEN => 403,
        self::NOT_FOUND => 404,
        self::CONFLICT => 409,
        self::UNPROCESSABLE_CONTENT => 422,
        self::TOO_MANY_REQUESTS => 429,
    ];

    private const DEFAULT_MESSAGE = [
        self::UNAUTHORIZED => 'Sign in required.',
        self::FORBIDDEN => 'Forbidden.',
        self::NOT_FOUND => 'Not found.',
    ];

    public function __construct(public readonly string $errorCode, ?string $message = null)
    {
        parent::__construct($message ?? self::DEFAULT_MESSAGE[$errorCode] ?? 'Bad request.');
    }

    public function status(): int
    {
        return self::STATUS[$this->errorCode];
    }

    public static function badRequest(?string $message = null): self
    {
        return new self(self::BAD_REQUEST, $message);
    }

    public static function unauthorized(?string $message = null): self
    {
        return new self(self::UNAUTHORIZED, $message);
    }

    public static function forbidden(?string $message = null): self
    {
        return new self(self::FORBIDDEN, $message);
    }

    public static function notFound(?string $message = null): self
    {
        return new self(self::NOT_FOUND, $message);
    }

    public static function conflict(?string $message = null): self
    {
        return new self(self::CONFLICT, $message);
    }

    public static function unprocessable(?string $message = null): self
    {
        return new self(self::UNPROCESSABLE_CONTENT, $message);
    }

    public static function tooManyRequests(?string $message = null): self
    {
        return new self(self::TOO_MANY_REQUESTS, $message);
    }
}
