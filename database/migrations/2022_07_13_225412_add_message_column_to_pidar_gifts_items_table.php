<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('pidar_gifts_items', function (Blueprint $table) {
            $table->text('message')->after('is_notified')->nullable()->default(null);
        });
    }
};
