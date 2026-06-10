<?php

namespace HelfenTalk\Connect\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Auto-discovers the schema of whitelisted tables. No manual endpoint
 * registration needed — the plugin learns the columns at request time.
 */
class SchemaInspector
{
    public function __construct(protected ?string $connection = null)
    {
    }

    /**
     * @return array<int, string>
     */
    public function allowedTables(): array
    {
        return array_values((array) config('helfentalk.allowed_tables', []));
    }

    public function tableExists(string $table): bool
    {
        return Schema::connection($this->connection)->hasTable($table);
    }

    /**
     * @return array<int, string>
     */
    public function columns(string $table): array
    {
        return Schema::connection($this->connection)->getColumnListing($table);
    }
}
