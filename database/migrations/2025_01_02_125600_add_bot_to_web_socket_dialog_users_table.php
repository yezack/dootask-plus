<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBotToWebSocketDialogUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('web_socket_dialog_users', function (Blueprint $table) {
            if (!Schema::hasColumn('web_socket_dialog_users', 'bot')) {
                $table->tinyInteger('bot')->nullable()->default(0)->after('userid')->comment('是否机器人');
                $table->index('bot');
            }
        });

        // 获取表前缀
        $prefix = DB::getTablePrefix();

        // 使用原生SQL更新数据
        /** @noinspection SqlNoDataSourceInspection */
        DB::statement("
            UPDATE {$prefix}web_socket_dialog_users du
            INNER JOIN {$prefix}users u ON u.userid = du.userid
            SET du.bot = 1
            WHERE u.bot = 1
        ");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('web_socket_dialog_users', function (Blueprint $table) {
            if (Schema::hasColumn('web_socket_dialog_users', 'bot')) {
                $table->dropIndex('bot');
                $table->dropColumn('bot');
            }
        });
    }
}
