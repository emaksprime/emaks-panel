<?php

namespace App\Services\Mikro;

use Closure;
use Illuminate\Database\Connection;

final class MikroBusinessWriteMonitor
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function measure(Closure $operation): MikroBusinessWriteMeasurement
    {
        $previouslyModified = $this->connection->hasModifiedRecords();
        $this->connection->forgetRecordModificationState();

        try {
            $value = $operation();
            $writeCount = $this->connection->hasModifiedRecords() ? 1 : 0;
        } finally {
            if ($previouslyModified) {
                $this->connection->recordsHaveBeenModified();
            } else {
                $this->connection->forgetRecordModificationState();
            }
        }

        return new MikroBusinessWriteMeasurement($value, $writeCount);
    }
}
