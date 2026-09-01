<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A table for the test suite only.
 *
 * The package ships no migration — an app owns its own schema — so the suite
 * has to stand one up. Column names here are the defaults the column map
 * assumes, which is what keeps the zero-config path honest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            // Nullable here so the bulk of the suite exercises the shipped
            // repository unmodified. OwnedStoreTest stands up its own table
            // with this column NOT NULL to prove that case separately.
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('platform')->default('shopify');
            $table->string('external_id')->nullable();
            $table->string('store_domain')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('scopes')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('uninstalled_at')->nullable();

            $table->string('domain')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('shop_owner')->nullable();
            $table->string('phone')->nullable();
            $table->string('currency')->nullable();
            $table->string('country_code')->nullable();
            $table->string('country_name')->nullable();
            $table->string('primary_locale')->nullable();
            $table->string('plan_name')->nullable();
            $table->string('weight_unit')->nullable();
            $table->boolean('password_enabled')->nullable();
            $table->json('shop_data')->nullable();
            $table->timestamp('shop_data_synced_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
