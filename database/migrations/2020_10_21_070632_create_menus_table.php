<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMenusTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('website_id')->nullable()->index();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name_in')->nullable();
            $table->string('name_en')->nullable();
            $table->string('menu_url')->nullable();
            $table->text('menu_icon')->nullable();
            $table->string('menu_type')->nullable();
            $table->string('menu_desc')->nullable();
            $table->tinyInteger('activestatus')->default('1');
            $table->string('created_by', 100)->default('_SYS_');
            $table->string('updated_by', 100)->default('_SYS_');
            $table->timestamp('deleted_at')->nullable();
            $table->string('deleted_by', 100)->default('_SYS_');
            $table->string('process_desc', 255)->nullable()->default('INSERT NEW DATA');
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
        Schema::dropIfExists('menus');
    }
}
