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
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->integer('tg_id')->unique();
            $table->string('title')->nullable();
            $table->enum('type', ['private', 'group', 'supergroup', 'channel'])->default('private');
            $table->timestamps();
        });
    }
};
