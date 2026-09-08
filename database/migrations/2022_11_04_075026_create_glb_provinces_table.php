<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateGlbProvincesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('glb_provinces', function (Blueprint $table) {
            $table->string('country_code', 60)->nullable()->index('con_glb_provinces_fk');
            $table->string('province_code_alias', 60)->nullable();
            $table->string('province_code', 60)->primary();
            $table->string('province_name', 120)->nullable()->unique('glb_provinces_UN');
            $table->string('province_iso_code', 2)->nullable();
            $table->integer('province_adm_code')->nullable();
            $table->string('postal_code_from', 60)->nullable();
            $table->string('postal_code_thru', 60)->nullable();
            $table->string('province_capital', 60)->nullable();
            $table->string('province_capital_name', 120)->nullable();
            $table->string('province_calling_code', 60)->nullable();
            $table->string('province_maps_point', 200)->nullable();
            $table->string('province_latitude', 120)->nullable();
            $table->string('province_longitude', 120)->nullable();
            $table->boolean('is_active')->default(true)->index('glb_provinces_is_active_IDX');
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
        Schema::dropIfExists('glb_provinces');
    }
}
