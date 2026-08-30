<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive by design: creates `integrations` if it does not exist, and adds
 * only the missing columns if it does. Safe to run against an app that
 * already stores integrations of its own.
 */
return new class extends Migration
{
    /** Identity and profile. Plain strings — other platforms do not promise ISO-length values. */
    private const STRINGS = [
        'integration_external_id',
        'integration_store_domain',
        'integration_domain',
        'integration_scopes',
        'integration_name',
        'integration_email',
        'integration_shop_owner',
        'integration_phone',
        'integration_currency',
        'integration_country_code',
        'integration_country_name',
        'integration_primary_locale',
        'integration_plan_name',
        'integration_weight_unit',
    ];

    /** Tokens. text, not string: encryption pushes a token well past 255 chars. */
    private const TEXTS = [
        'integration_access_token',
        'integration_refresh_token',
    ];

    private const TIMESTAMPS = [
        'integration_token_expires_at',
        'integration_installed_at',
        'integration_uninstalled_at',
        'integration_shop_data_synced_at',
    ];

    private const JSONS = [
        'integration_shop_data',
        'integration_metadata',
    ];

    private const BOOLEANS = [
        'integration_password_enabled',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('integrations')) {
            Schema::create('integrations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('integrations', function (Blueprint $table) {
            if (! Schema::hasColumn('integrations', 'integration_platform')) {
                $table->string('integration_platform')->default('shopify');
            }

            foreach (self::STRINGS as $name) {
                if (! Schema::hasColumn('integrations', $name)) {
                    $table->string($name)->nullable();
                }
            }

            foreach (self::TEXTS as $name) {
                if (! Schema::hasColumn('integrations', $name)) {
                    $table->text($name)->nullable();
                }
            }

            foreach (self::TIMESTAMPS as $name) {
                if (! Schema::hasColumn('integrations', $name)) {
                    $table->timestamp($name)->nullable();
                }
            }

            foreach (self::JSONS as $name) {
                if (! Schema::hasColumn('integrations', $name)) {
                    $table->json($name)->nullable();
                }
            }

            foreach (self::BOOLEANS as $name) {
                if (! Schema::hasColumn('integrations', $name)) {
                    $table->boolean($name)->nullable();
                }
            }
        });

        foreach ($this->indexes() as [$columns, $unique]) {
            $this->addIndex($columns, $unique);
        }
    }

    /** @return array<int, array{0: array<int, string>, 1: bool}> */
    private function indexes(): array
    {
        return [
            [['integration_platform', 'integration_external_id'], true],
            [['integration_platform', 'integration_store_domain'], true],
            [['user_id', 'integration_platform'], false],
            [['integration_platform', 'integration_uninstalled_at'], false],
            [['integration_token_expires_at'], false],
        ];
    }

    public function down(): void
    {
        // Indexes first. Dropping a column an index still references fails on
        // SQLite and leaves a broken index behind on MySQL.
        foreach ($this->indexes() as [$columns, $unique]) {
            $this->dropIndex($columns, $unique);
        }

        $columns = array_merge(
            ['integration_platform'],
            self::STRINGS,
            self::TEXTS,
            self::TIMESTAMPS,
            self::JSONS,
            self::BOOLEANS,
        );

        $present = array_values(array_filter(
            $columns,
            fn (string $name) => Schema::hasColumn('integrations', $name),
        ));

        if ($present === []) {
            return;
        }

        Schema::table('integrations', function (Blueprint $table) use ($present) {
            $table->dropColumn($present);
        });
    }

    /**
     * Indexes are added outside the column closure: a duplicate index name is
     * a hard error, and Schema::hasIndex() does not exist before Laravel 11.
     */
    private function addIndex(array $columns, bool $unique = false): void
    {
        $name = $this->indexName($columns, $unique);

        if ($this->indexExists($name)) {
            return;
        }

        try {
            Schema::table('integrations', function (Blueprint $table) use ($columns, $unique, $name) {
                $unique ? $table->unique($columns, $name) : $table->index($columns, $name);
            });
        } catch (\Throwable $e) {
            // An equivalent index under a different name, or duplicate values
            // in an existing table. Neither is worth failing a deploy over —
            // the app is correct without the index, only slower.
            report($e);
        }
    }

    private function indexName(array $columns, bool $unique): string
    {
        $name = 'integrations_'.implode('_', $columns).($unique ? '_unique' : '_index');

        return strlen($name) > 60 ? 'integrations_'.substr(md5($name), 0, 30) : $name;
    }

    private function dropIndex(array $columns, bool $unique): void
    {
        $name = $this->indexName($columns, $unique);

        if (! $this->indexExists($name)) {
            return;
        }

        try {
            Schema::table('integrations', function (Blueprint $table) use ($name, $unique) {
                $unique ? $table->dropUnique($name) : $table->dropIndex($name);
            });
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function indexExists(string $name): bool
    {
        $connection = Schema::getConnection();
        $database   = $connection->getDatabaseName();

        return match ($connection->getDriverName()) {
            'mysql', 'mariadb' => DB::table('information_schema.statistics')
                ->where('table_schema', $database)
                ->where('table_name', 'integrations')
                ->where('index_name', $name)
                ->exists(),
            'pgsql' => DB::table('pg_indexes')
                ->where('tablename', 'integrations')
                ->where('indexname', $name)
                ->exists(),
            'sqlite' => DB::selectOne("SELECT name FROM sqlite_master WHERE type = 'index' AND name = ?", [$name]) !== null,
            default => false,
        };
    }
};
