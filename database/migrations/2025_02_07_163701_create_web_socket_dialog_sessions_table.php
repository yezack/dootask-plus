<?php

use App\Models\WebSocketDialog;
use App\Models\WebSocketDialogMsg;
use App\Models\WebSocketDialogSession;
use App\Module\Base;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWebSocketDialogSessionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('web_socket_dialog_sessions')) {
            return;
        }
        Schema::create('web_socket_dialog_sessions', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('dialog_id')->unsigned()->index()->comment('对话ID');
            $table->string('title', 255)->default('')->comment('会话标题');
            $table->timestamps();
        });
        $list = WebSocketDialog::select(['web_socket_dialogs.*', 'u.email'])
            ->join('web_socket_dialog_users as du', 'web_socket_dialogs.id', '=', 'du.dialog_id')
            ->join('users as u', 'du.userid', '=', 'u.userid')
            ->where('u.email', 'like', 'ai-%@bot.system')
            ->where('web_socket_dialogs.type', 'user')
            ->get();
        foreach ($list as $item) {
            $title = WebSocketDialogMsg::whereDialogId($item->id)->where('key', '!=', '')->orderBy('id')->value('key');
            $session = WebSocketDialogSession::createInstance([
                'dialog_id' => $item->id,
                'title' => $title ? Base::cutStr($title, 100) : 'Unknown',
                'created_at' => $item->created_at,
            ]);
            $session->save();
            $item->session_id = $session->id;
            $item->save();
            WebSocketDialogMsg::whereDialogId($item->id)->update(['session_id' => $session->id]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('web_socket_dialog_sessions');
    }
}
