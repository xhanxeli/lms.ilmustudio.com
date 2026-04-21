<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAffiliateApiClientsTable extends Migration
{
    public function up()
    {
        Schema::create('affiliate_api_clients', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 255)->nullable();
            $table->string('access_key', 64)->unique();
            $table->string('secret_hash', 255);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('affiliate_api_clients');
    }
}
