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
        Schema::create('subscribe_categories', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            
            $table->integer('subscribe_id')->unsigned();
            $table->integer('category_id')->unsigned();
            
            $table->primary(['subscribe_id', 'category_id']);
            
            $table->foreign('subscribe_id')->references('id')->on('subscribes')->onDelete('cascade');
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('subscribe_categories');
    }
};
