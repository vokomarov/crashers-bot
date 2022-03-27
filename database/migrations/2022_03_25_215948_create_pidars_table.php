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
        Schema::create('pidar_history_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('chat_id');
            $table->unsignedBigInteger('sender_user_id')->nullable();
            $table->unsignedBigInteger('pidar_user_id');
            $table->date('date');
            $table->timestamps();

            $table->foreign('chat_id')->references('id')->on('chats')->onDelete('cascade');
            $table->foreign('sender_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('pidar_user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }
};
