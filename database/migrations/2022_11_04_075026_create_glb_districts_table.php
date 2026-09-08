<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateGlbDistrictsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('glb_districts', function (Blueprint $table) {
            $table->string('country_code', 60)->nullable()->index('glb_disctricts_country_code_IDX');
            $table->string('province_code', 60)->nullable()->index('ix05_con_glb_disctricts');
            $table->string('city_code', 60)->nullable()->index('glb_disctricts_city_code_IDX');
            $table->string('district_code_alias', 60)->nullable();
            $table->string('district_code', 60)->primary();
            $table->string('district_name', 120)->nullable()->index('ix01_con_glb_disctricts');
            $table->integer('district_number_of_lvl5')->nullable();
            $table->string('district_maps_point', 200)->nullable();
            $table->string('district_latitude', 120)->nullable();
            $table->string('district_longitude', 120)->nullable();
            $table->string('city_tlc', 60)->nullable()->index('ix07_con_glb_disctricts');
            $table->string('airport_code', 60)->nullable()->index('ix08_con_glb_disctricts');
            $table->string('seaport_code', 60)->nullable();
            $table->string('landport_code', 60)->nullable();
            $table->string('postal_code_from', 60)->nullable()->index('ix09_con_glb_disctricts');
            $table->string('postal_code_thru', 60)->nullable()->index('ix10_con_glb_disctricts');
            $table->boolean('is_active')->default(true)->index('glb_disctricts_is_active_IDX');
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
        Schema::dropIfExists('glb_districts');
    }
}
