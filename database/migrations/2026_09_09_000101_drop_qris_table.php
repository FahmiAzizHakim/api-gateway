<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The QRIS codes moved to thirdparty-service.
     *
     * They were here because the gateway stores the image, and for a while it
     * also brokered the Qrisly registration. But the gateway is meant to hold
     * only what authentication needs -- accounts, sessions, access -- and a
     * table of payment destinations is not that. Qrisly's client, its
     * credentials and the payments raised against a code all live in
     * thirdparty-service, so the record of what was registered belongs there
     * too, and registering became a local call instead of an HTTP hop.
     *
     * The images stay exactly where they are, in this application's public/:
     * that is the one origin a browser fetches files from, and
     * thirdparty-service writes into it through UPLOAD_PUBLIC_ROOT like
     * website-service and shop-service already do. Only the rows moved.
     *
     * Rows were copied across before this ran. There is nothing to copy back:
     * down() recreates an empty table, because a table this app no longer
     * reads or writes cannot be repopulated from anything it holds.
     */
    public function up(): void
    {
        Schema::dropIfExists('qris');
    }

    public function down(): void
    {
        // The shape it had, for a rollback that has to leave something behind.
        // See thirdparty-service's create_qris_table for the live definition
        // and the comments explaining each column.
        Schema::create('qris', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('website_id')->nullable()->index();
            $table->string('name');
            $table->unsignedBigInteger('qris_id')->index();
            $table->string('provider', 60)->nullable();
            $table->string('merchant_name')->nullable();
            $table->string('image_path');
            $table->string('image_original')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->boolean('is_active')->default(false)->index();
            $table->string('created_by', 60)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->string('updated_by', 60)->nullable();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }
};
