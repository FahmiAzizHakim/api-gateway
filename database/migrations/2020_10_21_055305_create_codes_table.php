<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCodesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('codes', function (Blueprint $table) {
            $table->bigIncrements('id')->index("codes_idx1");
            $table->string('parentcode', 60)->nullable()->index("codes_idx2");
            $table->string('code', 60)->unique('code_unique')->index("codes_idx3");
            $table->string('name')->nullable()->index("codes_idx5");
            $table->string('description')->nullable();
            $table->string('value')->nullable();
            $table->string('datatype', 60)->default('STRING');
            $table->integer('order')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('codes');
    }
}
