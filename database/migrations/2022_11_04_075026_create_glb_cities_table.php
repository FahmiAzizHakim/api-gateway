<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateGlbCitiesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('glb_cities', function (Blueprint $table) {
            $table->string('country_code', 60)->nullable()->index('glb_cities_FK');
            $table->string('province_code', 120)->nullable()->index('ix04_con_glb_cities');
            $table->string('city_code_alias', 60)->nullable();
            $table->string('city_code', 60)->primary();
            $table->string('city_type', 60)->nullable()->index('ix03_con_glb_cities');
            $table->string('city_name', 120)->nullable()->index('ix01_con_glb_cities');
            $table->string('city_capital', 120)->nullable();
            $table->string('city_maps_point', 120)->nullable();
            $table->string('city_latitude', 120)->nullable();
            $table->string('city_longitude', 120)->nullable();
            $table->string('postal_code_from', 60)->nullable()->index('ix07_con_glb_cities');
            $table->string('postal_code_thru', 60)->nullable()->index('ix08_con_glb_cities');
            $table->string('city_tlc', 3)->nullable()->index('ix05_con_glb_cities');
            $table->string('airport_code', 60)->nullable()->index('ix06_con_glb_cities');
            $table->string('seaport_code', 60)->nullable();
            $table->string('landport_code', 60)->nullable();
            $table->boolean('is_active')->default(true);
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
        Schema::dropIfExists('glb_cities');
    }
}
