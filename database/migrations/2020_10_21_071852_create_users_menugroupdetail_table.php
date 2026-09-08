<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersMenugroupdetailTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users_menugroupdetail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usergroup_id');
            $table->foreignId('menu_id');
            $table->string('created_by', 100)->default('_SYS_');
            $table->string('updated_by', 100)->default('_SYS_');
            $table->timestamp('deleted_at')->nullable();
            $table->string('deleted_by', 100)->default('_SYS_');
            $table->string('process_desc', 255)->nullable()->default('INSERT NEW DATA');
            $table->timestamps();

            $table->foreign('usergroup_id')->references('id')->on('users_menugroup');
            $table->foreign('menu_id')->references('id')->on('menus');
            
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('users_menugroupdetail');
    }
}
