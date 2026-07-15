<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class WebSocketDialogMsgsAddSessionId extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('web_socket_dialog_msgs', function (Blueprint $table) {
            if (!Schema::hasColumn('web_socket_dialog_msgs', 'session_id')) {
                $table->bigInteger('session_id')->index()->nullable()->default(0)->after('dialog_type')->comment('会话ID');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('web_socket_dialog_msgs', function (Blueprint $table) {
            $table->dropColumn('session_id');
        });
    }
}
