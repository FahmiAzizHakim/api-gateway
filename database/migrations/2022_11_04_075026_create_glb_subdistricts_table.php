<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateGlbSubdistrictsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('glb_subdistricts', function (Blueprint $table) {
            $table->string('country_code', 60)->nullable()->index('con_glb_subdistricts_fk_3');
            $table->string('country_regioncode', 190)->nullable();
            $table->string('province_code', 60)->nullable()->index('ix08_con_glb_subdistricts');
            $table->string('province_name', 190)->nullable();
            $table->string('province_iso_code', 190)->nullable();
            $table->string('province_adm_code', 190)->nullable();
            $table->string('city_code', 60)->nullable()->index('ix06_con_glb_subdistricts');
            $table->string('city_type', 190)->nullable();
            $table->string('city_name', 190)->nullable();
            $table->string('city_capital', 190)->nullable();
            $table->string('district_code', 60)->nullable()->index('ix04_con_glb_subdistricts');
            $table->string('district_name', 190)->nullable();
            $table->string('subdistrict_code', 60)->primary()->index('ix12_con_glb_subdistricts');;
            $table->string('subdistrict_name', 190)->nullable()->index('ix10_con_glb_subdistricts');
            $table->integer('subdistrict_postal_code')->nullable()->index('ix01_con_glb_subdistricts');
            $table->string('subdistrict_adm_code', 60)->nullable()->index('ix02_con_glb_subdistricts');
            $table->string('subdistrict_adm_name', 190)->nullable()->index('ix03_con_glb_subdistricts');
            $table->integer('subdistrict_number_of_lvl6')->nullable();
            $table->string('subdistrict_maps_point', 190)->nullable();
            $table->string('subdistrict_latitude', 120)->nullable();
            $table->string('subdistrict_longitude', 120)->nullable();
            $table->integer('subdistrict_number')->nullable();
            $table->string('city_tlc', 60)->nullable();
            $table->string('airport_code', 60)->nullable()->index('ix11_con_glb_subdistricts');
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
        Schema::dropIfExists('glb_subdistricts');
    }
}
