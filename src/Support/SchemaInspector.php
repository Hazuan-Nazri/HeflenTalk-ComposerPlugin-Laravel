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
     * The tables the plugin may touch. Resolves the 'all' wildcard to every
     * table in the database; otherwise returns the configured whitelist.
     *
     * @return array<int, string>
     */
    public function allowedTables(): array
    {
        $configured = config('helfentalk.allowed_tables', []);

        if ($configured === 'all' || $configured === ['all'])
        {
            return $this->allTables();
        }

        return array_values((array) $configured);
    }

    /**
     * Whether a given table is within the allowed set.
     */
    public function isAllowed(string $table): bool
    {
        return in_array($table, $this->allowedTables(), true);
    }

    /**
     * @return array<int, string>
     */
    public function allTables(): array
    {
        return Schema::connection($this->connection)->getTableListing();
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
