<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddActiveAndExpiredAtToSubscribeUsesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('subscribe_uses', function (Blueprint $table) {
            $table->boolean('active')->default(true)->after('webinar_id');
            $table->integer('expired_at')->unsigned()->nullable()->after('active');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('subscribe_uses', function (Blueprint $table) {
            $table->dropColumn('active');
            $table->dropColumn('expired_at');
        });
    }
} 