<?php

namespace App\Storage;

/** A stored object opened for reading. `$body` is a stream resource positioned at the start of the served slice. */
final class StoredObject
{
    /**
     * @param  resource  $body
     * @param  int  $size  Total size of the object (not of the served slice).
     * @param  ?int  $mtime  Unix time of the last modification.
     * @param  ?array{start: int, end: int}  $range  The inclusive slice `$body` holds when a range was requested; null when it is the whole object.
     */
    public function __construct(
        public $body,
        public readonly int $size,
        public readonly ?int $mtime,
        public readonly ?string $contentType,
        public readonly ?array $range,
    ) {}

    /** Number of bytes `$body` delivers. */
    public function length(): int
    {
        return $this->range ? $this->range['end'] - $this->range['start'] + 1 : $this->size;
    }

    /** The served bytes, read fully into memory (small files only). */
    public function contents(): string
    {
        $bytes = stream_get_contents($this->body, $this->length());
        $this->close();

        return $bytes === false ? '' : $bytes;
    }

    public function close(): void
    {
        if (is_resource($this->body)) {
            fclose($this->body);
        }
    }
}
