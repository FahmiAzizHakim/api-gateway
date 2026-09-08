<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateGlbCountriesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('glb_countries', function (Blueprint $table) {
            $table->string('country_code', 60)->primary();
            $table->string('country_alpha3_code', 3)->nullable();
            $table->string('country_numeric_code', 3)->nullable();
            $table->string('country_name', 120)->nullable()->unique('glb_countries_UN');
            $table->string('country_capital', 60)->nullable();
            $table->string('country_currencies', 60)->nullable();
            $table->string('country_currencies_name', 60)->nullable();
            $table->string('country_calling_code', 60)->nullable()->index('ix05_con_glb_countries');
            $table->string('country_maps_point', 200)->nullable();
            $table->string('country_latitude', 200)->nullable();
            $table->string('country_longitude', 200)->nullable();
            $table->boolean('is_active')->default(true)->index('glb_countries_is_active_IDX');
            $table->string('created_by', 60)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->string('updated_by', 60)->nullable();
            $table->timestamp('updated_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('glb_countries');
    }
}
