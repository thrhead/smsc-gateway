<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Messages Table
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->string('message_id', 64)->unique();
            $table->string('sender', 20);
            $table->string('recipient', 20);
            $table->text('content');
            $table->string('status', 20);
            $table->unsignedInteger('operator_id');
            $table->timestamps();
            
            $table->index('status');
            $table->index(['created_at', 'updated_at']);
        });

        // Operators Table
        Schema::create('operators', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('country_code', 5);
            $table->json('connection_params');
            $table->string('status', 20);
            $table->integer('priority');
            $table->integer('max_tps');
            $table->timestamps();
        });

        // Routes Table
        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 20);
            $table->unsignedInteger('operator_id');
            $table->integer('priority');
            $table->decimal('cost', 10, 4);
            $table->timestamps();

            $table->foreign('operator_id')->references('id')->on('operators');
        });

        // Users Table
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('api_key', 64)->unique();
            $table->json('permissions');
            $table->timestamps();
        });

        // Message Queue Table
        Schema::create('message_queue', function (Blueprint $table) {
            $table->id();
            $table->string('message_id', 64);
            $table->unsignedInteger('operator_id');
            $table->integer('priority');
            $table->timestamp('scheduled_at');
            $table->string('status', 20);
            $table->timestamps();

            $table->index('status');
            $table->index('scheduled_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('message_queue');
        Schema::dropIfExists('users');
        Schema::dropIfExists('routes');
        Schema::dropIfExists('operators');
        Schema::dropIfExists('messages');
    }
}; 