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
        Schema::create('pidar_gifts_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pidar_gift_id');
            $table->string('title', 1024);
            $table->dateTime('notification_at')->nullable()->default(null);
            $table->boolean('is_notified')->default(false);
            $table->dateTime('unblocking_at')->nullable()->default(null);
            $table->boolean('is_unblocked')->default(false);
            $table->timestamps();

            $table->foreign('pidar_gift_id')->references('id')->on('pidar_gifts')->onDelete('cascade');
        });
    }
};
