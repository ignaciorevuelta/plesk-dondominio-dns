<?php // plib/library/PartialSyncException.php
class Modules_DondominioDns_PartialSyncException extends RuntimeException
{
    public function __construct(string $message, private array $applied, Throwable $previous)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getApplied(): array
    {
        return $this->applied;
    }
}
