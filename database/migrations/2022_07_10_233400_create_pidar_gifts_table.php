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
        Schema::create('pidar_gifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pidar_user_id');
            $table->unsignedBigInteger('chat_id');
            $table->boolean('is_processed')->default(false);
            $table->timestamps();

            $table->foreign('pidar_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('chat_id')->references('id')->on('chats')->onDelete('cascade');
        });
    }
};
